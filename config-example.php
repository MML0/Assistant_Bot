<?php
// config.php — copy this file to config.php and fill in your values. Never commit config.php.
// Only the first four blocks existed before; everything after them is new and optional
// except 'payment' (needed for /buypro) and 'transcription.api_key' (needed for voice).
return [
    'database' => [
        'host' => 'localhost',
        'dbname' => 'YOUR_DB',
        'username' => 'YOUR_DB_USER',
        'password' => 'YOUR_DB_PASSWORD',
        'table_prefix' => 'v2_',          // new tables; the old `users`/`messages` stay untouched
    ],
    'admin' => [
        'password' => 'CHANGE_ME',        // used by migrate.php, check.php, get_users.php, send_msg.php
    ],
    'telegram' => [                       // Bale bot (Telegram-compatible API)
        'bot_token' => 'YOUR_BALE_BOT_TOKEN',
        'bot_username' => 'your_bot',
        'admin_chatid' => '123456789',    // your own chat id: admin alerts + /grantpremium, /routingstats
        // 'api_base' => 'https://tapi.bale.ai',
    ],
    'gpt' => [
        // Paid API (MetisAI, OpenAI-compatible): fallback when free models fail
        'api_key' => 'YOUR_METIS_API_KEY',
        'base_url' => 'https://api.metisai.ir/openai/v1',
        // Free models (OpenRouter)
        'free_api_key' => 'YOUR_OPENROUTER_KEY',
        'free_base_url' => 'https://openrouter.ai/api/v1',
    ],

    // ---------------- new ----------------
    'payment' => [
        'provider_token' => 'WALLET-XXXXXXXX',   // Bale wallet token (was hard-coded in buttons.php)
    ],
    'transcription' => [
        'api_key' => 'YOUR_GAPGPT_KEY',          // voice-to-text (was hard-coded in the old snippet)
        // 'base_url' => 'https://api.gapgpt.app/v1',
        // 'model' => 'gapgpt/whisper-1',
        // 'max_minutes_per_day' => 30,           // per premium user
        // 'allow_referral_pro' => false,         // true = referral/free PRO users may also use voice
    ],
    'monitor_chat_id' => 617483778,              // receives copies of chats/media (as before)
    // 'monitor_forward_media' => true,
    // 'ir_side' => ['bot_token' => ''],          // only for ir_side_transport.php

    // Optional tuning (defaults in src/Settings.php):
    // 'routing' => ['mode' => 'free_first'],     // free_first | quality_first | cost_min | paid_only
    // 'routing' => ['mode' => 'paid_only'],     // every answer from MetisAI, exactly like the old bot
    // 'paid' => ['max_requests_per_day' => 0, 'max_cost_per_day_usd' => 0],  // daily caps; 0 = no limit (default)
    // 'tiers' => ['extra' => ['free_models' => ['nvidia/nemotron-3-ultra-550b-a55b:free'], 'paid_model' => 'gpt-4.1']],
];
