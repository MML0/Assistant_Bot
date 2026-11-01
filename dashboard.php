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
                $plans[] = ['title' => $row['plan'] === null ? 'Invite PRO' : ($titles[$row['plan']] ?? $row['plan']), 'c' => (int) $row['c']];
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
                'cost_30d' => round($textCost + $voiceCost + $mediaCost, 4),
                'usd_toman' => max(1, (int) $s['usd_toman']),
                'series' => [
                    'messages' => $fill("SELECT DATE(created_at) d, COUNT(*) v FROM {$M} WHERE type='USER' AND created_at >= {$since} GROUP BY d"),
                    'active' => $fill("SELECT DATE(created_at) d, COUNT(DISTINCT user_id) v FROM {$M} WHERE type='USER' AND created_at >= {$since} GROUP BY d"),
                    'new_users' => $fill("SELECT DATE(created_at) d, COUNT(*) v FROM {$U} WHERE created_at >= {$since} GROUP BY d"),
                    'revenue' => $fill("SELECT DATE(created_at) d, SUM(amount) v FROM {$p}payments WHERE status='paid' AND created_at >= {$since} GROUP BY d", 10),
                ],
                'top' => $db->query("SELECT u.chat_id, u.first_name, u.username, COUNT(*) c FROM {$M} m JOIN {$U} u ON u.id = m.user_id WHERE m.type='USER' AND m.created_at >= CURDATE() GROUP BY u.id ORDER BY c DESC LIMIT 6")->fetchAll(),
                'media' => $db->query("SELECT action, status, COUNT(*) c FROM {$p}media_usage WHERE created_at >= CURDATE() GROUP BY action, status")->fetchAll(),
                'plans' => $plans,
                'host' => hostStats(),
            ]);
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
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bot Admin</title>
<style>
:root{color-scheme:light;--bg:#f5f6f8;--panel:#fff;--side:#fff;--line:#e5e7ec;--text:#15181e;--dim:#667085;--a:#2563eb;--a-soft:#e8effd;--ok:#12855a;--ok-soft:#e3f5ee;--bad:#c9304a;--bad-soft:#fbe9ec;--warn:#a96400;--warn-soft:#fbf1dd;--hover:#f2f4f7;--shadow:0 1px 2px rgba(16,24,40,.05)}
:root[data-theme=dark]{color-scheme:dark;--bg:#0f1115;--panel:#171a21;--side:#13161c;--line:#272b35;--text:#eceef2;--dim:#979eae;--a:#6d9bff;--a-soft:#1d2a47;--ok:#47c996;--ok-soft:#14302a;--bad:#f0758a;--bad-soft:#3a1d24;--warn:#e4ae56;--warn-soft:#352a14;--hover:#1e222b;--shadow:none}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]){color-scheme:dark;--bg:#0f1115;--panel:#171a21;--side:#13161c;--line:#272b35;--text:#eceef2;--dim:#979eae;--a:#6d9bff;--a-soft:#1d2a47;--ok:#47c996;--ok-soft:#14302a;--bad:#f0758a;--bad-soft:#3a1d24;--warn:#e4ae56;--warn-soft:#352a14;--hover:#1e222b;--shadow:none}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 Inter,system-ui,-apple-system,"Segoe UI",Vazirmatn,Tahoma,sans-serif;display:flex;min-height:100vh}
aside{width:216px;flex:none;background:var(--side);border-right:1px solid var(--line);padding:18px 12px;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;gap:4px}
aside h1{font-size:15px;font-weight:650;margin:0 8px 14px;display:flex;gap:8px;align-items:center}aside h1 i{width:9px;height:9px;border-radius:50%;background:var(--ok);display:inline-block}
nav{display:flex;flex-direction:column;gap:2px;flex:1}nav button{all:unset;cursor:pointer;padding:8px 10px;border-radius:7px;color:var(--dim);font-weight:500;display:block}nav button:hover{background:var(--hover);color:var(--text)}nav button.on{background:var(--a-soft);color:var(--a)}nav button:focus-visible,.btn:focus-visible{outline:2px solid var(--a);outline-offset:1px}
.foot{display:flex;gap:6px;align-items:center}.foot form{margin:0}
.wrap{flex:1;min-width:0;display:flex;flex-direction:column}.top{padding:20px 26px 0}.top h2{font-size:20px;font-weight:650;margin:0}.top p{margin:2px 0 0;color:var(--dim)}main{padding:16px 26px 40px;max-width:1320px;width:100%}
.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(165px,1fr))}.two{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));margin-top:12px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:16px;box-shadow:var(--shadow);min-width:0}.gap{margin-top:12px}
.stat span{color:var(--dim);font-size:13px;display:block}.stat b{display:block;font-size:25px;font-weight:650;font-variant-numeric:tabular-nums;margin:2px 0}.stat small{font-size:12px;color:var(--dim)}.stat small.up{color:var(--ok)}.stat small.down{color:var(--bad)}
.card h2,.h{font-size:14px;font-weight:600;margin:0 0 10px}h3{font-size:13px;margin:14px 0 6px;color:var(--dim);font-weight:600}
.tabs{display:flex;gap:4px}.tabs button{all:unset;cursor:pointer;padding:3px 9px;border-radius:6px;color:var(--dim);font-size:12.5px}.tabs button.on{background:var(--a-soft);color:var(--a)}
.chart{position:relative}.chart svg{display:block;width:100%;height:190px}.tip{position:absolute;pointer-events:none;background:var(--text);color:var(--panel);font-size:12px;padding:3px 8px;border-radius:6px;white-space:nowrap;transform:translate(-50%,-110%);display:none}
.feed{overflow:auto;display:flex;flex-direction:column;gap:6px}.msg{padding:8px 10px;border-radius:8px;background:var(--hover);overflow-wrap:anywhere}.msg.AI{background:var(--a-soft)}.msg small{color:var(--dim);display:block;margin-bottom:2px}.msg p{margin:0;direction:rtl;text-align:right;white-space:pre-wrap}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line);white-space:nowrap}th{color:var(--dim);font-weight:500;font-size:12.5px}tr.click{cursor:pointer}tr.click:hover td{background:var(--hover)}.scroll{overflow-x:auto}td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}
input,select,textarea{background:var(--panel);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:7px 10px;font:inherit;width:100%}input:focus,select:focus,textarea:focus{outline:2px solid var(--a);outline-offset:-1px;border-color:transparent}textarea{min-height:58px;font:13px ui-monospace,Menlo,monospace}label{display:block;font-size:13px;color:var(--dim);margin-bottom:9px}label.c{display:flex;gap:8px;align-items:center;color:var(--text)}label.c input{width:auto}.fields{display:grid;gap:0 10px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
.btn{background:var(--a);border:1px solid transparent;color:#fff;padding:7px 13px;border-radius:7px;cursor:pointer;font:inherit;font-weight:550}:root[data-theme=dark] .btn{color:#0f1115}.btn.g{background:var(--panel);border-color:var(--line);color:var(--text);font-weight:500}.btn.g:hover{background:var(--hover)}.btn.r{background:var(--bad);color:#fff}.btn:disabled{opacity:.5;cursor:default}.savebar{position:sticky;bottom:12px;margin-top:14px;display:flex;justify-content:flex-end;gap:8px}
.pill{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px;font-weight:500;background:var(--hover);color:var(--dim)}.pill.ok{background:var(--ok-soft);color:var(--ok)}.pill.bad{background:var(--bad-soft);color:var(--bad)}.pill.warn{background:var(--warn-soft);color:var(--warn)}.pill.a{background:var(--a-soft);color:var(--a)}.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}.dim{color:var(--dim)}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.between{justify-content:space-between}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:12.5px ui-monospace,Menlo,monospace;color:var(--dim);margin:0;max-height:420px;overflow:auto}
#toast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:var(--text);color:var(--panel);padding:9px 15px;border-radius:8px;display:none;z-index:30;max-width:90vw}.meter{height:7px;border-radius:9px;background:var(--hover);overflow:hidden;margin-top:4px}.meter div{height:100%;background:var(--a);border-radius:9px}.meter.hi div{background:var(--bad)}.kv{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid var(--line)}.kv:last-child{border:0}.kv b{font-weight:550;font-variant-numeric:tabular-nums}
.test{display:flex;gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--line);flex-wrap:wrap}.test>div{flex:1;min-width:190px;overflow-wrap:anywhere}.test img{width:96px;border-radius:8px}
.chips{display:flex;gap:6px;flex-wrap:wrap}.chips button{all:unset;cursor:pointer;padding:4px 11px;border:1px solid var(--line);border-radius:99px;font-size:13px;color:var(--dim);background:var(--panel)}.chips button b{font-weight:550;margin-left:4px;color:var(--text)}.chips button.on{background:var(--a-soft);border-color:transparent;color:var(--a)}.chips button.on b{color:var(--a)}
.filters{display:grid;gap:8px;grid-template-columns:minmax(220px,2fr) repeat(2,minmax(130px,1fr))}.pager{display:flex;justify-content:space-between;align-items:center;margin-top:12px;color:var(--dim)}
#drawer{position:fixed;inset:0;z-index:20;display:none}#drawer.open{display:block}#drawer .shade{position:absolute;inset:0;background:rgba(10,12,18,.42)}#drawer .panel{position:absolute;top:0;right:0;bottom:0;width:min(480px,100%);background:var(--panel);border-left:1px solid var(--line);padding:20px;overflow:auto}
@media (max-width:800px){body{display:block}aside{position:static;width:auto;height:auto;border-right:0;border-bottom:1px solid var(--line);padding:10px 12px}aside h1{margin:0 4px 8px}nav{flex-direction:row;overflow-x:auto}nav button{white-space:nowrap}.foot{margin-top:8px}.top{padding:14px 16px 0}main{padding:12px 16px 30px}.filters{grid-template-columns:1fr 1fr}.filters>:first-child{grid-column:1/-1}}
</style></head><body>
<aside><h1><i></i>Bot Admin</h1><nav id="nav"></nav><div class="foot"><button class="btn g" id="theme" title="Switch light or dark">◐</button><form method="post" action="?logout=1"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button class="btn g">Sign out</button></form></div></aside>
<div class="wrap"><div class="top"><h2 id="ptitle"></h2><p id="psub"></p></div><main id="main"></main></div><div id="drawer"><div class="shade"></div><div class="panel" id="dp"></div></div><div id="toast"></div><datalist id="dl-metis"></datalist><datalist id="dl-gapgpt"></datalist>
<script>
const CSRF=<?= json_encode($csrf) ?>;const $=(h)=>{const t=document.createElement('template');t.innerHTML=h.trim();return t.content.firstChild};
const esc=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));const num=(n)=>Number(n||0).toLocaleString('en-US');
const toast=(m,bad)=>{const t=document.getElementById('toast');t.textContent=m;t.style.display='block';t.style.color=bad?'var(--bad)':'var(--ok)';clearTimeout(toast.t);toast.t=setTimeout(()=>t.style.display='none',3500)};
async function api(name,body,qs=''){const r=await fetch('?api='+name+qs,body?{method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},body:JSON.stringify(body)}:{});if(r.status===401){location.reload();throw new Error('signed out')}const j=await r.json().catch(()=>({error:'Bad server response (HTTP '+r.status+')'}));if(j&&j.error)throw new Error(j.error);return j}
let SET=null;const loadSet=async()=>SET=await api('settings');
const OFFSET=new Date(<?= json_encode(date('Y-m-d\TH:i:s')) ?>+'Z').getTime()-Date.now();
const main=document.getElementById('main');let timers=[];const every=(f,ms)=>{f();timers.push(setInterval(f,ms))};
const pages={Overview,Live,Users,Payments,Plans,Models,Prompts,Tests};const subs={Overview:'How the bot is doing right now',Live:'Messages as they arrive',Users:'Search, filter and manage everyone',Payments:'Income and every payment',Plans:'Prices and limits for each kind of user',Models:'Who answers, and with which model',Prompts:'What the bot is told to be',Tests:'Check that each service works'};const nav=document.getElementById('nav');
function go(p){timers.forEach(clearInterval);timers=[];[...nav.children].forEach(b=>b.classList.toggle('on',b.textContent===p));main.innerHTML='';document.getElementById('ptitle').textContent=p;document.getElementById('psub').textContent=subs[p];try{sessionStorage.page=p}catch(e){}pages[p]()}
Object.keys(pages).forEach(p=>{const b=document.createElement('button');b.textContent=p;b.onclick=()=>go(p);nav.append(b)});
const root=document.documentElement;try{if(localStorage.theme)root.dataset.theme=localStorage.theme}catch(e){}document.getElementById('theme').onclick=()=>{const dark=root.dataset.theme?root.dataset.theme==='dark':matchMedia('(prefers-color-scheme:dark)').matches;root.dataset.theme=dark?'light':'dark';try{localStorage.theme=root.dataset.theme}catch(e){}};
const ago=(t)=>{if(!t)return '—';const s=(Date.now()+OFFSET-new Date(String(t).replace(' ','T')+'Z').getTime())/1000;if(s<60)return 'just now';if(s<3600)return Math.floor(s/60)+' min ago';if(s<86400)return Math.floor(s/3600)+' h ago';if(s<86400*60)return Math.floor(s/86400)+' d ago';return String(t).slice(0,10)};
function lineChart(el,rows,fmt=num){const W=720,H=190,L=38,B=20,T=10,R=6,vals=rows.map(r=>+r.v),max=Math.max(1,...vals),x=i=>L+(rows.length<2?0:i*(W-L-R)/(rows.length-1)),y=v=>T+(H-T-B)*(1-v/max);const pts=rows.map((r,i)=>x(i)+','+y(+r.v)).join(' ');
const grid=[0,.5,1].map(f=>`<line x1="${L}" x2="${W-R}" y1="${y(max*f)}" y2="${y(max*f)}" stroke="var(--line)"/><text x="${L-6}" y="${y(max*f)+4}" text-anchor="end" font-size="11" fill="var(--dim)">${compact(max*f)}</text>`).join('');const labels=rows.map((r,i)=>i%Math.ceil(rows.length/6)===0?`<text x="${x(i)}" y="${H-5}" text-anchor="middle" font-size="11" fill="var(--dim)">${esc(r.d.slice(5))}</text>`:'').join('');
el.innerHTML=`<div class="chart"><svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="Chart">${grid}<polygon points="${L},${y(0)} ${pts} ${x(rows.length-1)},${y(0)}" fill="var(--a)" opacity=".1"/><polyline points="${pts}" fill="none" stroke="var(--a)" stroke-width="2" vector-effect="non-scaling-stroke"/><circle r="4" fill="var(--a)" style="display:none"/>${labels}</svg><div class="tip"></div></div>`;
const svg=el.querySelector('svg'),tip=el.querySelector('.tip'),dot=el.querySelector('circle');svg.onmousemove=e=>{if(!rows.length)return;const b=svg.getBoundingClientRect(),i=Math.max(0,Math.min(rows.length-1,Math.round(((e.clientX-b.left)/b.width*W-L)/((W-L-R)/Math.max(1,rows.length-1)))));dot.setAttribute('cx',x(i));dot.setAttribute('cy',y(+rows[i].v));dot.style.display='';tip.style.display='block';tip.style.left=x(i)/W*100+'%';tip.style.top=y(+rows[i].v)/H*190+'px';tip.textContent=rows[i].d+': '+fmt(rows[i].v)};svg.onmouseleave=()=>{tip.style.display='none';dot.style.display='none'}}
const compact=(n)=>n>=1e6?(n/1e6).toFixed(1).replace('.0','')+'M':n>=1e3?(n/1e3).toFixed(1).replace('.0','')+'k':String(Math.round(n));

