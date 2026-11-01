<?php
// Admin dashboard. One password (config.php → admin.password), remembered by a cookie.
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex');

$services = new Bot\Services($config);
$s = $services->settings;
$password = (string) ($config['admin']['password'] ?? '');
const COOKIE = 'bot_admin';
const COOKIE_YEARS = 1000;

// The cookie is derived from the password, so changing the password signs every device out.
$signature = hash_hmac('sha256', 'dashboard-session', $password);
$csrf = hash_hmac('sha256', 'dashboard-csrf', $password);
$isAuthed = $password !== '' && hash_equals($signature, (string) ($_COOKIE[COOKIE] ?? ''));

function rememberAdmin(string $value): void {
    // Browsers may shorten long cookies (Chrome: 400 days), so every visit renews it.
    setcookie(COOKIE, $value, [
        'expires' => time() + COOKIE_YEARS * 31536000,
        'path' => dirname($_SERVER['SCRIPT_NAME']) === '/' ? '/' : dirname($_SERVER['SCRIPT_NAME']) . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ------------------------------ login ------------------------------
if (isset($_GET['logout']) && $isAuthed && hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
    setcookie(COOKIE, '', ['expires' => 1, 'path' => dirname($_SERVER['SCRIPT_NAME']) . '/']);
    header('Location: ' . basename(__FILE__));
    exit;
}
$loginError = '';
if (!$isAuthed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    $ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $tries = array_filter($services->store->read('admin_login.json', [])[$ip] ?? [], fn ($t) => $t > time() - 900);
    if (count($tries) >= 10) {
        $loginError = 'Too many tries. Wait 15 minutes.';
    } elseif ($password !== '' && hash_equals($password, (string) $_POST['password'])) {
        rememberAdmin($signature);
        header('Location: ' . basename(__FILE__));
        exit;
    } else {
        $tries[] = time();
        $services->store->update('admin_login.json', function (&$d) use ($ip, $tries) {
            $d[$ip] = array_values($tries);
        });
        $loginError = 'Wrong password.';
    }
}
if (!$isAuthed) {
    if (isset($_GET['api'])) {
        http_response_code(401);
        exit;
    }
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bot Admin</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(circle at 20% 10%,#3b2a8c,#0b0d1a 55%);font:16px system-ui;color:#eef}form{padding:32px;border-radius:20px;background:rgba(255,255,255,.08);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.15);width:min(320px,calc(100vw - 32px));box-sizing:border-box}h1{font-size:20px;margin:0 0 16px}input,button{width:100%;box-sizing:border-box;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.2);font:inherit}input{background:rgba(0,0,0,.25);color:#fff;margin-bottom:12px}button{background:#7c5cff;color:#fff;border:0;cursor:pointer;font-weight:600}.e{color:#ff9aa8;font-size:14px;margin-bottom:10px}</style></head>
<body><form method="post"><h1>🤖 Bot Admin</h1><?php if ($loginError): ?><div class="e"><?= htmlspecialchars($loginError) ?></div><?php endif; ?><input type="password" name="password" placeholder="Admin password" autofocus autocomplete="current-password"><button>Sign in</button></form></body></html><?php
    exit;
}
rememberAdmin($signature);

// ------------------------------ data ------------------------------
function dashDb(array $config): PDO {
    $d = $config['database'];
    return new PDO("mysql:host={$d['host']};dbname={$d['dbname']};charset=utf8mb4", $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function hostStats(): array {
    $mem = null;
    if (@is_readable('/proc/meminfo') && preg_match('/MemTotal:\s+(\d+).*?MemAvailable:\s+(\d+)/s', (string) @file_get_contents('/proc/meminfo'), $m)) {
        $mem = ['total_mb' => (int) ($m[1] / 1024), 'used_mb' => (int) (($m[1] - $m[2]) / 1024)];
    }
    $cpus = @is_readable('/proc/cpuinfo') ? substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor') : null;
    return [
        'load' => function_exists('sys_getloadavg') ? (@sys_getloadavg() ?: null) : null,
        'cpus' => $cpus ?: null,
        'mem' => $mem,
        'disk_free_gb' => round((float) @disk_free_space(__DIR__) / 1073741824, 1),
        'disk_total_gb' => round((float) @disk_total_space(__DIR__) / 1073741824, 1),
        'php' => PHP_VERSION,
    ];
}

function jsonOut($data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// A short silent clip, so the voice test needs no file. A provider that accepts it is working.
function silentWav(): string {
    $data = str_repeat("\0\0", 16000);
    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
}

// A red square, drawn here so the vision test needs no file.
function redSquarePng(int $size = 64): string {
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $rows = str_repeat("\0" . str_repeat("\xFF\x00\x00", $size), $size);
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($rows)) . $chunk('IEND', '');
}

function runTest(string $name, Bot\Services $services, array $config): array {
    $s = $services->settings;
    $media = new Bot\Media(null, $services->http, $s, $services->store);
    $start = microtime(true);
    $done = fn (bool $ok, string $detail, array $extra = []) => ['ok' => $ok, 'detail' => Bot\Redactor::redact($detail, 600), 'ms' => (int) ((microtime(true) - $start) * 1000)] + $extra;
    [$kind, $provider] = array_pad(explode(':', $name, 2), 2, null);
    switch ($kind) {
        case 'db':
            $n = dashDb($config)->query('SELECT COUNT(*) FROM ' . $s['table_prefix'] . 'media_usage')->fetchColumn();
            return $done(true, "Connected. media_usage has {$n} rows.");
        case 'bale':
            $res = $services->http->request('GET', rtrim($s['telegram_api_base'], '/') . "/bot{$config['telegram']['bot_token']}/getMe", ['http_errors' => false, 'timeout' => 15]);
            $me = json_decode((string) $res->getBody(), true);
            $hook = $services->http->request('GET', rtrim($s['telegram_api_base'], '/') . "/bot{$config['telegram']['bot_token']}/getWebhookInfo", ['http_errors' => false, 'timeout' => 15]);
            return $done(!empty($me['ok']), '@' . ($me['result']['username'] ?? '?') . ' · webhook ' . (json_decode((string) $hook->getBody(), true)['result']['url'] ?? 'unknown'));
        case 'text':
            $model = $s['tiers'][$s['default_tier']][$provider === 'gapgpt' ? 'gapgpt_models' : 'paid_models'][0] ?? 'gpt-4o-mini';
            $r = $services->textClient($provider)->complete([$model], [['role' => 'user', 'content' => 'Reply with the single word: ok']], microtime(true) + 40);
            return $done($r['ok'], $r['ok'] ? "{$r['model_used']}: " . mb_substr($r['content'], 0, 80) : "{$r['model_used']}: {$r['error']['class']} {$r['error']['detail']}");
        case 'openrouter':
            $res = $services->http->request('GET', rtrim($s['openrouter']['base_url'], '/') . '/models?limit=1', ['headers' => ['Authorization' => 'Bearer ' . $s['openrouter']['api_key']], 'http_errors' => false, 'timeout' => 20, 'connect_timeout' => 8]);
            return $done($res->getStatusCode() === 200, 'HTTP ' . $res->getStatusCode() . ' via ' . parse_url($s['openrouter']['base_url'], PHP_URL_HOST));
        case 'voice':
            $chain = Bot\Settings::mediaChain($s, 'voice', $provider);
            if (!$chain) {
                return $done(false, 'No model set for this provider.');
            }
            $res = $services->http->request('POST', rtrim($chain[0]['base_url'], '/') . '/audio/transcriptions', [
                'headers' => ['Authorization' => 'Bearer ' . $chain[0]['api_key']],
                'multipart' => [['name' => 'model', 'contents' => $chain[0]['model']], ['name' => 'file', 'contents' => silentWav(), 'filename' => 'test.wav', 'headers' => ['Content-Type' => 'audio/wav']]],
                'http_errors' => false, 'timeout' => 60,
            ]);
            return $done($res->getStatusCode() === 200, "{$chain[0]['model']}: HTTP {$res->getStatusCode()} " . mb_substr((string) $res->getBody(), 0, 200));
        case 'voice_real':
            return realVoiceTest($services, $config, $done);
        case 'vision':
            $png = redSquarePng();
            $r = $media->describeImage($png, 'image/png', 'What color is this image? One word.', $provider);
            return $done($r['ok'], $r['ok'] ? "{$r['model']}: " . mb_substr($r['text'], 0, 120) : implode(' | ', $r['errors']));
        case 'image':
            $r = $media->generateImage('A small red circle on a white background', $provider);
            return $done($r['ok'], $r['ok'] ? "{$r['model']}: " . strlen($r['bytes']) . ' bytes' : implode(' | ', $r['errors']), $r['ok'] ? ['image' => 'data:' . Bot\Media::imageMime($r['bytes']) . ';base64,' . base64_encode($r['bytes'])] : []);
    }
    return $done(false, 'Unknown test');
}

// Real speech, sent through Bale and handled by the same code as a user's voice message.
function realVoiceTest(Bot\Services $services, array $config, callable $done): array {
    $s = $services->settings;
    $gap = $s['providers']['gapgpt'] ?? null;
    if (!$gap) {
        return $done(false, 'GapGPT is not configured, and it makes the test speech.');
    }
    $spoken = 'سلام، این یک پیام آزمایشی برای تبدیل صدا به متن است.';
    $res = $services->http->request('POST', rtrim($gap['base_url'], '/') . '/audio/speech', [
        'headers' => ['Authorization' => 'Bearer ' . $gap['api_key']],
        'json' => ['model' => 'tts-1', 'voice' => 'alloy', 'response_format' => 'opus', 'input' => $spoken],
        'http_errors' => false, 'timeout' => 40,
    ]);
    if ($res->getStatusCode() !== 200) {
        return $done(false, 'Could not make test speech: HTTP ' . $res->getStatusCode());
    }
    $admin = (int) $config['telegram']['admin_chatid'];
    $base = rtrim($s['telegram_api_base'], '/') . "/bot{$config['telegram']['bot_token']}";
    $sent = $services->http->request('POST', "{$base}/sendVoice", [
        'multipart' => [
            ['name' => 'chat_id', 'contents' => (string) $admin],
            ['name' => 'caption', 'contents' => 'تست خودکار تبدیل صدا'],
            ['name' => 'voice', 'contents' => (string) $res->getBody(), 'filename' => 'voice.ogg', 'headers' => ['Content-Type' => 'audio/ogg']],
        ],
        'http_errors' => false, 'timeout' => 30,
    ]);
    $voice = json_decode((string) $sent->getBody(), true)['result']['voice'] ?? null;
    if (!$voice) {
        return $done(false, 'Bale did not accept the voice: ' . mb_substr((string) $sent->getBody(), 0, 200));
    }
    $replies = [];
    $outcome = $services->voice(dashDb($config), $config['telegram']['bot_token'], function ($to, $text) use (&$replies) {
        $replies[] = $text;
    })->withAccess(fn () => 30)->handle([
        'message_id' => 0, 'from' => ['id' => $admin, 'first_name' => 'test'], 'chat' => ['id' => $admin, 'type' => 'private'], 'voice' => $voice,
    ], random_int(1, 1 << 30));
    $meta = "Bale: {$voice['duration']}s, {$voice['mime_type']}, {$voice['file_size']} bytes";
    return $done($outcome === 'transcribed', "{$meta}. Result: {$outcome}. " . implode(' ', $replies));
}

function baleSend(Bot\Services $services, array $config, int $chatId, string $text, string $buttonText = '', string $buttonUrl = ''): array {
    $body = ['chat_id' => $chatId, 'text' => $text];
    if ($buttonText !== '') {
        $body['reply_markup'] = ['inline_keyboard' => [[['text' => $buttonText, 'url' => $buttonUrl]]]];
    }
    $res = $services->http->request('POST', rtrim($services->settings['telegram_api_base'], '/') . "/bot{$config['telegram']['bot_token']}/sendMessage", [
        'json' => $body, 'http_errors' => false, 'timeout' => 15, 'connect_timeout' => 5,
    ]);
    $json = json_decode((string) $res->getBody(), true);
    return ['ok' => !empty($json['ok']), 'error' => (string) ($json['description'] ?? ('HTTP ' . $res->getStatusCode()))];
}

// A glass button needs both its text and an https link. @return array{0:string,1:string}
function messageButton(array $input): array {
    $text = trim((string) ($input['button_text'] ?? ''));
    $url = trim((string) ($input['button_url'] ?? ''));
    if (($text === '') !== ($url === '') || ($url !== '' && !preg_match('~^https://\S+$~', $url)) || mb_strlen($text) > 64) {
        throw new \InvalidArgumentException('دکمه به متن (تا ۶۴ حرف) و لینکی که با https:// شروع شود نیاز دارد.');
    }
    return [$text, $url];
}

// Who a broadcast reaches. Banned users never get one.
function audienceSql(string $audience, array $plans, PDO $db): ?string {
    $on = "(u.is_pro=1 AND (u.pro_expire IS NULL OR u.pro_expire>NOW()))";
    $days = ['active_7' => 7, 'active_30' => 30];
    $sql = ['all' => '1=1', 'free' => "NOT {$on}", 'paying' => "{$on} AND u.plan IS NOT NULL", 'invited' => "{$on} AND u.plan IS NULL", 'expired' => "u.is_pro=1 AND u.pro_expire<=NOW()"][$audience] ?? null;
    if (isset($days[$audience])) {
        $sql = "EXISTS (SELECT 1 FROM {$GLOBALS['M']} m WHERE m.user_id = u.id AND m.type='USER' AND m.created_at >= DATE_SUB(NOW(), INTERVAL {$days[$audience]} DAY))";
    }
    if (str_starts_with($audience, 'plan_') && isset($plans[substr($audience, 5)])) {
        $sql = "{$on} AND u.plan=" . $db->quote(substr($audience, 5));
    }
    return $sql === null ? null : "u.baned=0 AND {$sql}";
}

// ------------------------------ API ------------------------------
if (isset($_GET['api'])) {
    $api = (string) $_GET['api'];
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !hash_equals($csrf, (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
            jsonOut(['error' => 'Page expired, reload it.'], 403);
        }
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $p = $s['table_prefix'];
        $U = $p . 'users';
        $M = $p . 'messages';

        if ($api === 'suite') {
            // The offline test suite: every provider is mocked, so it spends nothing.
            header('Content-Type: text/plain; charset=utf-8');
            @set_time_limit(120);
            require __DIR__ . '/tests/run.php';
            exit;
        }
        if ($api === 'test') {
            @set_time_limit(180);
            jsonOut(runTest((string) ($input['name'] ?? ''), $services, $config));
        }
        if ($api === 'settings') {
            jsonOut(Bot\AdminSettings::current($s) + ['providers' => array_keys($s['providers'])]);
        }
        if ($api === 'save') {
            $overlay = Bot\AdminSettings::sanitize($input);
            if (!Bot\AdminSettings::save($services->store, $overlay)) {
                jsonOut(['error' => 'Could not write to storage/.'], 500);
            }
            jsonOut(['ok' => true]);
        }
        if ($api === 'models') {
            $out = [];
            foreach ($s['providers'] as $name => $prov) {
                try {
                    $res = $services->http->request('GET', rtrim($prov['base_url'], '/') . '/models', ['headers' => ['Authorization' => 'Bearer ' . $prov['api_key']], 'http_errors' => false, 'timeout' => 20]);
                    $out[$name] = array_values(array_filter(array_column(json_decode((string) $res->getBody(), true)['data'] ?? [], 'id'), fn ($id) => !str_starts_with((string) $id, 'ft:')));
                    sort($out[$name]);
                } catch (\Throwable $e) {
                    $out[$name] = [];
                }
            }
            jsonOut($out);
        }

        $db = dashDb($config);
        if ($api === 'live') {
            $n = fn (string $sql) => (int) $db->query($sql)->fetchColumn();
            jsonOut([
                'online' => $n("SELECT COUNT(DISTINCT user_id) FROM {$M} WHERE type='USER' AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"),
                'active_today' => $n("SELECT COUNT(DISTINCT user_id) FROM {$M} WHERE type='USER' AND created_at >= CURDATE()"),
                'msg_5' => $n("SELECT COUNT(*) FROM {$M} WHERE type='USER' AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"),
                'last' => $db->query("SELECT MAX(created_at) FROM {$M} WHERE type='USER'")->fetchColumn(),
            ]);
        }
        if ($api === 'stats') {
            $n = fn (string $sql) => (int) $db->query($sql)->fetchColumn();
            $on = "is_pro=1 AND (pro_expire IS NULL OR pro_expire>NOW())";
            $since = "DATE_SUB(CURDATE(), INTERVAL 29 DAY)";
            $fill = function (string $sql, float $div = 1) use ($db): array {
                $have = [];
                foreach ($db->query($sql)->fetchAll() as $row) {
                    $have[$row['d']] = (float) $row['v'] / $div;
                }
                $out = [];
                for ($i = 29; $i >= 0; $i--) {
                    $day = date('Y-m-d', strtotime("-{$i} days"));
                    $out[] = ['d' => $day, 'v' => $have[$day] ?? 0];
                }
                return $out;
            };
            $titles = array_map(fn ($plan) => $plan['title'], $s['plans']);
            $plans = [];
            foreach ($db->query("SELECT plan, COUNT(*) c FROM {$U} WHERE {$on} GROUP BY plan ORDER BY c DESC")->fetchAll() as $row) {
                $plans[] = ['title' => $row['plan'] === null ? 'PRO دعوتی' : ($titles[$row['plan']] ?? $row['plan']), 'c' => (int) $row['c']];
            }
            // Text cost comes from the routing logs; voice and images from what they were used for.
            $textCost = 0.0;
            foreach (glob($s['storage_dir'] . '/logs/routing-*.jsonl') ?: [] as $file) {
                if (filemtime($file) >= time() - 30 * 86400) {
                    foreach (file($file) ?: [] as $line) {
                        $row = json_decode($line, true);
                        if (($row['type'] ?? '') === 'attempt') {
                            $textCost += (float) ($row['cost_usd'] ?? 0);
                        }
                    }
                }
            }
            $aiMs = 0;
            $aiCalls = 0;
            foreach (@file($s['storage_dir'] . '/logs/routing-' . date('Y-m-d') . '.jsonl') ?: [] as $line) {
                $row = json_decode($line, true);
                if (($row['type'] ?? '') === 'attempt' && !empty($row['success'])) {
                    $aiMs += (int) ($row['latency_ms'] ?? 0);
                    $aiCalls++;
                }
            }
            // Busy hours in Iran time. Saturday is day 0, as in the Persian week.
            $toIran = 12600 - (int) date('Z');
            $heat = $db->query("SELECT MOD(WEEKDAY(DATE_ADD(created_at, INTERVAL {$toIran} SECOND)) + 2, 7) w, HOUR(DATE_ADD(created_at, INTERVAL {$toIran} SECOND)) h, COUNT(*) c FROM {$M} WHERE type='USER' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 27 DAY) GROUP BY w, h")->fetchAll();
            $voiceCost = (float) $db->query("SELECT COALESCE(SUM(estimated_cost_usd),0) FROM {$p}voice_usage WHERE created_at >= {$since}")->fetchColumn();
            $mediaCost = (float) $db->query("SELECT COALESCE(SUM(CASE action WHEN 'image' THEN 0.008 ELSE 0.0004 END),0) FROM {$p}media_usage WHERE status='ok' AND created_at >= {$since}")->fetchColumn();
            jsonOut([
                'users' => $n("SELECT COUNT(*) FROM {$U}"),
                'new_today' => $n("SELECT COUNT(*) FROM {$U} WHERE created_at >= CURDATE()"),
                'paying' => $n("SELECT COUNT(*) FROM {$U} WHERE {$on} AND plan IS NOT NULL"),
                'invited' => $n("SELECT COUNT(*) FROM {$U} WHERE {$on} AND plan IS NULL"),
                'online' => $n("SELECT COUNT(DISTINCT user_id) FROM {$M} WHERE type='USER' AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"),
                'active_today' => $n("SELECT COUNT(DISTINCT user_id) FROM {$M} WHERE type='USER' AND created_at >= CURDATE()"),
                'active_yesterday' => $n("SELECT COUNT(DISTINCT user_id) FROM {$M} WHERE type='USER' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND created_at < CURDATE()"),
                'messages_today' => $n("SELECT COUNT(*) FROM {$M} WHERE type='USER' AND created_at >= CURDATE()"),
                'messages_yesterday' => $n("SELECT COUNT(*) FROM {$M} WHERE type='USER' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND created_at < CURDATE()"),
                'voice_today' => $n("SELECT COUNT(*) FROM {$p}voice_usage WHERE created_at >= CURDATE() AND status='ok'"),
                'revenue_today' => $n("SELECT COALESCE(SUM(amount),0) FROM {$p}payments WHERE status='paid' AND created_at >= CURDATE()"),
                'paid_today' => $n("SELECT COUNT(*) FROM {$p}payments WHERE status='paid' AND created_at >= CURDATE()"),
                'revenue_30d' => $n("SELECT COALESCE(SUM(amount),0) FROM {$p}payments WHERE status='paid' AND created_at >= {$since}"),
                'active_30' => $n("SELECT COUNT(DISTINCT user_id) FROM {$M} WHERE type='USER' AND created_at >= {$since}"),
                'avg_ms' => $aiCalls ? (int) ($aiMs / $aiCalls) : 0,
                'ai_calls' => $aiCalls,
                'heat' => $heat,
                'cost_30d' => round($textCost + $voiceCost + $mediaCost, 4),
                'usd_toman' => max(1, (int) $s['usd_toman']),
                'series' => [
                    'messages' => $fill("SELECT DATE(created_at) d, COUNT(*) v FROM {$M} WHERE type='USER' AND created_at >= {$since} GROUP BY d"),
                    'active' => $fill("SELECT DATE(created_at) d, COUNT(DISTINCT user_id) v FROM {$M} WHERE type='USER' AND created_at >= {$since} GROUP BY d"),
                    'new_users' => $fill("SELECT DATE(created_at) d, COUNT(*) v FROM {$U} WHERE created_at >= {$since} GROUP BY d"),
                    'revenue' => $fill("SELECT DATE(created_at) d, SUM(amount) v FROM {$p}payments WHERE status='paid' AND created_at >= {$since} GROUP BY d", 10),
                ],
                'top' => $db->query("SELECT u.chat_id, u.first_name, u.username, u.plan, u.is_pro, COUNT(*) c FROM {$M} m JOIN {$U} u ON u.id = m.user_id WHERE m.type='USER' AND m.created_at >= {$since} GROUP BY u.id ORDER BY c DESC LIMIT 8")->fetchAll(),
                'media' => $db->query("SELECT action, status, COUNT(*) c FROM {$p}media_usage WHERE created_at >= CURDATE() GROUP BY action, status")->fetchAll(),
                'plans' => $plans,
                'host' => hostStats(),
            ]);
        }
        if ($api === 'message') {
            $text = trim((string) ($input['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 4000) {
                jsonOut(['error' => 'Write 1 to 4000 characters.'], 400);
            }
            [$buttonText, $buttonUrl] = messageButton($input);
            $sent = baleSend($services, $config, (int) ($input['chat_id'] ?? 0), $text, $buttonText, $buttonUrl);
            jsonOut($sent['ok'] ? ['ok' => true] : ['error' => 'Bale refused it: ' . Bot\Redactor::redact($sent['error'], 200)], $sent['ok'] ? 200 : 400);
        }
        if ($api === 'broadcast') {
            $GLOBALS['M'] = $M;
            $action = (string) ($input['action'] ?? 'status');
            $file = 'broadcast.json';
            $job = $services->store->read($file, []);
            $public = fn (array $j) => $j ? array_diff_key($j, ['after' => 1, 'sql' => 1]) : null;
            $reply = fn (array $j, array $extra = []) => jsonOut(['job' => $public($j), 'history' => $services->store->read('broadcast_history.json', [])] + $extra);
            if ($action === 'status') {
                $reply($job);
            }
            if ($action === 'count') {
                $sql = audienceSql((string) ($input['audience'] ?? ''), $s['plans'], $db);
                if ($sql === null) {
                    jsonOut(['error' => 'Unknown audience.'], 400);
                }
                jsonOut(['count' => (int) $db->query("SELECT COUNT(*) FROM {$U} u WHERE {$sql}")->fetchColumn()]);
            }
            if ($action === 'test') {
                $text = trim((string) ($input['text'] ?? ''));
                if ($text === '' || mb_strlen($text) > 4000) {
                    jsonOut(['error' => 'Write 1 to 4000 characters.'], 400);
                }
                [$buttonText, $buttonUrl] = messageButton($input);
                $sent = baleSend($services, $config, (int) $config['telegram']['admin_chatid'], $text, $buttonText, $buttonUrl);
                jsonOut($sent['ok'] ? ['ok' => true] : ['error' => Bot\Redactor::redact($sent['error'], 200)], $sent['ok'] ? 200 : 400);
            }
            if ($action === 'start') {
                if (($job['status'] ?? '') === 'running') {
                    jsonOut(['error' => 'Another broadcast is still running. Finish or stop it first.'], 409);
                }
                $text = trim((string) ($input['text'] ?? ''));
                $audience = (string) ($input['audience'] ?? '');
                // 'me' only reaches the admin: a safe way to try the whole sending path.
                $sql = $audience === 'me' ? 'u.chat_id=' . (int) $config['telegram']['admin_chatid'] : audienceSql($audience, $s['plans'], $db);
                if ($sql === null || $text === '' || mb_strlen($text) > 4000) {
                    jsonOut(['error' => 'Choose an audience and write 1 to 4000 characters.'], 400);
                }
                [$buttonText, $buttonUrl] = messageButton($input);
                if ($job) {
                    $done = $public($job);
                    $services->store->update('broadcast_history.json', function (&$h) use ($done) {
                        array_unshift($h, $done);
                        $h = array_slice($h, 0, 14);
                    });
                }
                $job = ['id' => bin2hex(random_bytes(6)), 'text' => $text, 'button_text' => $buttonText, 'button_url' => $buttonUrl, 'audience' => $audience, 'sql' => $sql, 'after' => 0, 'sent' => 0, 'failed' => 0, 'total' => (int) $db->query("SELECT COUNT(*) FROM {$U} u WHERE {$sql}")->fetchColumn(), 'status' => 'running', 'started' => date('Y-m-d H:i:s'), 'last_error' => ''];
                $services->store->write($file, $job);
                $reply($job);
            }
            if ($action === 'stop') {
                if (($job['status'] ?? '') === 'running') {
                    $job['status'] = 'stopped';
                    $services->store->write($file, $job);
                }
                $reply($job);
            }
            if ($action === 'step') {
                // The lock stops two open tabs from sending the same people twice.
                $lock = $services->store->tryLock('broadcast');
                if (!$lock) {
                    $reply($job, ['busy' => true]);
                }
                try {
                    $job = $services->store->read($file, []);
                    if (($job['status'] ?? '') !== 'running') {
                        $reply($job);
                    }
                    $stmt = $db->prepare("SELECT u.id, u.chat_id FROM {$U} u WHERE {$job['sql']} AND u.id > ? ORDER BY u.id LIMIT 20");
                    $stmt->execute([(int) $job['after']]);
                    $rows = $stmt->fetchAll();
                    foreach ($rows as $row) {
                        $sent = baleSend($services, $config, (int) $row['chat_id'], $job['text'], $job['button_text'] ?? '', $job['button_url'] ?? '');
                        $job[$sent['ok'] ? 'sent' : 'failed']++;
                        if (!$sent['ok']) {
                            $job['last_error'] = Bot\Redactor::redact($sent['error'], 160);
                        }
                        $job['after'] = (int) $row['id'];
                        $services->store->write($file, $job); // saved per person, so a crash never re-sends
                        usleep(60000);
                    }
                    if (count($rows) < 20) {
                        $job['status'] = 'done';
                        $services->store->write($file, $job);
                    }
                } finally {
                    $services->store->release($lock);
                }
                $reply($job);
            }
            jsonOut(['error' => 'Unknown action'], 400);
        }
        if ($api === 'wallets') {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                $name = (string) ($input['provider'] ?? '');
                if (!in_array($name, ['metis', 'gapgpt'], true) || !is_numeric($input['balance'] ?? null) || $input['balance'] < 0) {
                    jsonOut(['error' => 'Choose a provider and a balance.'], 400);
                }
                $services->store->update('wallets.json', function (&$d) use ($name, $input) {
                    $d[$name] = ['balance' => (int) $input['balance'], 'since' => date('Y-m-d H:i:s')];
                });
                jsonOut(['ok' => true]);
            }
            $saved = $services->store->read('wallets.json', []);
            $out = [];
            foreach (['metis', 'gapgpt'] as $name) {
                $w = $saved[$name] ?? null;
                if (!$w) {
                    $out[$name] = ['balance' => null];
                    continue;
                }
                $stmt = $db->prepare("SELECT COALESCE(SUM(estimated_cost_usd),0) FROM {$p}voice_usage WHERE provider = ? AND created_at >= ?");
                $stmt->execute([$name, $w['since']]);
                $spent = (float) $stmt->fetchColumn();
                $stmt = $db->prepare("SELECT COALESCE(SUM(CASE action WHEN 'image' THEN 0.008 ELSE 0.0004 END),0) FROM {$p}media_usage WHERE status='ok' AND provider = ? AND created_at >= ?");
                $stmt->execute([$name, $w['since']]);
                $spent += (float) $stmt->fetchColumn();
                $from = strtotime($w['since']);
                foreach (glob($s['storage_dir'] . '/logs/routing-*.jsonl') ?: [] as $file) {
                    if (filemtime($file) >= $from) {
                        foreach (file($file) ?: [] as $line) {
                            $row = json_decode($line, true);
                            if (($row['type'] ?? '') === 'attempt' && ($row['provider'] ?? '') === $name && ($row['ts'] ?? 0) >= $from) {
                                $spent += (float) ($row['cost_usd'] ?? 0);
                            }
                        }
                    }
                }
                $out[$name] = ['balance' => $w['balance'], 'since' => $w['since'], 'spent' => round($spent, 4), 'left' => $w['balance'] - $spent * max(1, (int) $s['usd_toman'])];
            }
            jsonOut($out);
        }
        if ($api === 'feed') {
            $stmt = $db->prepare("SELECT m.id, m.type, LEFT(m.message, 400) message, m.created_at, u.first_name, u.username, u.chat_id FROM {$M} m JOIN {$U} u ON u.id = m.user_id WHERE m.id > ? AND m.type <> 'SUMMARY' ORDER BY m.id DESC LIMIT 40");
            $stmt->execute([(int) ($_GET['after'] ?? 0)]);
            jsonOut($stmt->fetchAll());
        }
        if ($api === 'users') {
            $on = "(u.is_pro=1 AND (u.pro_expire IS NULL OR u.pro_expire>NOW()))";
            $statuses = ['all' => '1=1', 'free' => "NOT {$on}", 'paying' => "{$on} AND u.plan IS NOT NULL", 'invited' => "{$on} AND u.plan IS NULL", 'expired' => "u.is_pro=1 AND u.pro_expire<=NOW()", 'banned' => 'u.baned=1'];
            foreach (array_keys($s['plans']) as $key) {
                $statuses['plan_' . $key] = "{$on} AND u.plan=" . $db->quote($key);
            }
            $where = [$statuses[(string) ($_GET['status'] ?? 'all')] ?? '1=1'];
            $args = [];
            $q = trim((string) ($_GET['q'] ?? ''));
            if ($q !== '') {
                $where[] = '(CAST(u.chat_id AS CHAR) LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
                $like = '%' . addcslashes(ltrim($q, '@'), '%_\\') . '%';
                $args = [$like, $like, $like, $like];
            }
            $days = ['today' => 'CURDATE()', '7d' => 'DATE_SUB(NOW(), INTERVAL 7 DAY)', '30d' => 'DATE_SUB(NOW(), INTERVAL 30 DAY)'];
            $active = (string) ($_GET['active'] ?? '');
            if (isset($days[$active])) {
                $where[] = "EXISTS (SELECT 1 FROM {$M} m WHERE m.user_id = u.id AND m.created_at >= {$days[$active]})";
            } elseif ($active === 'inactive') {
                $where[] = "NOT EXISTS (SELECT 1 FROM {$M} m WHERE m.user_id = u.id AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))";
            }
            $where = implode(' AND ', $where);
            $order = ['newest' => 'u.id DESC', 'oldest' => 'u.id ASC', 'messages' => 'messages DESC', 'last' => 'last_active IS NULL, last_active DESC', 'expiring' => "u.pro_expire IS NULL OR u.pro_expire<=NOW(), u.pro_expire ASC"][(string) ($_GET['sort'] ?? 'newest')] ?? 'u.id DESC';
            $per = 30;
            $page = max(0, (int) ($_GET['page'] ?? 0));
            $count = $db->prepare("SELECT COUNT(*) FROM {$U} u WHERE {$where}");
            $count->execute($args);
            $stmt = $db->prepare("SELECT u.id, u.chat_id, u.username, u.first_name, u.last_name, u.is_pro, u.pro_expire, u.plan, u.baned, u.created_at, (SELECT COUNT(*) FROM {$M} m WHERE m.user_id = u.id AND m.type='USER') messages, (SELECT MAX(m.created_at) FROM {$M} m WHERE m.user_id = u.id AND m.type='USER') last_active FROM {$U} u WHERE {$where} ORDER BY {$order} LIMIT {$per} OFFSET " . ($page * $per));
            $stmt->execute($args);
            $counts = [];
            foreach ($statuses as $key => $sql) {
                $counts[$key] = (int) $db->query("SELECT COUNT(*) FROM {$U} u WHERE {$sql}")->fetchColumn();
            }
            jsonOut(['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn(), 'per' => $per, 'counts' => $counts]);
        }
        if ($api === 'userinfo') {
            $id = (int) ($_GET['chat_id'] ?? 0);
            $stmt = $db->prepare("SELECT u.*, (SELECT COUNT(*) FROM {$M} m WHERE m.user_id = u.id AND m.type='USER') messages, (SELECT MAX(m.created_at) FROM {$M} m WHERE m.user_id = u.id AND m.type='USER') last_active FROM {$U} u WHERE u.chat_id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            if (!$user) {
                jsonOut(['error' => 'User not found.'], 404);
            }
            $isOn = !empty($user['is_pro']) && (empty($user['pro_expire']) || strtotime($user['pro_expire']) > time());
            $one = function (string $sql, array $a) use ($db) {
                $st = $db->prepare($sql);
                $st->execute($a);
                return (int) $st->fetchColumn();
            };
            $week = date('Y-m-d H:i:s', time() - 7 * 86400);
            $today = date('Y-m-d 00:00:00');
            $mediaSql = "SELECT COUNT(*) FROM {$p}media_usage WHERE telegram_user_id = ? AND action = ? AND created_at >= ? AND status IN ('ok','pending')";
            $voiceWhere = "telegram_user_id = ? AND created_at >= ? AND status IN ('ok','pending','timeout')";
            $msgs = $db->prepare("SELECT type, LEFT(message, 600) message, created_at FROM {$M} WHERE user_id = ? AND type <> 'SUMMARY' ORDER BY id DESC LIMIT 12");
            $msgs->execute([$user['id']]);
            $pays = $db->prepare("SELECT package, amount, status, created_at FROM {$p}payments WHERE telegram_user_id = ? ORDER BY id DESC LIMIT 10");
            $pays->execute([$id]);
            jsonOut([
                'user' => array_intersect_key($user, array_flip(['chat_id', 'username', 'first_name', 'last_name', 'is_pro', 'pro_expire', 'plan', 'baned', 'created_at', 'messages', 'last_active'])),
                'limits' => Bot\Plans::limits($s, $isOn, $user['plan']),
                'usage' => [
                    'chat_today' => $one("SELECT COUNT(*) FROM {$M} WHERE user_id = ? AND type='USER' AND created_at >= ?", [$user['id'], $today]),
                    'chat_month' => $one("SELECT COUNT(*) FROM {$M} WHERE user_id = ? AND type='USER' AND created_at >= ?", [$user['id'], date('Y-m-01 00:00:00')]),
                    'voice_week' => $one("SELECT COUNT(*) FROM {$p}voice_usage WHERE {$voiceWhere}", [$id, $week]),
                    'voice_seconds_today' => $one("SELECT COALESCE(SUM(duration_seconds),0) FROM {$p}voice_usage WHERE {$voiceWhere}", [$id, $today]),
                    'image_week' => $one($mediaSql, [$id, 'image', $week]),
                    'image_day' => $one($mediaSql, [$id, 'image', $today]),
                    'image_month' => $one($mediaSql, [$id, 'image', date('Y-m-01 00:00:00')]),
                    'vision_day' => $one($mediaSql, [$id, 'vision', $today]),
                ],
                'messages' => $msgs->fetchAll(),
                'payments' => $pays->fetchAll(),
            ]);
        }
        if ($api === 'user') {
            $id = (int) ($input['chat_id'] ?? 0);
            switch ((string) ($input['action'] ?? '')) {
                case 'ban':
                    $db->prepare("UPDATE {$U} SET baned = ? WHERE chat_id = ?")->execute([empty($input['value']) ? 0 : 1, $id]);
                    break;
                case 'plan':
                    $plan = (string) ($input['plan'] ?? '');
                    $days = (int) ($input['days'] ?? 0);
                    if (($plan !== '' && !isset($s['plans'][$plan])) || $days < 1 || $days > 3650) {
                        jsonOut(['error' => 'Choose a plan and 1 to 3650 days.'], 400);
                    }
                    $db->prepare("UPDATE {$U} SET is_pro = 1, plan = ?, pro_expire = ?, model = IF(model = ?, ?, model) WHERE chat_id = ?")
                        ->execute([$plan !== '' ? $plan : null, date('Y-m-d H:i:s', time() + $days * 86400), $s['default_tier'], $s['pro_tier'], $id]);
                    break;
                case 'free':
                    $db->prepare("UPDATE {$U} SET is_pro = 0, plan = NULL, pro_expire = NULL WHERE chat_id = ?")->execute([$id]);
                    $services->entitlements($db)->revoke($id);
                    break;
                default:
                    jsonOut(['error' => 'Unknown action'], 400);
            }
            jsonOut(['ok' => true]);
        }
        if ($api === 'payments') {
            jsonOut($db->query("SELECT pay.id, pay.telegram_user_id, pay.package, pay.amount, pay.status, pay.days, pay.charge_id, pay.created_at, u.first_name, u.username FROM {$p}payments pay LEFT JOIN {$U} u ON u.chat_id = pay.telegram_user_id ORDER BY pay.id DESC LIMIT 500")->fetchAll());
        }
        jsonOut(['error' => 'Unknown request'], 404);
    } catch (\InvalidArgumentException $e) {
        jsonOut(['error' => $e->getMessage()], 400);
    } catch (\Throwable $e) {
        jsonOut(['error' => Bot\Redactor::redact($e->getMessage(), 400)], 500);
    }
}
?><!doctype html>
<html lang="fa" dir="rtl" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bot Admin</title>
<style>
@font-face{font-family:Vazirmatn;src:url("assets/vazirmatn.woff2") format("woff2");font-weight:100 900;font-display:swap}
:root,[data-theme=dark]{color-scheme:dark;--bg:#0a0f1e;--surface:#121a30;--surface2:#18223d;--surface3:#1f2b4b;--line:#233054;--text:#e8edf8;--dim:#8d9abb;--a:#8b6dff;--a2:#5b8cff;--a3:#22d3ee;--ok:#22c55e;--warn:#f5a524;--bad:#f43f5e;--pink:#f472b6;--chip:rgba(139,109,255,.14);--grad:linear-gradient(135deg,#8b6dff 0%,#5b8cff 55%,#22d3ee 100%);--shadow:0 10px 30px -12px rgba(0,0,0,.55);--c1:#8b6dff;--c2:#22d3ee}
[data-theme=light]{color-scheme:light;--bg:#f4f6fb;--surface:#fff;--surface2:#f5f7fc;--surface3:#eaeef8;--line:#e2e7f2;--text:#121a2c;--dim:#64708c;--chip:rgba(139,109,255,.1);--shadow:0 10px 30px -18px rgba(30,41,80,.35);--c1:#6d4dff;--c2:#0891b2;--ok:#16a34a;--warn:#d97706;--bad:#e11d48}
*{box-sizing:border-box}html,body{margin:0}body{font:14.5px/1.7 Vazirmatn,Tahoma,"Segoe UI",sans-serif;background:var(--bg) radial-gradient(1200px 600px at 100% -10%,rgba(139,109,255,.12),transparent 60%) fixed;color:var(--text);-webkit-font-smoothing:antialiased}
a{color:var(--a2);text-decoration:none}.shell{display:grid;grid-template-columns:240px 1fr;min-height:100vh}
aside{position:sticky;top:0;height:100vh;overflow-y:auto;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(14px);border-left:1px solid var(--line);display:flex;flex-direction:column;padding:18px 14px}
.brand{display:flex;gap:12px;align-items:center;padding:4px 8px 20px}.brand b{display:block;font-size:17px;font-weight:800}.brand small{color:var(--dim)}.logo{display:grid;place-items:center;width:42px;height:42px;border-radius:14px;background:var(--grad);font-size:22px;box-shadow:0 8px 24px -8px rgba(139,109,255,.7)}
nav{display:flex;flex-direction:column;gap:3px}nav button{all:unset;cursor:pointer;display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:12px;color:var(--dim);font-weight:500;position:relative}nav button:hover{background:var(--surface2);color:var(--text)}nav button.on{background:var(--chip);color:var(--text);font-weight:700}nav button.on::before{content:"";position:absolute;right:-14px;top:9px;bottom:9px;width:4px;border-radius:4px 0 0 4px;background:var(--grad)}nav i{font-style:normal;width:24px;text-align:center}nav button:focus-visible,.btn:focus-visible{outline:2px solid var(--a);outline-offset:1px}
.pulse{margin-inline-start:auto;width:8px;height:8px;border-radius:50%;background:var(--ok);animation:pulse 2s infinite}@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.6)}70%{box-shadow:0 0 0 8px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
.foot{margin-top:auto;padding:12px 8px 0;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:8px}.state{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--dim)}.state i{width:9px;height:9px;border-radius:50%;background:var(--ok);flex:none}.foot form{margin:0}.link{all:unset;cursor:pointer;color:var(--dim);font-size:13.5px}.link:hover{color:var(--text)}
.wrap{min-width:0;display:flex;flex-direction:column}.top{position:sticky;top:0;z-index:10;display:flex;align-items:center;gap:14px;padding:12px 24px;background:color-mix(in srgb,var(--bg) 80%,transparent);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}.top h1{margin:0;font-size:18px;font-weight:800;white-space:nowrap}
.strip{display:flex;gap:8px;font-size:12.5px;color:var(--dim);flex-wrap:wrap;flex:1}.strip span{display:inline-flex;align-items:center;gap:6px;background:var(--surface2);border:1px solid var(--line);padding:2px 10px;border-radius:99px}.strip b{color:var(--text)}.dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.search{width:230px;flex:none}.icon{all:unset;cursor:pointer;width:36px;height:36px;display:grid;place-items:center;border-radius:10px;border:1px solid var(--line);background:var(--surface2);flex:none}
main{padding:20px 24px 40px;max-width:1380px;width:100%}
.hero{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;padding:22px 26px;border-radius:18px;background:var(--grad);color:#fff;box-shadow:0 18px 40px -20px rgba(139,109,255,.8)}.hero h2{margin:0;font-size:23px;font-weight:800}.hero p{margin:2px 0 0;opacity:.92}.hero .btn{background:rgba(255,255,255,.2);color:#fff;border:0}.hero .btn.w{background:#fff;color:#352a7a}
.kpis{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));margin-top:14px}.kpi{position:relative;overflow:hidden;background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:14px 16px 36px;box-shadow:var(--shadow);min-height:124px}.kpi i{position:absolute;left:14px;top:14px;width:34px;height:34px;border-radius:11px;display:grid;place-items:center;font-style:normal;background:var(--chip)}.kpi span{color:var(--dim);font-size:13px;display:block}.kpi b{display:block;font-size:26px;font-weight:800;line-height:1.35}.kpi small{color:var(--dim);font-size:12px;position:relative;z-index:1}.kpi svg{position:absolute;inset:auto 0 0 0;width:100%;height:30px;opacity:.6}.up{color:var(--ok)!important}.down{color:var(--bad)!important}
.grid21{display:grid;gap:14px;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);margin-top:14px}.two{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(min(100%,400px),1fr));margin-top:14px}.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
.card{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:var(--shadow);min-width:0}.gap{margin-top:14px}.card h2,.h{font-size:15px;font-weight:700;margin:0 0 12px}h3{font-size:13.5px;margin:14px 0 6px;color:var(--dim);font-weight:600}.stat span{color:var(--dim);font-size:13px;display:block}.stat b{display:block;font-size:24px;font-weight:800}.stat small{color:var(--dim);font-size:12px}
.head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap}.head h2{margin:0}.legend{font-size:12.5px;color:var(--dim);display:flex;gap:12px}.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-inline-end:5px}
.chart{position:relative}.chart svg{display:block;width:100%;height:230px}.tip{position:absolute;pointer-events:none;background:var(--surface3);border:1px solid var(--line);color:var(--text);font-size:12px;padding:3px 9px;border-radius:8px;white-space:nowrap;transform:translate(-50%,-115%);display:none;z-index:2}
.heat{display:grid;grid-template-columns:62px repeat(24,minmax(0,1fr));gap:3px;font-size:11px;color:var(--dim);align-items:center;direction:rtl}.heat u{text-decoration:none;text-align:center}.heat em{aspect-ratio:1;border-radius:5px;background:var(--c1);font-style:normal}
.hbar{display:grid;grid-template-columns:84px 1fr 62px;gap:10px;align-items:center;padding:5px 0;font-size:13px}.hbar div{height:9px;border-radius:9px;background:var(--surface3);overflow:hidden}.hbar div u{display:block;height:100%;border-radius:9px;background:linear-gradient(90deg,var(--c2),var(--c1))}.hbar b{font-weight:600;text-align:left}
.donut{display:flex;align-items:center;gap:18px;justify-content:center;flex-wrap:wrap;min-height:180px}.donut svg{width:150px;height:150px}.donut p{margin:3px 0;font-size:13px}.donut p i{display:inline-block;width:11px;height:11px;border-radius:4px;margin-inline-end:6px}
.feed{overflow:auto;display:flex;flex-direction:column;gap:7px}.msg{padding:8px 12px;border-radius:12px;background:var(--surface3);overflow-wrap:anywhere;max-width:92%;align-self:flex-start}.msg.AI{background:linear-gradient(135deg,#7b5cff,#5b8cff);color:#fff;align-self:flex-end}.msg.AI small{color:rgba(255,255,255,.8)}.msg small{color:var(--dim);display:block;margin-bottom:2px;font-size:11.5px}.msg p{margin:0;white-space:pre-wrap}
table{width:100%;border-collapse:collapse}th,td{text-align:right;padding:10px;border-bottom:1px solid var(--line);white-space:nowrap}th{color:var(--dim);font-weight:500;font-size:12.5px}tr.click{cursor:pointer}tr.click:hover td{background:var(--surface2)}.scroll{overflow-x:auto}.who{display:flex;gap:10px;align-items:center}.av{width:32px;height:32px;border-radius:50%;background:var(--grad);display:grid;place-items:center;color:#fff;font-weight:700;font-size:13px;flex:none}
input,select,textarea{background:var(--surface2);border:1px solid var(--line);border-radius:10px;color:var(--text);padding:8px 11px;font:inherit;width:100%}input:focus,select:focus,textarea:focus{outline:2px solid var(--a);outline-offset:-1px;border-color:transparent}textarea{min-height:58px;font:13px ui-monospace,Menlo,monospace;direction:ltr;text-align:left}textarea[dir=auto]{font:14px/1.7 Vazirmatn,Tahoma,sans-serif;direction:rtl;text-align:right}label{display:block;font-size:13px;color:var(--dim);margin-bottom:10px}label.c{display:flex;gap:8px;align-items:center;color:var(--text)}label.c input{width:auto}.fields{display:grid;gap:0 10px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr))}.ltr{direction:ltr;text-align:left}
.btn{background:var(--grad);border:0;color:#fff;padding:8px 15px;border-radius:10px;cursor:pointer;font:inherit;font-weight:700;white-space:nowrap}.btn.g{background:var(--surface2);box-shadow:inset 0 0 0 1px var(--line);color:var(--text);font-weight:500}.btn.g:hover{background:var(--surface3)}.btn.r{background:var(--bad)}.btn:disabled{opacity:.5;cursor:default}.savebar{position:sticky;bottom:12px;margin-top:14px;display:flex;justify-content:flex-end;gap:8px}
.pill{display:inline-block;padding:0 9px;border-radius:99px;font-size:12px;font-weight:600;background:var(--surface3);color:var(--dim)}.pill.ok{background:rgba(34,197,94,.14);color:var(--ok)}.pill.bad{background:rgba(244,63,94,.14);color:var(--bad)}.pill.warn{background:rgba(245,165,36,.14);color:var(--warn)}.pill.a{background:var(--chip);color:var(--a)}.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}.dim{color:var(--dim)}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.between{justify-content:space-between}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:12.5px ui-monospace,Menlo,monospace;color:var(--dim);margin:0;max-height:420px;overflow:auto;direction:ltr;text-align:left}
#toast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:var(--surface3);border:1px solid var(--line);padding:9px 16px;border-radius:12px;display:none;z-index:30;max-width:90vw}.meter{height:8px;border-radius:9px;background:var(--surface3);overflow:hidden;margin-top:4px}.meter div{height:100%;background:var(--grad);border-radius:9px}.meter.hi div{background:var(--bad)}.kv{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid var(--line)}.kv:last-child{border:0}.kv b{font-weight:600}
.test{display:flex;gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--line);flex-wrap:wrap}.test>div{flex:1;min-width:190px;overflow-wrap:anywhere}.test img{width:96px;border-radius:8px}
.chips{display:flex;gap:6px;flex-wrap:wrap}.chips button{all:unset;cursor:pointer;padding:3px 12px;border:1px solid var(--line);border-radius:99px;font-size:13px;color:var(--dim);background:var(--surface)}.chips button b{font-weight:600;margin-inline-start:5px;color:var(--text)}.chips button.on{background:var(--chip);border-color:transparent;color:var(--text)}
.filters{display:grid;gap:8px;grid-template-columns:minmax(220px,2fr) repeat(2,minmax(140px,1fr))}.pager{display:flex;justify-content:space-between;align-items:center;margin-top:12px;color:var(--dim)}
.preview{background:var(--surface2);border-radius:14px;padding:16px;min-height:130px}.bubble{background:var(--surface3);border-radius:14px;padding:10px 13px;white-space:pre-wrap;overflow-wrap:anywhere}.glass{margin-top:6px;text-align:center;padding:7px;border-radius:12px;background:rgba(139,109,255,.2);backdrop-filter:blur(6px);border:1px solid rgba(139,109,255,.35);font-size:13.5px}
#drawer{position:fixed;inset:0;z-index:20;display:none}#drawer.open{display:block}#drawer .shade{position:absolute;inset:0;background:rgba(5,8,18,.55)}#drawer .panel{position:absolute;top:0;left:0;bottom:0;width:min(490px,100%);background:var(--surface);border-right:1px solid var(--line);padding:20px;overflow:auto}
@media (max-width:900px){.shell{display:block}aside{position:static;height:auto;border:0;border-bottom:1px solid var(--line);padding:10px 12px}.brand{padding-bottom:8px}nav{flex-direction:row;overflow-x:auto}nav button{white-space:nowrap}nav button.on::before{display:none}.foot{flex-direction:row;justify-content:space-between;align-items:center}.top{position:static;flex-wrap:wrap;padding:10px 16px}.search{width:100%;order:9}main{padding:12px 16px 30px}.grid21{grid-template-columns:1fr}.filters{grid-template-columns:1fr 1fr}.filters>:first-child{grid-column:1/-1}.heat{grid-template-columns:44px repeat(24,minmax(9px,1fr));overflow-x:auto}}
</style></head><body><script>try{if(localStorage.theme)document.documentElement.dataset.theme=localStorage.theme}catch(e){}</script>
<div class="shell"><aside><div class="brand"><span class="logo">🤖</span><span><b>ربات هوش مصنوعی</b><small>پنل مدیریت</small></span></div><nav id="nav"></nav><div class="foot"><span class="state"><i></i><span id="bstate">در حال بررسی…</span></span><form method="post" action="?logout=1"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button class="link">🚪 خروج</button></form></div></aside>
<div class="wrap"><header class="top"><h1 id="ptitle"></h1><div class="strip" id="strip"></div><input class="search" id="gs" type="search" placeholder="جست‌وجوی کاربر: نام، یوزرنیم، آیدی…" aria-label="جست‌وجوی کاربر"><button class="icon" id="theme" title="تم روشن یا تیره" aria-label="تغییر تم">🌓</button></header><main id="main"></main></div></div><div id="drawer"><div class="shade"></div><div class="panel" id="dp"></div></div><div id="toast"></div><datalist id="dl-metis"></datalist><datalist id="dl-gapgpt"></datalist>
<script>
const CSRF=<?= json_encode($csrf) ?>;const $=(h)=>{const t=document.createElement('template');t.innerHTML=h.trim();return t.content.firstChild};
const esc=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));const num=(n)=>Number(n||0).toLocaleString('fa-IR');
const toast=(m,bad)=>{const t=document.getElementById('toast');t.textContent=m;t.style.display='block';t.style.color=bad?'var(--bad)':'var(--ok)';clearTimeout(toast.t);toast.t=setTimeout(()=>t.style.display='none',3500)};
async function api(name,body,qs=''){const r=await fetch('?api='+name+qs,body?{method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},body:JSON.stringify(body)}:{});if(r.status===401){location.reload();throw new Error('signed out')}const j=await r.json().catch(()=>({error:'Bad server response (HTTP '+r.status+')'}));if(j&&j.error)throw new Error(j.error);return j}
let SET=null;const loadSet=async()=>SET=await api('settings');
const OFFSET=new Date(<?= json_encode(date('Y-m-d\TH:i:s')) ?>+'Z').getTime()-Date.now();
const main=document.getElementById('main');let timers=[];const every=(f,ms)=>{f();timers.push(setInterval(f,ms))};
const pages={Overview,Live,Users,Broadcast,Payments,Plans,Models,Prompts,Tests};const meta={Overview:['داشبورد','📊'],Live:['زنده','⚡'],Users:['کاربران','👥'],Broadcast:['ارسال همگانی','📢'],Payments:['پرداخت‌ها','💳'],Plans:['پلن‌ها و محدودیت‌ها','💎'],Models:['مدل‌ها','🧠'],Prompts:['پرامپت‌ها','📝'],Tests:['تست سرویس‌ها','🧪']};const nav=document.getElementById('nav');
function go(p){timers.forEach(clearInterval);timers=[];[...nav.children].forEach(b=>b.classList.toggle('on',b.dataset.p===p));main.innerHTML='';document.getElementById('ptitle').textContent=meta[p][1]+' '+meta[p][0];try{sessionStorage.page=p}catch(e){}pages[p]();liveStrip()}
Object.keys(pages).forEach(p=>{const b=document.createElement('button');b.dataset.p=p;b.innerHTML=`<i>${meta[p][1]}</i><span>${meta[p][0]}</span>${p==='Live'?'<em class="pulse"></em>':''}`;b.onclick=()=>go(p);nav.append(b)});
document.getElementById('theme').onclick=()=>{const r=document.documentElement,n=r.dataset.theme==='light'?'dark':'light';r.dataset.theme=n;try{localStorage.theme=n}catch(e){}};
let uState={q:'',status:'all',active:'',sort:'newest',page:0};document.getElementById('gs').onkeydown=e=>{if(e.key==='Enter'){uState={...uState,q:e.target.value,page:0};go('Users');e.target.value=''}};
const ago=(t)=>{if(!t)return '—';const s=(Date.now()+OFFSET-new Date(String(t).replace(' ','T')+'Z').getTime())/1000;if(s<60)return 'همین الان';if(s<3600)return num(Math.floor(s/60))+' دقیقه پیش';if(s<86400)return num(Math.floor(s/3600))+' ساعت پیش';return num(Math.floor(s/86400))+' روز پیش'};
const compact=(n)=>n>=1e6?num(+(n/1e6).toFixed(1))+'M':n>=1e3?num(+(n/1e3).toFixed(1))+'K':num(Math.round(n));
let stripT;async function liveStrip(){clearInterval(stripT);const run=async()=>{try{const l=await api('live');const dot=c=>`<i class="dot" style="background:${c}"></i>`;document.getElementById('strip').innerHTML=`<span>${dot('var(--ok)')}<b>${num(l.online)}</b> آنلاین</span><span>${dot('var(--c1)')}<b>${num(l.active_today)}</b> فعال امروز</span><span>${dot('var(--c2)')}<b>${num(l.msg_5)}</b> پیام/۵ دقیقه</span>`;document.getElementById('bstate').textContent=l.last?'ربات فعال · '+ago(l.last):'هنوز پیامی نرسیده'}catch(e){}};run();stripT=setInterval(run,20000)}
const spark=(rows,color='var(--c1)')=>{const v=rows.slice(-14).map(r=>+r.v),m=Math.max(1,...v);const p=v.map((x,i)=>`${i*100/Math.max(1,v.length-1)},${30-x/m*26}`).join(' ');return `<svg viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true"><polyline points="${p}" fill="none" stroke="${color}" stroke-width="1.6" vector-effect="non-scaling-stroke"/></svg>`};
function combo(el,bars,line,fmt){const W=760,H=230,L=8,R=34,T=12,B=24,n=bars.length,step=(W-L-R)/n,mx=Math.max(1,...bars.map(r=>+r.v)),lx=Math.max(1,...line.map(r=>+r.v)),y=(v,m)=>T+(H-T-B)*(1-v/m),X=i=>W-R-(i+.5)*step;
const grid=[.25,.5,.75,1].map(f=>`<line x1="${L}" x2="${W-R}" y1="${y(mx*f,mx)}" y2="${y(mx*f,mx)}" stroke="var(--line)" stroke-dasharray="3 4"/>`).join('');const lab=[1].map(()=>`<text x="${W-R+6}" y="${y(mx,mx)+4}" font-size="11" fill="var(--dim)">${compact(mx)}</text>`).join('');
const rect=bars.map((r,i)=>`<rect x="${X(i)-step*.32}" y="${y(+r.v,mx)}" width="${step*.64}" height="${Math.max(1,H-B-y(+r.v,mx))}" rx="3" fill="var(--c1)" opacity=".85"/>`).join('');const days=bars.map((r,i)=>i%3===0?`<text x="${X(i)}" y="${H-7}" text-anchor="middle" font-size="11" fill="var(--dim)">${num(+new Intl.DateTimeFormat('en-u-ca-persian',{day:'numeric'}).format(new Date(r.d+'T12:00:00')).replace(/\D/g,''))}</text>`:'').join('');const pts=line.map((r,i)=>`${X(i)},${y(+r.v,lx)}`).join(' ');
el.innerHTML=`<div class="chart"><svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="نمودار ۳۰ روز">${grid}${lab}${rect}<polyline points="${pts}" fill="none" stroke="var(--c2)" stroke-width="2" vector-effect="non-scaling-stroke"/>${days}</svg><div class="tip"></div></div>`;const svg=el.querySelector('svg'),tip=el.querySelector('.tip');svg.onmousemove=e=>{const b=svg.getBoundingClientRect(),px=(e.clientX-b.left)/b.width*W,i=Math.max(0,Math.min(n-1,Math.floor((W-R-px)/step)));tip.style.display='block';tip.style.left=X(i)/W*100+'%';tip.style.top=y(+bars[i].v,mx)/H*230+'px';tip.textContent=fmt(bars[i],line[i])};svg.onmouseleave=()=>tip.style.display='none'}
function heat(el,rows){const dn=['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'],g={};let m=1;rows.forEach(r=>{g[r.w+'-'+r.h]=+r.c;m=Math.max(m,+r.c)});let h='<span></span>';for(let i=0;i<24;i++)h+=`<u>${i%3===0?num(i):''}</u>`;dn.forEach((d,w)=>{h+=`<span>${d}</span>`;for(let i=0;i<24;i++){const c=g[w+'-'+i]||0;h+=`<em title="${d}، ساعت ${num(i)}: ${num(c)} پیام" style="opacity:${c?.14+.86*c/m:.07}"></em>`}});el.innerHTML=`<div class="heat">${h}</div>`}
const hbars=(rows,unit)=>{const m=Math.max(1,...rows.map(r=>+r.c));return rows.map(r=>`<div class="hbar"><span dir="auto">${esc(r.title)}</span><div><u style="width:${r.c/m*100}%"></u></div><b>${num(r.c)} ${unit}</b></div>`).join('')||'<span class="dim">موردی نیست</span>'};
function donut(el,parts){const tot=parts.reduce((s,p)=>s+p.v,0)||1,R=54,C=2*Math.PI*R;let off=0;const arcs=parts.map(p=>{const a=p.v/tot*C,s=`<circle r="${R}" cx="75" cy="75" fill="none" stroke="${p.c}" stroke-width="20" stroke-dasharray="${a} ${C-a}" stroke-dashoffset="${-off}" transform="rotate(-90 75 75)"/>`;off+=a;return s}).join('');el.innerHTML=`<div class="donut"><svg viewBox="0 0 150 150" role="img" aria-label="ترکیب کاربران">${arcs}<text x="75" y="81" text-anchor="middle" font-size="19" font-weight="800" fill="var(--text)">${compact(tot)}</text></svg><div>${parts.map(p=>`<p><i style="background:${p.c}"></i>${esc(p.l)} · <b>${num(p.v)}</b></p>`).join('')}</div></div>`}
const initial=u=>esc((u.first_name||u.username||'؟').trim().charAt(0)||'؟');

