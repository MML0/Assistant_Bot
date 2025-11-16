<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

function sendToGPT($input): string{
    global $config;

    $apiKey  = $config['gpt']['api_key'];
    $baseUrl = rtrim($config['gpt']['base_url'], '/'); // https://api.metisai.ir/openai/v1

    $client = new Client([
        'base_uri' => $baseUrl . '/', // -> https://api.metisai.ir/openai/v1/
        'timeout'  => 30,
    ]);

    // 👇 If it's an array, just join everything with newlines
    if (is_array($input)) {
        $message = implode("\n", $input);
    } else {
        $message = (string) $input;
    }

    try {
        // POST https://api.metisai.ir/openai/v1/chat/completions
        $response = $client->post('chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'    => 'gpt-4o', // or 'gpt-4o-mini' or whatever Metis gives you
                'messages' => [
                    ['role' => 'user', 'content' => $message],
                ],
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


// Connect to DB
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

// System prompt
$system_prompt = <<<EOT
You are an AI be help full
EOT;

// Get incoming Telegram message
$update = json_decode(file_get_contents('php://input'), true);
if (!isset($update['message'])) exit;

$chat_id = $update['message']['chat']['id'];
$username = $update['message']['from']['username'] ?? null;
$first_name = $update['message']['from']['first_name'] ?? null;
$last_name = $update['message']['from']['last_name'] ?? null;
$user_message = $update['message']['text'] ?? '';

if (!$user_message) exit;

// ----------------- User Handling -----------------

// Check if user exists
$stmt = $db->prepare("SELECT * FROM users WHERE chat_id = ?");
$stmt->execute([$chat_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Create new user
    $stmt = $db->prepare("INSERT INTO users (chat_id, username, first_name, last_name) VALUES (?, ?, ?, ?)");
    $stmt->execute([$chat_id, $username, $first_name, $last_name]);
    $user_id = $db->lastInsertId();
    $is_pro = 0;
    $pro_expire = null;
} else {
    $user_id = $user['id'];
    $is_pro = $user['is_pro'];
    $pro_expire = $user['pro_expire'];

    // Check pro expiration
    if ($is_pro && $pro_expire && strtotime($pro_expire) < time()) {
        $is_pro = 0; // pro expired
    }
}

// ----------------- Daily Message Limit -----------------
if (!$is_pro) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND DATE(created_at) = CURDATE() AND type='USER'");
    $stmt->execute([$user_id]);
    $count_today = $stmt->fetchColumn();

    if ($count_today >= 9) {
        sendTelegramMessage($chat_id, "You reached your daily limit of 3 messages. Buy the pro plan to continue.");
        exit;
    }
}

// ----------------- Get Last 10 Messages -----------------
$stmt = $db->prepare("SELECT type, message FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT 10");
$stmt->execute([$user_id]);
$history_rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

$history = [$system_prompt];
foreach ($history_rows as $row) {
    $prefix = $row['type'] === 'USER' ? "User: " : "AI: ";
    $history[] = $prefix . $row['message'];
}

// Add current user message
$history[] = "User: " . $user_message;
// sendTelegramMessage($chat_id, "hey !");

// ----------------- Send to GPT -----------------
$gpt_reply = sendToGPT($history);

// ----------------- Save messages -----------------
$stmt = $db->prepare("INSERT INTO messages (user_id, message, type) VALUES (?, ?, 'USER')");
$stmt->execute([$user_id, $user_message]);

$stmt = $db->prepare("INSERT INTO messages (user_id, message, type) VALUES (?, ?, 'AI')");
$stmt->execute([$user_id, $gpt_reply]);

// ----------------- Send reply back to Telegram -----------------
sendTelegramMessage($chat_id, $gpt_reply);




function sendTelegramMessage($chat_id, $text) {
    global $config;
    $token = $config['telegram']['bot_token'];
    $url = "https://api.telegram.org/bot$token/sendMessage";

    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}
?>