function Overview(){main.innerHTML=`<div class="grid" id="st"></div><div class="card gap"><div class="row between"><h2 class="h" style="margin:0">Last 30 days</h2><div class="tabs" id="ct"></div></div><div id="chart" style="margin-top:10px"></div></div><div class="two"><div class="card"><h2>Money, last 30 days</h2><div id="money"></div></div><div class="card" style="grid-column:1/-1"><h2>Provider wallets</h2><p class="dim" style="margin:0 0 10px;font-size:12.5px">MetisAI and GapGPT don't give the balance to an API key. Enter what your panel shows, and this subtracts the bot's estimated spending since then.</p><div class="two" id="wallets" style="margin-top:0"><span class="dim">Loading…</span></div></div><div class="card"><h2>Subscribers now</h2><div id="plans"></div></div><div class="card"><h2>Most active today</h2><div id="top"></div></div><div class="card"><h2>Media today</h2><div id="media"></div></div><div class="card"><h2>Server</h2><div id="host"></div></div></div>`;
loadWallets();let series='messages',D=null;const names={messages:'Messages',active:'Active users',new_users:'New users',revenue:'Income (toman)'};const draw=()=>{document.getElementById('ct').innerHTML=Object.entries(names).map(([k,l])=>`<button data-k="${k}" class="${k===series?'on':''}">${l}</button>`).join('');lineChart(document.getElementById('chart'),D.series[series])};document.getElementById('ct').onclick=e=>{const b=e.target.closest('[data-k]');if(b){series=b.dataset.k;draw()}};
every(async()=>{const d=D=await api('stats');const delta=(a,b)=>{if(!b)return '';const p=Math.round((a-b)/b*100);return `<small class="${p>=0?'up':'down'}">${p>=0?'+':''}${p}% vs yesterday</small>`};const c=(v,l,x='')=>`<div class="card stat"><span>${l}</span><b>${v}</b>${x||'<small>&nbsp;</small>'}</div>`;
document.getElementById('st').innerHTML=c(num(d.online),'Online now',`<small>last 5 minutes</small>`)+c(num(d.active_today),'Active today',delta(d.active_today,d.active_yesterday))+c(num(d.messages_today),'Messages today',delta(d.messages_today,d.messages_yesterday))+c(num(d.users),'All users',`<small>+${num(d.new_today)} today</small>`)+c(num(d.paying),'Paying users',`<small>${num(d.invited)} on invite PRO</small>`)+c(num(d.revenue_today/10),'Toman today',`<small>${num(d.paid_today)} payments</small>`);draw();
const kv=(k,v,cl='')=>`<div class="kv"><span class="dim">${k}</span><b class="${cl}">${v}</b></div>`;const net=d.revenue_30d/10/d.usd_toman-d.cost_30d;
document.getElementById('money').innerHTML=kv('Income',num(d.revenue_30d/10)+' toman')+kv('Income in dollars','$'+(d.revenue_30d/10/d.usd_toman).toFixed(2))+kv('Estimated API cost','$'+d.cost_30d.toFixed(2))+kv('Estimated profit',(net<0?'−':'')+'$'+Math.abs(net).toFixed(2),net<0?'bad':'ok')+`<p class="dim" style="margin:8px 0 0;font-size:12.5px">Cost is estimated from logged usage. Dollar rate: ${num(d.usd_toman)} toman.</p>`;
document.getElementById('plans').innerHTML=d.plans.map(p=>kv(esc(p.title),num(p.c))).join('')||'<span class="dim">No subscribers</span>';
document.getElementById('top').innerHTML=d.top.map(u=>`<div class="kv" style="cursor:pointer" data-u="${esc(u.chat_id)}"><span dir="auto">${esc(u.first_name||u.chat_id)} ${u.username?'<span class="dim">@'+esc(u.username)+'</span>':''}</span><b>${num(u.c)}</b></div>`).join('')||'<span class="dim">Nobody yet today</span>';document.getElementById('top').onclick=e=>{const r=e.target.closest('[data-u]');if(r)openUser(r.dataset.u)};
const act={image:'Images made',vision:'Images analysed'};document.getElementById('media').innerHTML=kv('Voices transcribed',num(d.voice_today))+['image','vision'].map(a=>{const ok=d.media.filter(m=>m.action===a&&m.status==='ok').reduce((s,m)=>s+ +m.c,0),bad=d.media.filter(m=>m.action===a&&m.status==='failed').reduce((s,m)=>s+ +m.c,0);return kv(act[a],num(ok)+(bad?` <span class="bad">· ${num(bad)} failed</span>`:''))}).join('');
const h=d.host,load=h.load?h.load[0]:null,pct=(a,b)=>b?Math.min(100,Math.round(a/b*100)):0,meter=(l,p,t)=>`<label>${l}<span style="float:right">${t}</span><div class="meter ${p>85?'hi':''}"><div style="width:${p}%"></div></div></label>`;document.getElementById('host').innerHTML=(load!==null?meter('CPU load, 1 min',pct(load,h.cpus||Math.max(load,1)*1.5),load.toFixed(2)+(h.cpus?' of '+h.cpus+' cores':'')):'<p class="dim">The host hides CPU load.</p>')+(h.mem?meter('Memory',pct(h.mem.used_mb,h.mem.total_mb),num(h.mem.used_mb)+' / '+num(h.mem.total_mb)+' MB'):'')+meter('Disk',pct(h.disk_total_gb-h.disk_free_gb,h.disk_total_gb),h.disk_free_gb+' GB free')+`<span class="dim" style="font-size:12.5px">PHP ${esc(h.php)}</span>`},15000)}

