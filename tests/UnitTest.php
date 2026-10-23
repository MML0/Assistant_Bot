<?php

use Bot\ContextFitter;
use Bot\ErrorClassifier as E;
use Bot\Redactor;
use Bot\Settings;
use Bot\TaskClassifier;

$now = 1_800_000_000.0;

test('error classifier: rate limits, quota, auth, context, modality', function () use ($now) {
    T::eq(E::RATE_LIMIT, E::fromHttp(429, json_encode(['error' => ['code' => 429, 'message' => 'Provider returned error', 'metadata' => ['raw' => 'x is temporarily rate-limited upstream']]]), [], $now)['class']);
    $daily = E::fromHttp(429, json_encode(['error' => ['code' => 429, 'message' => 'Rate limit exceeded: free-models-per-day. Add 10 credits to unlock 1000 free model requests per day', 'metadata' => ['headers' => ['X-RateLimit-Limit' => '50', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (($now + 3600) * 1000)]]]]), [], $now);
    T::eq(E::ACCOUNT_DAILY, $daily['class']);
    T::eq('account', $daily['scope']);
    T::eq(0, $daily['remaining'], 'remaining from metadata headers');
    T::ok(abs($daily['retry_at'] - ($now + 3600)) < 1, 'reset ms epoch honoured');
    T::eq(E::ACCOUNT_RATE, E::fromHttp(429, '{"error":{"message":"Rate limit exceeded: free-models-per-min."}}', [], $now)['class']);
    $ra = E::fromHttp(429, '{"error":{"message":"slow down"}}', ['Retry-After' => ['30']], $now);
    T::eq($now + 30, $ra['retry_at'], 'Retry-After seconds');
    T::eq(E::AUTH, E::fromHttp(401, '{"error":{"message":"No auth credentials found"}}', [], $now)['class']);
    T::eq(E::CREDITS, E::fromHttp(402, '{"error":{"message":"Insufficient credits"}}', [], $now)['class']);
    T::eq(E::CONTEXT, E::fromHttp(400, '{"error":{"message":"This endpoint\'s maximum context length is 8192 tokens"}}', [], $now)['class']);
    T::eq(E::UNSUPPORTED, E::fromHttp(404, '{"error":{"message":"No endpoints found that support image input"}}', [], $now)['class']);
    T::eq(E::INVALID_MODEL, E::fromHttp(400, '{"error":{"message":"foo/bar is not a valid model ID"}}', [], $now)['class']);
    T::eq(E::INVALID_MODEL, E::fromHttp(404, '{"error":{"message":"No endpoints found for foo/bar:free."}}', [], $now)['class']);
    T::eq(E::PROVIDER, E::fromHttp(503, '{"error":{"message":"overloaded"}}', [], $now)['class']);
    T::eq(E::REFUSAL, E::fromHttp(403, '{"error":{"message":"Input was flagged by moderation"}}', [], $now)['class']);
    T::eq(E::INVALID_REQUEST, E::fromHttp(400, '{"error":{"message":"messages: field required"}}', [], $now)['class']);
    T::eq('request', E::scope(E::INVALID_REQUEST));
});

