<?php

namespace Bot;

/**
 * Merges config.php with safe defaults for the routing / transcription layer.
 *
 * Existing config.php files keep working: the old `gpt.free_api_key`, `gpt.free_base_url`
 * and `gpt.free_model` keys are used as fallbacks for the OpenRouter section.
 * Secrets can also come from environment variables (preferred).
 */
final class Settings
{
    public static function defaults(): array
    {
        return [
            'storage_dir' => dirname(__DIR__) . '/storage',

            'openrouter' => [
                'api_key'          => '',
                'base_url'         => 'https://openrouter.ai/api/v1',
                'app_url'          => '',          // optional HTTP-Referer for OpenRouter attribution
                'app_title'        => '',          // optional X-Title
                'seed_models'      => [],          // used only until the first successful discovery
                'connect_timeout'  => 4,
                'attempt_timeout'  => 20,          // seconds for one free-model attempt
                'max_tokens'       => 1500,
                // reasoning effort per task type, only sent to models that list `reasoning`
                'reasoning_effort' => ['ordinary' => 'low', 'coding' => 'medium', 'hard' => 'medium'],
                'provider_preferences' => null,    // e.g. ['data_collection' => 'deny'] (fewer free models)
                'usage_accounting' => true,        // ask OpenRouter to report usage/cost
                'discovery_timeout' => 10,
                'use_benchmarks'   => true,        // GET /benchmarks (Artificial Analysis indices)
                'intelligence_sort' => 'intelligence-high-to-low', // tried first, falls back to unsorted
            ],

            'routing' => [
                'mode'                    => 'free_first', // free_first | quality_first | cost_min | paid_only
                'max_free_attempts'       => 3,
                'total_deadline_seconds'  => 40,
                'paid_reserve_seconds'    => 12,    // time kept back for a paid fallback
                'pro_model_choice_is_explicit_paid' => false,
                'catalog_refresh_seconds' => 21600,
                'catalog_failure_backoff_seconds' => 900,
                'max_candidates'          => 15,
                'reserved_model_patterns' => [],    // fnmatch patterns saved for hard tasks
                'excluded_model_patterns' => ['openrouter/*'],
                'model_boosts'            => [],    // pattern => score delta (e.g. Persian-strong models)
                'unknown_intelligence_factor' => 0.9, // missing benchmark => median * factor (never 0)
                'account_free_rpm'        => 20,    // OpenRouter documented free-model limit
                'account_free_daily_limit' => null, // null => documented 50 / 1000 based on /key is_free_tier
                'scarce_rate_limit_ratio' => 0.3,
                'weights' => [
                    'ordinary' => ['quality' => 1.0, 'reliability' => 0.45, 'rate_limit' => 0.6, 'latency' => 0.25],
                    'coding'   => ['quality' => 1.0, 'reliability' => 0.35, 'rate_limit' => 0.4, 'latency' => 0.10],
                    'hard'     => ['quality' => 1.0, 'reliability' => 0.30, 'rate_limit' => 0.3, 'latency' => 0.05],
                ],
                'paid_on_refusal'         => false,
            ],

            'paid' => [
                'fallback_enabled'      => true,
                'default_model'         => 'gpt-4.1-mini',
                'max_requests_per_day'  => 300,
                'max_cost_per_day_usd'  => 2.0,
                'max_output_tokens'     => 700,
                'max_attempts'          => 3,
                'attempt_timeout'       => 20,
                'connect_timeout'       => 5,
                // Estimated USD per 1M tokens (MetisAI does not report cost); used for budgeting only.
                'price_per_million' => [
                    'default'      => ['input' => 2.0,  'output' => 8.0],
                    'gpt-4.1-mini' => ['input' => 0.4,  'output' => 1.6],
                    'gpt-4.1-nano' => ['input' => 0.1,  'output' => 0.4],
                    'gpt-4o-mini'  => ['input' => 0.15, 'output' => 0.6],
                    'gpt-4o'       => ['input' => 2.5,  'output' => 10.0],
                    'gpt-4.1'      => ['input' => 2.0,  'output' => 8.0],
                    'gpt-4'        => ['input' => 30.0, 'output' => 60.0],
                ],
            ],

            'transcription' => [
                'enabled'                => true,
                'base_url'               => 'https://api.gapgpt.app/v1',
                'api_key'                => '',
                'model'                  => 'gapgpt/whisper-1',
                'language'               => null,   // e.g. 'fa'; null = provider auto-detect
                'max_duration_seconds'   => 300,
                'max_file_bytes'         => 10 * 1024 * 1024,
                'allowed_mime_types'     => ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/wav', 'audio/x-wav', 'audio/webm'],
                'max_requests_per_hour'  => 20,
                'max_minutes_per_day'    => 30,
                'max_concurrent_global'  => 3,
                'duplicate_window_seconds' => 600,
                'timeout'                => 60,
                'cost_per_minute_usd'    => 0.006,  // estimate for monitoring, billed per started minute
                'allow_in_groups'        => false,
                'purchase_contact'       => '@mml_0',
            ],
        ];
    }

    public static function fromConfig(array $config): array
    {
        $s = self::mergeDeep(self::defaults(), array_intersect_key($config, self::defaults()));

        $gpt = $config['gpt'] ?? [];
        $s['gpt'] = $gpt; // existing paid API (api_key, base_url) stays where it was
        $s['telegram_api_base'] = $config['telegram']['api_base'] ?? 'https://api.telegram.org';
        if ($s['openrouter']['api_key'] === '' && !empty($gpt['free_api_key'])) {
            $s['openrouter']['api_key'] = $gpt['free_api_key'];
        }
        if (!isset($config['openrouter']['base_url']) && !empty($gpt['free_base_url'])) {
            $s['openrouter']['base_url'] = $gpt['free_base_url'];
        }
        if (!$s['openrouter']['seed_models'] && !empty($gpt['free_model'])) {
            $s['openrouter']['seed_models'] = [$gpt['free_model']];
        }

        // Environment variables win over config.php for secrets and the main switches.
        $env = [
            'OPENROUTER_API_KEY'     => ['openrouter', 'api_key'],
            'TRANSCRIPTION_API_KEY'  => ['transcription', 'api_key'],
            'TRANSCRIPTION_BASE_URL' => ['transcription', 'base_url'],
            'TRANSCRIPTION_MODEL'    => ['transcription', 'model'],
            'BOT_ROUTING_MODE'       => ['routing', 'mode'],
            'BOT_STORAGE_DIR'        => ['storage_dir'],
        ];
        foreach ($env as $name => $path) {
            $v = getenv($name);
            if ($v !== false && $v !== '') {
                self::setPath($s, $path, $v);
            }
        }
        $flag = getenv('BOT_PAID_FALLBACK_ENABLED');
        if ($flag !== false && $flag !== '') {
            $s['paid']['fallback_enabled'] = filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        }

        if (!in_array($s['routing']['mode'], ['free_first', 'quality_first', 'cost_min', 'paid_only'], true)) {
            $s['routing']['mode'] = 'free_first';
        }
        return $s;
    }

    /** Arrays with string keys merge recursively; lists and scalars are replaced. */
    public static function mergeDeep(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($base[$k]) && !array_is_list($v)) {
                $base[$k] = self::mergeDeep($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private static function setPath(array &$arr, array $path, $value): void
    {
        $ref = &$arr;
        foreach ($path as $p) {
            $ref = &$ref[$p];
        }
        $ref = $value;
    }
}