async function loadWallets(){const box=document.getElementById('wallets');if(!box)return;let d;try{d=await api('wallets')}catch(x){box.innerHTML=`<span class="bad">${esc(x.message)}</span>`;return}const links={metis:'https://metisai.ir',gapgpt:'https://gapgpt.app'},names={metis:'MetisAI',gapgpt:'GapGPT'};box.innerHTML=Object.keys(names).map(p=>{const w=d[p];return `<div class="card" style="box-shadow:none"><div class="row between"><b>${names[p]}</b><a href="${links[p]}" target="_blank" rel="noopener" class="dim">Open panel ↗</a></div>${w.balance!==null?`<div class="stat" style="margin:6px 0"><span>Estimated left</span><b class="${w.left<=0?'bad':''}">${num(Math.round(w.left))} T</b><small>${num(w.balance)} T on ${esc(w.since.slice(0,10))} − about $${w.spent.toFixed(3)} used</small></div>`:'<p class="dim" style="margin:8px 0">No balance entered yet.</p>'}<div class="row" style="flex-wrap:nowrap"><input type="number" min="0" placeholder="Balance now, in toman" data-w="${p}" aria-label="${names[p]} balance in toman"><button class="btn g" data-ws="${p}">Save</button></div></div>`}).join('');box.querySelectorAll('[data-ws]').forEach(b=>b.onclick=async()=>{const p=b.dataset.ws,v=box.querySelector(`[data-w="${p}"]`).value;if(v===''||+v<0)return toast('Enter the balance in toman',1);try{await api('wallets',{provider:p,balance:+v});toast('Saved');loadWallets()}catch(x){toast(x.message,1)}})}

