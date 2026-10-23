<?php
// Configuration settings config.php (copy to config.php; never commit config.php)
// Secrets may also come from environment variables, which take precedence:
//   OPENROUTER_API_KEY, TRANSCRIPTION_API_KEY, BOT_ROUTING_MODE, BOT_PAID_FALLBACK_ENABLED
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
        'admin_chatid' => '132465748',   // also the only account allowed to /grantpremium
    ],
    'gpt' => [
        // Existing paid API (MetisAI, OpenAI-compatible). Used only as fallback / when policy says so.
        'api_key' => 'YOUR_METIS_API_KEY',
        'base_url' => 'https://api.metisai.ir/openai/v1',
        // Free route (OpenRouter). Prefer the OPENROUTER_API_KEY env var for the key.
        'free_api_key' => getenv('OPENROUTER_API_KEY') ?: '',
        'free_base_url' => 'https://openrouter.ai/api/v1',
        'free_model' => '',              // optional seed model used until the first discovery
    ],

    // ---- Everything below is optional; defaults live in src/Settings.php ----
    'routing' => [
        'mode' => 'free_first',          // free_first | quality_first | cost_min | paid_only
        'max_free_attempts' => 3,        // free models tried per message (one attempt each)
        'total_deadline_seconds' => 40,
        'pro_model_choice_is_explicit_paid' => false, // true: PRO users' /setmodel choice goes straight to paid
        'reserved_model_patterns' => [], // e.g. ['deepseek/*'] keep for hard/coding tasks
    ],
    'paid' => [
        'fallback_enabled' => true,
        'max_requests_per_day' => 300,
        'max_cost_per_day_usd' => 2.0,   // estimated from the price table in src/Settings.php
        'max_output_tokens' => 700,
    ],
    'transcription' => [
        // Premium-only voice-to-text. Existing provider: GapGPT Whisper (paid per minute).
        'api_key' => getenv('TRANSCRIPTION_API_KEY') ?: '',
        'base_url' => 'https://api.gapgpt.app/v1',
        'model' => 'gapgpt/whisper-1',
        'max_duration_seconds' => 300,
        'max_minutes_per_day' => 30,     // per premium user
        'max_requests_per_hour' => 20,   // per premium user
    ],
];
?>
