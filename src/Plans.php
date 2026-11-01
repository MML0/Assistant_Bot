<?php

namespace Bot;

/** Subscription plans, per-action limits, button-to-command mapping and the Persian texts that show them. */
final class Plans
{
    /** Reply-keyboard button text => command. */
    public const KEYBOARD = [
        '💎 پلن‌های اشتراک' => '/buypro',
        '📊 مصرف من'       => '/usage',
        '🖼 ساخت تصویر'    => '/image',
        '🤖 انتخاب مدل'    => '/setmodel',
        '🧹 گفتگوی جدید'   => '/newchat',
        '🎁 PRO رایگان'    => '/getpro',
        'ℹ️ راهنما'        => '/help',
    ];

    /** Commands that inline buttons may run, as callback data "cmd_<name>". */
    private const CALLBACK_COMMANDS = ['buypro', 'usage', 'image', 'setmodel', 'newchat', 'getpro', 'help'];

    public static function commandForButton(string $text): ?string
    {
        return self::KEYBOARD[trim($text)] ?? null;
    }

    public static function commandForCallback(string $data): ?string
    {
        if (!str_starts_with($data, 'cmd_')) {
            return null;
        }
        $name = substr($data, 4);
        return in_array($name, self::CALLBACK_COMMANDS, true) ? '/' . $name : null;
    }

    public static function keyboardRows(): array
    {
        $rows = [];
        foreach (array_chunk(array_keys(self::KEYBOARD), 2) as $pair) {
            $rows[] = array_map(fn ($label) => ['text' => $label], $pair);
        }
        return $rows;
    }

    public static function menuButtons(): array
    {
        return [
            [['text' => '💎 پلن‌ها', 'callback_data' => 'cmd_buypro'], ['text' => '📊 مصرف من', 'callback_data' => 'cmd_usage']],
            [['text' => '🖼 ساخت تصویر', 'callback_data' => 'cmd_image'], ['text' => '🤖 انتخاب مدل', 'callback_data' => 'cmd_setmodel']],
            [['text' => '🧹 گفتگوی جدید', 'callback_data' => 'cmd_newchat'], ['text' => '🎁 PRO رایگان', 'callback_data' => 'cmd_getpro']],
        ];
    }

    /** The plan that applies right now, or null for free users and PRO users without a plan. */
    public static function active(array $settings, bool $isPro, ?string $planKey): ?array
    {
        return $isPro && $planKey !== null ? ($settings['plans'][$planKey] ?? null) : null;
    }

    /** Limits for this user: the plan's, or the free / PRO-without-plan (referral, older purchases) set. */
    public static function limits(array $settings, bool $isPro, ?string $planKey): array
    {
        $set = self::active($settings, $isPro, $planKey) ?? ($isPro ? $settings['pro_limits'] : $settings['free_limits']);
        return [
            'chat_per_day'          => (int) ($set['chat_per_day'] ?? 0),
            'chat_per_month'        => (int) ($set['chat_per_month'] ?? 0),
            'voice_minutes_per_day' => (int) ($set['voice_minutes_per_day'] ?? 0),
            'image_per_day'         => (int) ($set['image_per_day'] ?? 0),
            'image_per_month'       => (int) ($set['image_per_month'] ?? 0),
            'image_per_week'        => (int) ($set['image_per_week'] ?? 0),
            'voice_per_week'        => (int) ($set['voice_per_week'] ?? 0),
            'vision_per_day'        => (int) ($set['vision_per_day'] ?? 0),
            'tiers'                 => array_values((array) ($set['tiers'] ?? [])),
        ];
    }

    /** Messages allowed per day, or null for no limit (a negative value means unlimited). */
    public static function dailyChatLimit(array $settings, bool $isPro, ?string $planKey): ?int
    {
        $limit = self::limits($settings, $isPro, $planKey)['chat_per_day'];
        return $limit < 0 ? null : $limit;
    }

    public static function tierAllowed(array $settings, bool $isPro, ?string $planKey, string $tier): bool
    {
        return in_array($tier, self::limits($settings, $isPro, $planKey)['tiers'], true);
    }

    public static function currentLabel(array $settings, bool $isPro, ?string $planKey): string
    {
        if (!$isPro) {
            return 'سهمیه رایگان';
        }
        $plan = self::active($settings, $isPro, $planKey);
        return $plan ? $plan['title'] : 'PRO';
    }

    private static function sale(array $settings): array
    {
        return array_filter($settings['plans'], fn ($p) => $p['enabled'] ?? true);
    }