function Overview(){main.innerHTML=`<section class="hero" id="hero"></section><section class="kpis" id="st"></section><section class="grid21"><div class="card"><div class="head"><h2>📈 روند ۳۰ روز</h2><span class="legend"><span><i style="background:var(--c1)"></i>پیام</span><span><i style="background:var(--c2)"></i>کاربر فعال</span></span></div><div id="chart"></div></div><div class="card"><h2>👥 ترکیب کاربران</h2><div id="don"></div></div></section><section class="grid21"><div class="card"><div class="head"><h2>🔥 ساعت‌های شلوغ</h2><span class="dim">پیام‌ها، ۲۸ روز اخیر، به وقت ایران</span></div><div id="heat"></div></div><div class="card"><h2>💎 پلن‌های فعال</h2><div id="plans"></div><h2 style="margin-top:18px">🖼 رسانه امروز</h2><div id="media"></div></div></section><section class="grid21"><div class="card"><div class="head"><h2>🏆 فعال‌ترین کاربران</h2><span class="dim">۳۰ روز اخیر</span></div><div class="scroll" id="top"></div></div><div class="card"><h2>💰 مالی، ۳۰ روز</h2><div id="money"></div></div></section><section class="two"><div class="card"><h2>👛 کیف پول سرویس‌ها</h2><p class="dim" style="margin:0 0 10px;font-size:12.5px">متیس و گپ‌جی‌پی‌تی موجودی را با کلید API نمی‌دهند. موجودی پنل را وارد کنید تا مصرف تخمینی از آن کم شود.</p><div class="two" id="wallets" style="margin-top:0"><span class="dim">در حال بارگذاری…</span></div></div><div class="card"><h2>🖥 سرور</h2><div id="host"></div></div></section>`;loadWallets();
every(async()=>{const d=await api('stats');const hr=+new Intl.DateTimeFormat('en-GB',{hour:'numeric',hour12:false,timeZone:'Asia/Tehran'}).format(new Date()),greet=hr<5?'شب بخیر':hr<12?'صبح بخیر':hr<17?'روز بخیر':'عصر بخیر',today=new Intl.DateTimeFormat('fa-IR',{dateStyle:'long',timeZone:'Asia/Tehran'}).format(new Date());
document.getElementById('hero').innerHTML=`<div><h2>${greet} 👋</h2><p>امروز ${today} · ${num(d.active_today)} نفر سر زدند و ${num(d.messages_today)} پیام فرستادند.</p></div><div class="row"><button class="btn" onclick="go('Live')">⚡ پیام‌های زنده</button><button class="btn w" onclick="go('Broadcast')">📢 ارسال همگانی</button></div>`;
const delta=(a,b)=>{if(!a&&!b)return '';const up=a>=b;return `<small class="${up?'up':'down'}">${up?'▲':'▼'} ${num(Math.abs(a-b))} نسبت به دیروز</small>`},k=(ic,l,v,x,s='')=>`<div class="kpi"><i>${ic}</i><span>${l}</span><b>${v}</b>${x||'<small>&nbsp;</small>'}${s}</div>`,S=d.series;
document.getElementById('st').innerHTML=k('👥','کاربران',num(d.users),`<small>+${num(d.new_today)} امروز · ${num(d.active_30)} فعال در ۳۰ روز</small>`,spark(S.new_users))+k('🟢','آنلاین الان',num(d.online),'<small>۵ دقیقه اخیر</small>')+k('🙋','فعال امروز',num(d.active_today),delta(d.active_today,d.active_yesterday),spark(S.active,'var(--c2)'))+k('✉️','پیام‌های امروز',num(d.messages_today),delta(d.messages_today,d.messages_yesterday),spark(S.messages,'var(--pink)'))+k('💎','کاربران پولی',num(d.paying),`<small>${num(d.invited)} PRO دعوتی</small>`)+k('💵','درآمد امروز',num(d.revenue_today/10)+' ت',`<small>${num(d.paid_today)} پرداخت</small>`,spark(S.revenue,'var(--ok)'))+k('🎤','صدای امروز',num(d.voice_today),'<small>پیام صوتی پاسخ داده شده</small>')+k('⚙️','پاسخ هوش مصنوعی',d.avg_ms?num(d.avg_ms)+' ms':'—',`<small>میانگین ${num(d.ai_calls)} درخواست امروز</small>`);
combo(document.getElementById('chart'),S.messages,S.active,(b,l)=>`${new Intl.DateTimeFormat('fa-IR',{month:'short',day:'numeric'}).format(new Date(b.d+'T12:00:00'))}: ${num(b.v)} پیام، ${num(l.v)} فعال`);donut(document.getElementById('don'),[{l:'رایگان',v:d.users-d.paying-d.invited,c:'var(--surface3)'},{l:'پولی',v:d.paying,c:'var(--c1)'},{l:'PRO دعوتی',v:d.invited,c:'var(--c2)'}]);heat(document.getElementById('heat'),d.heat);document.getElementById('plans').innerHTML=hbars(d.plans,'نفر');
const kv=(a,v,c='')=>`<div class="kv"><span class="dim">${a}</span><b class="${c}">${v}</b></div>`,cnt=(a,st)=>d.media.filter(m=>m.action===a&&m.status===st).reduce((s,m)=>s+ +m.c,0);document.getElementById('media').innerHTML=kv('ساخت تصویر',num(cnt('image','ok'))+(cnt('image','failed')?` · <span class="bad">${num(cnt('image','failed'))} خطا</span>`:''))+kv('تحلیل تصویر',num(cnt('vision','ok'))+(cnt('vision','failed')?` · <span class="bad">${num(cnt('vision','failed'))} خطا</span>`:''));
document.getElementById('top').innerHTML=d.top.length?`<table><tr><th>کاربر</th><th>پیام</th><th>وضعیت</th></tr>${d.top.map((u,i)=>`<tr class="click" data-u="${esc(u.chat_id)}"><td><span class="who"><span class="av">${num(i+1)}</span><span dir="auto">${esc(u.first_name||u.chat_id)} ${u.username?'<span class="dim">@'+esc(u.username)+'</span>':''}</span></span></td><td><b>${num(u.c)}</b></td><td>${u.plan?'<span class="pill ok">'+esc(SET?.plans?.[u.plan]?.title||u.plan)+'</span>':(+u.is_pro?'<span class="pill a">PRO</span>':'<span class="pill">رایگان</span>')}</td></tr>`).join('')}</table>`:'<span class="dim">هنوز پیامی نیست</span>';document.getElementById('top').onclick=e=>{const r=e.target.closest('[data-u]');if(r)openUser(r.dataset.u)};
const usd=d.revenue_30d/10/d.usd_toman,net=usd-d.cost_30d;document.getElementById('money').innerHTML=kv('درآمد',num(d.revenue_30d/10)+' تومان')+kv('درآمد به دلار','$'+usd.toFixed(2))+kv('هزینه تخمینی API','$'+d.cost_30d.toFixed(2))+kv('سود تخمینی',(net<0?'−':'')+'$'+Math.abs(net).toFixed(2),net<0?'bad':'ok')+`<p class="dim" style="margin:8px 0 0;font-size:12.5px">نرخ دلار: ${num(d.usd_toman)} تومان. هزینه‌ها تخمینی است.</p>`;
const h=d.host,load=h.load?h.load[0]:null,pct=(a,b)=>b?Math.min(100,Math.round(a/b*100)):0,meter=(l,p,t)=>`<label>${l}<span style="float:left" dir="ltr">${t}</span><div class="meter ${p>85?'hi':''}"><div style="width:${p}%"></div></div></label>`;document.getElementById('host').innerHTML=(load!==null?meter('بار پردازنده',pct(load,h.cpus||Math.max(load,1)*1.5),load.toFixed(2)+(h.cpus?' / '+h.cpus:'')):'<p class="dim">هاست بار پردازنده را نمی‌دهد.</p>')+(h.mem?meter('حافظه',pct(h.mem.used_mb,h.mem.total_mb),h.mem.used_mb+' / '+h.mem.total_mb+' MB'):'')+meter('دیسک',pct(h.disk_total_gb-h.disk_free_gb,h.disk_total_gb),h.disk_free_gb+' GB free')+`<span class="dim" style="font-size:12.5px">PHP ${esc(h.php)}</span>`},20000);if(!SET)loadSet().catch(()=>{})}