function Live(){main.innerHTML=`<div class="card"><div class="row between"><span class="pill ok">● updates every 4 seconds</span><input id="lf" placeholder="Filter by text, name or chat ID" style="max-width:320px"></div><div class="feed" id="feed" style="max-height:calc(100vh - 210px);margin-top:10px"></div></div>`;let last=0;const f=document.getElementById('feed'),lf=document.getElementById('lf');const apply=()=>{const q=lf.value.toLowerCase();[...f.children].forEach(c=>c.style.display=!q||c.textContent.toLowerCase().includes(q)?'':'none')};lf.oninput=apply;f.onclick=e=>{const r=e.target.closest('[data-u]');if(r)openUser(r.dataset.u)};every(async()=>{const rows=await api('feed',null,'&after='+last);if(!rows.length){if(!last)f.innerHTML='<span class="dim">No messages yet</span>';return}if(!last)f.innerHTML='';rows.reverse().forEach(m=>{last=Math.max(last,+m.id);f.prepend($(`<div class="msg ${esc(m.type)}" data-u="${esc(m.chat_id)}" style="cursor:pointer"><small>${m.type==='AI'?'Bot → ':''}${esc(m.first_name)} ${m.username?'@'+esc(m.username):''} · ${esc(m.chat_id)} · ${esc(m.created_at)}</small><p>${esc(m.message)}</p></div>`))});while(f.children.length>200)f.lastChild.remove();apply()},4000)}

