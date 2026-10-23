<?php

namespace Bot;

/**
 * Free-first model routing:
 *   1. rank cached free candidates for this task (no network),
 *   2. one attempt per model, at most `max_free_attempts`, never in parallel,
 *   3. skip models that are cooling down; stop the free chain on account-wide errors,
 *   4. fall back to the existing paid API only when policy and budget allow it.
 */
final class Router
{

    private array $s;
    private ModelCatalog $catalog;
    private ModelHealth $health;
    private OpenRouterClient $free;
    private PaidClient $paid;
    private RoutingLog $log;
    private JsonStore $store;
    /** @var callable|null */
    private $alert;
    /** @var callable */
    private $now;

    public function __construct(array $settings, ModelCatalog $catalog, ModelHealth $health, OpenRouterClient $free, PaidClient $paid, RoutingLog $log, JsonStore $store, ?callable $alert = null, ?callable $now = null)
    {
        $this->s       = $settings;
        $this->catalog = $catalog;
        $this->health  = $health;
        $this->free    = $free;
        $this->paid    = $paid;
        $this->log     = $log;
        $this->store   = $store;
        $this->alert   = $alert;
        $this->now     = $now ?? fn () => microtime(true);
    }

    /**
     * @param array $opts purpose: chat|summary, tier (normal|smart|extra), paid_model(s), explicit_paid, tools, request_id
     * @return array ok, content, tier (free|paid|null), model, free_confirmed, error_class, user_message, attempts
     */
    public function complete(array $messages, array $opts = []): array
    {
        $start    = ($this->now)();
        $r        = $this->s['routing'];
        $mode     = $r['mode'];
        $deadline = $start + (float) $r['total_deadline_seconds'];
        $reqId    = $opts['request_id'] ?? bin2hex(random_bytes(6));
        $purpose  = $opts['purpose'] ?? 'chat';
        $explicitPaid = !empty($opts['explicit_paid']);
        $task     = TaskClassifier::classify($messages, $purpose);
        $needs    = TaskClassifier::requirements($messages, $opts['tools'] ?? []);
        $tier     = isset($opts['tier'], $this->s['tiers'][$opts['tier']]) ? $opts['tier'] : null;
        $base     = ['request_id' => $reqId, 'purpose' => $purpose, 'task' => $task, 'mode' => $mode, 'tier_name' => $tier];

        $paidAllowed = $this->paid->isConfigured()
            && ($explicitPaid || $mode === 'paid_only' || ($this->s['paid']['fallback_enabled'] && $mode !== 'cost_min'));

        $attempts = 0;
        $fallbackReason = null;
        $lastError = null;

        if ($explicitPaid) {
            $fallbackReason = 'explicit_paid_request';
        } elseif ($mode === 'paid_only') {
            $fallbackReason = 'paid_only_mode';
        } elseif (!$this->free->hasKey()) {
            $fallbackReason = 'no_openrouter_key';
        } else {
            $snap = $this->health->snapshot();
            $limitRpm = (int) $r['account_free_rpm'];
            if ($this->health->accountBlockedUntil($snap) > 0) {
                $fallbackReason = 'free_route_paused:' . ($snap['account']['free_cooldown_reason'] ?? 'unknown');
            } elseif ($limitRpm > 0 && $this->health->localAccountCount(60, $snap) >= $limitRpm) {
                $fallbackReason = 'local_rpm_guard';
            } else {
                $candidates = $this->rank($task, $needs, $messages, $snap, $mode, $tier);
                $maxAttempts = max(1, (int) $r['max_free_attempts']);
                $daily = $this->dailyFreeLimit();
                if ($daily !== null && $this->health->localAccountCountToday($snap) >= $daily) {
                    $maxAttempts = 1; // our own count says the daily allowance is probably used up
                }
                $invalidRequests = 0;
                if (!$candidates) {
                    $fallbackReason = 'no_eligible_free_model';
                }
                foreach ($candidates as $c) {
                    if ($attempts >= $maxAttempts) {
                        $fallbackReason = 'free_attempt_limit';
                        break;
                    }
                    $timeLeft = $deadline - ($this->now)() - ($paidAllowed ? (float) $r['paid_reserve_seconds'] : 0.0);
                    if ($timeLeft < 3) {
                        $fallbackReason = 'deadline';
                        break;
                    }
                    $maxTokens = (int) $this->s['openrouter']['max_tokens'];
                    if (!empty($c['model']['max_completion_tokens'])) {
                        $maxTokens = min($maxTokens, (int) $c['model']['max_completion_tokens']);
                    }
                    $fitted = ContextFitter::fit($messages, (int) $c['model']['context_length'], $maxTokens);
                    if ($fitted === null) {
                        continue; // cannot fit: skip without spending a request
                    }

                    $attempts++;
                    $this->health->recordSend($c['model']['id']);
                    $res = $this->free->chat($c['model'], $fitted, $task, $maxTokens, min((float) $this->s['openrouter']['attempt_timeout'], $timeLeft));

                    if ($res['ok']) {
                        $this->health->recordSuccess($c['model']['id'], $res['latency_ms'], ['remaining' => $res['remaining']]);
                        $isFree = self::confirmFree($c['model'], $res);
                        $this->log->record('attempt', $base + [
                            'attempt' => $attempts, 'tier' => 'free', 'model' => $res['model_used'], 'free' => $isFree,
                            'reason' => $c['reason'], 'latency_ms' => $res['latency_ms'], 'tokens_in' => $res['tokens_in'],
                            'tokens_out' => $res['tokens_out'], 'cost_usd' => $res['cost'], 'cost_source' => $res['cost'] !== null ? 'reported' : null,
                            'success' => true,
                        ]);
                        if ($isFree === false) {
                            $this->notify('free_not_free', "⚠️ Router: {$res['model_used']} reported a non-zero cost; check the catalogue.");
                        }
                        return $this->finish($base, $start, $attempts, true, 'free', $res['model_used'], $res['content'], $isFree, null, $attempts > 1 ? 'free_failover' : null);
                    }

                    $err = $res['error'];
                    $lastError = $err;
                    $this->health->recordFailure($c['model']['id'], $err, $res['latency_ms']);
                    $this->log->record('attempt', $base + [
                        'attempt' => $attempts, 'tier' => 'free', 'model' => $c['model']['id'], 'reason' => $c['reason'],
                        'latency_ms' => $res['latency_ms'], 'error_class' => $err['class'], 'success' => false,
                        'possibly_processed' => $err['possibly_processed'], 'detail' => $err['detail'], 'status' => $err['status'],
                    ]);

                    if ($err['scope'] === 'account') {
                        $fallbackReason = $err['class'];
                        if (in_array($err['class'], [ErrorClassifier::AUTH, ErrorClassifier::CREDITS], true)) {
                            $this->notify('free_' . $err['class'], "🚨 OpenRouter {$err['class']} (HTTP {$err['status']}). Free route paused; check the key/credits.");
                        }
                        break;
                    }
                    if ($err['class'] === ErrorClassifier::REFUSAL) {
                        if (!$r['paid_on_refusal']) {
                            return $this->finish($base, $start, $attempts, false, null, null, null, null, $err['class'], null, $this->msg('refusal'));
                        }
                        $fallbackReason = $err['class'];
                        break;
                    }
                    if ($err['class'] === ErrorClassifier::TIMEOUT) {
                        // Waiting for another free model would keep the user waiting again (a slow or
                        // filtered route to OpenRouter times out for every model): answer with the paid
                        // model now and skip the free route for a while.
                        $this->health->pauseFreeAfterTimeout();
                        $fallbackReason = 'free_timeout';
                        break;
                    }
                    if ($err['class'] === ErrorClassifier::INVALID_REQUEST && ++$invalidRequests >= 2) {
                        $fallbackReason = $err['class'];
                        break;
                    }
                    $fallbackReason = 'free_candidates_failed';
                }
                if ($candidates && $attempts === 0 && $fallbackReason === null) {
                    $fallbackReason = 'context_too_large_for_free_models';
                }
            }
        }

        // ---------------- paid fallback ----------------
        if (!$paidAllowed) {
            $why = $this->paid->isConfigured() ? 'paid_fallback_disabled' : 'paid_not_configured';
            return $this->finish($base, $start, $attempts, false, null, null, null, null, $lastError['class'] ?? $why, $fallbackReason ?? $why, $this->msg('unavailable'));
        }

        $paidModels = $opts['paid_models'] ?? (isset($opts['paid_model']) ? [$opts['paid_model']] : null)
            ?? ($tier ? $this->s['tiers'][$tier]['paid_models'] : null) ?? [$this->s['paid']['default_model']];
        $paidDeadline = ($explicitPaid || $mode === 'paid_only') ? $start + (float) $r['total_deadline_seconds'] : $deadline;
        $paidAttempt = $attempts;
        $res = $this->paid->complete($paidModels, $messages, $paidDeadline, function (array $a) use (&$paidAttempt, $base, $fallbackReason) {
            $paidAttempt++;
            $this->log->record('attempt', $base + [
                'attempt' => $paidAttempt, 'tier' => 'paid', 'model' => $a['model_used'], 'free' => false,
                'reason' => 'paid:' . $fallbackReason, 'latency_ms' => $a['latency_ms'], 'tokens_in' => $a['tokens_in'] ?? null,
                'tokens_out' => $a['tokens_out'] ?? null, 'cost_usd' => $a['cost'] ?? null, 'cost_source' => $a['cost_source'] ?? null,
                'success' => $a['ok'], 'error_class' => $a['error']['class'] ?? null,
                'possibly_processed' => $a['error']['possibly_processed'] ?? null, 'detail' => $a['error']['detail'] ?? null,
                'status' => $a['error']['status'] ?? null,
            ]);
        });

        if ($res['ok']) {
            return $this->finish($base, $start, $paidAttempt, true, 'paid', $res['model_used'], $res['content'], false, null, $fallbackReason);
        }
        $err = $res['error'];
        if ($err['class'] === ErrorClassifier::BUDGET) {
            $this->notify('paid_budget', "💸 Paid budget reached ({$err['detail']}). Paid fallback is paused until 00:00 UTC.");
            return $this->finish($base, $start, $paidAttempt, false, null, null, null, null, $err['class'], $fallbackReason, $this->msg('budget'));
        }
        $this->notify('paid_error_' . $err['class'], "🚨 GPT Error Log:\npaid model {$res['model_used']}: {$err['class']} (HTTP {$err['status']}) {$err['detail']}");
        return $this->finish($base, $start, $paidAttempt, false, null, null, null, null, $err['class'], $fallbackReason, $err['class'] === ErrorClassifier::REFUSAL ? $this->msg('refusal') : $this->msg('unavailable'));
    }

