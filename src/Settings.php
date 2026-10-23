<?php

namespace Bot;

/**
 * Merges config.php with safe defaults for the routing / transcription layer.
 *
 * The existing config.php keeps working unchanged: `gpt.free_api_key` and
 * `gpt.free_base_url` feed the OpenRouter section. Everything else is optional.
 * Secrets can also come from environment variables.
 */
final class Settings
{
    public static function defaults(): array
    {
        return [
            'storage_dir' => dirname(__DIR__) . '/storage',
            'table_prefix' => 'v2_',

            // Three tiers shown to users. Free models are tried in this order (validated
            // against OpenRouter's live list once discovery has run), then the tier's paid model.
            'tiers' => [
                'normal' => [
                    'label' => '⚡ عادی',
                    'free_models' => [
                        'nvidia/nemotron-3.5-lightning:free',
                        'nvidia/nemotron-3-super-120b-a12b:free',
                        'poolside/laguna-s-2.1:free',
                    ],
                    'paid_model' => 'gpt-4.1-nano',
                ],
                'smart' => [
                    'label' => '🧠 هوشمند',
                    'free_models' => [
                        'nvidia/nemotron-3-super-120b-a12b:free',
                        'thinkingmachines/inkling:free',
                        'nvidia/nemotron-3.5-super-vl:free',
                        'poolside/laguna-s-2.1:free',
                    ],
                    'paid_model' => 'gpt-4.1-mini',
                ],
                'extra' => [
                    'label' => '🚀 فوق هوشمند',
                    'free_models' => [
                        'nvidia/nemotron-3-ultra-550b-a55b:free',
                        'thinkingmachines/inkling:free',
                        'dots-studio/dots-3-note-preview:free',
                        'nvidia/nemotron-3-super-120b-a12b:free',
                    ],
                    'paid_model' => 'gpt-4.1',
                ],
            ],
            // Free models of these tiers are tried next if a tier's own free models all fail.
            'tier_fallback' => ['extra' => ['smart', 'normal'], 'smart' => ['normal'], 'normal' => []],
            'default_tier' => 'normal',   // free users always use this tier
            'pro_tier'     => 'smart',    // set when a user buys PRO (if they were on normal)

            'openrouter' => [
                'api_key'          => '',
                'base_url'         => 'https://openrouter.ai/api/v1',
                'app_url'          => '',
                'app_title'        => '',
                'seed_models'      => [],
                'connect_timeout'  => 4,
                'attempt_timeout'  => 20,
                'max_tokens'       => 1500,
                'reasoning_effort' => ['ordinary' => 'low', 'coding' => 'medium', 'hard' => 'medium'],
                'provider_preferences' => null,
                'usage_accounting' => true,
                'discovery_timeout' => 10,
                'use_benchmarks'   => true,
                'intelligence_sort' => 'intelligence-high-to-low',
            ],

            'routing' => [
                'mode'                    => 'free_first', // free_first | quality_first | cost_min | paid_only
                'max_free_attempts'       => 3,
                'total_deadline_seconds'  => 45,
                'paid_reserve_seconds'    => 15,
                'use_discovered_fallback' => false, // also try other discovered free models after the tier list
                'catalog_refresh_seconds' => 21600,
                'catalog_failure_backoff_seconds' => 900,
                'max_candidates'          => 200,
                'reserved_model_patterns' => [],
                'excluded_model_patterns' => ['openrouter/*'],
                'model_boosts'            => [],
                'unknown_intelligence_factor' => 0.9,
                'account_free_rpm'        => 20,
                'account_free_daily_limit' => null,
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
                'default_model'         => 'gpt-4.1-nano',
                'max_requests_per_day'  => 500,
                'max_cost_per_day_usd'  => 3.0,
                'max_output_tokens'     => 1000,
                'max_attempts'          => 2,
                'attempt_timeout'       => 25,
                'connect_timeout'       => 5,
                // Estimated USD per 1M tokens (MetisAI does not report cost); used for the budget only.
                'price_per_million' => [
                    'default'      => ['input' => 2.0,  'output' => 8.0],
                    'gpt-4.1-nano' => ['input' => 0.1,  'output' => 0.4],
                    'gpt-4.1-mini' => ['input' => 0.4,  'output' => 1.6],
                    'gpt-4o-mini'  => ['input' => 0.15, 'output' => 0.6],
                    'gpt-4.1'      => ['input' => 2.0,  'output' => 8.0],
                    'gpt-4o'       => ['input' => 2.5,  'output' => 10.0],
                ],
            ],

            'messages' => [
                'unavailable' => '⚠️ GPT پاسخ نداد. لطفاً دوباره تلاش کنید.',
                'budget'      => '⚠️ سقف استفاده امروز ربات پر شده است. لطفاً کمی بعد دوباره تلاش کنید.',
                'refusal'     => '⚠️ متأسفم، نمی‌توانم به این درخواست پاسخ بدهم.',
            ],

            'transcription' => [
                'enabled'                => true,
                'base_url'               => 'https://api.gapgpt.app/v1',
                'api_key'                => '',
                'model'                  => 'gapgpt/whisper-1',
                'language'               => null,
                'max_duration_seconds'   => 300,
                'max_file_bytes'         => 10 * 1024 * 1024,
                'allowed_mime_types'     => ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/webm'],
                'max_requests_per_hour'  => 20,
                'max_minutes_per_day'    => 30,
                'max_concurrent_global'  => 3,
                'duplicate_window_seconds' => 600,
                'timeout'                => 60,
                'cost_per_minute_usd'    => 0.006,
                'allow_in_groups'        => false,
                // false = only users with a verified PAID purchase (or admin grant); true = any active PRO
                'allow_referral_pro'     => false,
            ],
        ];
    }

    public static function fromConfig(array $config): array
    {
        $s = self::mergeDeep(self::defaults(), array_intersect_key($config, self::defaults()));

        $gpt = $config['gpt'] ?? [];
        $s['gpt'] = $gpt; // existing paid API (api_key, base_url) stays where it was
        $s['telegram_api_base'] = $config['telegram']['api_base'] ?? 'https://tapi.bale.ai';
        if ($s['openrouter']['api_key'] === '' && !empty($gpt['free_api_key'])) {
            $s['openrouter']['api_key'] = $gpt['free_api_key'];
        }
        if (!isset($config['openrouter']['base_url']) && !empty($gpt['free_base_url'])) {
            $s['openrouter']['base_url'] = $gpt['free_base_url'];
        }
        if (isset($config['database']['table_prefix'])) {
            $s['table_prefix'] = (string) $config['database']['table_prefix'];
        }

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
        if (!preg_match('~^[A-Za-z0-9_]{0,20}$~', $s['table_prefix'])) {
            $s['table_prefix'] = 'v2_';
        }
        if (!isset($s['tiers'][$s['default_tier']])) {
            $s['default_tier'] = array_key_first($s['tiers']);
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
