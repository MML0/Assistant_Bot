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
        sendTelegramMessage($chatId,
        "👋 خوش آمدید! معرف شناسایی شد.\n\nبرای دیدن دستورات و تغییر مدل ها /help را وارد کنید.\n\nهر سوالی داری بپرس، من پاسخ می‌دهم!  \n\nفقط پیامت رو ارسال کن! "
        );
    } else {
        sendTelegramMessage($chatId,
            "👋 خوش آمدید! من دستیار هوش مصنوعی شما هستم.\nبرای دیدن دستورات و تغییر مدل ها /help را وارد کنید.\n\nهر سوالی داری بپرس، من پاسخ می‌دهم!  \n\nفقط پیامت رو ارسال کن! "
        );
    }

    return true;
}

// ----- /help -----
if ($lower === '/help') {
    $remain_pro = getProTimeRemainingStr($chatId);
    sendTelegramMessage($chatId,
        "📌 دستورات:\n\n" .
        "/start –  شروع ربات 🚀\n" .
        "/help –  اطلاعات و راهنما ℹ️\n" .
        "/newchat –  پاک کردن حافظه چت 🧹\n" .
        "/setmodel –  انتخاب مدل هوش مصنوعی 🤖\n" .
        "/getpro –  دریافت *رایگان* امکانات PRO 🎁\n" .
        "/buypro –  خرید امکانات PRO 💳\n" .
        "🎤 ارسال پیام صوتی –  تبدیل صدا به متن (ویژه خریداران PRO)\n\n$remain_pro"
    );
    return true;
}

// ----- /setmodel -----
if ($lower === '/setmodel') {

    // Three tiers instead of GPT model names; the backend maps each tier to real models.
    $tiers = botServices()->settings['tiers'];
    $row = [];
    foreach ($tiers as $key => $tier) {
        $row[] = ['text' => $tier['label'], 'callback_data' => 'setmodel_' . $key];
    }
    $buttons = [$row];

    $remain_pro = getProTimeRemainingStr($chatId);
    $stmt = $db->prepare("SELECT model FROM " . T_USERS . " WHERE chat_id = ?");
    $stmt->execute([$chatId]);
    $userModel = $stmt->fetchColumn();
    $current = tierLabel(isset($tiers[$userModel]) ? $userModel : botServices()->settings['default_tier']);
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
        "💎 *مزایای PRO*\n".
        "• پیام‌های نامحدود\n".
        "• حافظه بلندمدت\n".
        "• دسترسی به مدل‌های هوشمند 🧠 و فوق هوشمند 🚀\n\n".
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

    $buttons = [
        [
            ['text' => 'بستهٔ هوشمندانه ✅| ۲۳۸ هزار تومان', 'callback_data' => 'buypro_smart'], // 2,380,000 rial
        ],
        [
            ['text' => 'بسته اقتصادی | ۱۱۹ هزار تومان', 'callback_data' => 'buypro_economic'], // 1,190,000
        ],
        [
            ['text' => 'بسته حرفه‌ای | ۳۹۰ هزار تومان', 'callback_data' => 'buypro_pro'], // 3,900,000
        ],
    ];
    $text = "🛒 لطفا بسته مورد نظر خود را انتخاب کنید:\n\n".
    "---      *بستهٔ هوشمندانه* ✅ 1 ماهه\n".
    // "\n".
    "🧠 پاسخ‌های با کیفیت و دقت بالاتر\n".
    // "💬 ارسال پیام و تصویر تا ۱۰ برابر بیشتر\n".
    // "📂 بارگذاری عکس و فایل تا ۱۰ برابر\n".
    // "\n".
    "⏱️ اعتبار: ۳۰ + ۱۴ روزه\n".
    // "💰 _قیمت بسته: ۳۳۸ هزار تومان_\n".
    "🔥 قیمت ویژه: ۲۳۸ هزار تومان\n".
    "\n".
    "+ 🎁 ۱۴ روز اعتبار هدیهٔ عید نوروز 🍏\n".
    "\n".
    "---      *بسته اقتصادی*\n".
    "🧠 پاسخ‌های با کیفیت و دقت بالاتر\n".
    // "💬 ارسال پیام و تصویر تا ۱۰ برابر بیشتر\n".
    // "🖌️ ویرایش تصاویر با کیفیت بالا\n".
    // "📂 بارگذاری عکس و فایل تا ۱۰ برابر\n".
    // "\n".
    "⏱️ اعتبار: ۲۰ روزه\n".
    // "💰 _قیمت بسته: ۲۱۹ هزار تومان_\n".
    "🔥 قیمت ویژه: ۱۱۹ هزار تومان\n".
    "\n".
    "---      *بسته حرفه‌ای*\n".
    "🧠 پاسخ‌های با کیفیت و دقت بالاتر\n".
    // "💬 ارسال پیام و تصویر تا ۵۰ برابر بیشتر\n".
    // "🖌️ ویرایش تصاویر با کیفیت بالا\n".
    // "📂 بارگذاری عکس و فایل تا ۵۰ برابر\n".
    // "\n".
    "⏱️ اعتبار: ۶۰ روزه\n".
    // "💰 _قیمت بسته: ۴۹۰ هزار تومان_\n".
    "🔥 قیمت ویژه: ۳۹۰ هزار تومان\n\n".
    "تمام بسته‌ها شامل :\n".
    "• پیام‌های نامحدود 📩\n".
    "• حافظه بلندمدت 🧠\n".
    "• دسترسی به مدل‌های هوشمند 🧠 و فوق هوشمند 🚀\n".
    "• تبدیل پیام صوتی به متن 🎤\n\n پشتیبانی: @suport_0";
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