const statusPill=(u)=>{if(+u.baned)return '<span class="pill bad">Banned</span>';const on=+u.is_pro&&(!u.pro_expire||new Date(u.pro_expire.replace(' ','T')+'Z').getTime()>Date.now()+OFFSET);if(!on)return +u.is_pro?'<span class="pill warn">Expired</span>':'<span class="pill">Free</span>';return u.plan?`<span class="pill ok">${esc(SET.plans[u.plan]?.title||u.plan)}</span>`:'<span class="pill a">Invite PRO</span>'};
let uState={q:'',status:'all',active:'',sort:'newest',page:0};
async function Users(){await loadSet();main.innerHTML=`<div class="chips" id="ch"></div><div class="card gap"><div class="filters"><input id="q" placeholder="Search name, @username or chat ID" value="${esc(uState.q)}"><select id="ac" aria-label="Activity"><option value="">Any activity</option><option value="today">Active today</option><option value="7d">Active in 7 days</option><option value="30d">Active in 30 days</option><option value="inactive">Inactive for 30+ days</option></select><select id="so" aria-label="Sort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="messages">Most messages</option><option value="last">Recently active</option><option value="expiring">Expiring soon</option></select></div><div class="scroll" id="tb" style="margin-top:10px"></div><div class="pager"><span id="pc"></span><span class="row"><button class="btn g" id="prev">Previous</button><button class="btn g" id="next">Next</button></span></div></div>`;document.getElementById('ac').value=uState.active;document.getElementById('so').value=uState.sort;
const load=async()=>{const u=uState,d=await api('users',null,`&q=${encodeURIComponent(u.q)}&status=${u.status}&active=${u.active}&sort=${u.sort}&page=${u.page}`);if(!document.getElementById('tb'))return;const chips=[['all','All'],['free','Free'],['paying','Paying'],['invited','Invite PRO'],...Object.entries(SET.plans).map(([k,p])=>['plan_'+k,p.title]),['expired','Expired'],['banned','Banned']];document.getElementById('ch').innerHTML=chips.map(([k,l])=>`<button data-s="${esc(k)}" class="${u.status===k?'on':''}" dir="auto">${esc(l)}<b>${num(d.counts[k]??0)}</b></button>`).join('');
document.getElementById('tb').innerHTML=d.rows.length?`<table><tr><th>User</th><th>Chat ID</th><th>Status</th><th>Until</th><th class="n">Messages</th><th>Last active</th><th>Joined</th></tr>${d.rows.map(r=>`<tr class="click" data-u="${esc(r.chat_id)}"><td dir="auto">${esc(r.first_name)} ${esc(r.last_name)} ${r.username?'<span class="dim">@'+esc(r.username)+'</span>':''}</td><td class="dim">${esc(r.chat_id)}</td><td>${statusPill(r)}</td><td class="dim">${r.pro_expire?esc(r.pro_expire.slice(0,10)):'—'}</td><td class="n">${num(r.messages)}</td><td class="dim">${ago(r.last_active)}</td><td class="dim">${esc(String(r.created_at).slice(0,10))}</td></tr>`).join('')}</table>`:'<p class="dim" style="padding:24px;text-align:center">Nobody matches these filters.</p>';const pages=Math.max(1,Math.ceil(d.total/d.per));document.getElementById('pc').textContent=`${num(d.total)} users · page ${u.page+1} of ${pages}`;document.getElementById('prev').disabled=u.page<1;document.getElementById('next').disabled=u.page+1>=pages};Users.reload=load;
let t;document.getElementById('q').oninput=e=>{clearTimeout(t);uState.q=e.target.value;uState.page=0;t=setTimeout(load,300)};document.getElementById('ac').onchange=e=>{uState.active=e.target.value;uState.page=0;load()};document.getElementById('so').onchange=e=>{uState.sort=e.target.value;uState.page=0;load()};document.getElementById('ch').onclick=e=>{const b=e.target.closest('[data-s]');if(b){uState.status=b.dataset.s;uState.page=0;load()}};document.getElementById('prev').onclick=()=>{uState.page--;load()};document.getElementById('next').onclick=()=>{uState.page++;load()};document.getElementById('tb').onclick=e=>{const r=e.target.closest('[data-u]');if(r)openUser(r.dataset.u)};load().catch(x=>toast(x.message,1))}

