<?php
// Configuration settings config.php 
return [
    'database' => [
        'host' => 'localhost',
        'dbname' => 'tegramdb',
        'username' => 'root',
        'password' => '',
    ],
    'admin' => [
        'password' => 'admin123',
    ],
    'telegram' => [
        'bot_token' => 'YOUR_TELEGRAM_BOT_TOKEN',
    ],
    'gpt' => [
        'api_key' => 'YOUR_METIS_API_KEY',
        'base_url' => 'https://api.metisai.ir/api/v1/chat',
        'bot_id' => 'YOUR_BOT_ID'
    ]
];
?>
