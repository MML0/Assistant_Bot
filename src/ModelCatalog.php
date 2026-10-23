<?php

namespace Bot;

use GuzzleHttp\ClientInterface;

/**
 * Model discovery. Separate from execution: chat requests only ever read the cached
 * catalogue (load()); refresh() talks to OpenRouter and is run after the reply has been
 * sent, or from cron/refresh_models.php. A failed refresh keeps the last known-good list.
 */
final class ModelCatalog
{
    private const FILE = 'model_catalog.json';
    private const MIN_CONTEXT = 8000;

    private JsonStore $store;
    private ClientInterface $http;
    private array $settings;
    private ?ModelHealth $health;
    /** @var callable */
    private $now;

    public function __construct(JsonStore $store, ClientInterface $http, array $settings, ?ModelHealth $health = null, ?callable $now = null)
    {
        $this->store    = $store;
        $this->http     = $http;
        $this->settings = $settings;
        $this->health   = $health;
        $this->now      = $now ?? fn () => microtime(true);
    }

    /** Cached catalogue, or the configured seed models before the first discovery. Never does I/O to OpenRouter. */
    public function models(): array
    {
        $cat = $this->state();
        if (!empty($cat['models'])) {
            return $cat['models'];
        }
        $seeds = [];
        foreach ((array) $this->settings['openrouter']['seed_models'] as $id) {
            if (is_string($id) && self::validId($id)) {
                $seeds[] = [
                    'id' => $id, 'name' => $id, 'context_length' => 32000, 'max_completion_tokens' => null,
                    'input_modalities' => ['text'], 'supported_parameters' => [],
                    'intelligence' => null, 'coding' => null, 'free_verified' => false, 'source' => 'seed',
                ];
            }
        }
        return $seeds;
    }

    public function state(): array
    {
        return $this->store->read(self::FILE, []);
    }

    public function keyInfo(): ?array
    {
        return $this->state()['key_info'] ?? null;
    }

    public function isDue(): bool
    {
        $cat = $this->state();
        $now = ($this->now)();
        $r   = $this->settings['routing'];
        if (!empty($cat['last_error']) && ($cat['last_attempt_at'] ?? 0) > $now - $r['catalog_failure_backoff_seconds']) {
            return false; // recent failure: back off instead of hammering the API
        }
        return ($cat['fetched_at'] ?? 0) < $now - $r['catalog_refresh_seconds'];
    }

    public function refreshIfDue(): bool
    {
        return $this->isDue() ? $this->refresh() : false;
    }

    public function refresh(): bool
    {
        $lock = $this->store->tryLock('catalog_refresh');
        if (!$lock) {
            return false; // another process is refreshing
        }
        try {
            $now  = ($this->now)();
            $prev = $this->state();

            $sorted = false;
            $list   = null;
            $sort   = $this->settings['openrouter']['intelligence_sort'];
            if ($sort) {
                $list = $this->fetchJson('models?sort=' . rawurlencode($sort));
                $sorted = is_array($list);
            }
            if (!is_array($list)) {
                $list = $this->fetchJson('models');
            }
            if (!is_array($list) || !isset($list['data']) || !is_array($list['data']) || !$list['data']) {
                $this->markFailure('models endpoint unavailable or malformed');
                return false;
            }

            $bench = $this->settings['openrouter']['use_benchmarks'] ? $this->fetchBenchmarks() : null;
            if ($bench === null) {
                $bench = $prev['benchmarks'] ?? [];
            }

            $models = self::buildCandidates($list['data'], $bench, $sorted, $this->settings, $now);
            if (!$models && !empty($prev['models'])) {
                $this->markFailure('discovery returned no eligible free models; kept last known-good list');
                return false;
            }

            $keyInfo = $this->fetchJson('key');
            $keyInfo = is_array($keyInfo) && isset($keyInfo['data']) ? self::safeKeyInfo($keyInfo['data'], $now) : ($prev['key_info'] ?? null);

            $this->store->write(self::FILE, [
                'models'          => $models,
                'fetched_at'      => $now,
                'last_attempt_at' => $now,
                'last_error'      => null,
                'sorted_by_intelligence' => $sorted,
                'benchmarks'      => $bench,
                'total_listed'    => count($list['data']),
                'key_info'        => $keyInfo,
            ]);
            if ($this->health) {
                $this->health->revalidate(array_column($models, 'id'));
            }
            return true;
        } finally {
            $this->store->release($lock);
        }
    }