const drawer=document.getElementById('drawer'),dp=document.getElementById('dp');const closeUser=()=>drawer.classList.remove('open');drawer.querySelector('.shade').onclick=closeUser;addEventListener('keydown',e=>{if(e.key==='Escape')closeUser()});
async function openUser(id){if(!SET)await loadSet();drawer.classList.add('open');dp.innerHTML='<span class="dim">Loading…</span>';let d;try{d=await api('userinfo',null,'&chat_id='+encodeURIComponent(id))}catch(x){dp.innerHTML=`<p class="bad">${esc(x.message)}</p>`;return}const u=d.user,us=d.usage,l=d.limits;const bar=(label,a,b)=>b>0?`<label>${label}<span style="float:right">${num(a)} of ${num(b)}</span><div class="meter ${a>=b?'hi':''}"><div style="width:${Math.min(100,a/b*100)}%"></div></div></label>`:(b<0?`<label>${label}<span style="float:right">${num(a)} · no limit</span></label>`:'');
dp.innerHTML=`<div class="row between"><h2 class="h" style="font-size:17px;margin:0" dir="auto">${esc(u.first_name)} ${esc(u.last_name)}</h2><button class="btn g" id="dx">Close</button></div><div class="dim">${u.username?'@'+esc(u.username)+' · ':''}${esc(u.chat_id)}</div><div class="row" style="margin-top:8px">${statusPill(u)}<span class="dim">${u.pro_expire?'until '+esc(u.pro_expire):''}</span></div>
<h3>Usage</h3>${bar('Messages today',us.chat_today,l.chat_per_day)}${bar('Messages this month',us.chat_month,l.chat_per_month)}${bar('Voice messages this week',us.voice_week,l.voice_per_week)}${bar('Voice minutes today',Math.ceil(us.voice_seconds_today/60),l.voice_minutes_per_day)}${bar('Images this week',us.image_week,l.image_per_week)}${bar('Images today',us.image_day,l.image_per_day)}${bar('Images this month',us.image_month,l.image_per_month)}${bar('Image analysis today',us.vision_day,l.vision_per_day)}
<div class="kv"><span class="dim">All messages</span><b>${num(u.messages)}</b></div><div class="kv"><span class="dim">Last active</span><b>${ago(u.last_active)}</b></div><div class="kv"><span class="dim">Joined</span><b>${esc(String(u.created_at).slice(0,10))}</b></div>
<h3>Change plan</h3><div class="row"><select id="gp" style="flex:1;width:auto"><option value="">Invite PRO</option>${Object.entries(SET.plans).map(([k,p])=>`<option value="${esc(k)}">${esc(p.title)}</option>`).join('')}</select><input id="gd" type="number" value="30" min="1" style="width:76px" aria-label="Days"><button class="btn" data-a="plan">Grant</button></div><div class="row" style="margin-top:8px"><button class="btn g" data-a="free">Make free</button><button class="btn ${+u.baned?'g':'r'}" data-a="ban" data-v="${+u.baned?0:1}">${+u.baned?'Unban':'Ban'}</button></div>
${d.payments.length?`<h3>Payments</h3>${d.payments.map(p=>`<div class="kv"><span class="dim">${esc(p.created_at)}</span><b class="${p.status==='paid'?'ok':'warn'}">${num(p.amount/10)} T · ${esc(p.package)}</b></div>`).join('')}`:''}
<h3>Recent messages</h3><div class="feed">${d.messages.map(m=>`<div class="msg ${esc(m.type)}"><small>${esc(m.created_at)}</small><p>${esc(m.message)}</p></div>`).join('')||'<span class="dim">None</span>'}</div>`;
dp.querySelector('#dx').onclick=closeUser;dp.querySelectorAll('[data-a]').forEach(b=>b.onclick=async()=>{const a=b.dataset.a;if(a==='free'&&!confirm('Remove this user\'s subscription?'))return;try{await api('user',{action:a,chat_id:id,value:+b.dataset.v,plan:dp.querySelector('#gp').value,days:+dp.querySelector('#gd').value});toast('Saved');openUser(id);if(Users.reload&&document.getElementById('tb'))Users.reload()}catch(x){toast(x.message,1)}})}

async function Payments(){main.innerHTML='<div class="grid" id="ps"></div><div class="card gap"><div class="row between"><div class="chips" id="pf"></div><input id="pq" placeholder="Search user or receipt" style="max-width:280px"></div><div class="scroll" id="tb" style="margin-top:10px">Loading…</div></div>';const rows=await api('payments');const paid=rows.filter(p=>p.status==='paid'),sum=a=>a.reduce((s,p)=>s+ +p.amount/10,0),c=(v,l)=>`<div class="card stat"><span>${l}</span><b>${v}</b></div>`,now=Date.now(),month=paid.filter(p=>now-new Date(p.created_at.replace(' ','T')+'Z').getTime()<30*864e5);document.getElementById('ps').innerHTML=c(num(sum(month)),'Toman, last 30 days')+c(num(month.length),'Paid, last 30 days')+c(num(month.length?sum(month)/month.length:0),'Average payment')+c(num(rows.length-paid.length),'Not completed');
let st='all';const draw=()=>{const q=document.getElementById('pq').value.toLowerCase();document.getElementById('pf').innerHTML=[['all','All'],['paid','Paid'],['other','Not completed']].map(([k,l])=>`<button data-s="${k}" class="${st===k?'on':''}">${l}</button>`).join('');const list=rows.filter(p=>(st==='all'||(st==='paid')===(p.status==='paid'))&&(!q||JSON.stringify(p).toLowerCase().includes(q)));document.getElementById('tb').innerHTML=list.length?`<table><tr><th>Date</th><th>User</th><th>Plan</th><th class="n">Toman</th><th class="n">Days</th><th>Status</th><th>Receipt</th></tr>${list.map(p=>`<tr class="click" data-u="${esc(p.telegram_user_id)}"><td class="dim">${esc(p.created_at)}</td><td dir="auto">${esc(p.first_name)} ${p.username?'<span class="dim">@'+esc(p.username)+'</span>':''}</td><td>${esc(SET?.plans?.[p.package]?.title||p.package)}</td><td class="n">${num(p.amount/10)}</td><td class="n">${esc(p.days)}</td><td><span class="pill ${p.status==='paid'?'ok':'warn'}">${esc(p.status)}</span></td><td class="dim">${esc(p.charge_id)}</td></tr>`).join('')}</table>`:'<p class="dim" style="padding:24px;text-align:center">No payments here yet.</p>'};document.getElementById('pf').onclick=e=>{const b=e.target.closest('[data-s]');if(b){st=b.dataset.s;draw()}};document.getElementById('pq').oninput=draw;document.getElementById('tb').onclick=e=>{const r=e.target.closest('[data-u]');if(r)openUser(r.dataset.u)};await loadSet();draw()}

