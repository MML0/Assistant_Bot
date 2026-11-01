<?php
// OpenRouter relay. Upload this one file to a host outside Iran, then set in the bot's config.php:
//   'openrouter' => ['base_url' => 'https://FOREIGN-HOST/or_relay.php/YOUR_SECRET'],
// Health check in a browser: https://FOREIGN-HOST/or_relay.php/YOUR_SECRET/health
// It only ever talks to OpenRouter and stores nothing. The API key stays in the bot's config.php.

const RELAY_SECRET = 'CHANGE_ME'; // at least 24 random characters: openssl rand -hex 24
const RELAY_TARGET = 'https://openrouter.ai/api/v1/';
const RELAY_PATHS  = ['chat/completions', 'models', 'key', 'benchmarks'];
const RELAY_TIMEOUT = 120;
const RELAY_MAX_BODY = 8388608;

function relayFail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => $status, 'message' => $message]]);
    exit;
}

function relayAuthHeader(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders());
        $auth = $headers['authorization'] ?? '';
    }
    return $auth;
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (strlen(RELAY_SECRET) < 24 || RELAY_SECRET === 'CHANGE_ME') {
    relayFail(503, 'relay secret is not set');
}

// /or_relay.php/SECRET/chat/completions: read from the URL, because not every host sets PATH_INFO.
$uriPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$script  = $_SERVER['SCRIPT_NAME'] ?? '';
$rest    = str_starts_with($uriPath, $script) ? substr($uriPath, strlen($script)) : ($_SERVER['PATH_INFO'] ?? '');
[$secret, $path] = array_pad(explode('/', ltrim($rest, '/'), 2), 2, '');

if (!hash_equals(RELAY_SECRET, $secret)) {
    relayFail(404, 'not found');
}
if (!function_exists('curl_init')) {
    relayFail(502, 'the curl extension is not enabled on the relay host');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === 'health') {
    $ch = curl_init(RELAY_TARGET . 'models?limit=1');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($ch);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'               => $status >= 200 && $status < 500,
        'openrouter_http'  => $status,
        'curl_error'       => $error,
        'php'              => PHP_VERSION,
        // If this stays false when the bot calls it, the host strips Authorization and the bot gets 401s.
        'auth_header_seen' => relayAuthHeader() !== '',
    ]);
    exit;
}

$allowed = in_array($path, RELAY_PATHS, true);
if (!$allowed || !($method === 'GET' || ($method === 'POST' && $path === 'chat/completions'))) {
    relayFail(404, 'path not allowed');
}

$headers = ['Accept: application/json'];
$auth = relayAuthHeader();
if ($auth !== '') {
    $headers[] = 'Authorization: ' . $auth;
}
foreach (['HTTP_HTTP_REFERER' => 'HTTP-Referer', 'HTTP_X_TITLE' => 'X-Title'] as $from => $to) {
    if (!empty($_SERVER[$from])) {
        $headers[] = $to . ': ' . $_SERVER[$from];
    }
}

$query = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
$ch = curl_init(RELAY_TARGET . $path . ($query !== '' ? '?' . $query : ''));
$options = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => RELAY_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 10,
];
if ($method === 'POST') {
    $body = file_get_contents('php://input', false, null, 0, RELAY_MAX_BODY + 1);
    if ($body === false || strlen($body) > RELAY_MAX_BODY) {
        relayFail(413, 'request too large');
    }
    $headers[] = 'Content-Type: application/json';
    $options[CURLOPT_POST] = true;
    $options[CURLOPT_POSTFIELDS] = $body;
}
$options[CURLOPT_HTTPHEADER] = $headers;

// The bot reads Retry-After and the rate-limit headers to pace free models, so pass them back.
$passBack = [];
$options[CURLOPT_HEADERFUNCTION] = static function ($c, string $line) use (&$passBack): int {
    $name = strtolower(trim(explode(':', $line, 2)[0]));
    if ($name === 'content-type' || $name === 'retry-after' || str_starts_with($name, 'x-ratelimit-')) {
        $passBack[] = trim($line);
    }
    return strlen($line);
};
curl_setopt_array($ch, $options);

$response = curl_exec($ch);
if ($response === false) {
    relayFail(502, 'relay could not reach OpenRouter: ' . curl_error($ch));
}

http_response_code((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
foreach ($passBack as $line) {
    header($line);
}
echo $response;