    public static function listText(array $settings, bool $isPro, ?string $planKey): string
    {
        $text = "💎 *پلن‌های اشتراک*\n\n";
        $text .= $isPro
            ? 'پلن فعلی شما: ' . self::currentLabel($settings, $isPro, $planKey) . "\n\n"
            : "در حال حاضر از سهمیه رایگان استفاده می‌کنید.\n\n";
        $media = $settings['media'];

        foreach (self::sale($settings) as $plan) {
            $price = number_format((int) $plan['price_toman']);
            $images = !empty($media['images_enabled']) && (int) $plan['image_per_day'] > 0;
            $vision = !empty($media['vision_enabled']) && (int) $plan['vision_per_day'] > 0;
            $voice  = !empty($media['voice_enabled']) && (int) $plan['voice_minutes_per_day'] > 0;
            $chat   = (int) $plan['chat_per_day'] < 0 ? 'نامحدود' : "{$plan['chat_per_day']} پیام در روز";
            $in  = array_merge(['متن'], $vision ? ['تصویر'] : [], $voice ? ['صدا'] : []);
            $out = array_merge(['متن'], $images ? ['تصویر'] : []);

            $text .= "🔹 *{$plan['title']}* — {$price} تومان\n";
            $text .= "💬 چت: {$chat}" . ((int) ($plan['chat_per_month'] ?? 0) > 0 ? " / {$plan['chat_per_month']} پیام در ماه" : '') . "\n";
            $text .= '🖼 ساخت تصویر: ' . ($images ? "{$plan['image_per_day']} در روز / {$plan['image_per_month']} در ماه" : 'در این پلن فعال نیست') . "\n";
            $text .= '👁 تحلیل رسانه: ' . ($vision ? "{$plan['vision_per_day']} تصویر در روز" : 'در این پلن فعال نیست') . "\n";
            $text .= '🎤 تبدیل صدا به متن: ' . ($voice ? "{$plan['voice_minutes_per_day']} دقیقه در روز" : 'در این پلن فعال نیست') . "\n";
            $text .= '📥 ورودی: ' . implode('، ', $in) . '  |  📤 خروجی: ' . implode('، ', $out) . "\n";
            $text .= "⏱ اعتبار: {$plan['days']} روز\n";
            $text .= "💰 مبلغ: {$price} تومان\n\n";
        }
        return $text . "برای خرید، یکی از دکمه‌های زیر را انتخاب کنید.\n\nپشتیبانی: @suport_0";
    }

    public static function buyButtons(array $settings): array
    {
        $rows = [];
        foreach (self::sale($settings) as $key => $plan) {
            $rows[] = [['text' => "🔹 {$plan['title']} | " . number_format((int) $plan['price_toman']) . ' تومان', 'callback_data' => 'buypro_' . $key]];
        }
        return $rows;
    }

    /** Invoice data for the payment flow. Bale amounts are in rial. */
    public static function packages(array $settings): array
    {
        $out = [];
        foreach ($settings['plans'] as $key => $plan) {
            $out[$key] = [
                'title'       => $plan['title'],
                'description' => "دسترسی {$plan['days']} روزه به {$plan['title']}",
                'amount'      => (int) $plan['price_toman'] * 10,
                'days'        => (int) $plan['days'],
            ];
        }
        return $out;
    }

    /** @param array $used keys: chat, voice_seconds, image_day, image_month, vision */
    public static function usageText(array $settings, bool $isPro, ?string $planKey, array $used, string $remaining): string
    {
        $l = self::limits($settings, $isPro, $planKey);
        $media = $settings['media'];
        $text = "📊 *مصرف شما*\n\n";
        $text .= '💎 پلن: ' . self::currentLabel($settings, $isPro, $planKey) . "\n";
        $text .= '💬 چت امروز: ' . (int) ($used['chat'] ?? 0) . ($l['chat_per_day'] < 0 ? ' پیام (بدون محدودیت)' : " از {$l['chat_per_day']} پیام") . "\n";
        if ($l['chat_per_month'] > 0) {
            $text .= '🗓 چت این ماه: ' . (int) ($used['chat_month'] ?? 0) . " از {$l['chat_per_month']} پیام\n";
        }
        if (!empty($media['voice_enabled']) && ($l['voice_minutes_per_day'] > 0 || isset($used['voice_limit']))) {
            $max = $l['voice_minutes_per_day'] ?: (int) $used['voice_limit'];
            $text .= $l['voice_per_week'] > 0
                ? '🎤 تبدیل صدا این هفته: ' . (int) ($used['voice_week'] ?? 0) . " از {$l['voice_per_week']} پیام\n"
                : '🎤 تبدیل صدا امروز: ' . (int) ceil(($used['voice_seconds'] ?? 0) / 60) . " از {$max} دقیقه\n";
        }
        if (!empty($media['images_enabled']) && $l['image_per_day'] > 0) {
            $text .= $l['image_per_week'] > 0
                ? '🖼 ساخت تصویر این هفته: ' . (int) ($used['image_week'] ?? 0) . " از {$l['image_per_week']}\n"
                : '🖼 ساخت تصویر امروز: ' . (int) ($used['image_day'] ?? 0) . " از {$l['image_per_day']} (این ماه " . (int) ($used['image_month'] ?? 0) . " از {$l['image_per_month']})\n";
        }
        if (!empty($media['vision_enabled']) && $l['vision_per_day'] > 0) {
            $text .= '👁 تحلیل تصویر امروز: ' . (int) ($used['vision'] ?? 0) . " از {$l['vision_per_day']}\n";
        }
        return $text . "\n" . $remaining;
    }
}