const limitFields=[['chat_per_day','Chat messages / day (-1 = unlimited)'],['chat_per_month','Chat messages / month (0 = no monthly limit)'],['voice_minutes_per_day','Voice minutes / day'],['image_per_day','Images / day'],['voice_per_week','Voice messages / week (0 = no weekly limit)'],['image_per_week','Images / week (0 = no weekly limit)'],['image_per_month','Images / month (0 = no monthly limit)'],['vision_per_day','Image analysis / day']];
function limitHtml(o,pre){return limitFields.map(([k,l])=>`<label>${l}<input type="number" data-k="${pre}.${k}" value="${esc(o[k]??0)}"></label>`).join('')}
function tierChecks(o,pre){return `<h3>Tiers this set may use</h3><div class="row">`+Object.entries(SET.tiers).map(([k,t])=>`<label class="c"><input type="checkbox" data-tier="${pre}" value="${esc(k)}" ${(o.tiers||[]).includes(k)?'checked':''}>${esc(t.label)}</label>`).join('')+'</div>'}
function saveAll(){return api('save',SET).then(()=>toast('Saved. The bot uses this from the next message.')).catch(e=>toast(e.message,1))}
function collect(){main.querySelectorAll('[data-k]').forEach(i=>{const path=i.dataset.k.split('.');let o=SET;path.slice(0,-1).forEach(k=>o=o[k]??={});o[path.at(-1)]=i.type==='checkbox'?i.checked:i.value});main.querySelectorAll('[data-list]').forEach(i=>{const path=i.dataset.list.split('.');let o=SET;path.slice(0,-1).forEach(k=>o=o[k]??={});o[path.at(-1)]=i.value.split(/[\s,]+/).filter(Boolean)});const seen={};main.querySelectorAll('[data-tier]').forEach(i=>{const pre=i.dataset.tier;seen[pre]??=[];if(i.checked)seen[pre].push(i.value)});Object.entries(seen).forEach(([pre,v])=>{const path=pre.split('.');let o=SET;path.forEach(k=>o=o[k]);o.tiers=v})}
async function Plans(){await loadSet();const render=()=>{main.innerHTML=`<div class="two" style="margin-top:0">${Object.entries(SET.plans).map(([k,p])=>`<div class="card"><div class="row" style="justify-content:space-between"><h2 style="margin:0">${esc(p.title)} <span class="pill">${esc(k)}</span></h2><button class="btn g" data-del="${esc(k)}">Delete</button></div><label class="c" style="margin-top:10px"><input type="checkbox" data-k="plans.${esc(k)}.enabled" ${p.enabled?'checked':''}>On sale</label><div class="fields"><label>Title<input data-k="plans.${esc(k)}.title" value="${esc(p.title)}" dir="auto"></label><label>Price (toman)<input type="number" data-k="plans.${esc(k)}.price_toman" value="${esc(p.price_toman)}"></label><label>Days<input type="number" data-k="plans.${esc(k)}.days" value="${esc(p.days)}"></label>${limitHtml(p,'plans.'+k)}</div>${tierChecks(p,'plans.'+k)}</div>`).join('')}<div class="card"><h2>Free users</h2><div class="fields">${limitHtml(SET.free_limits,'free_limits')}</div>${tierChecks(SET.free_limits,'free_limits')}</div><div class="card"><h2>Invite PRO</h2><p class="dim" style="margin-top:0">People who earned PRO by inviting friends or with the secret code. Keep this small, below your paid plans.</p><div class="fields">${limitHtml(SET.pro_limits,'pro_limits')}</div>${tierChecks(SET.pro_limits,'pro_limits')}</div></div><div class="card gap"><h2>Dollar rate</h2><label style="max-width:240px">Toman per dollar, for the profit estimate<input type="number" data-k="usd_toman" value="${esc(SET.usd_toman)}"></label></div><div class="savebar row"><input id="nk" placeholder="new plan key: lowercase letters" style="width:230px"><button class="btn g" id="add">Add plan</button><button class="btn" id="sv">Save</button></div>`;
document.getElementById('sv').onclick=()=>{collect();saveAll()};document.getElementById('add').onclick=()=>{collect();const k=document.getElementById('nk').value.trim();if(!/^[a-z]{1,20}$/.test(k))return toast('Use lowercase English letters only',1);SET.plans[k]={title:k,price_toman:100000,days:30,enabled:false,chat_per_day:30,chat_per_month:300,voice_minutes_per_day:0,voice_per_week:0,image_per_day:0,image_per_week:0,image_per_month:0,vision_per_day:0,tiers:Object.keys(SET.tiers)};render()};main.querySelectorAll('[data-del]').forEach(b=>b.onclick=()=>{if(confirm('Delete this plan? People on it become PRO without a plan.')){collect();delete SET.plans[b.dataset.del];render()}})};render()}

