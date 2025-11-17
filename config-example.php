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
        'bot_username' => 'my_bot',
        'admin_chatid' => '132465748',
    ],
    'gpt' => [
        'api_key' => 'YOUR_METIS_API_KEY',
        'base_url' => 'https://api.metisai.ir/api/v1/chat',
    ]
];
?>
