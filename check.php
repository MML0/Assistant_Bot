<?php
// ============================================================================
// check.php — deployment check (admin only). Spends NO credits and NO free quota
// unless you add &chat=1 (one tiny free-model request).
//
//   https://YOUR-HOST/PATH/check.php?pass=ADMIN_PASSWORD
//   https://YOUR-HOST/PATH/check.php?pass=ADMIN_PASSWORD&chat=1
//   https://YOUR-HOST/PATH/check.php?pass=ADMIN_PASSWORD&setwebhook=1   ← points the bot to THIS folder
// ============================================================================
$config = require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (PHP_SAPI !== 'cli') {
    if (!hash_equals((string) $config['admin']['password'], (string) ($_GET['pass'] ?? ''))) {
        http_response_code(403);
        die("❌ Wrong admin password.\n");
    }
} else {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}
@set_time_limit(120);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/bootstrap.php';

$ok = function (bool $cond, string $label, string $extra = '') {
    echo ($cond ? '✔ ' : '❌ ') . $label . ($extra !== '' ? " — {$extra}" : '') . "\n";
    return $cond;
};
$services = new Bot\Services($config);
$s = $services->settings;
$http = new GuzzleHttp\Client(['http_errors' => false, 'timeout' => 15, 'connect_timeout' => 6]);

echo "== Server\n";
$ok(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION, PHP_VERSION_ID >= 80200 ? '' : 'PHP 8.2+ is required by vendor/');
foreach (['curl', 'pdo_mysql', 'mbstring', 'json'] as $ext) {
    $ok(extension_loaded($ext), "extension {$ext}");
}
$ok(is_dir($s['storage_dir']) && is_writable($s['storage_dir']), 'storage/ writable', $s['storage_dir']);

echo "\n== Database\n";
try {
    $db = new PDO("mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
        $config['database']['username'], $config['database']['password']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ok(true, 'connected');
    $p = $s['table_prefix'];
    foreach (['users', 'messages', 'payments', 'premium_entitlements', 'voice_usage'] as $t) {
        try {
            $n = (int) $db->query("SELECT COUNT(*) FROM {$p}{$t}")->fetchColumn();
            $ok(true, "{$p}{$t}", "{$n} rows");
        } catch (Throwable $e) {
            $ok(false, "{$p}{$t}", 'missing → run migrate.php');
        }
    }
} catch (Throwable $e) {
    $ok(false, 'connect', Bot\Redactor::redact($e->getMessage(), 200));
}

echo "\n== Bale\n";
$base = rtrim($config['telegram']['api_base'] ?? 'https://tapi.bale.ai', '/');
$token = $config['telegram']['bot_token'];
try {
    $me = json_decode((string) $http->get("{$base}/bot{$token}/getMe")->getBody(), true);
    $ok(!empty($me['ok']), 'bot token', '@' . ($me['result']['username'] ?? '?'));
    $wh = json_decode((string) $http->get("{$base}/bot{$token}/getWebhookInfo")->getBody(), true);
    echo "  current webhook: " . ($wh['result']['url'] ?? '(unknown)') . "\n";
} catch (Throwable $e) {
    $ok(false, 'Bale API reachable', Bot\Redactor::redact($e->getMessage(), 200));
}
$ok(!empty($config['payment']['provider_token']) && $config['payment']['provider_token'] !== 'WALLET-CHANGE-ME', "payment.provider_token set in config.php", 'needed for /buypro');

if (!empty($_GET['setwebhook']) && PHP_SAPI !== 'cli') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/api.php';
    $r = json_decode((string) $http->get("{$base}/bot{$token}/setWebhook", ['query' => ['url' => $url]])->getBody(), true);
    $ok(!empty($r['ok']), "setWebhook → {$url}", $r['description'] ?? '');
}

echo "\n== OpenRouter (free models)\n";
$ok($s['openrouter']['api_key'] !== '', 'API key configured');
$refreshed = $services->catalog->refresh();
$state = $services->catalog->state();
if ($ok($refreshed, 'model list downloaded', $refreshed ? count($state['models'] ?? []) . ' free models' : ($state['last_error'] ?? 'OpenRouter not reachable from this host'))) {
    $free = array_column($state['models'] ?? [], null, 'id');
    echo "  free tier key: " . var_export($state['key_info']['is_free_tier'] ?? null, true) . " (true = 50 free requests/day, false = 1000/day)\n";
    foreach ($s['tiers'] as $key => $tier) {
        echo "  {$tier['label']} ({$key}):\n";
        foreach ($tier['free_models'] as $id) {
            $ok(isset($free[$id]), "    {$id}", isset($free[$id]) ? 'free, ctx ' . $free[$id]['context_length'] : 'not listed as free right now (skipped automatically)');
        }
    }
}
if (!empty($_GET['chat'])) {
    // cost_min = never paid; one attempt only
    $freeOnly = new Bot\Services(Bot\Settings::mergeDeep($config, ['routing' => ['mode' => 'cost_min', 'max_free_attempts' => 1]]));
    $r = $freeOnly->router->complete([['role' => 'user', 'content' => 'سلام! فقط با یک کلمه جواب بده: تست']], ['tier' => $s['default_tier']]);
    $ok($r['ok'] && $r['tier'] === 'free', 'one free chat request', ($r['model'] ?? '-') . ' → ' . mb_substr((string) $r['content'], 0, 60));
}

echo "\n== Paid API (MetisAI) — no tokens used\n";
try {
    $res = $http->get(rtrim($config['gpt']['base_url'], '/') . '/models', ['headers' => ['Authorization' => 'Bearer ' . $config['gpt']['api_key']]]);
    $ok($res->getStatusCode() < 500 && $res->getStatusCode() !== 401, 'reachable', 'HTTP ' . $res->getStatusCode());
} catch (Throwable $e) {
    $ok(false, 'reachable', Bot\Redactor::redact($e->getMessage(), 200));
}

echo "\n== Voice-to-text (GapGPT)\n";
$t = $s['transcription'];
if ($ok($t['api_key'] !== '', 'API key configured', "model {$t['model']}")) {
    try {
        $res = $http->get(rtrim($t['base_url'], '/') . '/models', ['headers' => ['Authorization' => 'Bearer ' . $t['api_key']]]);
        $ok($res->getStatusCode() < 500 && $res->getStatusCode() !== 401, 'reachable', 'HTTP ' . $res->getStatusCode());
    } catch (Throwable $e) {
        $ok(false, 'reachable', Bot\Redactor::redact($e->getMessage(), 200));
    }
}

echo "\n== Routing stats (today)\n" . $services->statsText(1) . "\n";
