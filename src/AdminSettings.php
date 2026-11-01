<?php

namespace Bot;

/**
 * What the dashboard may change. Saved to storage/admin_settings.json and applied over config.php
 * by Settings::fromConfig. API keys and passwords stay in config.php and never pass through here.
 */
final class AdminSettings
{
    public const FILE = 'admin_settings.json';
    private const RESERVED_PLAN_KEYS = ['smart', 'economic', 'pro']; // old packages, still payable
    private const PROVIDERS = ['metis', 'gapgpt'];
    private const MODES = ['paid_only', 'free_first', 'quality_first', 'cost_min'];

    /** The current values, in the form the dashboard edits and sends back. */
    public static function current(array $s): array
    {
        return [
            'plans' => $s['plans'], 'free_limits' => $s['free_limits'], 'pro_limits' => $s['pro_limits'],
            'media' => $s['media'], 'tiers' => $s['tiers'], 'routing_mode' => $s['routing']['mode'],
            'usd_toman' => $s['usd_toman'], 'prompts' => $s['prompts'], 'features' => $s['features'], 'default_tier' => $s['default_tier'], 'pro_tier' => $s['pro_tier'],
        ];
    }

    /** @throws \InvalidArgumentException with a message for the admin */
    public static function sanitize(array $in): array
    {
        $tiers = [];
        foreach ((array) ($in['tiers'] ?? []) as $key => $t) {
            self::key((string) $key, 'tier');
            $tiers[$key] = [
                'label'         => self::text($t['label'] ?? $key, 40),
                'paid_models'   => self::models($t['paid_models'] ?? []),
                'gapgpt_models' => self::models($t['gapgpt_models'] ?? []),
                'free_models'   => self::models($t['free_models'] ?? []),
            ];
            if (!$tiers[$key]['paid_models'] && !$tiers[$key]['gapgpt_models']) {
                throw new \InvalidArgumentException("Tier {$key} needs at least one paid model.");
            }
        }
        if (!$tiers) {
            throw new \InvalidArgumentException('At least one tier is required.');
        }

        $plans = [];
        foreach ((array) ($in['plans'] ?? []) as $key => $p) {
            self::key((string) $key, 'plan');
            if (in_array($key, self::RESERVED_PLAN_KEYS, true)) {
                throw new \InvalidArgumentException("Plan key {$key} is reserved for old packages.");
            }
            $plans[$key] = self::limitSet($p, array_keys($tiers)) + [
                'title'       => self::text($p['title'] ?? $key, 60),
                'price_toman' => self::int($p['price_toman'] ?? 0, 1000, 1000000000, 'price'),
                'days'        => self::int($p['days'] ?? 30, 1, 3650, 'days'),
                'enabled'     => !empty($p['enabled']),
            ];
        }

        $media = (array) ($in['media'] ?? []);
        $order = [];
        $models = [];
        foreach (['text', 'voice', 'image', 'vision'] as $action) {
            $order[$action] = array_values(array_intersect((array) ($media['order'][$action] ?? []), self::PROVIDERS));
            if (!$order[$action]) {
                throw new \InvalidArgumentException("Choose at least one provider for {$action}.");
            }
            if ($action !== 'text') {
                foreach (self::PROVIDERS as $p) {
                    $models[$action][$p] = self::model($media['models'][$action][$p] ?? '');
                }
            }
        }

        $mode = (string) ($in['routing_mode'] ?? 'paid_only');
        $default = (string) ($in['default_tier'] ?? '');
        $pro = (string) ($in['pro_tier'] ?? '');
        if (!in_array($mode, self::MODES, true) || !isset($tiers[$default], $tiers[$pro])) {
            throw new \InvalidArgumentException('Invalid routing mode or default tier.');
        }

        return [
            'plans'       => $plans,
            'free_limits' => self::limitSet((array) ($in['free_limits'] ?? []), array_keys($tiers)),
            'pro_limits'  => self::limitSet((array) ($in['pro_limits'] ?? []), array_keys($tiers)),
            'tiers'       => $tiers,
            'default_tier' => $default,
            'pro_tier'    => $pro,
            'routing'     => ['mode' => $mode],
            'usd_toman'   => self::int($in['usd_toman'] ?? 270510, 1, 100000000, 'dollar rate'),
            'features'    => ['secret_pro_code' => !empty($in['features']['secret_pro_code'])],
            'prompts'     => [
                'chat'    => self::text($in['prompts']['chat'] ?? '', 20000),
                'summary' => self::text($in['prompts']['summary'] ?? '', 20000),
                'vision'  => self::text($in['prompts']['vision'] ?? '', 20000),
            ],
            'media'       => [
                'images_enabled' => !empty($media['images_enabled']),
                'vision_enabled' => !empty($media['vision_enabled']),
                'voice_enabled'  => !empty($media['voice_enabled']),
                'order'  => $order,
                'models' => $models,
                'image'  => [
                    'size'    => self::choice($media['image']['size'] ?? '1024x1024', ['1024x1024', '1024x1536', '1536x1024', 'auto', '']),
                    'quality' => self::choice($media['image']['quality'] ?? 'low', ['low', 'medium', 'high', 'auto', '']),
                ],
            ],
        ];
    }

