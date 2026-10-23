<?php

$config = require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/commands.php';
require __DIR__ . '/buttons.php';
require __DIR__ . '/src/bootstrap.php';

$adminChatId = $config['telegram']['admin_chatid'];

// New tables (v2_*) so the old version keeps its own tables and can be restored any time.
$tablePrefix = Bot\Settings::fromConfig($config)['table_prefix'];
define('T_USERS', $tablePrefix . 'users');
define('T_MESSAGES', $tablePrefix . 'messages');
define('T_PAYMENTS', $tablePrefix . 'payments');
// Account that receives copies of chats/media for monitoring (as before).
$monitorChatId = $config['monitor_chat_id'] ?? 617483778;

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

// Free-first routing: tier's free OpenRouter models → the tier's paid model (MetisAI). See src/Router.php.
function botServices(): Bot\Services {
    static $services = null;
    global $config;
    if ($services === null) {
        $adminChatId = $config['telegram']['admin_chatid'];
        $services = new Bot\Services($config, null, function (string $text) use ($adminChatId) {
            sendTelegramMessage($adminChatId, $text, '');
        });
    }
    return $services;
}

// Model tier of the user: normal | smart | extra. Users without active PRO always get the default tier.
function getUserTier($chatId) {
    global $db, $isPro;
    $settings = botServices()->settings;

    if (empty($isPro)) return $settings['default_tier'];

    $stmt = $db->prepare("SELECT model FROM " . T_USERS . " WHERE chat_id = ?");
    $stmt->execute([$chatId]);
    $tier = $stmt->fetchColumn();

    return isset($settings['tiers'][$tier]) ? $tier : $settings['default_tier'];
}

function tierLabel($tier): string {
    $tiers = botServices()->settings['tiers'];
    return $tiers[$tier]['label'] ?? (string) $tier;
}

function sendToGPTResult(array $messages, string $purpose = 'chat'): array {
    global $chatId;
    $tier = $purpose === 'summary' ? botServices()->settings['default_tier'] : getUserTier($chatId);
    return botServices()->router->complete($messages, ['purpose' => $purpose, 'tier' => $tier]);
}

function sendToGPT(array $messages): string {
    return sendToGPTResult($messages)['user_message'];
}

// Runs after the user already has the reply: refresh the free-model list if it is stale.
function botAfterResponse(): void {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    try {
        botServices()->catalog->refreshIfDue();
    } catch (\Throwable $e) {
        error_log('[router] catalogue refresh failed: ' . Bot\Redactor::redact($e->getMessage(), 200));
    }
}


// Summarize the current history using GPT
function summarize_history(array $messageHistory): ?string{
    global $config , $userId , $chatId , $username , $lastName,$firstName, $monitorChatId;

    // Build a single prompt like in your Python version
    $prompt = implode("\n", $messageHistory)
        . "\nChatbot: Please shortly summarize the conversation so far, focusing on the key details and important points. "
        . "Ensure that no critical information is lost and that the summary preserves the meaning and flow of the conversation.";

    // Use your chat API function (expects role-based messages)
    $messages = [
        ['role' => 'system', 'content' => 'You are a helpful assistant that summarizes conversations.'],
        ['role' => 'user',   'content' => $prompt],
    ];

    $result = sendToGPTResult($messages, 'summary');
    if (!$result['ok']) {
        return null; // keep the history as is rather than saving an error as the summary
    }
    $summery = $result['content'];

    $logText = "New summary \n"
    . "User ID: {$userId} "
    . "Chat ID: {$chatId} "
    . "Name: {$firstName} {$lastName} "
    . "Username: @" . ($username ?: '—')."\n\n".$summery;

    $adminChatId = $config['telegram']['admin_chatid'];

    // sendTelegramMessage($adminChatId, $logText);
    // sendTelegramMessage(5975340507, $logText);
    sendTelegramMessage($monitorChatId, $logText);

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
        $stmt = $db->prepare("SELECT id, is_pro, pro_expire FROM " . T_USERS . " WHERE id = ?");
        $stmt->execute([$userId]);
    } else {
        $stmt = $db->prepare("SELECT id, is_pro, pro_expire FROM " . T_USERS . " WHERE chat_id = ?");
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
    $sql = "UPDATE " . T_USERS . " SET is_pro = 1, pro_expire = :expireAt WHERE id = :id";
    $params = [
        ':expireAt' => $newExpire,
        ':id'       => $userId,
    ];

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount() > 0;
}


