<?php

$config = require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/commands.php';
require __DIR__ . '/buttons.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// ----------------- CONFIG CONSTANTS -----------------
const FREE_DAILY_LIMIT = 4;      // free user daily message limit
const HISTORY_LIMIT    = 12;     // how many past messages to send to AI

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
function sendToGPT(array $messages): string{
    global $config;

    $apiKey  = $config['gpt']['api_key'];
    $baseUrl = rtrim($config['gpt']['base_url'], '/'); // e.g. https://api.metisai.ir/openai/v1

    $client = new Client([
        'base_uri' => $baseUrl . '/',
        'timeout'  => 5,
    ]);

    $maxAttempts = 3;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $response = $client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'      => 'gpt-4.1-mini',
                    'messages'   => $messages,
                    'max_tokens' => 200,
                ],
            ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (isset($data['choices'][0]['message']['content'])) {
                return $data['choices'][0]['message']['content'];
            }

            return 'No text in response. Raw body: ' . $body;

        } catch (RequestException $e) {
            // If last attempt, return error
            if ($attempt === $maxAttempts) {
                if ($e->hasResponse()) {
                    $status  = $e->getResponse()->getStatusCode();
                    $content = (string) $e->getResponse()->getBody();
                    return "HTTP error {$status}: {$content}";
                }
                return 'Request error: ' . $e->getMessage();
            }

            // Small backoff before retry
            usleep(200000 * $attempt); // 0.2s, 0.4s, 0.6s
        } catch (\Throwable $e) {
            if ($attempt === $maxAttempts) {
                return 'Unexpected error: ' . $e->getMessage();
            }
            usleep(20000 * $attempt);
        }
    }

    // Should never reach here
    return 'Unknown error while calling GPT.';
}

// Summarize the current history using GPT
function summarize_history(array $messageHistory): string
{
    // Build a single prompt like in your Python version
    $prompt = implode("\n", $messageHistory)
        . "\nChatbot: Please summarize the conversation so far, focusing on the key details and important points. "
        . "Ensure that no critical information is lost and that the summary preserves the meaning and flow of the conversation.";

    // Use your chat API function (expects role-based messages)
    $messages = [
        ['role' => 'system', 'content' => 'You are a helpful assistant that summarizes conversations.'],
        ['role' => 'user',   'content' => $prompt],
    ];

    return sendToGPT($messages);
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
function makeUserPro(?int $userId = null, $chatId = null, ?string $expireAt = null): bool
{
    global $db;

    if ($userId === null && $chatId === null) {
        return false; // need at least one identifier
    }

    // Decide which column to use
    if ($userId !== null) {
        $sql  = "UPDATE users SET is_pro = 1, pro_expire = :expireAt WHERE id = :id";
        $params = [
            ':expireAt' => $expireAt,
            ':id'       => $userId,
        ];
    } else {
        $sql  = "UPDATE users SET is_pro = 1, pro_expire = :expireAt WHERE chat_id = :chatId";
        $params = [
            ':expireAt' => $expireAt,
            ':chatId'   => $chatId,
        ];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount() > 0;
}

// ----------------- TELEGRAM FUNCTION -----------------
function sendTelegramMessage($chatId, $text): void
{
    global $config;

    $token = $config['telegram']['bot_token'];
    $url   = "https://api.telegram.org/bot{$token}/sendMessage";

    $data = [
        'chat_id' => $chatId,
        'text'    => $text,
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



// Check if message is a command
if (handleCommand($chatId, $userText)) {
    exit; // stop further processing
}

// ----------------- SYSTEM PROMPT -----------------
$systemPrompt = <<<EOT
You are a helpful AI assistant. Answer clearly and concisely.my name is $firstName $lastName say hi 
EOT;

if (!$userText) {
    exit;
}

// ----------------- USER HANDLING -----------------
$stmt = $db->prepare("SELECT * FROM users WHERE chat_id = ?");
$stmt->execute([$chatId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // New user
    $stmt = $db->prepare("INSERT INTO users (chat_id, username, first_name, last_name) VALUES (?, ?, ?, ?)");
    $stmt->execute([$chatId, $username, $firstName, $lastName]);
    $userModel = 'gpt-4.1-mini'; // default model

    $userId    = $db->lastInsertId();
    $isPro     = 0;
    $proExpire = null;
} else {
    $userId    = $user['id'];
    $isPro     = $user['is_pro'];
    $proExpire = $user['pro_expire'];
    $userModel = $user['model']; // gpt-4.1-mini (default) or selected model

    // Pro expiration check
    if ($isPro && $proExpire && strtotime($proExpire) < time()) {
        $isPro = 0; // pro expired
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

    sendTelegramMessage(
        $chatId,
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
            "You reached your daily limit of " . FREE_DAILY_LIMIT . " messages.
Upgrade to PRO for:
 • Consistent long-term chat memory 
 • Unlimited messages
 • Advanced model selection  4.1, 4o, 5, 5.1 and more. 
Use /getpro to upgrade.");
        exit;
    }
}

// ----------------- BUILD CHAT HISTORY FOR GPT -----------------
$stmt = $db->prepare("
    SELECT type, message 
    FROM messages 
    WHERE user_id = ?
    ORDER BY id DESC 
    LIMIT " . (HISTORY_LIMIT + 100)
);
$stmt->execute([$userId]);
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
if ($isPro) {
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
            $flatHistory[] = $row['message'];

            continue;
        }

        if ($row['type'] === 'USER') {
            $messagesForGPT[] = [
                'role'    => 'user',
                'content' => $row['message'],
            ];
            $flatHistory[] = $row['message'];

        } elseif ($row['type'] === 'AI') {
            $messagesForGPT[] = [
                'role'    => 'assistant',
                'content' => $row['message'],
            ];
            $flatHistory[] = $row['message'];
        }
    }
}

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
    $stmt = $db->prepare("INSERT INTO messages (user_id, message, type) VALUES (?, ?, 'SUMMARY')");
    $stmt->execute([$userId, $summary]);

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
            'content' => "Continuing: " . $userText
        ],
    ];
}




// ----------------- SEND TO GPT -----------------
$gptReply = sendToGPT($messagesForGPT);

// ----------------- SAVE MESSAGES -----------------
$stmt = $db->prepare("INSERT INTO messages (user_id, message, type) VALUES (?, ?, 'USER')");
$stmt->execute([$userId, $userText]);

$stmt = $db->prepare("INSERT INTO messages (user_id, message, type) VALUES (?, ?, 'AI')");
$stmt->execute([$userId, $gptReply]);

// ----------------- SEND REPLY BACK TO TELEGRAM -----------------
sendTelegramMessage($chatId, $gptReply);

?>
