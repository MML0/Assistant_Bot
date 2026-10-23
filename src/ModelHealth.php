<?php

namespace Bot;

/**
 * Remembers what each model (and the OpenRouter account) did recently:
 * successes, failures, latency, rate limits, cooldowns and local request counts.
 *
 * Local counts are only our own view. They are NOT the provider's remaining quota,
 * which stays "unknown" unless OpenRouter reports it in headers or the /key endpoint.
 */
final class ModelHealth
{
    private const FILE = 'model_health.json';
    private const RECENT = 20;

    private JsonStore $store;
    private array $settings;
    /** @var callable */
    private $now;

    public function __construct(JsonStore $store, array $settings, ?callable $now = null)
    {
        $this->store    = $store;
        $this->settings = $settings;
        $this->now      = $now ?? fn () => microtime(true);
    }

    public function snapshot(): array
    {
        return $this->store->read(self::FILE, ['models' => [], 'account' => []]);
    }

    /** Count a free request at send time (also when the answer is later lost to a timeout). */
    public function recordSend(string $model): void
    {
        $now = ($this->now)();
        $this->store->update(self::FILE, function (&$d) use ($model, $now) {
            $d['models'][$model]['requests'][] = $now;
            $d['models'][$model]['requests'] = self::prune($d['models'][$model]['requests'], $now - 86400);
            $d['account']['requests'][] = $now;
            $d['account']['requests'] = self::prune($d['account']['requests'], $now - 86400);
        }, ['models' => [], 'account' => []]);
    }

    public function recordSuccess(string $model, float $latencyMs, array $rate = []): void
    {
        $now = ($this->now)();
        $this->store->update(self::FILE, function (&$d) use ($model, $latencyMs, $now, $rate) {
            $m = $d['models'][$model] ?? [];
            $m['ok'] = ($m['ok'] ?? 0) + 1;
            $m['last_success'] = $now;
            $m['consecutive_failures'] = 0;
            $m['cooldown_until'] = 0;
            $m['recent'][] = ['ok', round($now), (int) $latencyMs];
            $m['recent'] = array_slice($m['recent'], -self::RECENT);
            $d['models'][$model] = $m;
            self::applyRateInfo($d, $rate, $now);
        }, ['models' => [], 'account' => []]);
    }

    /** @param array $err result of ErrorClassifier */
    public function recordFailure(string $model, array $err, float $latencyMs): void
    {
        $now = ($this->now)();
        $cfg = $this->settings['routing'];
        $this->store->update(self::FILE, function (&$d) use ($model, $err, $latencyMs, $now, $cfg) {
            $class = $err['class'];
            $m = $d['models'][$model] ?? [];
            $m['fail'] = ($m['fail'] ?? 0) + 1;
            $m['last_failure'] = $now;
            $m['last_error'] = $class;
            $m['consecutive_failures'] = ($m['consecutive_failures'] ?? 0) + 1;
            $m['recent'][] = [$class, round($now), (int) $latencyMs];
            $m['recent'] = array_slice($m['recent'], -self::RECENT);
            $n = $m['consecutive_failures'];

            $cooldown = 0.0;
            switch ($class) {
                case ErrorClassifier::RATE_LIMIT:
                    $m['last_rate_limit'] = $now;
                    $cooldown = $err['retry_at'] ? max(30.0, $err['retry_at'] - $now) : min(3600, 60 * 2 ** ($n - 1));
                    break;
                case ErrorClassifier::PROVIDER:
                    $cooldown = $err['retry_at'] ? max(30.0, $err['retry_at'] - $now) : min(1800, 120 * 2 ** ($n - 1));
                    break;
                case ErrorClassifier::TIMEOUT:
                    $cooldown = min(1800, 90 * 2 ** ($n - 1));
                    break;
                case ErrorClassifier::INVALID_MODEL:
                    $m['invalid_until'] = $now + 86400; // until a later catalogue refresh re-lists it
                    break;
                case ErrorClassifier::EMPTY:
                    $cooldown = 300;
                    break;
                case ErrorClassifier::UNSUPPORTED:
                case ErrorClassifier::CONTEXT:
                    // A property of this request, not of the model's health.
                    $m['consecutive_failures']--;
                    break;
                default:
                    $cooldown = min(1800, 120 * 2 ** ($n - 1));
            }
            if ($cooldown > 0) {
                $m['cooldown_until'] = max($m['cooldown_until'] ?? 0, $now + $cooldown);
            }
            $d['models'][$model] = $m;

            if ($err['scope'] === 'account') {
                $acc = $cfg['account_cooldowns'] ?? [];
                $until = match ($class) {
                    ErrorClassifier::ACCOUNT_DAILY => $err['retry_at'] ?: self::nextUtcMidnight($now),
                    ErrorClassifier::ACCOUNT_RATE  => $err['retry_at'] ?: $now + 60,
                    ErrorClassifier::AUTH, ErrorClassifier::CREDITS => $now + ($acc[$class] ?? 1800),
                    ErrorClassifier::NETWORK       => $now + 30,
                    default                        => $now + 60,
                };
                $d['account']['free_cooldown_until']  = max($d['account']['free_cooldown_until'] ?? 0, $until);
                $d['account']['free_cooldown_reason'] = $class;
                if ($class === ErrorClassifier::ACCOUNT_DAILY) {
                    $d['account']['last_daily_quota_hit'] = $now;
                }
            }
            if ($err['remaining'] !== null) {
                $d['account']['reported_remaining'] = $err['remaining'];
                $d['account']['reported_at'] = $now;
            }
        }, ['models' => [], 'account' => []]);
    }