test('error classifier: transport failures and possible processing', function () use ($now) {
    $req = new GuzzleHttp\Psr7\Request('POST', 'https://x');
    $t = E::fromException(new GuzzleHttp\Exception\ConnectException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received', $req), $now);
    T::eq(E::TIMEOUT, $t['class']);
    T::ok($t['possibly_processed'], 'read timeout may have been processed');
    $n = E::fromException(new GuzzleHttp\Exception\ConnectException('cURL error 6: Could not resolve host: openrouter.ai', $req), $now);
    T::eq(E::NETWORK, $n['class']);
    T::ok(!$n['possibly_processed']);
});

test('redactor strips keys and bot tokens', function () {
    Redactor::registerSecrets(['my-db-password-123']);
    $s = Redactor::redact('GET https://api.telegram.org/file/bot9876543210:FAKEfakeFAKEfakeFAKEfake_0123456789/voice.ogg Bearer sk-or-v1-abcdef1234567890abcdef pw my-db-password-123 tpsg-FAKEmetisKEY0000000000');
    T::ok(!str_contains($s, 'FAKEfakeFAKE'), 'telegram token removed');
    T::ok(!str_contains($s, 'abcdef1234567890'), 'api key removed');
    T::ok(!str_contains($s, 'my-db-password-123'), 'registered secret removed');
    T::ok(!str_contains($s, 'FAKEmetisKEY'), 'metis key removed');
});

test('task classifier', function () {
    T::eq('ordinary', TaskClassifier::classify([['role' => 'user', 'content' => 'سلام، حالت چطوره؟']]));
    T::eq('coding', TaskClassifier::classify([['role' => 'user', 'content' => "Why does this python function throw TypeError?\n```def f(x): return x+1```"]]));
    T::eq('hard', TaskClassifier::classify([['role' => 'user', 'content' => 'Prove step by step that the integral of 1/x is ln x']]));
    T::eq('ordinary', TaskClassifier::classify([['role' => 'user', 'content' => 'prove the theorem']], 'summary'), 'summaries are ordinary');
    T::ok(TaskClassifier::requirements([['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'x']]]]])['image']);
});

test('context fitter keeps system, summary and newest user turn', function () {
    $msgs = [['role' => 'system', 'content' => 'sys'], ['role' => 'assistant', 'content' => 'Summary of our chat so far: we talked about cats']];
    for ($i = 0; $i < 40; $i++) {
        $msgs[] = ['role' => $i % 2 ? 'assistant' : 'user', 'content' => str_repeat("message $i ", 60)];
    }
    $msgs[] = ['role' => 'user', 'content' => 'final question'];
    $fit = ContextFitter::fit($msgs, 4000, 1000);
    T::ok($fit !== null && count($fit) < count($msgs), 'trimmed');
    T::eq('sys', $fit[0]['content']);
    T::ok(str_starts_with($fit[1]['content'], 'Summary of our chat'), 'summary kept');
    T::eq('final question', end($fit)['content']);
    T::ok(ContextFitter::messagesTokens($fit) <= 4000 * 0.9 - 1000, 'within budget');
    T::eq(null, ContextFitter::fit([['role' => 'user', 'content' => str_repeat('x', 30000)]], 4000, 1000), 'impossible fit');
    T::eq($msgs, ContextFitter::fit($msgs, 200000, 1000), 'untouched when it fits');
});

test('settings: old config keys and env vars', function () {
    $s = Settings::fromConfig(['gpt' => ['free_api_key' => 'sk-or-old', 'free_model' => 'arcee-ai/x:free', 'free_base_url' => 'https://openrouter.ai/api/v1', 'api_key' => 'k', 'base_url' => 'u']]);
    T::eq('sk-or-old', $s['openrouter']['api_key']);
    T::eq(['arcee-ai/x:free'], $s['openrouter']['seed_models']);
    T::eq('free_first', $s['routing']['mode'], 'default mode');
    T::eq('k', $s['gpt']['api_key']);
    putenv('OPENROUTER_API_KEY=sk-or-from-env');
    putenv('BOT_PAID_FALLBACK_ENABLED=false');
    $s = Settings::fromConfig(['gpt' => ['free_api_key' => 'sk-or-old']]);
    T::eq('sk-or-from-env', $s['openrouter']['api_key'], 'env wins');
    T::eq(false, $s['paid']['fallback_enabled']);
    putenv('OPENROUTER_API_KEY');
    putenv('BOT_PAID_FALLBACK_ENABLED');
    T::eq('free_first', Settings::fromConfig(['routing' => ['mode' => 'nonsense']])['routing']['mode'], 'invalid mode falls back');
});