async function loadWallets(){const box=document.getElementById('wallets');if(!box)return;let d;try{d=await api('wallets')}catch(x){box.innerHTML=`<span class="bad">${esc(x.message)}</span>`;return}const links={metis:'https://metisai.ir',gapgpt:'https://gapgpt.app'},names={metis:'متیس',gapgpt:'گپ‌جی‌پی‌تی'};box.innerHTML=Object.keys(names).map(p=>{const w=d[p];return `<div><div class="row between"><b>${names[p]}</b><a href="${links[p]}" target="_blank" rel="noopener">پنل ↗</a></div>${w.balance!==null?`<div class="stat" style="margin:6px 0"><span>مانده تخمینی</span><b class="${w.left<=0?'bad':''}">${num(Math.round(w.left))} ت</b><small>${num(w.balance)} ت در ${esc(w.since.slice(0,10))} − حدود $${w.spent.toFixed(3)} مصرف</small></div>`:'<p class="dim" style="margin:8px 0">موجودی وارد نشده.</p>'}<div class="row" style="flex-wrap:nowrap"><input type="number" min="0" placeholder="موجودی به تومان" data-w="${p}" aria-label="موجودی ${names[p]}"><button class="btn g" data-ws="${p}">ثبت</button></div></div>`}).join('');box.querySelectorAll('[data-ws]').forEach(b=>b.onclick=async()=>{const p=b.dataset.ws,v=box.querySelector(`[data-w="${p}"]`).value;if(v===''||+v<0)return toast('موجودی را به تومان وارد کنید',1);try{await api('wallets',{provider:p,balance:+v});toast('ثبت شد');loadWallets()}catch(x){toast(x.message,1)}})}