    /**
     * Orders eligible free models for this task. Ordinary chat keeps scarce models
     * (configured, or often rate-limited) at the back; hard/coding tasks may use them first.
     *
     * @return array<int,array{model:array,score:float,scarce:bool,reason:string}>
     */
    public function rank(string $task, array $needs, array $messages, array $snap, string $mode, ?string $tier = null): array
    {
        $r = $this->s['routing'];
        $w = $r['weights'][$task] ?? $r['weights']['ordinary'];

        $essential = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $essential[] = $m;
            }
        }
        $essential[] = end($messages) ?: ['content' => ''];
        $minTokens = ContextFitter::messagesTokens($essential) + 256;

        $eligible = [];
        $pool = $this->catalog->models();
        $ordered = $tier !== null ? $this->tierModels($tier, $pool) : null;
        foreach ($ordered ?? $pool as $m) {
            if (!$this->health->isAvailable($m['id'], $snap)) {
                continue;
            }
            $mods = $m['input_modalities'] ?? ['text'];
            $params = $m['supported_parameters'] ?? [];
            if (($needs['image'] && !in_array('image', $mods, true))
                || ($needs['file'] && !in_array('file', $mods, true))
                || ($needs['audio'] && !in_array('audio', $mods, true))
                || ($needs['tools'] && !in_array('tools', $params, true))) {
                continue;
            }
            if ($minTokens > ($m['context_length'] ?? 0) * 0.9) {
                continue;
            }
            $eligible[] = $m;
        }
        if (!$eligible) {
            return [];
        }

        $maxQ = max(array_map(fn ($m) => (float) ($m['rank_quality'] ?? $m['intelligence'] ?? 50), $eligible)) ?: 1.0;
        $codingKnown = array_filter(array_column($eligible, 'coding'), fn ($v) => $v !== null);
        $maxC = $codingKnown ? max($codingKnown) : null;

        $out = [];
        foreach ($eligible as $m) {
            $q = (float) ($m['rank_quality'] ?? $m['intelligence'] ?? 50) / $maxQ;
            if ($task === 'coding' && $maxC) {
                $q = $m['coding'] !== null ? $m['coding'] / $maxC : $q * 0.95;
            }
            $h = $snap['models'][$m['id']] ?? [];
            $rel = ModelHealth::reliability($h);
            [$rlr, $n] = ModelHealth::rateLimitRatio($h);
            $lat = ModelHealth::avgLatencyMs($h) ?? 8000;
            $latPen = min(1.0, $lat / 30000);
            $boost = 0.0;
            foreach ((array) $r['model_boosts'] as $pattern => $delta) {
                if (fnmatch($pattern, $m['id'])) {
                    $boost += (float) $delta;
                }
            }
            $scarce = $rlr >= $r['scarce_rate_limit_ratio'] && $n >= 3;
            foreach ((array) $r['reserved_model_patterns'] as $p) {
                if (fnmatch($p, $m['id'])) {
                    $scarce = true;
                }
            }
            $score = $mode === 'quality_first'
                ? $q + 0.1 * $rel + $boost
                : $w['quality'] * $q + $w['reliability'] * $rel - $w['rate_limit'] * $rlr - $w['latency'] * $latPen + $boost;
            $out[] = [
                'model'  => $m,
                'score'  => round($score, 4),
                'scarce' => $scarce,
                'reason' => sprintf('%s q=%.2f%s rel=%.2f rl=%.2f score=%.3f%s', $task, $q, !empty($m['quality_estimated']) ? '~' : '', $rel, $rlr, $score, $scarce ? ' scarce' : ''),
            ];
        }
        if ($ordered !== null) {
            // Tier lists are an explicit preference order: keep it (cooldowns already filtered).
            $tierOut = array_values(array_filter($out, fn ($c) => !empty($c['model']['tier_listed'])));
            $rest    = array_values(array_filter($out, fn ($c) => empty($c['model']['tier_listed'])));
            foreach ($tierOut as &$c) {
                $c['reason'] = 'tier:' . $tier . ' ' . $c['reason'];
            }
            unset($c);
            usort($rest, fn ($a, $b) => [$b['score'], $a['model']['id']] <=> [$a['score'], $b['model']['id']]);
            return array_merge($tierOut, $rest);
        }
        $protect = $task === 'ordinary' && $mode !== 'quality_first';
        usort($out, function ($a, $b) use ($protect) {
            if ($protect && $a['scarce'] !== $b['scarce']) {
                return $a['scarce'] ? 1 : -1;
            }
            return [$b['score'], $a['model']['id']] <=> [$a['score'], $b['model']['id']];
        });
        return $out;
    }

    /**
     * Free models for a tier, then its fallback tiers, in configured order. Once discovery has
     * run, only models OpenRouter currently lists as free are kept (retired / now-paid ones drop out).
     */
    private function tierModels(string $tier, array $pool): array
    {
        $byId = [];
        foreach ($pool as $m) {
            $byId[$m['id']] = $m;
        }
        $verified = !empty($this->catalog->state()['models']);
        $out = [];
        $seen = [];
        foreach (array_merge([$tier], $this->s['tier_fallback'][$tier] ?? []) as $t) {
            foreach ($this->s['tiers'][$t]['free_models'] ?? [] as $id) {
                if (isset($seen[$id]) || !is_string($id) || !ModelCatalog::validId($id)) {
                    continue;
                }
                $seen[$id] = true;
                if (isset($byId[$id])) {
                    $out[] = ['tier_listed' => true] + $byId[$id];
                } elseif (!$verified) {
                    // No live catalogue yet: use the configured id, unverified (never reported as free).
                    $out[] = [
                        'id' => $id, 'name' => $id, 'context_length' => 262144, 'max_completion_tokens' => null,
                        'input_modalities' => ['text'], 'supported_parameters' => [], 'intelligence' => null,
                        'coding' => null, 'free_verified' => false, 'source' => 'tier_config', 'tier_listed' => true,
                    ];
                }
            }
        }
        if ($this->s['routing']['use_discovered_fallback']) {
            foreach ($pool as $m) {
                if (!isset($seen[$m['id']])) {
                    $out[] = $m;
                }
            }
        }
        return $out;
    }

    private function msg(string $key): string
    {
        return $this->s['messages'][$key] ?? '⚠️';
    }

    /** Documented OpenRouter daily free allowance, unless configured. Only an estimate. */
    private function dailyFreeLimit(): ?int
    {
        $cfg = $this->s['routing']['account_free_daily_limit'];
        if ($cfg !== null) {
            return (int) $cfg;
        }
        $tier = $this->catalog->keyInfo()['is_free_tier'] ?? null;
        return $tier === true ? 50 : ($tier === false ? 1000 : null);
    }

    /** true = provider-reported zero cost or verified zero pricing for the served model; null = unknown. */
    private static function confirmFree(array $model, array $res): ?bool
    {
        if ($res['cost'] !== null) {
            return $res['cost'] == 0.0;
        }
        $baseReq  = preg_replace('~:.*$~', '', $model['id']);
        $baseUsed = preg_replace('~:.*$~', '', $res['model_used']);
        return !empty($model['free_verified']) && $baseReq === $baseUsed ? true : null;
    }

    private function finish(array $base, float $start, int $attempts, bool $ok, ?string $tier, ?string $model, ?string $content, ?bool $free, ?string $errClass, ?string $fallbackReason, ?string $userMessage = null): array
    {
        $this->log->record('final', $base + [
            'success' => $ok, 'tier' => $tier, 'model' => $model, 'free' => $free, 'attempts' => $attempts,
            'error_class' => $errClass, 'fallback_reason' => $fallbackReason,
            'total_ms' => (int) round((($this->now)() - $start) * 1000),
        ]);
        return [
            'ok'             => $ok,
            'content'        => $content,
            'tier'           => $tier,
            'model'          => $model,
            'free_confirmed' => $free,
            'error_class'    => $errClass,
            'fallback_reason' => $fallbackReason,
            'user_message'   => $ok ? $content : ($userMessage ?? $this->msg('unavailable')),
            'attempts'       => $attempts,
            'request_id'     => $base['request_id'],
        ];
    }

    /** Admin alerts, at most one per key every 30 minutes. */
    private function notify(string $key, string $text): void
    {
        if (!$this->alert) {
            return;
        }
        $now = ($this->now)();
        $send = $this->store->update('alerts.json', function (&$d) use ($key, $now) {
            if (($d[$key] ?? 0) > $now - 1800) {
                return false;
            }
            $d[$key] = $now;
            return true;
        });
        if ($send) {
            try {
                ($this->alert)(Redactor::redact($text, 1000));
            } catch (\Throwable $e) {
                // alerts must never break a user reply
            }
        }
    }
}
