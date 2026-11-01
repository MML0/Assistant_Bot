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
        $remain_pro = getProTimeRemainingStr($chatId);

        sendTelegramMessage($chatId,
            "شما نمی‌توانید از لینک ارجاع خود استفاده کنید.\nاما به هر حال خوش آمدید! 😊\n\n$remain_pro"
        );
        return true;
    }

    // ---------------------------
    // NEW USER REFERRAL REWARDING
    // ---------------------------
    // ONLY reward if this is a new user (not in DB yet)
    if (!$user && $referrer) {

        // Find referrer's DB record
        $stmt = $db->prepare("SELECT id FROM " . T_USERS . " WHERE chat_id = ?");
        $stmt->execute([$referrer]);
        $refUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($refUser) {

            $refUserId = $refUser['id'];
            $expireAt = (new DateTime('+6 hours'))->format('Y-m-d H:i:s');

            makeUserPro($refUserId, null, $expireAt);
            $remain_pro = getProTimeRemainingStr($referrer);
            // Tell referrer
            sendTelegramMessage( $referrer, "🎉 کسی با لینک شما عضو شد!\n به اشتراک PRO شما افزوده شد! 🚀\n\n$remain_pro" );
            $logText = "pro: Chat ID: {$refUserId} ";
            sendTelegramMessage($adminChatId, $logText);
        }
    }

    // ---- Greeting the new user ----
    if ($referrer) {
        sendMainKeyboard($chatId,
        "👋 خوش آمدید! معرف شناسایی شد.\n\nبرای دیدن دستورات و تغییر مدل ها /help را وارد کنید.\n\nهر سوالی داری بپرس، من پاسخ می‌دهم!  \n\nفقط پیامت رو ارسال کن! "
        );
    } else {
        sendMainKeyboard($chatId,
            "👋 خوش آمدید! من دستیار هوش مصنوعی شما هستم.\nبرای دیدن دستورات و تغییر مدل ها /help را وارد کنید.\n\nهر سوالی داری بپرس، من پاسخ می‌دهم!  \n\nفقط پیامت رو ارسال کن! "
        );
    }

    return true;
}

// ----- /help -----
if ($lower === '/help') {
    $remain_pro = getProTimeRemainingStr($chatId);
    sendMainKeyboard($chatId,
        "📌 دستورات:\n\n" .
        "/start –  شروع ربات 🚀\n" .
        "/help –  اطلاعات و راهنما ℹ️\n" .
        "/newchat –  پاک کردن حافظه چت 🧹\n" .
        "/setmodel –  انتخاب مدل هوش مصنوعی 🤖\n" .
        "/usage –  مشاهده مصرف امروز 📊\n" .
        "/image –  ساخت تصویر با هوش مصنوعی 🖼\n" .
        "/getpro –  دریافت رایگان امکانات PRO 🎁\n" .
        "/buypro –  پلن‌های اشتراک و خرید 💳\n" .
        "🎤 ارسال پیام صوتی –  تبدیل صدا به متن (ویژه پلن‌های پولی)\n" .
        "👁 ارسال عکس –  تحلیل تصویر؛ سوالتان را زیر عکس بنویسید (ویژه پلن‌های پولی)\n\n$remain_pro"
    );
    sendButtons($chatId, "یکی از گزینه‌ها را انتخاب کنید:", Bot\Plans::menuButtons());
    return true;
}

// ----- /image [description] -----
if (preg_match('~^/image(?:\s+(.+))?$~su', trim(str_replace("@$botUsername", "", $userText)), $m)) {
    global $update;
    handleImageRequest($chatId, $update['message']['from']['id'], trim($m[1] ?? ''));
    return true;
}

