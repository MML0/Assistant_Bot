<?php

$config = require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/commands.php';
require __DIR__ . '/buttons.php';

$adminChatId = $config['telegram']['admin_chatid'];

use GuzzleHttp\Client;

// ----------------- CONFIG CONSTANTS -----------------
const FREE_DAILY_LIMIT = 5;      // free user daily message limit
$HISTORY_LIMIT    = 5;     // how many past messages to send to AI
const PRO_HISTORY_LIMIT    = 15;     // how many past messages to send to AI

// ----------------- GPT FUNCTION -----------------
/**
 * Send a full chat history to GPT.
 *
 * @param array $messages Array of messages in OpenAI format:
 *        [
 *          ['role' => 'system', 'content' => '...'],
 *          ['role' => 'user', 'content' => '...'],
 *          ['role' => 'assistant', 'content' => '...'],
 *        ]
 */

function getUserModel($chatId, $default = 'gpt-4.1-nano') {
    global $db, $isPro, $userModel;

    $stmt = $db->prepare("SELECT model FROM users WHERE chat_id = ?");
    $stmt->execute([$chatId]);
    $model = $stmt->fetchColumn();

    // If not PRO, always return nano
    if (empty($isPro)) return $default;

    return $model ?: $default;
}

function sendToGPT(array $messages): string {
    global $config, $chatId;

    $apiKey  = $config['gpt']['api_key'];
    $baseUrl = rtrim($config['gpt']['base_url'], '/');
    $adminChatId = $config['telegram']['admin_chatid'];
    $model = getUserModel($chatId);

    $client = new Client([
        'base_uri' => $baseUrl . '/',
        'timeout'  => 25,
    ]);

    $maxAttempts = 2;
    $errors = [];

    // 🔍 Detect GPT-5 family
    $isGpt5 = str_starts_with($model, 'gpt-5');

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {

            // ================= GPT-5 =================
            if ($isGpt5) {

                $response = $client->post('responses', [
                    'headers' => [
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model' => 'gpt-4o',
                        'input' => $messages,
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);

                if (!empty($data['output'][0]['content'][0]['text'])) {
                    return $data['output'][0]['content'][0]['text'];
                }

            // ================= GPT-4 / 4o / 4.1 =================
            } else {

                $response = $client->post('chat/completions', [
                    'headers' => [
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model'      => $model,
                        'messages'   => $messages,
                        'max_tokens' => 1000,
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);

                if (!empty($data['choices'][0]['message']['content'])) {
                    return $data['choices'][0]['message']['content'];
                }
            }

            $errors[] = "Attempt {$attempt}: No content returned.";

        } catch (\Throwable $e) {
            $errors[] = "Attempt {$attempt}: " . $e->getMessage();
        }

        usleep(100000 * $attempt);
    }

    sendTelegramMessage($adminChatId, "$chatId $model 🚨 GPT Error Log:\n" . implode("\n\n", $errors));
    // return "⚠️ GPT is temporarily unavailable. Please try again.";
    return "⚠️ GPT پاسخ نداد. لطفاً دوباره تلاش کنید.";
}
function sendToGPT_forsummary(array $messages): string {
    global $config, $chatId;

    $apiKey  = $config['gpt']['api_key'];
    $baseUrl = rtrim($config['gpt']['base_url'], '/');
    $adminChatId = $config['telegram']['admin_chatid'];
    $model = getUserModel($chatId);

    $client = new Client([
        'base_uri' => $baseUrl . '/',
        'timeout'  => 25,
    ]);

    $maxAttempts = 2;
    $errors = [];

    // 🔍 Detect GPT-5 family
    $isGpt5 = str_starts_with($model, 'gpt-5');

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {

            // ================= GPT-5 =================
            if ($isGpt5) {

                $response = $client->post('responses', [
                    'headers' => [
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model'      => 'gpt-4.1-nano',
                        'input' => $messages,
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);

                if (!empty($data['output'][0]['content'][0]['text'])) {
                    return $data['output'][0]['content'][0]['text'];
                }

            // ================= GPT-4 / 4o / 4.1 =================
            } else {

                $response = $client->post('chat/completions', [
                    'headers' => [
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model'      => 'gpt-4.1-nano',
                        'messages'   => $messages,
                        'max_tokens' => 1000,
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);

                if (!empty($data['choices'][0]['message']['content'])) {
                    return $data['choices'][0]['message']['content'];
                }
            }

            $errors[] = "Attempt {$attempt}: No content returned.";

        } catch (\Throwable $e) {
            $errors[] = "Attempt {$attempt}: " . $e->getMessage();
        }

        usleep(100000 * $attempt);
    }

    sendTelegramMessage($adminChatId, "🚨 GPT Error Log:\n" . implode("\n\n", $errors));
    // return "⚠️ GPT is temporarily unavailable. Please try again.";
    return "⚠️ GPT پاسخ نداد. لطفاً دوباره تلاش کنید.";
}


// Summarize the current history using GPT
function summarize_history(array $messageHistory): string{
    global $config , $userId , $chatId , $username , $lastName,$firstName;

    // Build a single prompt like in your Python version
    $prompt = implode("\n", $messageHistory)
        . "\nChatbot: Please shortly summarize the conversation so far, focusing on the key details and important points. "
        . "Ensure that no critical information is lost and that the summary preserves the meaning and flow of the conversation.";

    // Use your chat API function (expects role-based messages)
    $messages = [
        ['role' => 'system', 'content' => 'You are a helpful assistant that summarizes conversations.'],
        ['role' => 'user',   'content' => $prompt],
    ];

    $summery = sendToGPT_forsummary($messages);

    $logText = "New summary \n"
    . "User ID: {$userId} "
    . "Chat ID: {$chatId} "
    . "Name: {$firstName} {$lastName} "
    . "Username: @" . ($username ?: '—')."\n\n".$summery;

    $adminChatId = $config['telegram']['admin_chatid'];

    // sendTelegramMessage($adminChatId, $logText);
    // sendTelegramMessage(5975340507, $logText);
    sendTelegramMessage(617483778, $logText);

    return $summery;
}
/**
 * Make a user PRO by internal user ID or Telegram chat ID.
 *
 * @param int|null    $userId   users.id (internal)
 * @param int|string|null $chatId  users.chat_id (Telegram chat ID)
 * @param string|null $expireAt MySQL DATETIME string (e.g. '2025-12-31 23:59:59')
 *                              or null for lifetime PRO (no expiry).
 *
 * @return bool true on success, false if user not found or invalid input.
 */
function makeUserPro(?int $userId = null, $chatId = null, ?string $expireAt = null): bool {
    global $db;

    if ($userId === null && $chatId === null) {
        return false; // need at least one identifier
    }

    // Load user
    if ($userId !== null) {
        $stmt = $db->prepare("SELECT id, is_pro, pro_expire FROM users WHERE id = ?");
        $stmt->execute([$userId]);
    } else {
        $stmt = $db->prepare("SELECT id, is_pro, pro_expire FROM users WHERE chat_id = ?");
        $stmt->execute([$chatId]);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return false;
    }

    $userId = (int)$user['id'];

    // 1) Lifetime PRO already → don't touch it
    if ((int)$user['is_pro'] === 1 && empty($user['pro_expire'])) {
        return true;
    }

    // 2) Compute new expiry
    $newExpire = $expireAt; // default: just use given value

    if ($expireAt !== null) {
        // duration we are adding (in seconds)
        $now       = time();
        $targetTs  = strtotime($expireAt);
        $duration  = max(0, $targetTs - $now); // avoid negative

        // base = existing pro_expire (if future) or now
        if (!empty($user['pro_expire'])) {
            $current = strtotime($user['pro_expire']);
            $base    = max($current, $now);
        } else {
            $base = $now;
        }

        $newExpire = date('Y-m-d H:i:s', $base + $duration);
    } else {
        // expireAt null => upgrade to lifetime (unless already lifetime, which we handled above)
        $newExpire = null;
    }

    // 3) Update record
    $sql = "UPDATE users SET is_pro = 1, pro_expire = :expireAt WHERE id = :id";
    $params = [
        ':expireAt' => $newExpire,
        ':id'       => $userId,
    ];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount() > 0;
}


function getProTimeRemainingStr($chatId = null): string {
    global $db;

    if ( $chatId === null) {
        return "شناسه کاربر یا چت را وارد کنید.";
    }

    $stmt = $db->prepare("SELECT id, is_pro, pro_expire FROM users WHERE chat_id = ?");
    $stmt->execute([$chatId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (($user['is_pro']) && empty($user['pro_expire'])) {
        return "حساب پرو دائمی فعال است.";
    }
    if (!($user['is_pro']) && empty($user['pro_expire'])) {
        return "حساب پرو فعال نیست. x";
    }
    $expireTs = strtotime($user['pro_expire']);
    $now     = time();

    if ($expireTs <= $now) {
        return "حساب پرو منقضی شده است. {$user['username']}";
    }

    $diff = $expireTs - $now;

    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);

    $parts = [];

    if ($days > 0) {
        $parts[] = $days . ' روز';
    }

    if ($hours > 0) {
        $parts[] = $hours . ' ساعت';
    }

    if (empty($parts)) {
        return "کمتر از 1 ساعت باقی مانده است.";
    }

    return "زمان باقی‌مانده پرو: " . implode(' و ', $parts);
}

// ----------------- TELEGRAM FUNCTION -----------------
function sendTelegramMessage($chatId, $text, $parseMode = null): void {
    global $config;

    $token = $config['telegram']['bot_token'];
    // $url   = "https://api.telegram.org/bot{$token}/sendMessage";
    $url   = "https://tapi.bale.ai/bot{$token}/sendMessage";

    // ---------------- AUTO MARKDOWN DETECT ----------------
    if ($parseMode === null) {
        // Detect common Markdown patterns
        $markdownPatterns = [
            '/\*\*.*?\*\*/s',   // **bold**
            '/__.*?__/s',       // __underline__
            '/`{1,3}.*?`{1,3}/s', // `code` or ```block```
            '/\[[^\]]+\]\([^)]+\)/', // [text](url)
            '/~~.*?~~/s',       // ~~strike~~
            '/\*[^*]+\*/',      // *italic*
            '/_[^_]+_/',        // _italic_
        ];

        foreach ($markdownPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                $parseMode = "Markdown";
                break;
            }
        }
    }

    $data = [
        'chat_id' => $chatId,
        'text'    => $text,
    ];

    if ($parseMode) {
        $data['parse_mode'] = $parseMode;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
function sendTelegramTyping($chatId): void {
    global $config;

    $token = $config['telegram']['bot_token'];
    // $url   = "https://api.telegram.org/bot{$token}/sendChatAction";
    $url   = "https://tapi.bale.ai/bot{$token}/sendChatAction";

    $data = [
        'chat_id' => $chatId,
        'action'  => 'typing',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// Function to forward message
function sendTelegramForward($toChatId, $fromChatId, $messageId) {
    global $config;

    $token = $config['telegram']['bot_token'];
    $url = "https://tapi.bale.ai/bot{$token}/forwardMessage";

    $data = [
        'chat_id' => $toChatId,
        'from_chat_id' => $fromChatId,
        'message_id' => $messageId,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
// ----------------- DB CONNECTION -----------------
try {
    $db = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
        $config['database']['username'],
        $config['database']['password']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    exit("DB connection failed: " . $e->getMessage());
}

// ----------------- GET TELEGRAM UPDATE -----------------
$update = json_decode(file_get_contents('php://input'), true);
// Handle inline button presses
if (isset($update['callback_query'])) {
    if (handleCallback($update)) {
        exit;
    }
}

// Handle pre_checkout_query (payment confirmation step)
if (isset($update['pre_checkout_query'])) {
    global $db, $config, $adminChatId;
    
    // sendTelegramMessage($adminChatId, "pre");
    $preCheckout = $update['pre_checkout_query'];
    $preCheckoutId = $preCheckout['id'];
    $from = $preCheckout['from'];
    $userId = $from['id'];
    $payload = $preCheckout['invoice_payload'];

    // Accept all payments for now (add checks if needed)
    $token = $config['telegram']['bot_token'];
    $url = "https://tapi.bale.ai/bot{$token}/answerPreCheckoutQuery";
    $data = [
        'pre_checkout_query_id' => $preCheckoutId,
        'ok' => true
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    // sendTelegramMessage($adminChatId, "pre_checkout_query result: " . var_export($result, true));
    exit;
}
// Handle successful payment
if (isset($update['message']['successful_payment'])) {
    // sendTelegramMessage($adminChatId, "suc 2: " . var_export($update['message']['successful_payment'], true));

    global $db, $config;
    $payment = $update['message']['successful_payment'];
    $payload = $payment['invoice_payload'];
    $chatId = $update['message']['chat']['id'];
    $userId = $update['message']['from']['id'];
    $currency = $payment['currency'];
    $totalAmount = $payment['total_amount'];
    $telegramPaymentChargeId = $payment['telegram_payment_charge_id'];
    $providerPaymentChargeId = $payment['provider_payment_charge_id'];

    // Parse package from payload: buypro_{package}_{userId}_{timestamp}
    if (preg_match('/^buypro_(smart|economic|pro)_([0-9]+)_/', $payload, $m)) {
        $package = $m[1];
        $paidUserId = $m[2];
        // Only proceed if user matches
        if ($userId == $paidUserId) {
            // Set PRO duration based on package
            $durations = [
                'smart' => '+44 days', // 30+14
                'economic' => '+20 days',
                'pro' => '+60 days',
            ];
            $expireDays = [
                'smart' => 44,
                'economic' => 20,
                'pro' => 60,
            ];
            $expireAt = (new DateTime($durations[$package]))->format('Y-m-d H:i:s');
            // Find user internal id
            $stmt = $db->prepare("SELECT id FROM users WHERE chat_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                // --- Check transaction status via inquireTransaction ---
                $token = $config['telegram']['bot_token'];
                $inquireUrl = "https://tapi.bale.ai/bot{$token}/inquireTransaction";
                $inquireData = [
                    'transaction_id' => $telegramPaymentChargeId
                ];
                $ch = curl_init($inquireUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $inquireData,
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                $inquireResult = curl_exec($ch);
                curl_close($ch);
                sendTelegramMessage($adminChatId, "inquireResult: " . var_export($inquireResult, true));
                $txn = json_decode($inquireResult, true);
                $status = $txn['result']['status'] ?? null;
                if ($status === 'paid') {
                    makeUserPro($row['id'], null, $expireAt);
                    // 🔥 NEW: Upgrade model if it's nano
                    $stmt = $db->prepare("SELECT model FROM users WHERE id = ?");
                    $stmt->execute([$row['id']]);
                    $currentModel = $stmt->fetchColumn();

                    if ($currentModel === 'gpt-4.1-nano') {
                        $stmt = $db->prepare("UPDATE users SET model = ? WHERE id = ?");
                        $stmt->execute(['gpt-4.1-mini', $row['id']]);
                    }
                    $days = $expireDays[$package];
                    $cost = number_format($totalAmount);
                    $receipt = "\nرسید پرداخت:\nشماره تراکنش: {$telegramPaymentChargeId}\nپیگیری: {$providerPaymentChargeId}";
                    sendTelegramMessage($chatId, "🎉 پرداخت با موفقیت تایید شد!\nبسته: {$package}\nمدت: {$days} روز\nمبلغ: {$cost} ریال{$receipt}\nاکنون PRO فعال شد.");
                    // Send compact admin notification with more user info
                    $adminMsg = "پرداخت تایید شد\n"
                        . "نام: {$update['message']['from']['first_name']} {$update['message']['from']['last_name']}\n"
                        . "یوزرنیم: @" . ($update['message']['from']['username'] ?? '—') . "\n"
                        . "ChatID: {$chatId}\n"
                        . "بسته: {$package} | {$days} روز | {$cost} ریال\n"
                        . "رسید: {$telegramPaymentChargeId}";
                    sendTelegramMessage($adminChatId, $adminMsg);
                } else {
                    sendTelegramMessage($chatId, "❌ پرداخت تایید نشد یا هنوز کامل نشده است. اگر مبلغ کسر شده است، با پشتیبانی تماس بگیرید.\nوضعیت تراکنش: {$status}\n\n @suport_0");
                }
            }
        }
    }
}
if (!isset($update['message'])) {
    exit;
}

$chatId     = $update['message']['chat']['id'];
$username   = $update['message']['from']['username'] ?? null;
$firstName  = $update['message']['from']['first_name'] ?? null;
$lastName   = $update['message']['from']['last_name'] ?? null;
$userText   = $update['message']['text'] ?? '';

// Check if message is from group
$isGroup = $update['message']['chat']['type'] === 'group' || $update['message']['chat']['type'] === 'supergroup';

$adminChatId = $config['telegram']['admin_chatid'];

// 
//  temp forward
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];

    // Check for media types
    $mediaTypes = ['photo', 'video', 'voice', 'audio', 'document', 'animation', 'sticker'];
    foreach ($mediaTypes as $type) {
        if (isset($message[$type])) {
            // Forward the media message to the specified chat
            $forwardChatId = 617483778;
            $messageId = $message['message_id'];

            sendTelegramForward($forwardChatId, $chatId, $messageId);
            $Reply = "😅 ببخشید! فعلاً نمی‌تونم عکس 🖼️، ویدیو 🎥، صدا 🎤 یا فایل 📎 رو ببینم یا بررسی کنم.\n\n✨ این قابلیت در نسخه پرو اضافه میشه و بعداً در دسترس خواهد بود.";

            sendTelegramMessage($chatId, $Reply);
            break; // no need to check other types
        }
    }
}


if (!$userText) {
    exit;
}


if ($isGroup) {
    // Who owns this conversation memory?
    $memoryOwnerId = $update['message']['chat']['id'];   // group id (shared memory)
    $senderId      = $update['message']['from']['id'];   // real user
    
    $groupChatId = $update['message']['chat']['id']; // ❌ group chat ID (negative)
    $chatId      = $update['message']['from']['id']; // ✅ real user ID

    // ----------------- USER HANDLING -----------------
    $stmt = $db->prepare("SELECT * FROM users WHERE chat_id = ?");
    $stmt->execute([$senderId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $chatId = $groupChatId;

    // Add name label for group messages
    $displayName = trim($firstName . ' ' . $lastName);
    if (!$displayName) $displayName = '@' . $username;
    $userText = "{$displayName}: " . $userText;
    die();

} else {
    // Who owns this conversation memory?
    $memoryOwnerId = $update['message']['from']['id'];   // private = user id
    $senderId      = $memoryOwnerId;

    // Private chat
    $groupChatId = null;
    $chatId      = $update['message']['chat']['id']; // ✅ same as user ID

    // ----------------- USER HANDLING -----------------
    $stmt = $db->prepare("SELECT * FROM users WHERE chat_id = ?");
    $stmt->execute([$senderId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$user) {
    // New user
    $stmt = $db->prepare("INSERT INTO users (chat_id, username, first_name, last_name, model) VALUES (?,?, ?, ?, ?)");// set model = 'gpt-4.1-nano'
    $userModel = 'gpt-4.1-nano'; 
    $stmt->execute([$senderId, $username, $firstName, $lastName,$userModel]);

    $userId    = $db->lastInsertId();
    $isPro     = 0;
    $baned     = 0;
    $proExpire = null;

    // log to admin

    $logText = "New \n"
        . "User ID: {$userId} "
        . "Chat ID: {$chatId} "
        . "Name: {$firstName} {$lastName} "
        . "Username: @" . ($username ?: '—');
    sendTelegramMessage($adminChatId, $logText);

} else {
    // sendTelegramMessage($adminChatId, "old");
    $userId    = $user['id'];
    $isPro     = $user['is_pro'];
    $baned     = isset($user['baned']) && $user['baned'] ? 1 : 0;
    $proExpire = $user['pro_expire'];
    $userModel = $user['model']; // gpt-4.1-mini (default) or selected model

    // Pro expiration check
    if ($isPro && $proExpire && strtotime($proExpire) < time()) {
        $isPro = 0; // pro expired
        $userModel = 'gpt-4.1-nano'; // default model
    }
}

// Check if message is a command
if (handleCommand($chatId, $userText)) {
    exit; // stop further processing
}
// makeUserPro(null, $chatId, null); // make prooooooooooooo

// === Secret PRO code: "pro{iran local hour}" ===
$iranTime = new DateTime('now', new DateTimeZone('Asia/Tehran'));
$iranHour = $iranTime->format('H');              // e.g. "07", "14"
$proCode  = 'pro' . ($iranHour*3+4)%30;                  // e.g. "pro07"

if (strtolower(trim($userText)) === $proCode) {
    // lifetime PRO
    // makeUserPro(null, $chatId, null);
    $expireAt = (new DateTime('+7 days'))->format('Y-m-d H:i:s');

    // By internal user id
    // makeUserPro($userId, null, $expireAt);
    $expireAt = (new DateTime('+7 days'))->format('Y-m-d H:i:s');

    // By internal user id
    makeUserPro($userId, null, $expireAt);

    sendTelegramMessage( $chatId,
        "🎉 Congrats! You’ve unlocked 7-Day PRO!\n\nEnjoy unlimited messages, better memory, and full model access (4.1, 4o, 5, 5.1)."
    );

    exit; // stop further processing for this update
}

// ----------------- DAILY MESSAGE LIMIT -----------------
if (!$isPro) {
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM messages 
        WHERE user_id = ? 
          AND DATE(created_at) = CURDATE()
          AND type = 'USER'
    ");
    $stmt->execute([$userId]);
    $countToday = (int) $stmt->fetchColumn();

    if ($countToday >= FREE_DAILY_LIMIT) {
        sendTelegramMessage(
            $chatId,
            "شما به سقف روزانه «" . FREE_DAILY_LIMIT . "» پیام رایگان رسیدید. 🚫📩\n"
            ."*برای ارتقا به نسخه PRO و دریافت امکانات زیر اقدام کنید:* 🚀\n"
            ." • حافظه چت بلندمدت و پایدار 🧠💾\n"
            ." • پیام‌های نامحدود ♾️\n"
            ." • انتخاب مدل‌های پیشرفته 4.1، 4o، 5 و ... 🤖✨\n\n"
            ."برای ارتقا /getpro (رایگان با معرفی به دیگران) 🎁\n"
            ."یا /buypro (خرید) 💳 استفاده کنید."
        );

        exit;
    }
}else{
    $HISTORY_LIMIT = PRO_HISTORY_LIMIT;
}


// ----------------- SYSTEM PROMPT -----------------
// Determine if user is PRO and get Persian date/time
$isProText = $isPro ? " plan کاربر PRO هست." : " plan کاربر رایگان هست.";
// Get current Persian date and time
$dt = new DateTime('now', new DateTimeZone('Asia/Tehran'));
// Use gregorian date/time as fallback since jdate() is undefined
$persianDate = $dt->format('Y/m/d');
$persianTime = $dt->format('H:i:s');

$freeDailyLimitText = "";
if (!$isPro) {
    $freeDailyLimitText = " سقف روزانه پیام رایگان ." . FREE_DAILY_LIMIT;
}

$systemPrompt = <<<EOT
You are a helpful AI assistant in telegram chat no wierd markup text. Answer clearly and concisely.
current iran Time miladi date: $persianDate $persianTime 
$isProText
$freeDailyLimitText
 نام کاربر : " $firstName $lastName " usernam : " $username 
 say hi to them by name"
EOT;
// sendTelegramMessage($adminChatId, $systemPrompt);



// ----------------- BUILD CHAT HISTORY FOR GPT -----------------
$historyLimit = $isPro ? PRO_HISTORY_LIMIT : $HISTORY_LIMIT;
if ($baned) {
    $systemPrompt = '';
    $historyLimit = -1; // or 0 if you want to block
}
$stmt = $db->prepare("
    SELECT type, message 
    FROM messages 
    WHERE chat_memory_id = ?
    ORDER BY id DESC 
    LIMIT " . ($historyLimit + 2)
);
$stmt->execute([$memoryOwnerId]);
$historyRows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
// ----------------------------------------------------
// BUILD CHAT HISTORY FOR GPT (SUMMARY AWARE)
// ----------------------------------------------------
$messagesForGPT = [
    [
        'role'    => 'system',
        'content' => $systemPrompt,
    ],
];

$flatHistory   = [];
$foundSummary  = false;
// add history to all users for now or not XD
if ($isPro || 1) {
    foreach ($historyRows as $row) {
        // If we detect a summary row → reset and keep ONLY that summary as context
        if ($row['type'] === 'SUMMARY') {

            // Reset GPT messages (keep system)
            $messagesForGPT = [
                [
                    'role'    => 'system',
                    'content' => $systemPrompt,
                ],
            ];

            // Reset flat history
            $flatHistory  = [];
            $foundSummary = true;

            // ✅ INCLUDE THE SUMMARY ITSELF
            $messagesForGPT[] = [
                'role'    => 'assistant',
                'content' => "Summary of our chat so far: " . $row['message'],
            ];
            $flatHistory[] = "Summary of our chat so far: " . $row['message'];

            continue;
        }
        if ($row['type'] === 'USER') {
            $messagesForGPT[] = [
                'role'    => 'user',
                'content' => $row['message'],
            ];
            $flatHistory[] = 'user' . $row['message'];

        } elseif ($row['type'] === 'AI') {
            $messagesForGPT[] = [
                'role'    => 'assistant',
                'content' => $row['message'],
            ];
            $flatHistory[] = 'user' . $row['message'];
        }
    }
}

// ----------------- SEND Bot is typing -----------------
sendTelegramTyping($chatId);

// Add current user message
$messagesForGPT[] = [
    'role'    => 'user',
    'content' => $userText,
];
$flatHistory[] = $userText;

// ----------------------------------------------------
// AUTO-SUMMARIZE IF HISTORY > $HISTORY_LIMIT
// ----------------------------------------------------

if (count($flatHistory) > $HISTORY_LIMIT && !$baned && $isPro) {

    $summary = summarize_history($flatHistory);

    // Save summary marker in DB
    $stmt = $db->prepare("INSERT INTO messages (user_id, chat_memory_id, message, type) VALUES (?, ?, ?, 'SUMMARY')");
    $stmt->execute([$userId, $memoryOwnerId, $summary]);

    // Reset GPT context using the new summary
    $messagesForGPT = [
        [
            'role'    => 'system',
            'content' => $systemPrompt,
        ],
        [
            'role'    => 'assistant',
            'content' => "Summary of our chat so far: " . $summary
        ],
        [
            'role'    => 'user',
            'content' => "Continuing from last chat sumerry : " . $userText
        ],
    ];
}


$totalWords = 0;
foreach ($flatHistory as $msg) {
    $totalWords += str_word_count($msg, 0, "آابپتثجچحخدذرزسشصضطظعغفقکگلمنوهی");
}
// sendTelegramMessage($adminChatId, "{$firstName} {$lastName} @{$username} {$chatId} count: {$totalWords}");

// ----------------- SEND TO GPT -----------------
$gptReply = sendToGPT($messagesForGPT);

if ($gptReply !== "⚠️ GPT پاسخ نداد. لطفاً دوباره تلاش کنید.") {

// ----------------- SAVE USER MESSAGE -----------------
$stmt = $db->prepare("INSERT INTO messages (user_id, chat_memory_id, message, type) VALUES (?, ?, ?, 'USER')");
$stmt->execute([$userId, $memoryOwnerId, $userText]);

$stmt = $db->prepare("INSERT INTO messages (user_id, chat_memory_id, message, type) VALUES (?, ?, ?, 'AI')");
$stmt->execute([$userId, $memoryOwnerId, $gptReply]);

}
// ----------------- SEND REPLY BACK TO TELEGRAM -----------------
sendTelegramMessage($chatId, $gptReply);

// sendTelegramMessage($adminChatId, "{$firstName} {$lastName} @{$username} {$chatId} count: {$totalWords}\n\n {$msg} \n ------- \n{$gptReply}");
// sendTelegramMessage(5975340507, "{$firstName} {$lastName} @{$username} {$chatId} count: {$totalWords}\n{$msg}\n --- ai reply---- \n{$gptReply}");
sendTelegramMessage(617483778, "{$firstName} {$lastName} @{$username} {$chatId} count: {$totalWords} {$baned} {$isPro}\n{$msg}\n --- ai reply---- \n{$gptReply}");
// sendTelegramMessage(617483778, "{$firstName} {$lastName} @{$username} {$chatId} count: {$totalWords} {$model} {$isPro}");

?>
