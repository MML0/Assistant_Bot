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

    $callback = $update['callback_query'];
    $chatId   = $callback['message']['chat']['id'];
    $data     = $callback['data']; // the value of the pressed button
    $model = str_replace('setmodel_', '', $data);

    sendTelegramMessage($chatId, "✅ Your model has been updated to ".$model);
    // Example: model selection
    if (str_starts_with($data, 'setmodel_')) {
        $model = substr($data, strlen('setmodel_'));

        global $db;

        // Save into DB
        $stmt = $db->prepare("UPDATE users SET model = ? WHERE chat_id = ?");
        $stmt->execute([$model, $chatId]);

        answerCallback($callback['id'], "Model set to: $model ✔");

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