function Live(){main.innerHTML=`<div class="card"><div class="row between"><span class="pill ok">● به‌روزرسانی هر ۴ ثانیه</span><input id="lf" placeholder="فیلتر متن، نام یا آیدی" style="max-width:320px"></div><div class="feed" id="feed" style="max-height:calc(100vh - 200px);margin-top:12px"></div></div>`;let last=0;const f=document.getElementById('feed'),lf=document.getElementById('lf');const apply=()=>{const q=lf.value.toLowerCase();[...f.children].forEach(c=>c.style.display=!q||c.textContent.toLowerCase().includes(q)?'':'none')};lf.oninput=apply;f.onclick=e=>{const r=e.target.closest('[data-u]');if(r)openUser(r.dataset.u)};every(async()=>{const rows=await api('feed',null,'&after='+last);if(!rows.length){if(!last)f.innerHTML='<span class="dim">هنوز پیامی نیست</span>';return}if(!last)f.innerHTML='';rows.reverse().forEach(m=>{last=Math.max(last,+m.id);f.prepend($(`<div class="msg ${esc(m.type)}" data-u="${esc(m.chat_id)}" style="cursor:pointer"><small>${m.type==='AI'?'🤖 ربات به ':''}${esc(m.first_name)} ${m.username?'@'+esc(m.username):''} · ${esc(m.chat_id)} · ${ago(m.created_at)}</small><p dir="auto">${esc(m.message)}</p></div>`))});while(f.children.length>200)f.lastChild.remove();apply()},4000)}

