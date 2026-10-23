<?php

namespace Bot;

use GuzzleHttp\ClientInterface;

/**
 * The paid MetisAI (OpenAI-compatible) API, used when the free models are not available.
 *
 * A tier has an ordered list of paid models. Models get retired (gpt-4.1-nano was shut down on
 * 2026-10-23, gpt-4o / gpt-4.1 follow on 2027-01-14), so when MetisAI answers that a model is
 * deprecated or unknown, the next model is used at once and the retired one is skipped for a day.
 * Any other failure is tried once more, like the old bot did (MetisAI forwards to a pool of
 * upstream keys, and a refused request often succeeds on the next try).
 */
final class PaidClient
{
    private const RETIRED_FILE = 'paid_models.json';
    private const RETIRED_SECONDS = 86400;

    private ClientInterface $http;
    private array $settings;
    private PaidBudget $budget;
    private ?JsonStore $store;
    /** @var callable */
    private $now;
    /** @var callable */
    private $sleep;

    public function __construct(ClientInterface $http, array $settings, PaidBudget $budget, ?callable $now = null, ?callable $sleep = null, ?JsonStore $store = null)
    {
        $this->http     = $http;
        $this->settings = $settings;
        $this->budget   = $budget;
        $this->store    = $store;
        $this->now      = $now ?? fn () => microtime(true);
        $this->sleep    = $sleep ?? fn (float $s) => usleep((int) ($s * 1_000_000));
    }

    public function isConfigured(): bool
    {
        return ($this->settings['gpt']['api_key'] ?? '') !== '' && ($this->settings['gpt']['base_url'] ?? '') !== '';
    }

