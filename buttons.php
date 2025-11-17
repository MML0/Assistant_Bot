<?php

// Send inline keyboard (glass buttons)
function sendButtons($chatId, $text, $buttons) {
    global $config;

    $token = $config['telegram']['bot_token'];
    $url   = "https://api.telegram.org/bot{$token}/sendMessage";

    $payload = [
        'chat_id' => $chatId,
        'text'    => $text,
        'reply_markup' => json_encode([
            'inline_keyboard' => $buttons
        ])
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}


// Handle callback query
function handleCallback($update) {
    if (!isset($update['callback_query'])) {
        return false;
    }

    global $db;

    $callback = $update['callback_query'];
    $chatId   = $callback['message']['chat']['id'];
    $data     = $callback['data']; // e.g. "setmodel_gpt-4.1-mini"

    // Only handle model buttons
    if (str_starts_with($data, 'setmodel_')) {

        // Check if user is PRO
        $stmt = $db->prepare("SELECT is_pro FROM users WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['is_pro']) {
            // Not PRO → deny
            answerCallback($callback['id'], "Model change is PRO only.");
            sendTelegramMessage(
                $chatId,
                "🚫 Only PRO users can change the model.\nUse /getpro to upgrade."
            );
            return true;
        }

        // User is PRO → update model
        $model = substr($data, strlen('setmodel_')); // remove "setmodel_"

        $stmt = $db->prepare("UPDATE users SET model = ? WHERE chat_id = ?");
        $stmt->execute([$model, $chatId]);

        answerCallback($callback['id'], "Model set to: $model ✔");
        sendTelegramMessage($chatId, "✅ Your model has been updated to $model");
        return true;
    }

    return false; // no callback handled
}



// Respond to Telegram so the loading animation stops
function answerCallback($callbackId, $text = "") {
    global $config;

    $token = $config['telegram']['bot_token'];
    $url   = "https://api.telegram.org/bot{$token}/answerCallbackQuery";

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