async function Models(){await loadSet();const A=[['text','💬 Text'],['voice','🎤 Voice to text'],['image','🖼 Image generation'],['vision','👁 Image understanding']],P=['metis','gapgpt'],name={metis:'MetisAI',gapgpt:'GapGPT'},m=SET.media;
main.innerHTML=`<div class="card"><h2>Features</h2><div class="row"><label class="c"><input type="checkbox" data-k="media.voice_enabled" ${m.voice_enabled?'checked':''}>Voice to text</label><label class="c"><input type="checkbox" data-k="media.images_enabled" ${m.images_enabled?'checked':''}>Image generation</label><label class="c"><input type="checkbox" data-k="media.vision_enabled" ${m.vision_enabled?'checked':''}>Image understanding</label><label class="c"><input type="checkbox" data-k="features.secret_pro_code" ${SET.features.secret_pro_code?'checked':''}>Secret PRO code <span class="warn">(guessable: anyone can get 7 days)</span></label></div></div>
<div class="card" style="margin-top:14px"><h2>Who answers first</h2><p class="dim" style="margin-top:0">The first provider gets every request. Any failure moves to the second.</p><div class="fields">${A.map(([a,l])=>`<label>${l}<select data-order="${a}">${[['metis','gapgpt'],['gapgpt','metis'],['metis'],['gapgpt']].map(o=>`<option value="${o.join(',')}" ${(m.order[a]||[]).join(',')===o.join(',')?'selected':''}>${o.map(x=>name[x]).join(' → ')}</option>`).join('')}</select></label>`).join('')}<label>Free OpenRouter models<select data-k="routing_mode">${[['paid_only','Off: paid providers only'],['free_first','On: try free models first (needs the relay)']].map(([v,l])=>`<option value="${v}" ${SET.routing_mode===v?'selected':''}>${l}</option>`).join('')}</select></label></div></div>
<div class="card" style="margin-top:14px"><h2>Media models</h2><div class="fields">${A.slice(1).flatMap(([a,l])=>P.map(p=>`<label>${l}: ${name[p]}<input list="dl-${p}" data-k="media.models.${a}.${p}" value="${esc(m.models[a]?.[p]||'')}"></label>`)).join('')}<label>Image size<select data-k="media.image.size">${['1024x1024','1024x1536','1536x1024','auto'].map(v=>`<option ${m.image.size===v?'selected':''}>${v}</option>`).join('')}</select></label><label>Image quality (gpt-image)<select data-k="media.image.quality">${['low','medium','high','auto'].map(v=>`<option ${m.image.quality===v?'selected':''}>${v}</option>`).join('')}</select></label></div></div>
<div class="two">${Object.entries(SET.tiers).map(([k,t])=>`<div class="card"><h2>Tier: ${esc(k)}</h2><label>Button label<input data-k="tiers.${esc(k)}.label" value="${esc(t.label)}" dir="auto"></label><label>MetisAI models, in order<textarea data-list="tiers.${esc(k)}.paid_models">${esc((t.paid_models||[]).join('\n'))}</textarea></label><label>GapGPT models, in order (GLM, DeepSeek and others live here)<textarea data-list="tiers.${esc(k)}.gapgpt_models">${esc((t.gapgpt_models||[]).join('\n'))}</textarea></label><label>Free OpenRouter models<textarea data-list="tiers.${esc(k)}.free_models">${esc((t.free_models||[]).join('\n'))}</textarea></label></div>`).join('')}</div>
<div class="card" style="margin-top:14px"><h2>Available models</h2><div class="two" id="av" style="margin-top:0"><span class="dim">Loading…</span></div></div><div class="savebar"><button class="btn" id="sv">Save</button></div>`;
document.getElementById('sv').onclick=()=>{collect();main.querySelectorAll('[data-order]').forEach(s=>SET.media.order[s.dataset.order]=s.value.split(','));saveAll()};
api('models').then(d=>{document.getElementById('av').innerHTML=P.map(p=>{const l=d[p]||[];document.getElementById('dl-'+p).innerHTML=l.map(x=>`<option value="${esc(x)}">`).join('');return `<div><h3>${name[p]}: ${l.length} listed</h3><pre style="max-height:220px">${esc(l.join('\n'))}</pre></div>`}).join('')}).catch(e=>toast(e.message,1))}

async function Prompts(){await loadSet();const F=[['chat','Chat system prompt','Placeholders: {date} {time} {plan} {limit} {first_name} {last_name} {username}'],['vision','Image understanding system prompt','Used when a user sends a photo.'],['summary','Chat summary system prompt','Used to shorten long chat memory.']];main.innerHTML=F.map(([k,l,h])=>`<div class="card" style="margin-bottom:14px"><h2>${l}</h2><p class="dim" style="margin-top:0">${h}</p><textarea data-k="prompts.${k}" dir="auto" style="min-height:${k==='chat'?260:110}px;font:14px/1.6 system-ui,Tahoma,sans-serif"></textarea></div>`).join('')+'<div class="savebar"><button class="btn" id="sv">Save</button></div>';F.forEach(([k])=>main.querySelector(`[data-k="prompts.${k}"]`).value=SET.prompts[k]);document.getElementById('sv').onclick=()=>{collect();saveAll()}}

function Tests(){const T=[['db','Database','free'],['bale','Bale bot and webhook','free'],['text:metis','Text: MetisAI','tiny cost'],['text:gapgpt','Text: GapGPT','tiny cost'],['voice_real','Voice, the whole way: real Persian speech through Bale','tiny cost · sends you a voice'],['voice:metis','Voice: MetisAI (silent clip)','tiny cost'],['voice:gapgpt','Voice: GapGPT (silent clip)','tiny cost'],['vision:metis','Image understanding: MetisAI','tiny cost'],['vision:gapgpt','Image understanding: GapGPT','tiny cost'],['image:metis','Image generation: MetisAI','costs one image'],['image:gapgpt','Image generation: GapGPT','costs one image'],['openrouter','OpenRouter / relay','free']];
main.innerHTML=`<div class="card"><div class="row" style="justify-content:space-between"><h2 style="margin:0">Live tests</h2><button class="btn" id="all">Run all free and tiny tests</button></div><div id="tests" style="margin-top:8px">${T.map(([n,l,c])=>`<div class="test" data-n="${n}"><b style="min-width:230px">${l}</b><span class="pill">${c}</span><div class="dim r"></div><button class="btn g run">Run</button></div>`).join('')}</div></div><div class="card" style="margin-top:14px"><div class="row" style="justify-content:space-between"><h2 style="margin:0">Offline code tests</h2><button class="btn g" id="suite">Run</button></div><p class="dim">Every provider is mocked, so this spends nothing.</p><pre id="sout"></pre></div>`;
const run=async(el)=>{const r=el.querySelector('.r');r.className='dim r';r.textContent='Running…';try{const d=await api('test',{name:el.dataset.n});r.className='r '+(d.ok?'ok':'bad');r.textContent=(d.ok?'✔ ':'✘ ')+d.detail+' ('+d.ms+' ms)';if(d.image){const i=new Image();i.src=d.image;r.append(i)}}catch(x){r.className='r bad';r.textContent='✘ '+x.message}};
main.querySelectorAll('.run').forEach(b=>b.onclick=()=>run(b.closest('.test')));document.getElementById('all').onclick=async()=>{for(const el of main.querySelectorAll('.test')){if(!el.dataset.n.startsWith('image')&&el.dataset.n!=='voice_real')await run(el)}};document.getElementById('suite').onclick=async()=>{const o=document.getElementById('sout');o.textContent='Running…';const r=await fetch('?api=suite');const t=await r.text();o.textContent=t.split('\n').filter(l=>!l.includes('✔')).join('\n').trim()}}

let start='Overview';try{start=pages[sessionStorage.page]?sessionStorage.page:start}catch(e){}go(start);
</script></body></html>