    /**
     * Filters genuinely free text models and ranks them. Pure function (unit-tested).
     */
    public static function buildCandidates(array $data, array $bench, bool $sorted, array $settings, float $now): array
    {
        $excluded = (array) $settings['routing']['excluded_model_patterns'];
        $out = [];
        $position = 0;
        foreach ($data as $m) {
            $position++;
            if (!is_array($m) || !isset($m['id']) || !is_string($m['id']) || !self::validId($m['id'])) {
                continue;
            }
            $id = $m['id'];
            foreach ($excluded as $p) {
                if (fnmatch($p, $id)) {
                    continue 2;
                }
            }
            if (!self::isGenuinelyFree($m)) {
                continue;
            }
            $outMods = $m['architecture']['output_modalities'] ?? ['text'];
            if (!in_array('text', (array) $outMods, true)) {
                continue;
            }
            $ctx = (int) ($m['context_length'] ?? ($m['top_provider']['context_length'] ?? 0));
            if ($ctx < self::MIN_CONTEXT) {
                continue;
            }
            if (!empty($m['expiration_date']) && ($exp = strtotime((string) $m['expiration_date'])) !== false && $exp < $now + 86400) {
                continue; // retiring within a day
            }

            [$intel, $coding] = self::scoresFor($m, $bench);
            $out[] = [
                'id'                    => $id,
                'name'                  => (string) ($m['name'] ?? $id),
                'canonical_slug'        => $m['canonical_slug'] ?? null,
                'context_length'        => $ctx,
                'max_completion_tokens' => isset($m['top_provider']['max_completion_tokens']) ? (int) $m['top_provider']['max_completion_tokens'] : null,
                'input_modalities'      => array_values((array) ($m['architecture']['input_modalities'] ?? ['text'])),
                'supported_parameters'  => array_values((array) ($m['supported_parameters'] ?? [])),
                'intelligence'          => $intel,
                'coding'                => $coding,
                'sort_position'         => $sorted ? $position : null,
                'free_verified'         => true,
                'source'                => 'discovery',
            ];
        }

        // Missing scores are estimated (median * factor), never treated as zero.
        $known = array_values(array_filter(array_column($out, 'intelligence'), fn ($v) => $v !== null));
        $factor = (float) $settings['routing']['unknown_intelligence_factor'];
        $fallback = $known ? self::median($known) * $factor : null;
        foreach ($out as &$o) {
            $o['quality_estimated'] = $o['intelligence'] === null;
            if ($o['intelligence'] === null && $fallback === null && $o['sort_position'] !== null) {
                // No benchmark data at all: fall back to OpenRouter's intelligence sort order.
                $o['rank_quality'] = 100.0 / (1 + $o['sort_position'] / 25);
            } else {
                $o['rank_quality'] = $o['intelligence'] ?? $fallback ?? 50.0;
            }
        }
        unset($o);

        usort($out, function ($a, $b) {
            $qa = max($a['rank_quality'], $a['coding'] ?? 0);
            $qb = max($b['rank_quality'], $b['coding'] ?? 0);
            return [$qb, $b['context_length'], $a['id']] <=> [$qa, $a['context_length'], $b['id']];
        });
        return array_slice($out, 0, max(1, (int) $settings['routing']['max_candidates']));
    }

    /** Free only if every published price component is exactly zero (never by name alone). */
    public static function isGenuinelyFree(array $m): bool
    {
        $p = $m['pricing'] ?? null;
        if (!is_array($p) || !isset($p['prompt'], $p['completion'])) {
            return false;
        }
        foreach ($p as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (!is_numeric($v) || (float) $v != 0.0) {
                return false; // includes -1 ("variable", e.g. routers)
            }
        }
        return true;
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('~^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._:-]*$~i', $id) && strlen($id) <= 120;
    }

