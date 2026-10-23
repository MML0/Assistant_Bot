<?php

// Send inline keyboard (glass buttons)
function sendButtons($chatId, $text, $buttons, $parseMode = null) {
    global $config;

    $token = $config['telegram']['bot_token'];
    // $url   = "https://api.telegram.org/bot{$token}/sendMessage";
    $url   = "https://tapi.bale.ai/bot{$token}/sendMessage";

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

// Add handling for buypro buttons to acknowledge selection
function handleBuyProCallback($callback) {
    global $config;
    $chatId = $callback['message']['chat']['id'];
    $data = $callback['data'];
    $userId = $callback['from']['id'];

    if (str_starts_with($data, 'buypro_')) {
        $package = substr($data, strlen('buypro_'));

        // Acknowledge the button press
        answerCallback($callback['id'], "You selected the $package package.");

        // Define package details
        $packages = [
            'smart' => [
                'title' => 'بسته هوشمندانه ۴۴ روزه',
                'description' => 'دسترسی ۴۴ روزه به امکانات PRO',
                'amount' => 2380000,
            ],
            'economic' => [
                'title' => 'بسته اقتصادی',
                'description' => 'دسترسی ۲۰ روزه به امکانات PRO',
                'amount' => 1190000,
            ],
            'pro' => [
                'title' => 'بسته حرفه‌ای',
                'description' => 'دسترسی ۶۰ روزه به امکانات PRO',
                'amount' => 3900000,
            ],
        ];

        if (!isset($packages[$package])) {
            sendTelegramMessage($chatId, "❌ بسته انتخابی نامعتبر است.");
            return true;
        }

        $pkg = $packages[$package];

        // Prepare invoice data
        // $provider_token = 'WALLET-TEST-1111111111111111'; // Replace with real token in production
        $provider_token = 'WALLET-CHANGE-ME'; // Replace with real token in production
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
            'prices' => json_encode($prices),
        ];

        // Send invoice via Bale API
        $token = $config['telegram']['bot_token'];
        $url = "https://tapi.bale.ai/bot{$token}/sendInvoice";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

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
        $stmt = $db->prepare("SELECT is_pro, pro_expire FROM users WHERE chat_id = ?");
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

        // User is PRO and not expired → update model
        $model = substr($data, strlen('setmodel_')); // remove "setmodel_"

        $stmt = $db->prepare("UPDATE users SET model = ? WHERE chat_id = ?");
        $stmt->execute([$model, $chatId]);

        answerCallback($callback['id'], "مدل تنظیم شد به: $model ✔");
        sendTelegramMessage($chatId, "✅ مدل شما به $model به‌روزرسانی شد");
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
    $url   = "https://tapi.bale.ai/bot{$token}/answerCallbackQuery";

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