// ----- /usage -----
if ($lower === '/usage') {
    global $isPro;
    $settings = botServices()->settings;

    $stmt = $db->prepare("SELECT COUNT(*) FROM " . T_MESSAGES . " WHERE user_id = ? AND DATE(created_at) = CURDATE() AND type = 'USER'");
    $stmt->execute([$userId]);
    $usedToday = (int) $stmt->fetchColumn();

    // Voice line only for users who have voice-to-text; a failed lookup just hides it.
    $used = ['chat' => $usedToday];
    $stmt = $db->prepare("SELECT COUNT(*) FROM " . T_MESSAGES . " WHERE user_id = ? AND created_at >= ? AND type = 'USER'");
    $stmt->execute([$userId, date('Y-m-01 00:00:00')]);
    $used['chat_month'] = (int) $stmt->fetchColumn();
    try {
        $minutes = voiceMinutesFor($chatId);
        if ($minutes > 0) {
            $used['voice_limit'] = $minutes;
            $stmt = $db->prepare("SELECT COALESCE(SUM(duration_seconds), 0) FROM " . botServices()->settings['table_prefix'] . "voice_usage WHERE telegram_user_id = ? AND DATE(created_at) = CURDATE() AND status IN ('ok', 'pending', 'timeout')");
            $stmt->execute([$chatId]);
            $used['voice_seconds'] = (int) $stmt->fetchColumn();
        }
        $media = botMedia();
        $used['image_day'] = $media->used((int) $chatId, 'image', date('Y-m-d 00:00:00'));
        $used['image_month'] = $media->used((int) $chatId, 'image', date('Y-m-01 00:00:00'));
        $weekAgo = date('Y-m-d H:i:s', time() - 7 * 86400);
        $used['image_week'] = $media->used((int) $chatId, 'image', $weekAgo);
        $stmt = $db->prepare("SELECT COUNT(*) FROM " . $settings['table_prefix'] . "voice_usage WHERE telegram_user_id = ? AND created_at >= ? AND status IN ('ok', 'pending', 'timeout')");
        $stmt->execute([$chatId, $weekAgo]);
        $used['voice_week'] = (int) $stmt->fetchColumn();
        $used['vision'] = $media->used((int) $chatId, 'vision', date('Y-m-d 00:00:00'));
    } catch (\Throwable $e) {
        // A failed lookup only leaves those lines at zero.
    }

    $text = Bot\Plans::usageText($settings, (bool) $isPro, $user['plan'] ?? null, $used, getProTimeRemainingStr($chatId));
    sendButtons($chatId, $text, [[['text' => '💎 پلن‌های اشتراک', 'callback_data' => 'cmd_buypro']]], "Markdown");
    return true;
}

// ----- /setmodel -----
if ($lower === '/setmodel') {

    // Three tiers instead of GPT model names; the backend maps each tier to real models.
    global $isPro;
    $settings = botServices()->settings;
    $tiers = $settings['tiers'];
    // Plan holders see the tiers of their plan; everyone else sees all of them as an offer.
    $plan = Bot\Plans::active($settings, (bool) $isPro, $user['plan'] ?? null);
    $row = [];
    foreach ($tiers as $key => $tier) {
        if ($plan !== null && !Bot\Plans::tierAllowed($settings, true, $user['plan'], $key)) continue;
        $row[] = ['text' => $tier['label'], 'callback_data' => 'setmodel_' . $key];
    }
    $buttons = [$row];

    $remain_pro = getProTimeRemainingStr($chatId);
    $current = tierLabel(getUserTier($chatId));
    $text = "🤖 *مدل فعلی شما:* $current\n\n"
        . "⚡ عادی: سریع، برای گفتگوهای روزمره\n"
        . "🧠 هوشمند: پاسخ‌های دقیق‌تر\n"
        . "🚀 فوق هوشمند: قوی‌ترین مدل برای سوال‌های سخت، برنامه‌نویسی و تحلیل\n\n"
        . "مدل خود را انتخاب کنید:\n\n(تنها کاربران PRO می توانند مدل ها را تغییر دهند)\n\n$remain_pro";
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
        INSERT INTO " . T_MESSAGES . " (user_id, chat_memory_id, message, type)
        VALUES (?, ?, '', 'SUMMARY')
    ");
    $stmt->execute([$userId, $memoryOwnerId]);

    if ($isGroup) {
        sendTelegramMessage($chatId, "🧹 حافظه چت گروه پاک شد. برای همه تازه شروع می‌کنیم!");
    } else {
        sendTelegramMessage($chatId, "✅ تاریخچه چت شما پاک شد. می‌توانید تازه شروع کنید!");
    }

    return true;
}


