<?php

function handleCommand($chatId, $userText) {
    global $db, $user, $config, $userId;

    $adminChatId = $config['telegram']['admin_chatid'];
 
    $botUsername = $config['telegram']['bot_username']; 
    $lower = strtolower(trim(str_replace("@$botUsername", "", $userText)));

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
            $expireAt = (new DateTime('+1 days'))->format('Y-m-d H:i:s');

            makeUserPro($refUserId, null, $expireAt);

            // Tell referrer
            sendTelegramMessage(
                $referrer,
                "🎉 Someone joined using your link!\nYou earned 1 days of PRO! 🚀"
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
                ['text' => '🛸 gpt-5',        'callback_data' => 'setmodel_gpt-5'],
                ['text' => '✨ gpt-5-mini',   'callback_data' => 'setmodel_gpt-5-mini'],
            ],
            [
                ['text' => '📦 gpt-5-nano',   'callback_data' => 'setmodel_gpt-5-nano'],
            ],
        ];
    }
    $stmt = $db->prepare("SELECT model FROM users WHERE chat_id = ?");
    $stmt->execute([$chatId]);
    $userModel = $stmt->fetchColumn();
    $text = "🤖 *Your current model:* `$userModel`\n\nChoose your model:\n\n(Only PRO users can switch models)";
    sendButtons($chatId, $text, $buttons, "Markdown");
    return true;
}

// ----- /newchat -----
if ($lower === '/newchat') {
    global $isGroup, $update;

    // Decide memory owner (same logic used earlier)
    if ($isGroup) {
        $memoryOwnerId = $update['message']['chat']['id'];   // group memory
    } else {
        $memoryOwnerId = $update['message']['from']['id'];   // private memory
    }

    $userId = $user['id'];

    // Insert empty summary marker → wipes previous context
    $stmt = $db->prepare("
        INSERT INTO messages (user_id, chat_memory_id, message, type)
        VALUES (?, ?, '', 'SUMMARY')
    ");
    $stmt->execute([$userId, $memoryOwnerId]);

    if ($isGroup) {
        sendTelegramMessage($chatId, "🧹 Group chat memory cleared. Starting fresh for everyone!");
    } else {
        sendTelegramMessage($chatId, "✅ Your chat history has been cleared. You can start fresh!");
    }

    return true;
}


// ----- /getpro -----
if ($lower === '/getpro') {
    global $config, $update ,$isGroup;
    // Real user ID (works in both private & group)
    $userChatId = $update['message']['from']['id'];

    $botUsername = $config['telegram']['bot_username']; // e.g. MyCoolBot
    $refLink     = "https://t.me/{$botUsername}?start=ref{$userChatId}";

    $text =
        "💎 *PRO Benefits*\n".
        "• Unlimited messages\n".
        "• Long-term memory\n".
        "• Access to advanced models (4.1, 4o, 5 series)\n\n".
        "✨ *Invite & Earn PRO*\n".
        "Share your personal link. Each friend who joins gives you *3 days PRO*:\n\n".
        "`{$refLink}`\n\n".
        "🛒 Want to buy PRO? Contact @mml\_0";
    sendTelegramMessage($chatId, $text, "Markdown");

    return true;
}

// ----- /premiumstatus [telegram_user_id] -----
if (preg_match('~^/premiumstatus(?:\s+(\d+))?$~', $lower, $m)) {
    global $update;
    $senderId = (int) $update['message']['from']['id'];
    $target   = $senderId;
    if (!empty($m[1])) {
        if (!isTrustedAdmin()) {
            return true; // only the admin may look up other users
        }
        $target = (int) $m[1];
    }
    try {
        $expiry = (new Bot\Entitlements($db))->activeExpiry($target);
    } catch (\Throwable $e) {
        sendTelegramMessage($chatId, "Premium status is unavailable right now.", '');
        return true;
    }
    sendTelegramMessage($chatId, $expiry
        ? "💎 Paid premium (voice-to-text) active until {$expiry}."
        : "No active paid premium. Voice-to-text requires paid premium.", '');
    return true;
}

// ----- admin: /grantpremium <telegram_user_id> <days> [payment_ref] -----
// Use only after verifying the payment yourself; this is the ONLY way premium is granted.
if (preg_match('~^/grantpremium\s+(\d+)\s+(\d+)(?:\s+(\S{1,100}))?$~', $lower, $m)) {
    if (!isTrustedAdmin()) {
        return false; // behave like an unknown message for everyone else
    }
    $days = (int) $m[2];
    if ($days < 1 || $days > 3650) {
        sendTelegramMessage($chatId, "Days must be between 1 and 3650.", '');
        return true;
    }
    global $update;
    try {
        $expiry = (new Bot\Entitlements($db))->grant((int) $m[1], $days, (int) $update['message']['from']['id'], $m[3] ?? null);
    } catch (\Throwable $e) {
        sendTelegramMessage($chatId, "❌ Grant failed. Did you run migrate_upgrade.php?", '');
        return true;
    }
    makeUserPro(null, (int) $m[1], $expiry); // paid premium also includes the normal PRO benefits
    sendTelegramMessage($chatId, "✅ Paid premium for {$m[1]} active until {$expiry}.", '');
    return true;
}

// ----- admin: /revokepremium <telegram_user_id> -----
if (preg_match('~^/revokepremium\s+(\d+)$~', $lower, $m)) {
    if (!isTrustedAdmin()) {
        return false;
    }
    try {
        $n = (new Bot\Entitlements($db))->revoke((int) $m[1]);
    } catch (\Throwable $e) {
        sendTelegramMessage($chatId, "❌ Revoke failed. Did you run migrate_upgrade.php?", '');
        return true;
    }
    sendTelegramMessage($chatId, "✅ Cancelled {$n} premium entitlement(s) for {$m[1]}.", '');
    return true;
}

// ----- admin: /routingstats [days] -----
if (preg_match('~^/routingstats(?:\s+(\d{1,2}))?$~', $lower, $m)) {
    if (!isTrustedAdmin()) {
        return false;
    }
    sendTelegramMessage($chatId, botServices()->statsText(max(1, (int) ($m[1] ?? 1))), '');
    return true;
}
    return false; // no command matched
}

// The admin is identified by the sender's Telegram id in a private chat, never by chat
// membership, group admin rights or anything the user can type.
function isTrustedAdmin(): bool {
    global $update, $config;
    $from = $update['message']['from']['id'] ?? null;
    $type = $update['message']['chat']['type'] ?? '';
    return $from !== null && $type === 'private'
        && (string) $from === (string) $config['telegram']['admin_chatid'];
}
