<?php

function handleCommand($chatId, $userText) {
    global $db, $user, $config; // FIXED — add globals
    $lower = strtolower(trim($userText));

// ----- /start -----
if (str_starts_with($lower, '/start')) {

    $referrer = null;

    // Detect "?start=ref12345"
    if (preg_match('/ref([0-9]+)/', $lower, $m)) {
        $referrer = (int)$m[1];
    }

    // Prevent self-referral
    if ($referrer == $chatId) {
        sendTelegramMessage($chatId,
            "⚠️ You cannot use your own referral link.\nBut welcome anyway! 😊"
        );
        return true;
    }

    // ---------------------------
    // NEW USER REFERRAL REWARDING
    // ---------------------------
    // ONLY reward if this is a new user (not in DB yet)
    if (!$user && $referrer) {

        // Find referrer's DB record
        $stmt = $db->prepare("SELECT id FROM users WHERE chat_id = ?");
        $stmt->execute([$referrer]);
        $refUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($refUser) {

            $refUserId = $refUser['id'];
            $expireAt = (new DateTime('+7 days'))->format('Y-m-d H:i:s');

            makeUserPro($refUserId, null, $expireAt);

            // Tell referrer
            sendTelegramMessage(
                $referrer,
                "🎉 Someone joined using your link!\nYou earned *7 days of PRO*! 🚀"
            );
        }
    }

    // ---- Greeting the new user ----
    if ($referrer) {
        sendTelegramMessage($chatId,
            "👋 Welcome! Referral detected.\nYou're now connected — enjoy chatting!"
        );
    } else {
        sendTelegramMessage($chatId,
            "👋 Welcome! I'm your AI assistant.\nType /help to see commands."
        );
    }

    return true;
}

    // ----- /help -----
    if ($lower === '/help') {
        sendTelegramMessage($chatId,
            "📌 *Commands*\n\n".
            "/start – Start the bot\n".
            "/help – Info & usage\n".
            "/setmodel – Choose AI model\n".
            "/getpro – Unlock PRO features"
        );
        return true;
    }

    // ----- /setmodel -----
    if ($lower === '/setmodel') {

        $buttons = [
            [
                ['text' => '⚡ 4.1-mini', 'callback_data' => 'setmodel_gpt-4.1-mini'],
                ['text' => '🤖 4o',        'callback_data' => 'setmodel_gpt-4o']
            ],
            [
                ['text' => '🚀 5',         'callback_data' => 'setmodel_gpt-5'],
                ['text' => '🧠 5.1',       'callback_data' => 'setmodel_gpt-5.1']
            ]
        ];

        sendButtons($chatId, "Choose your model: \n  only pro users", $buttons);
        return true;
    }


// ----- /getpro -----
if ($lower === '/getpro') {
    global $config;

    $botUsername = $config['telegram']['bot_username']; // e.g. "MyCoolBot"
    $refLink     = "https://t.me/{$botUsername}?start=ref" . $chatId;

    sendTelegramMessage($chatId,
        "💎 *PRO Benefits*\n".
        "• Unlimited messages\n".
        "• Long-term memory\n".
        "• Models: 4.1, 4o, 5, 5.1\n".
        "• Faster responses\n\n".
        "✨ Share this personal invite link with your friends. For each friend who starts the bot with it, you get *7 days of PRO*:\n".
        $refLink
    );
    return true;
}


    return false; // no command matched
}
