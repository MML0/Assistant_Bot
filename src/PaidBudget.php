<?php

namespace Bot;

/**
 * Optional daily (UTC) spending guard for the paid API (0 = no limit, the default, like the
 * old bot that always answered with MetisAI). A request first reserves its worst-case
 * cost; once the answer arrives the reservation is settled to the measured/estimated cost.
 * Costs are estimates from the configured price table (the paid API reports no cost).
 */
final class PaidBudget
{
    private const FILE = 'paid_budget.json';

    private JsonStore $store;
    private array $cfg;
    /** @var callable */
    private $now;

    public function __construct(JsonStore $store, array $settings, ?callable $now = null)
    {
        $this->store = $store;
        $this->cfg   = $settings['paid'];
        $this->now   = $now ?? fn () => microtime(true);
    }

    public function estimateCost(string $model, int $tokensIn, int $tokensOut): float
    {
        $table = $this->cfg['price_per_million'];
        $p = $table[$model] ?? $table['default'] ?? ['input' => 0, 'output' => 0];
        return ($tokensIn * $p['input'] + $tokensOut * $p['output']) / 1_000_000;
    }

    /** @return array{0:bool,1:?string} [allowed, reason-if-denied] */
    public function reserve(float $worstCase): array
    {
        $today = $this->today();
        $cfg   = $this->cfg;
        return $this->store->update(self::FILE, function (&$d) use ($today, $worstCase, $cfg) {
            if (($d['date'] ?? null) !== $today) {
                $d = ['date' => $today, 'requests' => 0, 'cost' => 0.0, 'denied' => 0];
            }
            if ($cfg['max_requests_per_day'] > 0 && $d['requests'] >= $cfg['max_requests_per_day']) {
                $d['denied']++;
                return [false, 'max_requests_per_day'];
            }
            if ($cfg['max_cost_per_day_usd'] > 0 && $d['cost'] + $worstCase > $cfg['max_cost_per_day_usd']) {
                $d['denied']++;
                return [false, 'max_cost_per_day_usd'];
            }
            $d['requests']++;
            $d['cost'] += $worstCase;
            return [true, null];
        });
    }

    /** Replace a reservation with the actual (estimated) cost. */
    public function settle(float $reserved, float $actual): void
    {
        $today = $this->today();
        $this->store->update(self::FILE, function (&$d) use ($today, $reserved, $actual) {
            if (($d['date'] ?? null) === $today) {
                $d['cost'] = max(0.0, $d['cost'] - $reserved + $actual);
            }
        });
    }

    public function today(): string
    {
        return gmdate('Y-m-d', (int) ($this->now)());
    }

    public function status(): array
    {
        $d = $this->store->read(self::FILE, []);
        if (($d['date'] ?? null) !== $this->today()) {
            $d = ['date' => $this->today(), 'requests' => 0, 'cost' => 0.0, 'denied' => 0];
        }
        return $d + ['max_requests' => $this->cfg['max_requests_per_day'], 'max_cost' => $this->cfg['max_cost_per_day_usd']];
    }
}
