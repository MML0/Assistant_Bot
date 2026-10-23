<?php

set_time_limit(0);

$token = "CHANGE_ME_BOT_TOKEN";
$api = "https://tapi.bale.ai/bot$token/";

// -------- TEMP FOLDER ----------
$tempDir = __DIR__ . "/temp/";
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// -------- LOG FUNCTION ----------
function logError($msg) {
    file_put_contents("error_log.txt", date("Y-m-d H:i:s") . " - $msg\n", FILE_APPEND);
}

// -------- SEND TEXT ----------
function sendMessage($chat_id, $text) {
    global $api;

    file_get_contents($api . "sendMessage?" . http_build_query([
        "chat_id" => $chat_id,
        "text" => $text
    ]));
}

// -------- GET INPUT ----------
$update = json_decode(file_get_contents("php://input"), true);

if (!$update) {
    exit;
}

// -------- HANDLE MESSAGE ----------
if (isset($update['message'])) {

    $message = $update['message'];
    $chat_id = $message['chat']['id'];

    // Encode chat_id in Base64
    $encoded = base64_encode($chat_id);

    $reply = "I can't transfer backwards.\n\n";
    $reply .= "send this ID in other side for connection :\n";
    $reply .= "🆔 Your ID :\n";
    $reply .= $encoded;

    sendMessage($chat_id, $reply);
}