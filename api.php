<?php

$config = require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/commands.php';
require __DIR__ . '/buttons.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// ----------------- CONFIG CONSTANTS -----------------
const FREE_DAILY_LIMIT = 4;      // free user daily message limit
const HISTORY_LIMIT    = 30;     // how many past messages to send to AI

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

function getUserModel($chatId, $default = 'gpt-4.1-mini') {
    global $db;

    $stmt = $db->prepare("SELECT model FROM users WHERE chat_id = ?");
    $stmt->execute([$chatId]);
    $model = $stmt->fetchColumn();

    return $model ?: $default; // fallback if null
}

function sendToGPT(array $messages): string {
    global $config, $chatId;

    $apiKey  = $config['gpt']['api_key'];
    $baseUrl = rtrim($config['gpt']['base_url'], '/');
    $adminChatId = $config['telegram']['admin_chatid'];
    $model = getUserModel($chatId);

    $client = new Client([
        'base_uri' => $baseUrl . '/',
        'timeout'  => 5,
    ]);

    $maxAttempts = 3;
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
                        'max_tokens' => 700,
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

        usleep(500000 * $attempt);
    }

    sendTelegramMessage($adminChatId, "🚨 GPT Error Log:\n" . implode("\n\n", $errors));
    return "⚠️ GPT is temporarily unavailable. Please try again.";
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

    $summery = sendToGPT($messages);

    $logText = "New summary \n"
    . "User ID: {$userId} "
    . "Chat ID: {$chatId} "
    . "Name: {$firstName} {$lastName} "
    . "Username: @" . ($username ?: '—')."\n\n".$summery;

    $adminChatId = $config['telegram']['admin_chatid'];

    sendTelegramMessage($adminChatId, $logText);

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

// ----------------- TELEGRAM FUNCTION -----------------
function sendTelegramMessage($chatId, $text, $parseMode = null): void {
    global $config;

    $token = $config['telegram']['bot_token'];
    $url   = "https://api.telegram.org/bot{$token}/sendMessage";

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
    $url   = "https://api.telegram.org/bot{$token}/sendChatAction";

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

// ----------------- DB CONNECTION -----------------
try {
    $db = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']}",
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


// ----------------- SYSTEM PROMPT -----------------
$systemPrompt = <<<EOT
You are a helpful AI assistant in telegram chat no wierd markup text. Answer clearly and concisely.my name is $firstName $lastName say hi 
EOT;

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


// Check if message is a command
if (handleCommand($chatId, $userText)) {
    exit; // stop further processing
}

if (!$user) {
    // New user
    $stmt = $db->prepare("INSERT INTO users (chat_id, username, first_name, last_name) VALUES (?, ?, ?, ?)");
    $stmt->execute([$senderId, $username, $firstName, $lastName]);
    $userModel = 'gpt-4.1-mini'; // default model

    $userId    = $db->lastInsertId();
    $isPro     = 0;
    $proExpire = null;

    // log to admin

    $logText = "New \n"
        . "User ID: {$userId} "
        . "Chat ID: {$chatId} "
        . "Name: {$firstName} {$lastName} "
        . "Username: @" . ($username ?: '—');
    sendTelegramMessage($adminChatId, $logText);

} else {
    $userId    = $user['id'];
    $isPro     = $user['is_pro'];
    $proExpire = $user['pro_expire'];
    $userModel = $user['model']; // gpt-4.1-mini (default) or selected model

    // Pro expiration check
    if ($isPro && $proExpire && strtotime($proExpire) < time()) {
        $isPro = 0; // pro expired
        $userModel = 'gpt-4.1-mini'; // default model
    }
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
            "You reached your daily limit of " . FREE_DAILY_LIMIT . " messages.\n"
            ."Upgrade to PRO for:  \n"
            ." • Consistent long-term chat memory \n"
            ." • Unlimited messages\n"
            ." • Advanced model selection 4.1, 4o, 5 and ... \n\n"
            ."Use /getpro to upgrade.");
        exit;
    }
}

// ----------------- BUILD CHAT HISTORY FOR GPT -----------------
$stmt = $db->prepare("
    SELECT type, message 
    FROM messages 
    WHERE chat_memory_id = ?
    ORDER BY id DESC 
    LIMIT " . (HISTORY_LIMIT + 10)
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
// AUTO-SUMMARIZE IF HISTORY > HISTORY_LIMIT
// ----------------------------------------------------

if (count($flatHistory) > HISTORY_LIMIT) {

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
sendTelegramMessage($adminChatId, "{$firstName} {$lastName} @{$username} count: {$totalWords}");


// ----------------- SEND TO GPT -----------------
$gptReply = sendToGPT($messagesForGPT);

// ----------------- SAVE MESSAGES -----------------
$stmt = $db->prepare("INSERT INTO messages (user_id, chat_memory_id, message, type) VALUES (?, ?, ?, 'USER')");
$stmt->execute([$userId, $memoryOwnerId, $userText]);

$stmt = $db->prepare("INSERT INTO messages (user_id, chat_memory_id, message, type) VALUES (?, ?, ?, 'AI')");
$stmt->execute([$userId, $memoryOwnerId, $gptReply]);


// ----------------- SEND REPLY BACK TO TELEGRAM -----------------
sendTelegramMessage($chatId, $gptReply);

?>
