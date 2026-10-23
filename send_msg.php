<?php
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

$config = require 'config.php';

$chatId = $_GET['chatid'] ?? null;
$text = $_GET['text'] ?? null;
$input = $_GET['pass'] ?? '';

if ($input !== $config['admin']['password']) {
    http_response_code(403);
    echo json_encode(['error' => 'Wrong admin password.']);
    exit;
}

if (!$chatId || !$text) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing chatid or text parameter.']);
    exit;
}

header('Content-Type: application/json');

try {
    sendTelegramMessage($chatId, $text);
    echo json_encode(['success' => true, 'message' => 'Message sent.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message: ' . $e->getMessage()]);
}

exit;