    public function accountBlockedUntil(?array $snap = null): float
    {
        $snap ??= $this->snapshot();
        $until = (float) ($snap['account']['free_cooldown_until'] ?? 0);
        return $until > ($this->now)() ? $until : 0.0;
    }

    public function isAvailable(string $model, ?array $snap = null): bool
    {
        $snap ??= $this->snapshot();
        $m   = $snap['models'][$model] ?? [];
        $now = ($this->now)();
        return ($m['cooldown_until'] ?? 0) <= $now && ($m['invalid_until'] ?? 0) <= $now;
    }

    /** Bayesian success rate (prior 0.8 with weight 4) so a single failure does not bury a model. */
    public static function reliability(array $m): float
    {
        $recent = $m['recent'] ?? [];
        $ok = 0;
        $n  = 0;
        foreach ($recent as $r) {
            if (in_array($r[0], [ErrorClassifier::UNSUPPORTED, ErrorClassifier::CONTEXT], true)) {
                continue;
            }
            $n++;
            if ($r[0] === 'ok') {
                $ok++;
            }
        }
        return ($ok + 4 * 0.8) / ($n + 4);
    }

    public static function rateLimitRatio(array $m): array
    {
        $recent = array_slice($m['recent'] ?? [], -10);
        $rl = 0;
        foreach ($recent as $r) {
            if ($r[0] === ErrorClassifier::RATE_LIMIT) {
                $rl++;
            }
        }
        return [count($recent) ? $rl / count($recent) : 0.0, count($recent)];
    }

    public static function avgLatencyMs(array $m): ?float
    {
        $lat = [];
        foreach ($m['recent'] ?? [] as $r) {
            if ($r[0] === 'ok') {
                $lat[] = $r[2];
            }
        }
        return $lat ? array_sum($lat) / count($lat) : null;
    }

    public function localAccountCount(float $sinceSeconds, ?array $snap = null): int
    {
        $snap ??= $this->snapshot();
        $from = ($this->now)() - $sinceSeconds;
        return count(array_filter($snap['account']['requests'] ?? [], fn ($t) => $t >= $from));
    }

    public function localAccountCountToday(?array $snap = null): int
    {
        $snap ??= $this->snapshot();
        $now  = ($this->now)();
        $from = self::nextUtcMidnight($now) - 86400;
        return count(array_filter($snap['account']['requests'] ?? [], fn ($t) => $t >= $from));
    }

    /** Clears `invalid` flags for models the live catalogue lists again. */
    public function revalidate(array $modelIds): void
    {
        $this->store->update(self::FILE, function (&$d) use ($modelIds) {
            foreach ($modelIds as $id) {
                if (isset($d['models'][$id]['invalid_until'])) {
                    unset($d['models'][$id]['invalid_until']);
                }
            }
        }, ['models' => [], 'account' => []]);
    }

    public static function nextUtcMidnight(float $now): float
    {
        return (float) (intdiv((int) $now, 86400) + 1) * 86400;
    }

    private static function prune(array $times, float $from): array
    {
        $times = array_values(array_filter($times, fn ($t) => $t >= $from));
        return array_slice($times, -3000);
    }

    private static function applyRateInfo(array &$d, array $rate, float $now): void
    {
        if (isset($rate['remaining']) && $rate['remaining'] !== null) {
            $d['account']['reported_remaining'] = $rate['remaining'];
            $d['account']['reported_at'] = $now;
        }
    }
}
