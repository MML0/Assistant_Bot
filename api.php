<?php
$config = require 'config.php';
use OpenAI\Client;

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

    if ($count_today >= 13) {
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

// ----------------- Send to GPT -----------------
$gpt_reply = sendToGPT($history);

// ----------------- Save messages -----------------
$stmt = $db->prepare("INSERT INTO messages (user_id, message, type) VALUES (?, ?, 'USER')");
$stmt->execute([$user_id, $user_message]);

$stmt = $db->prepare("INSERT INTO messages (user_id, message, type) VALUES (?, ?, 'AI')");
$stmt->execute([$user_id, $gpt_reply]);

// ----------------- Send reply back to Telegram -----------------
sendTelegramMessage($chat_id, $gpt_reply);

// ----------------- Functions -----------------


function sendToGPT($message_history, $model = "gpt-4.1-mini") {
    global $config;

    $api_key = $config['gpt']['api_key'];

    // Initialize OpenAI client
    $client = \OpenAI::client($api_key);

    // Combine all messages into a single input string
    $input_text = implode("\n", $message_history);

    // Send request using the Responses API
    $response = $client->responses()->create([
        'model' => $model,
        'input' => $input_text,
    ]);

    // Return the output text
    return $response->outputText ?? "No reply from GPT.";
}



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