    public static function save(JsonStore $store, array $overlay): bool
    {
        return $store->write(self::FILE, $overlay);
    }

    private static function limitSet(array $p, array $tierKeys): array
    {
        return [
            'chat_per_day'          => self::int($p['chat_per_day'] ?? 0, -1, 1000000, 'chat limit'),
            'chat_per_month'        => self::int($p['chat_per_month'] ?? 0, 0, 10000000, 'chat limit'),
            'voice_minutes_per_day' => self::int($p['voice_minutes_per_day'] ?? 0, 0, 1440, 'voice limit'),
            'image_per_day'         => self::int($p['image_per_day'] ?? 0, 0, 100000, 'image limit'),
            'image_per_month'       => self::int($p['image_per_month'] ?? 0, 0, 1000000, 'image limit'),
            'image_per_week'        => self::int($p['image_per_week'] ?? 0, 0, 100000, 'image limit'),
            'voice_per_week'        => self::int($p['voice_per_week'] ?? 0, 0, 100000, 'voice limit'),
            'vision_per_day'        => self::int($p['vision_per_day'] ?? 0, 0, 100000, 'vision limit'),
            'tiers'                 => array_values(array_intersect((array) ($p['tiers'] ?? []), $tierKeys)),
        ];
    }

    private static function key(string $key, string $what): void
    {
        // Plan keys travel inside invoice payloads, which only accept lowercase letters.
        if (!preg_match('/^[a-z]{1,20}$/', $key)) {
            throw new \InvalidArgumentException("The {$what} key \"{$key}\" must be lowercase English letters only.");
        }
    }

    private static function int($v, int $min, int $max, string $what): int
    {
        if (!is_numeric($v) || (int) $v < $min || (int) $v > $max) {
            throw new \InvalidArgumentException("Invalid {$what}: must be between {$min} and {$max}.");
        }
        return (int) $v;
    }

    private static function text($v, int $max): string
    {
        $v = trim((string) $v);
        if ($v === '' || mb_strlen($v) > $max) {
            throw new \InvalidArgumentException("Text must be 1 to {$max} characters.");
        }
        return $v;
    }

    private static function model($v): string
    {
        $v = trim((string) $v);
        if ($v !== '' && !preg_match('~^[A-Za-z0-9._:/-]{1,120}$~', $v)) {
            throw new \InvalidArgumentException("Invalid model name: {$v}");
        }
        return $v;
    }

    private static function models($v): array
    {
        $list = is_array($v) ? $v : preg_split('/[\s,]+/', (string) $v);
        return array_values(array_unique(array_filter(array_map([self::class, 'model'], $list), fn ($m) => $m !== '')));
    }

    private static function choice($v, array $allowed): string
    {
        return in_array((string) $v, $allowed, true) ? (string) $v : (string) $allowed[0];
    }
}