    /** @return array{0:?float,1:?float} */
    private static function scoresFor(array $m, array $bench): array
    {
        $intel  = self::findNumber($m, 'intelligence_index');
        $coding = self::findNumber($m, 'coding_index');
        foreach (self::keysFor($m) as $k) {
            if (isset($bench[$k])) {
                $intel  ??= $bench[$k]['intelligence'] ?? null;
                $coding ??= $bench[$k]['coding'] ?? null;
                break;
            }
        }
        return [$intel, $coding];
    }

    private static function keysFor(array $m): array
    {
        $keys = [];
        foreach ([$m['id'] ?? null, $m['canonical_slug'] ?? null] as $k) {
            if (!is_string($k)) {
                continue;
            }
            $k = strtolower(preg_replace('~:[a-z0-9-]+$~i', '', $k));
            $keys[] = $k;
            $keys[] = self::stripDate($k);
        }
        return array_values(array_unique($keys));
    }

    private static function stripDate(string $slug): string
    {
        return preg_replace('~-(\d{8}|\d{4}-\d{2}-\d{2}|\d{4})$~', '', $slug);
    }

    private static function findNumber($node, string $name, int $depth = 0): ?float
    {
        if (!is_array($node) || $depth > 4) {
            return null;
        }
        if (isset($node[$name]) && is_numeric($node[$name])) {
            return (float) $node[$name];
        }
        foreach ($node as $k => $v) {
            if (is_array($v) && $k !== 'pricing') {
                $r = self::findNumber($v, $name, $depth + 1);
                if ($r !== null) {
                    return $r;
                }
            }
        }
        return null;
    }

    /** Artificial Analysis indices from GET /benchmarks, keyed by normalised model slug. */
    private function fetchBenchmarks(): ?array
    {
        $res = $this->fetchJson('benchmarks?source=artificial-analysis');
        if (!is_array($res) || !isset($res['data']) || !is_array($res['data'])) {
            return null;
        }
        $map = [];
        foreach ($res['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = $row['model_permaslug'] ?? $row['permaslug'] ?? $row['model_id'] ?? $row['slug'] ?? $row['model'] ?? null;
            if (!is_string($slug)) {
                continue;
            }
            $intel  = self::findNumber($row, 'intelligence_index');
            $coding = self::findNumber($row, 'coding_index');
            if ($intel === null && $coding === null) {
                continue;
            }
            $k = strtolower(preg_replace('~:[a-z0-9-]+$~i', '', $slug));
            foreach ([$k, self::stripDate($k)] as $key) {
                $map[$key]['intelligence'] = $map[$key]['intelligence'] ?? $intel;
                $map[$key]['coding']       = $map[$key]['coding'] ?? $coding;
            }
        }
        return $map ?: null;
    }

    private function fetchJson(string $path): ?array
    {
        $cfg = $this->settings['openrouter'];
        $headers = ['Accept' => 'application/json'];
        if ($cfg['api_key'] !== '') {
            $headers['Authorization'] = 'Bearer ' . $cfg['api_key'];
        }
        try {
            $res = $this->http->request('GET', rtrim($cfg['base_url'], '/') . '/' . $path, [
                'headers'         => $headers,
                'timeout'         => $cfg['discovery_timeout'],
                'connect_timeout' => $cfg['connect_timeout'],
                'http_errors'     => false,
            ]);
            if ($res->getStatusCode() !== 200) {
                return null;
            }
            $json = json_decode((string) $res->getBody(), true);
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function markFailure(string $reason): void
    {
        $now = ($this->now)();
        $this->store->update(self::FILE, function (&$d) use ($reason, $now) {
            $d['last_attempt_at'] = $now;
            $d['last_error'] = $reason;
        });
    }

    /** Keeps only non-sensitive account fields from GET /key. */
    private static function safeKeyInfo(array $d, float $now): array
    {
        return [
            'is_free_tier'    => isset($d['is_free_tier']) ? (bool) $d['is_free_tier'] : null,
            'limit'           => $d['limit'] ?? null,
            'limit_remaining' => $d['limit_remaining'] ?? null,
            'usage'           => $d['usage'] ?? null,
            'usage_daily'     => $d['usage_daily'] ?? null,
            'fetched_at'      => $now,
        ];
    }

    private static function median(array $v): float
    {
        sort($v);
        $n = count($v);
        return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }
}
