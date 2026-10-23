<?php

namespace Bot;

/**
 * Structured routing diagnostics (JSON lines under storage/logs). Never contains prompts,
 * replies, API keys or user identifiers: only request ids, models, timings, tokens and costs.
 */
final class RoutingLog
{
    private JsonStore $store;
    /** @var callable */
    private $now;

    public function __construct(JsonStore $store, ?callable $now = null)
    {
        $this->store = $store;
        $this->now   = $now ?? fn () => microtime(true);
    }

    public function record(string $type, array $fields): void
    {
        $now = ($this->now)();
        $allowed = ['request_id', 'purpose', 'task', 'mode', 'tier_name', 'attempt', 'tier', 'model', 'free', 'reason', 'latency_ms',
            'tokens_in', 'tokens_out', 'cost_usd', 'cost_source', 'error_class', 'fallback_reason', 'success',
            'attempts', 'possibly_processed', 'total_ms', 'detail'];
        $rec = ['ts' => round($now, 3), 'type' => $type] + array_intersect_key($fields, array_flip($allowed));
        if (isset($rec['detail'])) {
            $rec['detail'] = Redactor::redact((string) $rec['detail'], 200);
        }
        $this->store->appendLine('logs/routing-' . gmdate('Y-m-d', (int) $now) . '.jsonl', $rec);
    }

    /** Reads the last $days of logs. */
    public function records(int $days): array
    {
        $now = ($this->now)();
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $path = $this->store->path('logs/routing-' . gmdate('Y-m-d', (int) $now - $i * 86400) . '.jsonl');
            if (!is_file($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $r = json_decode($line, true);
                if (is_array($r)) {
                    $out[] = $r;
                }
            }
        }
        return $out;
    }

    public function summary(int $days, array $health = [], array $budget = [], array $catalog = []): array
    {
        $finals = ['total' => 0, 'free' => 0, 'paid' => 0, 'failed' => 0, 'fallback' => 0, 'paid_cost' => 0.0];
        $models = [];
        $quotaHits = 0;
        foreach ($this->records($days) as $r) {
            if ($r['type'] === 'final') {
                $finals['total']++;
                if (empty($r['success'])) {
                    $finals['failed']++;
                } elseif (($r['tier'] ?? '') === 'paid') {
                    $finals['paid']++;
                } else {
                    $finals['free']++;
                }
                if (($r['attempts'] ?? 1) > 1 || !empty($r['fallback_reason'])) {
                    $finals['fallback']++;
                }
            } elseif ($r['type'] === 'attempt') {
                $m = $r['model'] ?? '?';
                $models[$m] ??= ['n' => 0, 'ok' => 0, 'lat' => 0, 'tier' => $r['tier'] ?? '?', 'errors' => []];
                $models[$m]['n']++;
                if (!empty($r['success'])) {
                    $models[$m]['ok']++;
                    $models[$m]['lat'] += $r['latency_ms'] ?? 0;
                } else {
                    $c = $r['error_class'] ?? 'unknown';
                    $models[$m]['errors'][$c] = ($models[$m]['errors'][$c] ?? 0) + 1;
                    if ($c === ErrorClassifier::ACCOUNT_DAILY) {
                        $quotaHits++;
                    }
                }
                if (($r['tier'] ?? '') === 'paid') {
                    $finals['paid_cost'] += (float) ($r['cost_usd'] ?? 0);
                }
            }
        }
        $byModel = [];
        foreach ($models as $id => $m) {
            $byModel[$id] = [
                'tier'         => $m['tier'],
                'attempts'     => $m['n'],
                'success_rate' => $m['n'] ? round($m['ok'] / $m['n'], 3) : null,
                'avg_latency_ms' => $m['ok'] ? (int) round($m['lat'] / $m['ok']) : null,
                'errors'       => $m['errors'],
                'repeatedly_failing' => $m['n'] >= 3 && $m['ok'] / $m['n'] < 0.5,
            ];
        }
        uasort($byModel, fn ($a, $b) => $b['attempts'] <=> $a['attempts']);

        $now = ($this->now)();
        $cooling = [];
        foreach ($health['models'] ?? [] as $id => $m) {
            if (($m['cooldown_until'] ?? 0) > $now) {
                $cooling[$id] = (int) ($m['cooldown_until'] - $now) . 's (' . ($m['last_error'] ?? '?') . ')';
            }
        }
        $t = max(1, $finals['total']);
        return [
            'days'               => $days,
            'requests'           => $finals['total'],
            'free_pct'           => round(100 * $finals['free'] / $t, 1),
            'paid_pct'           => round(100 * $finals['paid'] / $t, 1),
            'failed_pct'         => round(100 * $finals['failed'] / $t, 1),
            'fallback_pct'       => round(100 * $finals['fallback'] / $t, 1),
            'estimated_paid_cost_usd' => round($finals['paid_cost'], 4),
            'daily_quota_hits'   => $quotaHits,
            'free_route_blocked_until' => ($health['account']['free_cooldown_until'] ?? 0) > $now
                ? gmdate('Y-m-d H:i:s', (int) $health['account']['free_cooldown_until']) . ' UTC (' . ($health['account']['free_cooldown_reason'] ?? '?') . ')' : null,
            'models_cooling_down' => $cooling,
            'by_model'           => $byModel,
            'paid_budget_today'  => $budget,
            'catalog'            => [
                'models'      => count($catalog['models'] ?? []),
                'fetched_at'  => isset($catalog['fetched_at']) ? gmdate('Y-m-d H:i', (int) $catalog['fetched_at']) . ' UTC' : 'never',
                'last_error'  => $catalog['last_error'] ?? null,
                'is_free_tier' => $catalog['key_info']['is_free_tier'] ?? 'unknown',
            ],
        ];
    }

    public static function format(array $s): string
    {
        $lines = [
            "📊 Routing ({$s['days']}d): {$s['requests']} requests",
            "free {$s['free_pct']}% · paid {$s['paid_pct']}% · failed {$s['failed_pct']}% · fallback {$s['fallback_pct']}%",
            "Paid est. cost: \${$s['estimated_paid_cost_usd']} · daily-quota hits: {$s['daily_quota_hits']}",
            'Today paid: ' . ($s['paid_budget_today']['requests'] ?? 0) . '/' . (($s['paid_budget_today']['max_requests'] ?? 0) ?: 'no limit')
                . ' req, $' . round($s['paid_budget_today']['cost'] ?? 0, 4) . '/' . (($s['paid_budget_today']['max_cost'] ?? 0) ? '$' . $s['paid_budget_today']['max_cost'] : 'no limit'),
            'Catalog: ' . $s['catalog']['models'] . ' free models, refreshed ' . $s['catalog']['fetched_at']
                . ($s['catalog']['last_error'] ? ' (last error: ' . $s['catalog']['last_error'] . ')' : ''),
        ];
        if ($s['free_route_blocked_until']) {
            $lines[] = '⛔ Free route paused until ' . $s['free_route_blocked_until'];
        }
        foreach ($s['models_cooling_down'] as $id => $c) {
            $lines[] = "❄️ {$id}: {$c}";
        }
        $lines[] = '';
        foreach (array_slice($s['by_model'], 0, 12, true) as $id => $m) {
            $lines[] = ($m['repeatedly_failing'] ? '⚠️ ' : '• ') . "{$id} [{$m['tier']}] {$m['attempts']}× ok "
                . ($m['success_rate'] !== null ? round($m['success_rate'] * 100) . '%' : '-')
                . ($m['avg_latency_ms'] !== null ? " {$m['avg_latency_ms']}ms" : '');
        }
        return implode("\n", $lines);
    }
}
