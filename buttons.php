<?php

// Send inline keyboard (glass buttons)
function sendButtons($chatId, $text, $buttons, $parseMode = null) {
    global $config;

    $token = $config['telegram']['bot_token'];
    // $url   = "https://api.telegram.org/bot{$token}/sendMessage";
    $url   = telegramApiBase() . "/bot{$token}/sendMessage";

    $payload = [
        'chat_id' => $chatId,
        'text'    => $text,
        'reply_markup' => json_encode([
            'inline_keyboard' => $buttons
        ])
    ];

    // Only add parse_mode if provided
    if ($parseMode) {
        $payload['parse_mode'] = $parseMode;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// PRO packages (one place for titles, prices in rial and days).
function buyProPackages(): array {
    return [
        'smart' => [
            'title' => 'بسته هوشمندانه ۴۴ روزه',
            'description' => 'دسترسی ۴۴ روزه به امکانات PRO',
            'amount' => 2380000,
            'days' => 44, // 30 + 14
        ],
        'economic' => [
            'title' => 'بسته اقتصادی',
            'description' => 'دسترسی ۲۰ روزه به امکانات PRO',
            'amount' => 1190000,
            'days' => 20,
        ],
        'pro' => [
            'title' => 'بسته حرفه‌ای',
            'description' => 'دسترسی ۶۰ روزه به امکانات PRO',
            'amount' => 3900000,
            'days' => 60,
        ],
    ];
}

// Add handling for buypro buttons to acknowledge selection
function handleBuyProCallback($callback) {
    global $config;
    $chatId = $callback['message']['chat']['id'];
    $data = $callback['data'];
    $userId = $callback['from']['id'];

    if (str_starts_with($data, 'buypro_')) {
        $package = substr($data, strlen('buypro_'));

        // Acknowledge the button press
        answerCallback($callback['id'], "بسته انتخاب شد ✔");

        // Define package details
        $packages = buyProPackages();

        if (!isset($packages[$package])) {
            sendTelegramMessage($chatId, "❌ بسته انتخابی نامعتبر است.");
            return true;
        }

        $pkg = $packages[$package];

        // Prepare invoice data (wallet token lives in config.php → 'payment' => ['provider_token' => ...])
        $provider_token = $config['payment']['provider_token'] ?? '';
        if ($provider_token === '' || $provider_token === 'WALLET-CHANGE-ME') {
            sendTelegramMessage($chatId, "❌ پرداخت فعلاً در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید: @suport_0");
            sendTelegramMessage($config['telegram']['admin_chatid'], "⚠️ پرداخت غیرفعال است: در config.php این خط را اضافه کنید:\n'payment' => ['provider_token' => 'WALLET-...'],", '');
            return true;
        }
        $prices = [
            [
                'label' => $pkg['title'],
                'amount' => $pkg['amount'],
            ]
        ];
        $payload = [
            'chat_id' => $chatId,
            'title' => $pkg['title'],
            'description' => $pkg['description'],
            'payload' => 'buypro_' . $package . '_' . $userId . '_' . time(),
            'provider_token' => $provider_token,
            'prices' => $prices,
        ];

        // Send invoice via Bale API
        $result = baleCall('sendInvoice', $payload, 10);
        if (empty($result['ok'])) {
            sendTelegramMessage($chatId, "❌ ارسال فاکتور ناموفق بود. لطفاً کمی بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید: @suport_0");
            sendTelegramMessage($config['telegram']['admin_chatid'], "⚠️ sendInvoice ناموفق بود:\n" . Bot\Redactor::redact(json_encode($result, JSON_UNESCAPED_UNICODE), 300), '');
            return true;
        }

        // Optionally, inform user
        sendTelegramMessage($chatId, "🧾 فاکتور پرداخت برای بسته {$pkg['title']} ارسال شد. لطفاً پرداخت را تکمیل کنید.");

        return true;
    }

    return false;
}

// Handle callback query
function handleCallback($update) {
    if (!isset($update['callback_query'])) {
        return false;
    }

    global $db;

    $callback = $update['callback_query'];
    $chatId   = $callback['message']['chat']['id'];
    $data     = $callback['data']; // e.g. "setmodel_gpt-4.1-nano"

    // Only handle model buttons
    if (str_starts_with($data, 'setmodel_')) {

        // Check if user is PRO and not expired
        $stmt = $db->prepare("SELECT is_pro, pro_expire FROM " . T_USERS . " WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $isPro = $row && $row['is_pro'];
        $proExpire = $row && $row['pro_expire'] ? strtotime($row['pro_expire']) : null;
        $now = time();

        if (!$isPro || ($proExpire && $proExpire < $now)) {
            // Not PRO or expired → deny
            answerCallback($callback['id'], "تغییر مدل فقط برای کاربران PRO فعال هست.");
            sendTelegramMessage(
                $chatId,
                "🚫 فقط کاربران PRO فعال می‌تونن مدل رو تغییر بدن.\n"
                ."برای ارتقا از /getpro (رایگان با معرفی به دیگران)\nیا /buypro (خرید) استفاده کنید."
            );
            return true;
        }

        // User is PRO and not expired → update model tier (only known tiers are accepted)
        $model = substr($data, strlen('setmodel_')); // remove "setmodel_"
        if (!isset(botServices()->settings['tiers'][$model])) {
            answerCallback($callback['id'], "مدل نامعتبر است.");
            return true;
        }

        $stmt = $db->prepare("UPDATE " . T_USERS . " SET model = ? WHERE chat_id = ?");
        $stmt->execute([$model, $chatId]);

        $label = tierLabel($model);
        answerCallback($callback['id'], "مدل تنظیم شد به: $label ✔");
        sendTelegramMessage($chatId, "✅ مدل شما به $label به‌روزرسانی شد");
        return true;
    }

    // Handle buypro buttons
    if (str_starts_with($data, 'buypro_')) {
        return handleBuyProCallback($callback);
    }
    return false; // no callback handled
}



// Respond to Telegram so the loading animation stops
function answerCallback($callbackId, $text = "") {
    global $config;

    $token = $config['telegram']['bot_token'];
    // $url   = "https://api.telegram.org/bot{$token}/answerCallbackQuery";
    $url   = telegramApiBase() . "/bot{$token}/answerCallbackQuery";

    $payload = [
        'callback_query_id' => $callbackId,
        'text'              => $text,
        'show_alert'        => false
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true
    ]);
    curl_exec($ch);
    curl_close($ch);
}