// ----- /getpro -----
if ($lower === '/getpro') {
    global $config, $update ,$isGroup;
    // Real user ID (works in both private & group)
    $userChatId = $update['message']['from']['id'];

    $botUsername = $config['telegram']['bot_username']; // e.g. MyCoolBot
    $refLink     = "https://ble.ir/{$botUsername}?start=ref{$userChatId}";
    $text =
        "🎁 *PRO دعوتی*\n".
        "• " . (int) botServices()->settings['pro_limits']['chat_per_day'] . " پیام در روز\n".
        "• حافظه بلندمدت\n".
        "• " . (int) botServices()->settings['pro_limits']['image_per_week'] . " تصویر در هفته\n".
        "• " . (int) botServices()->settings['pro_limits']['voice_per_week'] . " تبدیل صدا به متن در هفته\n\n".
        "✨ *دعوت و کسب PRO*\n".
        "لینک یا بنر شخصی خود را به اشتراک بگذارید. هر دوستی که بپیوندد  به اشتراک *PRO* شما اضافه می‌شود:\n\n".
        "*{$refLink}*\n\n".
        "🛒 می‌خواهید PRO بخرید؟ /buypro";
    sendTelegramMessage($chatId, $text, "Markdown");
    sendTelegramMessage($chatId, "بنر با لینک شخصی شما برای ارسال 👇", "Markdown");
    $text = "
    • ربات هوشمند گفتگو
    • انتخاب مدل‌های هوشمند و فوق هوشمند\n\n".
    // "• قابلیت دریافت پیام صوتی و پاسخگویی به زودی
    // • توانایی خلق تصویر با هوش مصنوعی به زودی\n\n".
    "[@{$botUsername}]({$refLink}) \n\n".
    "همین حالا شروع کنید و از امکانات پیشرفته بهره‌مند شوید!
    ";

    $buttons = [
        [
            ['text' => 'شروع', 'url' => $refLink],
        ],
    ];

    sendButtons($chatId, $text, $buttons, "Markdown");

    return true;
}
// ----- /buypro -----
if ($lower === '/buypro') {
    global $config, $update ,$isGroup;
    // Real user ID (works in both private & group)
    $userChatId = $update['message']['from']['id'];

    global $isPro;
    $settings = botServices()->settings;
    $buttons = Bot\Plans::buyButtons($settings);
    $text = Bot\Plans::listText($settings, (bool) $isPro, $user['plan'] ?? null);
    sendButtons($userChatId, $text, $buttons, "Markdown");

    return true;
}
// ----- /premiumstatus [chat_id] -----
if (preg_match('~^/premiumstatus(?:\s+(\d+))?$~', $lower, $m)) {
    global $update;
    $target = (int) $update['message']['from']['id'];
    if (!empty($m[1])) {
        if (!isTrustedAdmin()) {
            return true; // only the admin may look up other users
        }
        $target = (int) $m[1];
    }
    try {
        $expiry = botServices()->entitlements($db)->activeExpiry($target);
    } catch (\Throwable $e) {
        sendTelegramMessage($chatId, "وضعیت اشتراک صوتی فعلاً قابل بررسی نیست.", '');
        return true;
    }
    sendTelegramMessage($chatId, $expiry
        ? "🎤 تبدیل صدا به متن برای شما تا {$expiry} فعال است."
        : "🎤 تبدیل صدا به متن فقط برای خریداران PRO فعال است. برای خرید: /buypro", '');
    return true;
}

// ----- admin: /grantpremium <chat_id> <days> [receipt] -----
// For payments made outside the bot (e.g. card-to-card), after you have checked the payment.
if (preg_match('~^/grantpremium\s+(\d+)\s+(\d+)(?:\s+(\S{1,100}))?$~', $lower, $m)) {
    if (!isTrustedAdmin()) {
        return false; // behave like a normal message for everyone else
    }
    global $update;
    $days = (int) $m[2];
    if ($days < 1 || $days > 3650) {
        sendTelegramMessage($chatId, "تعداد روز باید بین 1 تا 3650 باشد.", '');
        return true;
    }
    try {
        $expiry = botServices()->entitlements($db)->grant((int) $m[1], $days, (int) $update['message']['from']['id'], $m[3] ?? null);
    } catch (\Throwable $e) {
        sendTelegramMessage($chatId, "❌ ثبت ناموفق بود. آیا migrate.php را اجرا کرده‌اید؟", '');
        return true;
    }
    makeUserPro(null, (int) $m[1], $expiry); // paid premium also includes the normal PRO benefits
    sendTelegramMessage($chatId, "✅ اشتراک پرداختی برای {$m[1]} تا {$expiry} فعال شد (PRO + تبدیل صدا به متن).", '');
    sendTelegramMessage((int) $m[1], "🎉 اشتراک PRO شما فعال شد!\n🎤 تبدیل پیام صوتی به متن هم برای شما فعال است.\n\n" . getProTimeRemainingStr((int) $m[1]));
    return true;
}

// ----- admin: /revokepremium <chat_id> -----
if (preg_match('~^/revokepremium\s+(\d+)$~', $lower, $m)) {
    if (!isTrustedAdmin()) {
        return false;
    }
    try {
        $n = botServices()->entitlements($db)->revoke((int) $m[1]);
    } catch (\Throwable $e) {
        sendTelegramMessage($chatId, "❌ لغو ناموفق بود. آیا migrate.php را اجرا کرده‌اید؟", '');
        return true;
    }
    sendTelegramMessage($chatId, "✅ {$n} اشتراک صوتی برای {$m[1]} لغو شد.", '');
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

// The admin is identified by the sender's id in a private chat, never by anything the user can type.
function isTrustedAdmin(): bool {
    global $update, $config;
    $from = $update['message']['from']['id'] ?? null;
    $type = $update['message']['chat']['type'] ?? '';
    return $from !== null && $type === 'private'
        && (string) $from === (string) $config['telegram']['admin_chatid'];
}