const statusPill=(u)=>{if(+u.baned)return '<span class="pill bad">مسدود</span>';const on=+u.is_pro&&(!u.pro_expire||new Date(u.pro_expire.replace(' ','T')+'Z').getTime()>Date.now()+OFFSET);if(!on)return +u.is_pro?'<span class="pill warn">منقضی</span>':'<span class="pill">رایگان</span>';return u.plan?`<span class="pill ok">${esc(SET.plans[u.plan]?.title||u.plan)}</span>`:'<span class="pill a">PRO دعوتی</span>'};
async function Users(){await loadSet();main.innerHTML=`<div class="chips" id="ch"></div><div class="card gap"><div class="filters"><input id="q" placeholder="🔍 نام، @یوزرنیم یا آیدی" value="${esc(uState.q)}"><select id="ac" aria-label="فعالیت"><option value="">هر فعالیتی</option><option value="today">فعال امروز</option><option value="7d">فعال در ۷ روز</option><option value="30d">فعال در ۳۰ روز</option><option value="inactive">بدون فعالیت ۳۰ روز</option></select><select id="so" aria-label="مرتب‌سازی"><option value="newest">جدیدترین</option><option value="oldest">قدیمی‌ترین</option><option value="messages">بیشترین پیام</option><option value="last">آخرین فعالیت</option><option value="expiring">نزدیک به انقضا</option></select></div><div class="scroll" id="tb" style="margin-top:10px"></div><div class="pager"><span id="pc"></span><span class="row"><button class="btn g" id="prev">قبلی</button><button class="btn g" id="next">بعدی</button></span></div></div>`;document.getElementById('ac').value=uState.active;document.getElementById('so').value=uState.sort;
const load=async()=>{const u=uState,d=await api('users',null,`&q=${encodeURIComponent(u.q)}&status=${u.status}&active=${u.active}&sort=${u.sort}&page=${u.page}`);if(!document.getElementById('tb'))return;const chips=[['all','همه'],['free','رایگان'],['paying','پولی'],['invited','PRO دعوتی'],...Object.entries(SET.plans).map(([k,p])=>['plan_'+k,p.title]),['expired','منقضی'],['banned','مسدود']];document.getElementById('ch').innerHTML=chips.map(([k,l])=>`<button data-s="${esc(k)}" class="${u.status===k?'on':''}">${esc(l)}<b>${num(d.counts[k]??0)}</b></button>`).join('');
document.getElementById('tb').innerHTML=d.rows.length?`<table><tr><th>کاربر</th><th>آیدی</th><th>وضعیت</th><th>تا تاریخ</th><th>پیام</th><th>آخرین فعالیت</th><th>عضویت</th></tr>${d.rows.map(r=>`<tr class="click" data-u="${esc(r.chat_id)}"><td><span class="who"><span class="av">${initial(r)}</span><span dir="auto">${esc(r.first_name)} ${esc(r.last_name)} ${r.username?'<span class="dim">@'+esc(r.username)+'</span>':''}</span></span></td><td class="dim">${esc(r.chat_id)}</td><td>${statusPill(r)}</td><td class="dim">${r.pro_expire?esc(r.pro_expire.slice(0,10)):'—'}</td><td>${num(r.messages)}</td><td class="dim">${ago(r.last_active)}</td><td class="dim">${esc(String(r.created_at).slice(0,10))}</td></tr>`).join('')}</table>`:'<p class="dim" style="padding:24px;text-align:center">کاربری با این فیلترها پیدا نشد.</p>';const pages=Math.max(1,Math.ceil(d.total/d.per));document.getElementById('pc').textContent=`${num(d.total)} کاربر · صفحه ${num(u.page+1)} از ${num(pages)}`;document.getElementById('prev').disabled=u.page<1;document.getElementById('next').disabled=u.page+1>=pages};Users.reload=load;
let t;document.getElementById('q').oninput=e=>{clearTimeout(t);uState.q=e.target.value;uState.page=0;t=setTimeout(load,300)};document.getElementById('ac').onchange=e=>{uState.active=e.target.value;uState.page=0;load()};document.getElementById('so').onchange=e=>{uState.sort=e.target.value;uState.page=0;load()};document.getElementById('ch').onclick=e=>{const b=e.target.closest('[data-s]');if(b){uState.status=b.dataset.s;uState.page=0;load()}};document.getElementById('prev').onclick=()=>{uState.page--;load()};document.getElementById('next').onclick=()=>{uState.page++;load()};document.getElementById('tb').onclick=e=>{const r=e.target.closest('[data-u]');if(r)openUser(r.dataset.u)};load().catch(x=>toast(x.message,1))}

