<?php

// What the bot does with images. Limits are checked on the server before any provider is called.

/** The user's row plus what their plan allows. */
function mediaUser($telegramUserId): array {
    global $db;
    $stmt = $db->prepare("SELECT * FROM " . T_USERS . " WHERE chat_id = ?");
    $stmt->execute([$telegramUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $isPro = $row && $row['is_pro'] && (empty($row['pro_expire']) || strtotime($row['pro_expire']) > time());
    $plan = $row['plan'] ?? null;
    return [
        'row' => $row,
        'is_pro' => $isPro,
        'plan' => $plan,
        'limits' => Bot\Plans::limits(botServices()->settings, $isPro, $plan),
    ];
}

function botMedia(): Bot\Media {
    global $db, $config;
    $s = botServices();
    return new Bot\Media($db, $s->http, $s->settings, $s->store, $config['telegram']['bot_token']);
}

// Answer Bale at once: image requests take longer than its webhook waits, and it would send them again.
function finishWebhookEarly(): void {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
}

function plansButton(): array {
    return [[['text' => '💎 پلن‌های اشتراک', 'callback_data' => 'cmd_buypro']]];
}

// ----- /image, or the description sent after pressing the button -----
function handleImageRequest($chatId, $telegramUserId, string $prompt): void {
    global $config, $monitorChatId;
    $media = botMedia();
    $user = mediaUser($telegramUserId);
    if ($user['row'] && !empty($user['row']['baned'])) {
        return;
    }

    $why = $media->refusal((int) $telegramUserId, 'image', $user['limits']);
    if ($why !== null) {
        sendButtons($chatId, $why, plansButton());
        return;
    }
    if ($prompt === '') {
        $media->setPending((int) $telegramUserId);
        sendTelegramMessage($chatId, "🖼 توضیح تصویری را که می‌خواهید بفرستید.\n\nمثال: یک گربه فضانورد روی ماه، سبک نقاشی آبرنگ", '');
        return;
    }

    $lock = botServices()->store->tryLock('media_user_' . $telegramUserId);
    if (!$lock) {
        sendTelegramMessage($chatId, "⏳ درخواست قبلی شما در حال انجام است. لطفاً کمی صبر کنید.", '');
        return;
    }
    $file = null;
    try {
        finishWebhookEarly();
        sendTelegramMessage($chatId, "🎨 در حال ساخت تصویر... ممکن است تا یک دقیقه طول بکشد.", '');
        $usageId = $media->begin((int) $telegramUserId, 'image');
        $result = $media->generateImage($prompt);
        $media->finish($usageId, $result);
        if (!$result['ok']) {
            sendTelegramMessage($chatId, "❌ ساخت این تصویر انجام نشد و از سهمیه شما کم نشد. لطفاً توضیح را تغییر دهید یا بعداً دوباره تلاش کنید.", '');
            sendTelegramMessage($config['telegram']['admin_chatid'], "🚨 Image failed:\n" . implode("\n", $result['errors']), '');
            return;
        }
        $file = tempnam(sys_get_temp_dir(), 'bot_img_');
        file_put_contents($file, $result['bytes']);
        $sent = sendPhotoFile($chatId, $file, Bot\Media::imageMime($result['bytes']), '🖼 ' . mb_substr($prompt, 0, 900));
        if (empty($sent['ok'])) {
            sendTelegramMessage($chatId, "❌ ارسال تصویر انجام نشد. لطفاً دوباره تلاش کنید.", '');
            sendTelegramMessage($config['telegram']['admin_chatid'], "🚨 sendPhoto failed:\n" . Bot\Redactor::redact(json_encode($sent, JSON_UNESCAPED_UNICODE), 300), '');
            $media->finish($usageId, ['ok' => false, 'provider' => $result['provider'], 'model' => $result['model']]);
            return;
        }
        sendTelegramMessage($monitorChatId, "🖼 {$telegramUserId} {$result['provider']}/{$result['model']}: " . mb_substr($prompt, 0, 300), '');
    } finally {
        if ($file && is_file($file)) {
            @unlink($file);
        }
        botServices()->store->release($lock);
    }
}

// ----- a photo sent to the bot; the caption is the question -----
function handlePhotoMessage(array $message): void {
    global $db, $config;
    $chatId = $message['chat']['id'];
    $telegramUserId = $message['from']['id'];
    $media = botMedia();
    $user = mediaUser($telegramUserId);
    if ($user['row'] && !empty($user['row']['baned'])) {
        return;
    }

    $why = $media->refusal((int) $telegramUserId, 'vision', $user['limits']);
    if ($why !== null) {
        sendButtons($chatId, $why, plansButton());
        return;
    }
    $lock = botServices()->store->tryLock('media_user_' . $telegramUserId);
    if (!$lock) {
        sendTelegramMessage($chatId, "⏳ درخواست قبلی شما در حال انجام است. لطفاً کمی صبر کنید.", '');
        return;
    }
    try {
        finishWebhookEarly();
        sendTelegramTyping($chatId);
        $photo = $media->downloadPhoto($message['photo']);
        if ($photo === null) {
            sendTelegramMessage($chatId, "❌ دریافت تصویر ممکن نشد. لطفاً دوباره بفرستید.", '');
            return;
        }
        $question = trim((string) ($message['caption'] ?? '')) ?: 'این تصویر را توضیح بده.';
        $usageId = $media->begin((int) $telegramUserId, 'vision');
        $result = $media->describeImage($photo[0], $photo[1], $question);
        $media->finish($usageId, $result);
        if (!$result['ok']) {
            sendTelegramMessage($chatId, "❌ تحلیل این تصویر انجام نشد و از سهمیه شما کم نشد. لطفاً بعداً دوباره تلاش کنید.", '');
            sendTelegramMessage($config['telegram']['admin_chatid'], "🚨 Vision failed:\n" . implode("\n", $result['errors']), '');
            return;
        }
        foreach (Bot\VoiceTranscriber::chunks($result['text'], 4000) as $part) {
            sendTelegramMessage($chatId, $part, '');
        }
        // Keep the answer in chat memory so the user can ask about it. As an AI message it is not
        // counted against the chat limit.
        if ($user['row']) {
            $db->prepare("INSERT INTO " . T_MESSAGES . " (user_id, chat_memory_id, message, type) VALUES (?, ?, ?, 'AI')")
                ->execute([$user['row']['id'], $telegramUserId, "[تحلیل تصویر کاربر] " . $result['text']]);
        }
    } finally {
        botServices()->store->release($lock);
    }
}

// Voices per week for this user; 0 means no weekly limit.
function voiceWeeklyLimitFor($telegramUserId): int {
    return (int) (mediaUser($telegramUserId)['limits']['voice_per_week'] ?? 0);
}

// Daily voice minutes for this user; 0 means no access. The plan decides. Without a plan, a
// verified older purchase keeps the standard allowance.
function voiceMinutesFor($telegramUserId): int {
    global $db;
    $s = botServices();
    if (empty($s->settings['media']['voice_enabled'])) {
        return 0;
    }
    $user = mediaUser($telegramUserId);
    if (Bot\Plans::active($s->settings, $user['is_pro'], $user['plan'])) {
        return $user['limits']['voice_minutes_per_day'];
    }
    if ($user['limits']['voice_minutes_per_day'] > 0) {
        return $user['limits']['voice_minutes_per_day'];
    }
    return $s->entitlements($db)->hasActivePaidPremium((int) $telegramUserId)
        ? (int) $s->settings['transcription']['max_minutes_per_day'] : 0;
}
