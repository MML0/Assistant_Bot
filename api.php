<?php

$config = require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// ----------------- CONFIG CONSTANTS -----------------
const FREE_DAILY_LIMIT = 9;      // free user daily message limit
const HISTORY_LIMIT    = 10;     // how many past messages to send to AI

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
function sendToGPT(array $messages): string
{
    global $config;

    $apiKey  = $config['gpt']['api_key'];
    $baseUrl = rtrim($config['gpt']['base_url'], '/'); // e.g. https://api.metisai.ir/openai/v1

    $client = new Client([
        'base_uri' => $baseUrl . '/',
        'timeout'  => 30,
    ]);

    try {
        $response = $client->post('chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                // 'model'       => 'gpt-4o', // or your desired model
                'model'       => 'gpt-4.1-mini', // or your desired model
                'messages'    => $messages,
                'max_tokens'  => 200,
            ],
        ]);

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (isset($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }

        return 'No text in response. Raw body: ' . $body;

    } catch (RequestException $e) {
        if ($e->hasResponse()) {
            $status  = $e->getResponse()->getStatusCode();
            $content = (string) $e->getResponse()->getBody();
            return "HTTP error {$status}: {$content}";
        }
        return 'Request error: ' . $e->getMessage();
    } catch (\Throwable $e) {
        return 'Unexpected error: ' . $e->getMessage();
    }
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
if (!isset($update['message'])) {
    exit;
}

$chatId     = $update['message']['chat']['id'];
$username   = $update['message']['from']['username'] ?? null;
$firstName  = $update['message']['from']['first_name'] ?? null;
$lastName   = $update['message']['from']['last_name'] ?? null;
$userText   = $update['message']['text'] ?? '';

// makeUserPro(null, $chatId, null); // make prooooooooooooo
// ----------------- SYSTEM PROMPT -----------------
$systemPrompt = <<<EOT
You are a helpful AI assistant. Answer clearly and concisely.my name is $firstName $lastName
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

    $userId    = $db->lastInsertId();
    $isPro     = 0;
    $proExpire = null;
} else {
    $userId    = $user['id'];
    $isPro     = $user['is_pro'];
    $proExpire = $user['pro_expire'];

    // Pro expiration check
    if ($isPro && $proExpire && strtotime($proExpire) < time()) {
        $isPro = 0; // pro expired
    }
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
            "You reached your daily limit of " . FREE_DAILY_LIMIT . " messages. Buy the pro plan to continue."
        );
        exit;
    }
}

// ----------------- BUILD CHAT HISTORY FOR GPT -----------------
$stmt = $db->prepare("
    SELECT type, message 
    FROM messages 
    WHERE user_id = ?
    ORDER BY id DESC 
    LIMIT " . HISTORY_LIMIT
);
$stmt->execute([$userId]);
$historyRows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

// Start messages array with system prompt
$messagesForGPT = [
    [
        'role'    => 'system',
        'content' => $systemPrompt,
    ],
];

// Add previous conversation
foreach ($historyRows as $row) {
    if ($row['type'] === 'USER') {
        $messagesForGPT[] = [
            'role'    => 'user',
            'content' => $row['message'],
        ];
    } else { // 'AI'
        $messagesForGPT[] = [
            'role'    => 'assistant',
            'content' => $row['message'],
        ];
    }
}

// Add current user message at the end
$messagesForGPT[] = [
    'role'    => 'user',
    'content' => $userText,
];

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