const drawer=document.getElementById('drawer'),dp=document.getElementById('dp');const closeUser=()=>drawer.classList.remove('open');drawer.querySelector('.shade').onclick=closeUser;addEventListener('keydown',e=>{if(e.key==='Escape')closeUser()});
const btnFields=(a,b)=>`<div class="fields" style="margin-top:8px"><label>متن دکمه شیشه‌ای (اختیاری)<input id="${a}" maxlength="64" dir="auto" placeholder="مثلاً: 🎁 دیدن پیشنهاد"></label><label>لینک دکمه<input id="${b}" class="ltr" dir="ltr" placeholder="https://ble.ir/…"></label></div>`;
async function openUser(id){if(!SET)await loadSet();drawer.classList.add('open');dp.innerHTML='<span class="dim">در حال بارگذاری…</span>';let d;try{d=await api('userinfo',null,'&chat_id='+encodeURIComponent(id))}catch(x){dp.innerHTML=`<p class="bad">${esc(x.message)}</p>`;return}const u=d.user,us=d.usage,l=d.limits;const bar=(label,a,b)=>b>0?`<label>${label}<span style="float:left">${num(a)} از ${num(b)}</span><div class="meter ${a>=b?'hi':''}"><div style="width:${Math.min(100,a/b*100)}%"></div></div></label>`:(b<0?`<label>${label}<span style="float:left">${num(a)} · بدون سقف</span></label>`:'');
dp.innerHTML=`<div class="row between"><span class="who"><span class="av" style="width:44px;height:44px;font-size:18px">${initial(u)}</span><span><b style="font-size:17px" dir="auto">${esc(u.first_name)} ${esc(u.last_name)}</b><br><span class="dim" dir="ltr">${u.username?'@'+esc(u.username)+' · ':''}${esc(u.chat_id)}</span></span></span><button class="btn g" id="dx">بستن</button></div><div class="row" style="margin-top:8px">${statusPill(u)}<span class="dim">${u.pro_expire?'تا '+esc(u.pro_expire.slice(0,10)):''}</span></div>
<h3>مصرف</h3>${bar('پیام امروز',us.chat_today,l.chat_per_day)}${bar('پیام این ماه',us.chat_month,l.chat_per_month)}${bar('صدا این هفته',us.voice_week,l.voice_per_week)}${bar('دقیقه صدا امروز',Math.ceil(us.voice_seconds_today/60),l.voice_minutes_per_day)}${bar('تصویر این هفته',us.image_week,l.image_per_week)}${bar('تصویر امروز',us.image_day,l.image_per_day)}${bar('تصویر این ماه',us.image_month,l.image_per_month)}${bar('تحلیل تصویر امروز',us.vision_day,l.vision_per_day)}
<div class="kv"><span class="dim">همه پیام‌ها</span><b>${num(u.messages)}</b></div><div class="kv"><span class="dim">آخرین فعالیت</span><b>${ago(u.last_active)}</b></div><div class="kv"><span class="dim">عضویت</span><b>${esc(String(u.created_at).slice(0,10))}</b></div>
<h3>✉️ ارسال پیام</h3><textarea id="um" dir="auto" maxlength="4000" placeholder="پیام به این کاربر" style="min-height:74px"></textarea>${btnFields('ubt','ubu')}<div class="row" style="justify-content:flex-end"><button class="btn" id="us">ارسال در بله</button></div>
<h3>تغییر پلن</h3><div class="row"><select id="gp" style="flex:1;width:auto"><option value="">PRO دعوتی</option>${Object.entries(SET.plans).map(([k,p])=>`<option value="${esc(k)}">${esc(p.title)}</option>`).join('')}</select><input id="gd" type="number" value="30" min="1" style="width:76px" aria-label="روز"><button class="btn" data-a="plan">فعال‌سازی</button></div><div class="row" style="margin-top:8px"><button class="btn g" data-a="free">رایگان کردن</button><button class="btn ${+u.baned?'g':'r'}" data-a="ban" data-v="${+u.baned?0:1}">${+u.baned?'رفع مسدودی':'مسدود کردن'}</button></div>
${d.payments.length?`<h3>پرداخت‌ها</h3>${d.payments.map(p=>`<div class="kv"><span class="dim">${esc(p.created_at)}</span><b class="${p.status==='paid'?'ok':'warn'}">${num(p.amount/10)} ت · ${esc(SET.plans[p.package]?.title||p.package)}</b></div>`).join('')}`:''}
<h3>پیام‌های اخیر</h3><div class="feed">${d.messages.map(m=>`<div class="msg ${esc(m.type)}"><small>${ago(m.created_at)}</small><p dir="auto">${esc(m.message)}</p></div>`).join('')||'<span class="dim">پیامی نیست</span>'}</div>`;
dp.querySelector('#dx').onclick=closeUser;dp.querySelector('#us').onclick=async e=>{const b=e.target,t=dp.querySelector('#um');if(!t.value.trim())return toast('اول پیام را بنویسید',1);b.disabled=true;try{await api('message',{chat_id:id,text:t.value,button_text:dp.querySelector('#ubt').value,button_url:dp.querySelector('#ubu').value});t.value='';toast('فرستاده شد')}catch(x){toast(x.message,1)}b.disabled=false};dp.querySelectorAll('[data-a]').forEach(b=>b.onclick=async()=>{const a=b.dataset.a;if(a==='free'&&!confirm('اشتراک این کاربر حذف شود؟'))return;try{await api('user',{action:a,chat_id:id,value:+b.dataset.v,plan:dp.querySelector('#gp').value,days:+dp.querySelector('#gd').value});toast('ذخیره شد');openUser(id);if(Users.reload&&document.getElementById('tb'))Users.reload()}catch(x){toast(x.message,1)}})}

