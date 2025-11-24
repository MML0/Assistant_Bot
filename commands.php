<?php

function handleCommand($chatId, $userText) {
    global $db, $user, $config ;

    $adminChatId = $config['telegram']['admin_chatid'];
 
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
            $expireAt = (new DateTime('+3 days'))->format('Y-m-d H:i:s');

            makeUserPro($refUserId, null, $expireAt);

            // Tell referrer
            sendTelegramMessage(
                $referrer,
                "🎉 Someone joined using your link!\nYou earned 3 days of PRO! 🚀"
            );
        }
    }

    // ---- Greeting the new user ----
    if ($referrer) {
        sendTelegramMessage($chatId,
            "👋 Welcome! Referral detected.\n\nType /help to see commands.\n\nAsk anything and I will reply to you! "
        );
    } else {
        sendTelegramMessage($chatId,
            "👋 Welcome! I'm your AI assistant.\nType /help to see commands.\n\nAsk anything and I will reply to you! "
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

    if( $chatId == $adminChatId){
        $buttons = [
            [
                ['text' => '🌐 gpt-4',        'callback_data' => 'setmodel_gpt-4'],
                ['text' => '🤖 gpt-4o',       'callback_data' => 'setmodel_gpt-4o'],
            ],
            [
                ['text' => '⚡ gpt-4o-mini',  'callback_data' => 'setmodel_gpt-4o-mini'],
                ['text' => '🚀 gpt-4.1',      'callback_data' => 'setmodel_gpt-4.1'],
            ],
            [
                ['text' => '⚡ gpt-4.1-mini', 'callback_data' => 'setmodel_gpt-4.1-mini'],
                ['text' => '🧩 gpt-4.1-nano', 'callback_data' => 'setmodel_gpt-4.1-nano'],
            ],
            [
                ['text' => '🛸 gpt-5',        'callback_data' => 'setmodel_gpt-5'],
                ['text' => '✨ gpt-5-mini',   'callback_data' => 'setmodel_gpt-5-mini'],
            ],
            [
                ['text' => '📦 gpt-5-nano',   'callback_data' => 'setmodel_gpt-5-nano'],
            ],
        ];
        
    }else{
        $buttons = [
            [
                ['text' => '🌐 gpt-4',        'callback_data' => 'setmodel_gpt-4'],
                ['text' => '🤖 gpt-4o',       'callback_data' => 'setmodel_gpt-4o'],
            ],
            [
                ['text' => '⚡ gpt-4o-mini',  'callback_data' => 'setmodel_gpt-4o-mini'],
                ['text' => '🚀 gpt-4.1',      'callback_data' => 'setmodel_gpt-4.1'],
            ],
            [
                ['text' => '⚡ gpt-4.1-mini', 'callback_data' => 'setmodel_gpt-4.1-mini'],
                ['text' => '🧩 gpt-4.1-nano', 'callback_data' => 'setmodel_gpt-4.1-nano'],
            ],
            [
                ['text' => '🛸 gpt-5',        'callback_data' => 'setmodel_gpt-4o'],
                ['text' => '✨ gpt-5-mini',   'callback_data' => 'setmodel_gpt-5-mini'],
            ],
            [
                ['text' => '📦 gpt-5-nano',   'callback_data' => 'setmodel_gpt-5-nano'],
            ],
        ];
    }
    sendButtons($chatId, "Choose your model: (Only PRO users can switch models)", $buttons);
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
        "• Models: 4.1, 4o, 5, 5.1\n\n".
        "✨ Share this personal invite link with your friends. For each friend who starts the bot with it, you get 3 days of PRO:\n\n".
        $refLink
    );
    return true;
}


    return false; // no command matched
}