// ----------------- PAYMENTS -----------------
function isPaidStatus($status): bool {
    return is_string($status) && in_array(strtolower($status), ['paid', 'succeeded', 'success', 'successful', 'completed'], true);
}

function isFailedStatus($status): bool {
    return is_string($status) && in_array(strtolower($status), ['failed', 'failure', 'canceled', 'cancelled', 'rejected', 'refunded', 'expired'], true);
}

// Already credited automatically, or by the admin with /grantpremium using this receipt number.
function paymentAlreadyCredited($chargeId): bool {
    global $db, $tablePrefix;
    $stmt = $db->prepare("SELECT 1 FROM " . T_PAYMENTS . " WHERE charge_id = ? AND status = 'paid' LIMIT 1");
    $stmt->execute([(string) $chargeId]);
    if ($stmt->fetchColumn() !== false) {
        return true;
    }
    // /grantpremium stores the receipt lower-cased (commands are matched in lower case)
    $stmt = $db->prepare("SELECT 1 FROM {$tablePrefix}premium_entitlements WHERE payment_ref IN (?, ?) AND status = 'active' LIMIT 1");
    $stmt->execute([(string) $chargeId, strtolower((string) $chargeId)]);
    return $stmt->fetchColumn() !== false;
}

function recordPayment($telegramUserId, ?int $userId, string $package, $amount, $currency, $payload, $chargeId, $providerChargeId, string $status, int $days): void {
    global $db;
    try {
        $stmt = $db->prepare("INSERT INTO " . T_PAYMENTS . " (telegram_user_id, user_id, package, amount, currency, payload, charge_id, provider_charge_id, status, days, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status)");
        $stmt->execute([$telegramUserId, $userId, $package, (int) $amount, (string) $currency, (string) $payload, (string) $chargeId, (string) $providerChargeId, $status, $days, date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        error_log('[payments] could not record payment: ' . Bot\Redactor::redact($e->getMessage(), 200));
    }
}

function getProTimeRemainingStr($chatId = null): string {
    global $db;

    if ( $chatId === null) {
        return "شناسه کاربر یا چت را وارد کنید.";
    }

    $stmt = $db->prepare("SELECT id, is_pro, pro_expire, username FROM " . T_USERS . " WHERE chat_id = ?");
    $stmt->execute([$chatId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return "حساب پرو فعال نیست. x";
    }

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
function telegramApiBase(): string {
    global $config;
    return rtrim($config['telegram']['api_base'] ?? 'https://tapi.bale.ai', '/');
}

/**
 * Calls a Bale Bot API method and returns the decoded answer (['ok' => false, ...] on failure).
 * Sends JSON first (real true/false and arrays). Only if Bale explicitly rejects that, the same
 * call is repeated once in the form encoding the old code used; never after a network error,
 * so an invoice cannot be sent twice.
 */
function baleCall(string $method, array $params, int $timeout = 8): array {
    global $config;
    $url = telegramApiBase() . "/bot{$config['telegram']['bot_token']}/{$method}";
    $send = function ($body, array $headers) use ($url, $timeout) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $raw === '') {
            return null; // network error: we do not know whether Bale processed it
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json + ['_http' => $code] : ['ok' => false, '_http' => $code, 'description' => mb_substr($raw, 0, 200)];
    };

    $res = $send(json_encode($params, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
    if (is_array($res) && empty($res['ok'])) {
        $form = array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'true' : 'false') : $v), $params);
        $res2 = $send($form, []);
        if (is_array($res2)) {
            return $res2;
        }
    }
    return $res ?? ['ok' => false, 'description' => 'network error'];
}

function sendTelegramMessage($chatId, $text, $parseMode = null): void {
    global $config;

    $token = $config['telegram']['bot_token'];
    // $url   = "https://api.telegram.org/bot{$token}/sendMessage";
    $url   = telegramApiBase() . "/bot{$token}/sendMessage";

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
    $url   = telegramApiBase() . "/bot{$token}/sendChatAction";

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
    $url = telegramApiBase() . "/bot{$token}/forwardMessage";

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
$rawUpdate = file_get_contents('php://input');
$update = json_decode($rawUpdate, true);
if (!is_array($update)) {
    exit;
}
// Bale re-delivers updates when the webhook is slow; never process (or credit) one twice.
// The key includes a hash of the body, so two different updates can never be mistaken for one.
if (isset($update['update_id']) && !(new Bot\UpdateDeduper(botServices()->store))->firstSeen('u:' . $update['update_id'] . ':' . md5($rawUpdate))) {
    exit;
}
// Handle inline button presses
if (isset($update['callback_query'])) {
    if (handleCallback($update)) {
        exit;
    }
}

// Handle pre_checkout_query (payment confirmation step)
// Bale asks "may this payment go ahead?" and waits at most 10 seconds for ok = true.
if (isset($update['pre_checkout_query'])) {
    global $db, $config, $adminChatId;

    $preCheckout = $update['pre_checkout_query'];
    $preCheckoutId = $preCheckout['id'];
    $payload = $preCheckout['invoice_payload'] ?? null;

    // Accept every invoice this bot created (buypro_{package}_{userId}_{time}).
    $okToPay = $payload === null || (bool) preg_match('/^buypro_(smart|economic|pro)_([0-9]+)_/', (string) $payload);
    $answer = ['pre_checkout_query_id' => $preCheckoutId, 'ok' => $okToPay];
    if (!$okToPay) {
        $answer['error_message'] = 'این فاکتور معتبر نیست. لطفاً دوباره /buypro را بزنید.';
    }
    $result = baleCall('answerPreCheckoutQuery', $answer, 6);
    if (empty($result['ok'])) {
        sendTelegramMessage($adminChatId, "⚠️ answerPreCheckoutQuery ناموفق بود (پرداخت کاربر تایید نشد):\n" . Bot\Redactor::redact(json_encode($result, JSON_UNESCAPED_UNICODE), 300), '');
    }
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
            // Set PRO duration based on package (same values as before: 44 / 20 / 60 days)
            $expireDays = array_map(fn ($p) => $p['days'], buyProPackages());
            $expireAt = (new DateTime('+' . $expireDays[$package] . ' days'))->format('Y-m-d H:i:s');
            // Find user internal id
            $stmt = $db->prepare("SELECT id FROM " . T_USERS . " WHERE chat_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                sendTelegramMessage($adminChatId, "⚠️ پرداخت برای کاربری که در دیتابیس نیست: ChatID {$userId} | رسید: {$telegramPaymentChargeId}");
            }
            if ($row && paymentAlreadyCredited($telegramPaymentChargeId)) {
                exit; // the same payment was delivered twice; it is already credited
            }
            if ($row) {
                // --- Check transaction status via inquireTransaction ---
                // The transaction can still be settling when this message arrives, so ask a few times.
                $status = null;
                $txn = [];
                for ($try = 1; $try <= 4; $try++) {
                    $txn = baleCall('inquireTransaction', ['transaction_id' => (string) $telegramPaymentChargeId], 8);
                    $status = $txn['result']['status'] ?? null;
                    if (isPaidStatus($status) || isFailedStatus($status)) {
                        break;
                    }
                    if ($try < 4) {
                        sleep(2);
                    }
                }
                sendTelegramMessage($adminChatId, "inquireResult: " . Bot\Redactor::redact(json_encode($txn, JSON_UNESCAPED_UNICODE), 500), '');
                if (isPaidStatus($status)) {
                    $status = 'paid';
                }
                recordPayment($userId, (int) $row['id'], $package, $totalAmount, $currency, $payload, $telegramPaymentChargeId, $providerPaymentChargeId, (string) $status, $expireDays[$package]);
                if ($status === 'paid') {
                    makeUserPro($row['id'], null, $expireAt);
                    // Upgrade the model tier if the user is still on the default (normal) tier
                    $stmt = $db->prepare("SELECT model FROM " . T_USERS . " WHERE id = ?");
                    $stmt->execute([$row['id']]);
                    $currentModel = $stmt->fetchColumn();

                    $tierSettings = botServices()->settings;
                    if ($currentModel === $tierSettings['default_tier'] || !isset($tierSettings['tiers'][$currentModel])) {
                        $stmt = $db->prepare("UPDATE " . T_USERS . " SET model = ? WHERE id = ?");
                        $stmt->execute([$tierSettings['pro_tier'], $row['id']]);
                    }
                    $days = $expireDays[$package];
                    // Verified payment → premium-only features (voice-to-text) for the same period
                    try {
                        botServices()->entitlements($db)->grant((int) $userId, $days, 0, (string) $telegramPaymentChargeId, Bot\Entitlements::SOURCE_BALE_PAYMENT);
                    } catch (\Throwable $e) {
                        sendTelegramMessage($adminChatId, "⚠️ ثبت اشتراک صوتی ناموفق بود: ChatID {$userId} | رسید: {$telegramPaymentChargeId}\n" . Bot\Redactor::redact($e->getMessage(), 200), '');
                    }
                    $cost = number_format($totalAmount);
                    $receipt = "\nرسید پرداخت:\nشماره تراکنش: {$telegramPaymentChargeId}\nپیگیری: {$providerPaymentChargeId}";
                    sendTelegramMessage($chatId, "🎉 پرداخت با موفقیت تایید شد!\nبسته: {$package}\nمدت: {$days} روز\nمبلغ: {$cost} ریال{$receipt}\nاکنون PRO فعال شد.\n🎤 تبدیل پیام صوتی به متن هم برای شما فعال شد.");
                    // Send compact admin notification with more user info
                    $adminMsg = "پرداخت تایید شد\n"
                        . "نام: " . ($update['message']['from']['first_name'] ?? '') . " " . ($update['message']['from']['last_name'] ?? '') . "\n"
                        . "یوزرنیم: @" . ($update['message']['from']['username'] ?? '—') . "\n"
                        . "ChatID: {$chatId}\n"
                        . "بسته: {$package} | {$days} روز | {$cost} ریال\n"
                        . "رسید: {$telegramPaymentChargeId}";
                    sendTelegramMessage($adminChatId, $adminMsg);
                } else {
                    sendTelegramMessage($chatId, "❌ پرداخت تایید نشد یا هنوز کامل نشده است. اگر مبلغ کسر شده است، با پشتیبانی تماس بگیرید.\nوضعیت تراکنش: {$status}\n\n @suport_0");
                    if (!isFailedStatus($status)) {
                        // Bale reported a successful payment but the inquiry did not confirm it yet.
                        sendTelegramMessage($adminChatId, "⚠️ پرداخت تایید نشد (وضعیت: " . ($status ?? 'نامشخص') . ")\n"
                            . "ChatID: {$userId} | بسته: {$package} | مبلغ: " . number_format((int) $totalAmount) . " ریال\n"
                            . "رسید: {$telegramPaymentChargeId}\n"
                            . "اگر پرداخت در کیف پول شما ثبت شده، با این دستور فعال کنید:\n"
                            . "/grantpremium {$userId} {$expireDays[$package]} {$telegramPaymentChargeId}", '');
                    }
                }
            }
        }
    }
    exit;
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

    // ----------------- VOICE → TEXT (premium only, checked on the server) -----------------
    if (!$isGroup && Bot\VoiceTranscriber::isAudioMessage($message)) {
        if (!empty($config['monitor_forward_media'] ?? true)) {
            sendTelegramForward($monitorChatId, $chatId, $message['message_id']);
        }
        $stmt = $db->prepare("SELECT id, is_pro, pro_expire, baned FROM " . T_USERS . " WHERE chat_id = ?");
        $stmt->execute([$message['from']['id']]);
        $voiceUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($voiceUser && !empty($voiceUser['baned'])) {
            exit;
        }
        $voice = botServices()->voice($db, $config['telegram']['bot_token'], function ($to, $text) {
            sendTelegramMessage($to, $text, '');
        }, function ($telegramUserId) use ($voiceUser) {
            // only used when transcription.allow_referral_pro = true
            return $voiceUser && $voiceUser['is_pro'] && (empty($voiceUser['pro_expire']) || strtotime($voiceUser['pro_expire']) > time());
        });
        $voice->handle($message, $update['update_id'] ?? null, $voiceUser ? (int) $voiceUser['id'] : null);
        exit;
    }

    // Check for media types
    $mediaTypes = ['photo', 'video', 'voice', 'audio', 'document', 'animation', 'sticker'];
    foreach ($mediaTypes as $type) {
        if (isset($message[$type])) {
            // Forward the media message to the specified chat
            $forwardChatId = $monitorChatId;
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
    $stmt = $db->prepare("SELECT * FROM " . T_USERS . " WHERE chat_id = ?");
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
    $stmt = $db->prepare("SELECT * FROM " . T_USERS . " WHERE chat_id = ?");
    $stmt->execute([$senderId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$user) {
    // New user
    $stmt = $db->prepare("INSERT INTO " . T_USERS . " (chat_id, username, first_name, last_name, model) VALUES (?,?, ?, ?, ?)");
    $userModel = botServices()->settings['default_tier']; 
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
    $userModel = $user['model']; // model tier: normal | smart | extra

    // Pro expiration check
    if ($isPro && $proExpire && strtotime($proExpire) < time()) {
        $isPro = 0; // pro expired
        $userModel = botServices()->settings['default_tier']; // default tier
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
        "🎉 تبریک! اشتراک ۷ روزه PRO برای شما فعال شد!\n\nاز پیام‌های نامحدود، حافظه بهتر و مدل‌های هوشمند 🧠 و فوق هوشمند 🚀 لذت ببرید."
    );

    exit; // stop further processing for this update
}

// ----------------- DAILY MESSAGE LIMIT -----------------
if (!$isPro) {
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM " . T_MESSAGES . " 
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
            ." • انتخاب مدل‌های هوشمند 🧠 و فوق هوشمند 🚀\n\n"
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
    FROM " . T_MESSAGES . " 
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
}
if (isset($summary) && $summary !== null) {

    // Save summary marker in DB
    $stmt = $db->prepare("INSERT INTO " . T_MESSAGES . " (user_id, chat_memory_id, message, type) VALUES (?, ?, ?, 'SUMMARY')");
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
$gptResult = sendToGPTResult($messagesForGPT);
$gptReply  = $gptResult['user_message'];

if ($gptResult['ok']) {

// ----------------- SAVE USER MESSAGE -----------------
$stmt = $db->prepare("INSERT INTO " . T_MESSAGES . " (user_id, chat_memory_id, message, type) VALUES (?, ?, ?, 'USER')");
$stmt->execute([$userId, $memoryOwnerId, $userText]);

$stmt = $db->prepare("INSERT INTO " . T_MESSAGES . " (user_id, chat_memory_id, message, type) VALUES (?, ?, ?, 'AI')");
$stmt->execute([$userId, $memoryOwnerId, $gptReply]);

}
// ----------------- SEND REPLY BACK TO TELEGRAM -----------------
sendTelegramMessage($chatId, $gptReply);

// sendTelegramMessage($adminChatId, "{$firstName} {$lastName} @{$username} {$chatId} count: {$totalWords}\n\n {$msg} \n ------- \n{$gptReply}");
// sendTelegramMessage(5975340507, "{$firstName} {$lastName} @{$username} {$chatId} count: {$totalWords}\n{$msg}\n --- ai reply---- \n{$gptReply}");
sendTelegramMessage($monitorChatId, "{$firstName} {$lastName} @{$username} {$chatId} count: {$totalWords} {$baned} {$isPro} " . ($gptResult['model'] ?? '-') . "\n{$msg}\n --- ai reply---- \n{$gptReply}");

botAfterResponse();
// sendTelegramMessage(617483778, "{$firstName} {$lastName} @{$username} {$chatId} count: {$totalWords} {$model} {$isPro}");

?>