async function Broadcast(){await loadSet();const aud=[['all','همه کاربران'],['active_7','فعال در ۷ روز'],['active_30','فعال در ۳۰ روز'],['free','کاربران رایگان'],['paying','کاربران پولی'],['invited','PRO دعوتی'],['expired','اشتراک منقضی'],...Object.entries(SET.plans).map(([k,p])=>['plan_'+k,p.title])],audName=Object.fromEntries(aud);audName.me='فقط من';
main.innerHTML=`<section class="grid21" style="margin-top:0"><div class="card"><h2>📢 ارسال برای همه (تبلیغ، اطلاعیه، خبر)</h2><label>متن پیام<textarea id="bt" dir="auto" maxlength="3900" placeholder="سلام دوستان 👋" style="min-height:150px"></textarea></label>${btnFields('bbt','bbu')}<label>به چه کسانی؟<select id="ba">${aud.map(([k,l])=>`<option value="${esc(k)}">${esc(l)}</option>`).join('')}</select></label><div class="row between"><span class="dim" id="bc"></span><span class="row"><button class="btn g" id="bx">🧪 اول برای خودم بفرست</button><button class="btn" id="bs">📢 ارسال</button></span></div><p class="dim" style="margin:10px 0 0;font-size:12.5px">کاربران مسدود پیام نمی‌گیرند. تا پایان ارسال این صفحه را باز بگذارید؛ اگر بسته شد، برگردید و «ادامه» را بزنید.</p></div>
<div><div class="card"><h2>👀 پیش‌نمایش</h2><div class="preview"><div class="bubble" id="pv" dir="auto">متن پیام این‌جا دیده می‌شود…</div><div class="glass" id="pvb" hidden></div></div></div></div></section><section class="card gap"><h2>🗂 ارسال‌های اخیر</h2><div id="bj"></div></section>`;
const $i=x=>document.getElementById(x),ba=$i('ba'),bt=$i('bt'),bc=$i('bc'),bj=$i('bj');let count=0,looping=false;const counted=async()=>{bc.textContent='در حال شمارش…';count=(await api('broadcast',{action:'count',audience:ba.value})).count;bc.textContent=num(count)+' نفر'};ba.onchange=counted;counted().catch(x=>toast(x.message,1));
const pv=()=>{$i('pv').textContent=bt.value||'متن پیام این‌جا دیده می‌شود…';const t=$i('bbt').value.trim();$i('pvb').hidden=!t;$i('pvb').textContent=t?'🔗 '+t:''};[bt,$i('bbt')].forEach(x=>x.oninput=pv);
const item=(j,cur)=>{const done=j.sent+j.failed,p=j.total?Math.min(100,done/j.total*100):100,lab={running:'● در حال ارسال',done:'✅ تمام شد',stopped:'⏸ متوقف'}[j.status];return `<div class="kv" style="display:block;padding:12px 0"><div class="row between"><span class="row"><span class="pill ${j.status==='done'?'ok':j.status==='running'?'a':'warn'}">${lab}</span><span class="dim" style="font-size:12.5px">${esc(audName[j.audience]||j.audience)} · ${ago(j.started)}</span></span>${cur&&j.status==='running'?`<span class="row">${looping?'':'<button class="btn" id="jc">▶️ ادامه</button>'}<button class="btn g" id="js">⏸ توقف</button></span>`:''}</div><p style="margin:6px 0;white-space:pre-wrap" dir="auto">${esc(j.text.slice(0,220))}${j.button_text?' <span class="pill a">🔗 '+esc(j.button_text)+'</span>':''}</p><div class="meter"><div style="width:${p}%"></div></div><p class="dim" style="margin:4px 0 0;font-size:12.5px">${num(j.sent)} رسید · ${num(j.failed)} نرسید · از ${num(j.total)} نفر${j.last_error?' · آخرین خطا: '+esc(j.last_error):''}</p></div>`};
const show=r=>{const j=r.job,list=[...(j?[j]:[]),...(r.history||[])];bj.innerHTML=list.length?list.map((x,i)=>item(x,i===0&&!!j)).join(''):'<span class="dim">هنوز پیامی برای همه فرستاده نشده.</span>';const c=bj.querySelector('#jc'),s=bj.querySelector('#js');if(c)c.onclick=loop;if(s)s.onclick=async()=>{looping=false;show(await api('broadcast',{action:'stop'}))}};
const loop=async()=>{if(looping)return;looping=true;try{for(;;){const r=await api('broadcast',{action:'step'});if(!document.getElementById('bj')){looping=false;return}if(!looping)return;if(r.job?.status!=='running')looping=false;show(r);if(!looping)return;if(r.busy)await new Promise(z=>setTimeout(z,1500))}}catch(x){looping=false;toast(x.message,1);const r=await api('broadcast',{action:'status'}).catch(()=>null);if(r)show(r)}};
const body=()=>({text:bt.value,button_text:$i('bbt').value,button_url:$i('bbu').value});$i('bx').onclick=async()=>{if(!bt.value.trim())return toast('اول پیام را بنویسید',1);try{await api('broadcast',{action:'test',...body()});toast('برای شما فرستاده شد')}catch(x){toast(x.message,1)}};
$i('bs').onclick=async()=>{if(!bt.value.trim())return toast('اول پیام را بنویسید',1);await counted();if(!confirm(`این پیام برای ${num(count)} نفر فرستاده شود؟ بعد از شروع برگشت‌پذیر نیست.`))return;try{const r=await api('broadcast',{action:'start',audience:ba.value,...body()});bt.value='';$i('bbt').value='';$i('bbu').value='';pv();show(r);loop()}catch(x){toast(x.message,1)}};show(await api('broadcast',{action:'status'}))}

async function Payments(){main.innerHTML='<div class="grid" id="ps"></div><div class="card gap"><div class="row between"><div class="chips" id="pf"></div><input id="pq" placeholder="جست‌وجوی کاربر یا رسید" style="max-width:280px"></div><div class="scroll" id="tb" style="margin-top:10px">در حال بارگذاری…</div></div>';const rows=await api('payments');await loadSet();const paid=rows.filter(p=>p.status==='paid'),sum=a=>a.reduce((s,p)=>s+ +p.amount/10,0),c=(v,l)=>`<div class="card stat"><span>${l}</span><b>${v}</b></div>`,now=Date.now()+OFFSET,month=paid.filter(p=>now-new Date(p.created_at.replace(' ','T')+'Z').getTime()<30*864e5);document.getElementById('ps').innerHTML=c(num(sum(month)),'تومان، ۳۰ روز')+c(num(month.length),'پرداخت موفق، ۳۰ روز')+c(num(month.length?Math.round(sum(month)/month.length):0),'میانگین پرداخت')+c(num(rows.length-paid.length),'ناموفق');
let st='all';const draw=()=>{const q=document.getElementById('pq').value.toLowerCase();document.getElementById('pf').innerHTML=[['all','همه'],['paid','موفق'],['other','ناموفق']].map(([k,l])=>`<button data-s="${k}" class="${st===k?'on':''}">${l}</button>`).join('');const list=rows.filter(p=>(st==='all'||(st==='paid')===(p.status==='paid'))&&(!q||JSON.stringify(p).toLowerCase().includes(q)));document.getElementById('tb').innerHTML=list.length?`<table><tr><th>تاریخ</th><th>کاربر</th><th>پلن</th><th>تومان</th><th>روز</th><th>وضعیت</th><th>رسید</th></tr>${list.map(p=>`<tr class="click" data-u="${esc(p.telegram_user_id)}"><td class="dim">${esc(p.created_at)}</td><td dir="auto">${esc(p.first_name)} ${p.username?'<span class="dim">@'+esc(p.username)+'</span>':''}</td><td>${esc(SET?.plans?.[p.package]?.title||p.package)}</td><td>${num(p.amount/10)}</td><td>${num(p.days)}</td><td><span class="pill ${p.status==='paid'?'ok':'warn'}">${p.status==='paid'?'موفق':esc(p.status)}</span></td><td class="dim">${esc(p.charge_id)}</td></tr>`).join('')}</table>`:'<p class="dim" style="padding:24px;text-align:center">پرداختی نیست.</p>'};document.getElementById('pf').onclick=e=>{const b=e.target.closest('[data-s]');if(b){st=b.dataset.s;draw()}};document.getElementById('pq').oninput=draw;document.getElementById('tb').onclick=e=>{const r=e.target.closest('[data-u]');if(r)openUser(r.dataset.u)};draw()}