    /** GPT-5 / GPT-6 and o-series models reason first: they take max_completion_tokens and reasoning_effort. */
    public static function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('~^(gpt-[5-9](?!-chat)|o[1-9])~', $model);
    }

    /** @param string|string[] $models in order of preference */
    public function complete(string|array $models, array $messages, float $deadline, ?callable $onAttempt = null): array
    {
        $cfg      = $this->settings['paid'];
        $apiKey   = $this->settings['gpt']['api_key'];
        $baseUrl  = rtrim($this->settings['gpt']['base_url'], '/');
        $models   = $this->order((array) $models);
        $tokensIn = ContextFitter::messagesTokens($messages);
        $last     = null;
        $i        = 0;
        $retried  = false;

        for ($attempt = 1; $attempt <= max(1, (int) $cfg['max_attempts']) && isset($models[$i]); $attempt++) {
            $model = $models[$i];
            $remaining = $deadline - ($this->now)();
            if ($remaining < 2) {
                break;
            }
            $reasoning = self::isReasoningModel($model);
            $maxOut = (int) ($reasoning ? ($cfg['reasoning_max_output_tokens'] ?? 3000) : $cfg['max_output_tokens']);
            $worst = $this->budget->estimateCost($model, $tokensIn, $maxOut);
            [$allowed, $why] = $this->budget->reserve($worst);
            if (!$allowed) {
                return ['ok' => false, 'error' => ['class' => ErrorClassifier::BUDGET, 'scope' => 'account', 'status' => 0, 'detail' => $why, 'retry_at' => null, 'remaining' => null, 'possibly_processed' => false], 'model_used' => $model];
            }

            $start = ($this->now)();
            $result = $this->attempt($baseUrl, $apiKey, $model, $messages, $maxOut, $reasoning, min((float) $cfg['attempt_timeout'], $remaining));
            $result['latency_ms'] = (int) round((($this->now)() - $start) * 1000);
            $result['attempt']    = $attempt;
            $result['model_used'] = $model;

            if ($result['ok']) {
                $cost = $this->budget->estimateCost($model, $result['tokens_in'] ?? $tokensIn, $result['tokens_out'] ?? ContextFitter::estimateTokens($result['content']));
                $this->budget->settle($worst, $cost);
                $result['cost'] = $cost;
                $result['cost_source'] = 'estimated';
                if ($onAttempt) {
                    $onAttempt($result);
                }
                return $result;
            }

            $err = $result['error'];
            // Only a lost response may have been billed; keep its reservation, release the rest.
            $this->budget->settle($worst, $err['possibly_processed'] ? $worst : 0.0);
            $result['cost'] = $err['possibly_processed'] ? $worst : 0.0;
            $result['cost_source'] = 'estimated';
            if ($onAttempt) {
                $onAttempt($result);
            }
            $last = $result;

            if ($err['class'] === ErrorClassifier::INVALID_MODEL) {
                $this->markRetired($model, (string) $err['detail']);
                $i++;
                $retried = false;
                continue; // the next model at once, no waiting
            }
            if ($retried || in_array($err['class'], [ErrorClassifier::UNSUPPORTED, ErrorClassifier::INVALID_REQUEST, ErrorClassifier::CONTEXT], true)) {
                $i++; // this model had its second chance, or the request does not suit it: next model
                $retried = false;
            } else {
                $retried = true; // like the old bot: the same model once more
            }
            if (!isset($models[$i])) {
                break;
            }
            $wait = 0.3 * $attempt + mt_rand(0, 200) / 1000;
            if ($err['retry_at']) {
                $wait = max($wait, min(5.0, $err['retry_at'] - ($this->now)())); // Retry-After, up to 5 s
            }
            if (($this->now)() + $wait + 2 > $deadline) {
                break;
            }
            ($this->sleep)($wait);
        }
        return $last ?? ['ok' => false, 'error' => ['class' => ErrorClassifier::TIMEOUT, 'scope' => 'model', 'status' => 0, 'detail' => 'no time left for paid attempt', 'retry_at' => null, 'remaining' => null, 'possibly_processed' => false], 'model_used' => $models[0] ?? null];
    }

    /** Paid models MetisAI reported as retired, with when they may be tried again. @return array<string,array{until:float,why:string}> */
    public function retired(): array
    {
        if (!$this->store) {
            return [];
        }
        $now = ($this->now)();
        return array_filter($this->store->read(self::RETIRED_FILE, [])['retired'] ?? [], fn ($r) => ($r['until'] ?? 0) > $now);
    }

    /** Configured order, retired models last (still tried if nothing else is left). */
    private function order(array $models): array
    {
        $models = array_values(array_unique(array_filter($models, fn ($m) => is_string($m) && $m !== '')));
        $retired = $this->retired();
        $alive = array_values(array_filter($models, fn ($m) => !isset($retired[$m])));
        return array_merge($alive, array_values(array_diff($models, $alive)));
    }

    private function markRetired(string $model, string $why): void
    {
        $until = ($this->now)() + self::RETIRED_SECONDS;
        $this->store?->update(self::RETIRED_FILE, function (&$d) use ($model, $why, $until) {
            $d['retired'][$model] = ['until' => $until, 'why' => mb_substr($why, 0, 200)];
        });
    }

    private function attempt(string $baseUrl, string $apiKey, string $model, array $messages, int $maxOut, bool $reasoning, float $timeout): array
    {
        $body = ['model' => $model, 'messages' => $messages];
        if ($reasoning) {
            $body['max_completion_tokens'] = $maxOut; // includes the hidden reasoning tokens
            if (!empty($this->settings['paid']['reasoning_effort'])) {
                $body['reasoning_effort'] = $this->settings['paid']['reasoning_effort'];
            }
        } else {
            $body['max_tokens'] = $maxOut;
        }
        try {
            $res = $this->http->request('POST', $baseUrl . '/chat/completions', [
                'headers'         => ['Authorization' => "Bearer {$apiKey}", 'Content-Type' => 'application/json'],
                'json'            => $body,
                'timeout'         => max(1.0, $timeout),
                'connect_timeout' => min((float) $this->settings['paid']['connect_timeout'], max(1.0, $timeout)),
                'http_errors'     => false,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => ErrorClassifier::fromException($e, ($this->now)())];
        }

        $raw = (string) $res->getBody();
        if ($res->getStatusCode() !== 200) {
            return ['ok' => false, 'error' => ErrorClassifier::fromHttp($res->getStatusCode(), $raw, $res->getHeaders(), ($this->now)())];
        }
        $data = json_decode($raw, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            $err = ErrorClassifier::fromHttp(502, '{"error":{"message":"No content returned."}}', [], ($this->now)());
            $err['class'] = ErrorClassifier::EMPTY;
            $err['scope'] = 'model';
            return ['ok' => false, 'error' => $err];
        }
        $u = $data['usage'] ?? [];
        return [
            'ok'         => true,
            'content'    => $text,
            'tokens_in'  => $u['prompt_tokens'] ?? $u['input_tokens'] ?? null,
            'tokens_out' => $u['completion_tokens'] ?? $u['output_tokens'] ?? null,
        ];
    }
}