const limitFields=[['chat_per_day','پیام در روز (‎-1 یعنی نامحدود)'],['chat_per_month','پیام در ماه (۰ یعنی بدون سقف)'],['voice_minutes_per_day','دقیقه صدا در روز'],['image_per_day','تصویر در روز'],['voice_per_week','صدا در هفته (۰ یعنی بدون سقف)'],['image_per_week','تصویر در هفته (۰ یعنی بدون سقف)'],['image_per_month','تصویر در ماه (۰ یعنی بدون سقف)'],['vision_per_day','تحلیل تصویر در روز']];
function limitHtml(o,pre){return limitFields.map(([k,l])=>`<label>${l}<input type="number" data-k="${pre}.${k}" value="${esc(o[k]??0)}"></label>`).join('')}
function tierChecks(o,pre){return `<h3>مدل‌های مجاز</h3><div class="row">`+Object.entries(SET.tiers).map(([k,t])=>`<label class="c"><input type="checkbox" data-tier="${pre}" value="${esc(k)}" ${(o.tiers||[]).includes(k)?'checked':''}>${esc(t.label)}</label>`).join('')+'</div>'}
function saveAll(){return api('save',SET).then(()=>toast('ذخیره شد. ربات از پیام بعدی از آن استفاده می‌کند.')).catch(e=>toast(e.message,1))}
function collect(){main.querySelectorAll('[data-k]').forEach(i=>{const path=i.dataset.k.split('.');let o=SET;path.slice(0,-1).forEach(k=>o=o[k]??={});o[path.at(-1)]=i.type==='checkbox'?i.checked:i.value});main.querySelectorAll('[data-list]').forEach(i=>{const path=i.dataset.list.split('.');let o=SET;path.slice(0,-1).forEach(k=>o=o[k]??={});o[path.at(-1)]=i.value.split(/[\s,]+/).filter(Boolean)});const seen={};main.querySelectorAll('[data-tier]').forEach(i=>{const pre=i.dataset.tier;seen[pre]??=[];if(i.checked)seen[pre].push(i.value)});Object.entries(seen).forEach(([pre,v])=>{const path=pre.split('.');let o=SET;path.forEach(k=>o=o[k]);o.tiers=v})}
async function Plans(){await loadSet();const render=()=>{main.innerHTML=`<div class="two" style="margin-top:0">${Object.entries(SET.plans).map(([k,p])=>`<div class="card"><div class="row" style="justify-content:space-between"><h2 style="margin:0">${esc(p.title)} <span class="pill">${esc(k)}</span></h2><button class="btn g" data-del="${esc(k)}">حذف</button></div><label class="c" style="margin-top:10px"><input type="checkbox" data-k="plans.${esc(k)}.enabled" ${p.enabled?'checked':''}>در حال فروش</label><div class="fields"><label>عنوان<input data-k="plans.${esc(k)}.title" value="${esc(p.title)}" dir="auto"></label><label>قیمت (تومان)<input type="number" data-k="plans.${esc(k)}.price_toman" value="${esc(p.price_toman)}"></label><label>مدت (روز)<input type="number" data-k="plans.${esc(k)}.days" value="${esc(p.days)}"></label>${limitHtml(p,'plans.'+k)}</div>${tierChecks(p,'plans.'+k)}</div>`).join('')}<div class="card"><h2>کاربران رایگان</h2><div class="fields">${limitHtml(SET.free_limits,'free_limits')}</div>${tierChecks(SET.free_limits,'free_limits')}</div><div class="card"><h2>PRO دعوتی</h2><p class="dim" style="margin-top:0">کسانی که با دعوت دوستان یا کد مخفی PRO گرفته‌اند. این سهمیه را کمتر از پلن‌های پولی نگه دارید.</p><div class="fields">${limitHtml(SET.pro_limits,'pro_limits')}</div>${tierChecks(SET.pro_limits,'pro_limits')}</div></div><div class="card gap"><h2>نرخ دلار</h2><label style="max-width:240px">تومان برای هر دلار، برای تخمین سود<input type="number" data-k="usd_toman" value="${esc(SET.usd_toman)}"></label></div><div class="savebar row"><input id="nk" placeholder="کلید پلن جدید: حروف کوچک انگلیسی" style="width:230px"><button class="btn g" id="add">افزودن پلن</button><button class="btn" id="sv">ذخیره</button></div>`;
document.getElementById('sv').onclick=()=>{collect();saveAll()};document.getElementById('add').onclick=()=>{collect();const k=document.getElementById('nk').value.trim();if(!/^[a-z]{1,20}$/.test(k))return toast('فقط حروف کوچک انگلیسی',1);SET.plans[k]={title:k,price_toman:100000,days:30,enabled:false,chat_per_day:30,chat_per_month:300,voice_minutes_per_day:0,voice_per_week:0,image_per_day:0,image_per_week:0,image_per_month:0,vision_per_day:0,tiers:Object.keys(SET.tiers)};render()};main.querySelectorAll('[data-del]').forEach(b=>b.onclick=()=>{if(confirm('این پلن حذف شود؟ کاربرانش PRO دعوتی می‌شوند.')){collect();delete SET.plans[b.dataset.del];render()}})};render()}

async function Models(){await loadSet();const A=[['text','💬 متن'],['voice','🎤 صدا به متن'],['image','🖼 ساخت تصویر'],['vision','👁 تحلیل تصویر']],P=['metis','gapgpt'],name={metis:'متیس',gapgpt:'گپ‌جی‌پی‌تی'},m=SET.media;
main.innerHTML=`<div class="card"><h2>قابلیت‌ها</h2><div class="row"><label class="c"><input type="checkbox" data-k="media.voice_enabled" ${m.voice_enabled?'checked':''}>صدا به متن</label><label class="c"><input type="checkbox" data-k="media.images_enabled" ${m.images_enabled?'checked':''}>ساخت تصویر</label><label class="c"><input type="checkbox" data-k="media.vision_enabled" ${m.vision_enabled?'checked':''}>تحلیل تصویر</label><label class="c"><input type="checkbox" data-k="features.secret_pro_code" ${SET.features.secret_pro_code?'checked':''}>کد مخفی PRO <span class="warn">(قابل حدس: هر کسی ۷ روز می‌گیرد)</span></label></div></div>
<div class="card" style="margin-top:14px"><h2>چه کسی اول پاسخ بدهد</h2><p class="dim" style="margin-top:0">هر درخواست اول به سرویس اول می‌رود و اگر خطا داد به دومی.</p><div class="fields">${A.map(([a,l])=>`<label>${l}<select data-order="${a}">${[['metis','gapgpt'],['gapgpt','metis'],['metis'],['gapgpt']].map(o=>`<option value="${o.join(',')}" ${(m.order[a]||[]).join(',')===o.join(',')?'selected':''}>${o.map(x=>name[x]).join(' → ')}</option>`).join('')}</select></label>`).join('')}<label>مدل‌های رایگان OpenRouter<select data-k="routing_mode">${[['paid_only','خاموش: فقط سرویس‌های پولی'],['free_first','روشن: اول مدل‌های رایگان (رله لازم است)']].map(([v,l])=>`<option value="${v}" ${SET.routing_mode===v?'selected':''}>${l}</option>`).join('')}</select></label></div></div>
<div class="card" style="margin-top:14px"><h2>مدل‌های رسانه</h2><div class="fields">${A.slice(1).flatMap(([a,l])=>P.map(p=>`<label>${l}: ${name[p]}<input list="dl-${p}" data-k="media.models.${a}.${p}" value="${esc(m.models[a]?.[p]||'')}"></label>`)).join('')}<label>اندازه تصویر<select data-k="media.image.size">${['1024x1024','1024x1536','1536x1024','auto'].map(v=>`<option ${m.image.size===v?'selected':''}>${v}</option>`).join('')}</select></label><label>کیفیت تصویر<select data-k="media.image.quality">${['low','medium','high','auto'].map(v=>`<option ${m.image.quality===v?'selected':''}>${v}</option>`).join('')}</select></label></div></div>
<div class="two">${Object.entries(SET.tiers).map(([k,t])=>`<div class="card"><h2>مدل: ${esc(k)}</h2><label>نام دکمه<input data-k="tiers.${esc(k)}.label" value="${esc(t.label)}" dir="auto"></label><label>مدل‌های متیس، به ترتیب<textarea data-list="tiers.${esc(k)}.paid_models">${esc((t.paid_models||[]).join('\n'))}</textarea></label><label>مدل‌های گپ‌جی‌پی‌تی، به ترتیب<textarea data-list="tiers.${esc(k)}.gapgpt_models">${esc((t.gapgpt_models||[]).join('\n'))}</textarea></label><label>مدل‌های رایگان OpenRouter<textarea data-list="tiers.${esc(k)}.free_models">${esc((t.free_models||[]).join('\n'))}</textarea></label></div>`).join('')}</div>
<div class="card" style="margin-top:14px"><h2>مدل‌های موجود</h2><div class="two" id="av" style="margin-top:0"><span class="dim">در حال بارگذاری…</span></div></div><div class="savebar"><button class="btn" id="sv">ذخیره</button></div>`;
document.getElementById('sv').onclick=()=>{collect();main.querySelectorAll('[data-order]').forEach(s=>SET.media.order[s.dataset.order]=s.value.split(','));saveAll()};
api('models').then(d=>{document.getElementById('av').innerHTML=P.map(p=>{const l=d[p]||[];document.getElementById('dl-'+p).innerHTML=l.map(x=>`<option value="${esc(x)}">`).join('');return `<div><h3>${name[p]}: ${l.length} مدل</h3><pre style="max-height:220px">${esc(l.join('\n'))}</pre></div>`}).join('')}).catch(e=>toast(e.message,1))}

async function Prompts(){await loadSet();const F=[['chat','پرامپت سیستم چت','جای‌گذاری‌ها: {date} {time} {plan} {limit} {first_name} {last_name} {username}'],['vision','پرامپت تحلیل تصویر','وقتی کاربر عکس می‌فرستد.'],['summary','پرامپت خلاصه چت','برای کوتاه کردن حافظه چت‌های طولانی.']];main.innerHTML=F.map(([k,l,h])=>`<div class="card" style="margin-bottom:14px"><h2>${l}</h2><p class="dim" style="margin-top:0">${h}</p><textarea data-k="prompts.${k}" dir="auto" style="min-height:${k==='chat'?260:110}px;font:14px/1.6 system-ui,Tahoma,sans-serif"></textarea></div>`).join('')+'<div class="savebar"><button class="btn" id="sv">ذخیره</button></div>';F.forEach(([k])=>main.querySelector(`[data-k="prompts.${k}"]`).value=SET.prompts[k]);document.getElementById('sv').onclick=()=>{collect();saveAll()}}

function Tests(){const T=[['db','پایگاه داده','رایگان'],['bale','ربات بله و وب‌هوک','رایگان'],['text:metis','متن: متیس','هزینه ناچیز'],['text:gapgpt','متن: گپ‌جی‌پی‌تی','هزینه ناچیز'],['voice_real','صدا، کل مسیر: گفتار واقعی فارسی از طریق بله','هزینه ناچیز · یک صدا برایتان می‌فرستد'],['voice:metis','صدا: متیس (فایل بی‌صدا)','هزینه ناچیز'],['voice:gapgpt','صدا: گپ‌جی‌پی‌تی (فایل بی‌صدا)','هزینه ناچیز'],['vision:metis','تحلیل تصویر: متیس','هزینه ناچیز'],['vision:gapgpt','تحلیل تصویر: گپ‌جی‌پی‌تی','هزینه ناچیز'],['image:metis','ساخت تصویر: متیس','هزینه یک تصویر'],['image:gapgpt','ساخت تصویر: گپ‌جی‌پی‌تی','هزینه یک تصویر'],['openrouter','OpenRouter / رله','رایگان']];
main.innerHTML=`<div class="card"><div class="row" style="justify-content:space-between"><h2 style="margin:0">تست‌های زنده</h2><button class="btn" id="all">اجرای تست‌های رایگان و کم‌هزینه</button></div><div id="tests" style="margin-top:8px">${T.map(([n,l,c])=>`<div class="test" data-n="${n}"><b style="min-width:230px">${l}</b><span class="pill">${c}</span><div class="dim r"></div><button class="btn g run">اجرا</button></div>`).join('')}</div></div><div class="card" style="margin-top:14px"><div class="row" style="justify-content:space-between"><h2 style="margin:0">تست‌های کد</h2><button class="btn g" id="suite">اجرا</button></div><p class="dim">بدون تماس با سرویس‌ها و بدون هزینه.</p><pre id="sout"></pre></div>`;
const run=async(el)=>{const r=el.querySelector('.r');r.className='dim r';r.textContent='در حال اجرا…';try{const d=await api('test',{name:el.dataset.n});r.className='r '+(d.ok?'ok':'bad');r.textContent=(d.ok?'✔ ':'✘ ')+d.detail+' ('+d.ms+' ms)';if(d.image){const i=new Image();i.src=d.image;r.append(i)}}catch(x){r.className='r bad';r.textContent='✘ '+x.message}};
main.querySelectorAll('.run').forEach(b=>b.onclick=()=>run(b.closest('.test')));document.getElementById('all').onclick=async()=>{for(const el of main.querySelectorAll('.test')){if(!el.dataset.n.startsWith('image')&&el.dataset.n!=='voice_real')await run(el)}};document.getElementById('suite').onclick=async()=>{const o=document.getElementById('sout');o.textContent='در حال اجرا…';const r=await fetch('?api=suite');const t=await r.text();o.textContent=t.split('\n').filter(l=>!l.includes('✔')).join('\n').trim()}}

let start='Overview';try{start=pages[sessionStorage.page]?sessionStorage.page:start}catch(e){}go(start);
</script></body></html>
