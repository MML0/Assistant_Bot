<?php

use Bot\JsonStore;
use Bot\Media;
use Bot\Services;
use Bot\Settings;
use Bot\VoiceTranscriber;

const TINY_PNG = "\x89PNG\r\n\x1a\nfake";

function mediaConfig(string $dir, array $extra = []): array
{
    return $extra + [
        'storage_dir' => $dir,
        'gpt' => ['api_key' => 'metis-key-000000', 'base_url' => 'https://metis.example/v1'],
        'gap_gpt' => ['api_key' => 'gap-key-0000000', 'base_url' => 'https://gap.example/v1'],
        'routing' => ['mode' => 'paid_only'],
    ];
}

function mediaDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE v2_media_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, telegram_user_id INTEGER, action TEXT, provider TEXT, model TEXT, status TEXT, created_at TEXT)');
    return $db;
}

test('text: MetisAI answers first and GapGPT is not called', function () {
    $http = new FakeHttp(fn ($req) => chatOk('from metis', 'gpt-5.4-nano'));
    $r = (new Services(mediaConfig(tmpdir()), $http->client))->router->complete([['role' => 'user', 'content' => 'hi']], ['tier' => 'normal']);
    T::ok($r['ok']);
    T::eq('from metis', $r['content']);
    T::eq(0, $http->count('gap.example'));
});

test('text: MetisAI failing falls back to GapGPT with its own models', function () {
    $http = new FakeHttp(fn ($req) => $req->getUri()->getHost() === 'metis.example' ? orError(500, 'down') : chatOk('from gap', 'gpt-5.4-nano'));
    $r = (new Services(mediaConfig(tmpdir()), $http->client))->router->complete([['role' => 'user', 'content' => 'hi']], ['tier' => 'normal']);
    T::ok($r['ok'], 'answered');
    T::eq('from gap', $r['content']);
    T::ok($http->count('metis.example') >= 1, 'tried MetisAI first');
    T::eq('metis.example', $http->history[0]['request']->getUri()->getHost());
});

test('text: order from the dashboard is respected, and no gap_gpt block means MetisAI alone', function () {
    $dir = tmpdir();
    file_put_contents($dir . '/admin_settings.json', json_encode(['media' => ['order' => ['text' => ['gapgpt', 'metis']]]]));
    $http = new FakeHttp(fn ($req) => chatOk('ok', 'x'));
    (new Services(mediaConfig($dir), $http->client))->router->complete([['role' => 'user', 'content' => 'hi']], ['tier' => 'normal']);
    T::eq('gap.example', $http->history[0]['request']->getUri()->getHost());

    $config = mediaConfig(tmpdir());
    unset($config['gap_gpt']);
    $http = new FakeHttp(fn ($req) => orError(500, 'down'));
    $r = (new Services($config, $http->client))->router->complete([['role' => 'user', 'content' => 'hi']], ['tier' => 'normal']);
    T::ok(!$r['ok']);
    T::eq(0, $http->count('gap.example'));
});

test('image: falls back on failure, accepts base64 and URLs, refuses non-images', function () {
    $s = Settings::fromConfig(mediaConfig(tmpdir()));
    $b64 = base64_encode(TINY_PNG);
    $http = new FakeHttp(fn ($req) => $req->getUri()->getHost() === 'metis.example' ? orError(400, 'nope') : json(200, ['data' => [['b64_json' => $b64]]]));
    $r = (new Media(null, $http->client, $s, new JsonStore(tmpdir())))->generateImage('cat');
    T::ok($r['ok']);
    T::eq('gapgpt', $r['provider']);
    T::eq(TINY_PNG, $r['bytes']);
    T::eq(1, count($r['errors']), 'the MetisAI failure is kept for the admin');
    T::eq('low', $http->bodies('images/generations')[0]['quality']);

    $http = new FakeHttp(function ($req) {
        return str_contains((string) $req->getUri(), 'images/generations') ? json(200, ['data' => [['url' => 'https://cdn.example/a.png']]]) : new GuzzleHttp\Psr7\Response(200, [], TINY_PNG);
    });
    $r = (new Media(null, $http->client, $s, new JsonStore(tmpdir())))->generateImage('cat');
    T::eq('metis', $r['provider']);
    T::eq(TINY_PNG, $r['bytes']);

    $http = new FakeHttp(fn ($req) => json(200, ['data' => [['b64_json' => base64_encode('<html>not an image')]]]));
    T::ok(!(new Media(null, $http->client, $s, new JsonStore(tmpdir())))->generateImage('cat')['ok'], 'a non-image answer is a failure');
});

test('vision: sends the image and falls back', function () {
    $s = Settings::fromConfig(mediaConfig(tmpdir()));
    $http = new FakeHttp(fn ($req) => $req->getUri()->getHost() === 'metis.example' ? orError(500, 'x') : chatOk('یک گربه', 'gpt-4o-mini'));
    $r = (new Media(null, $http->client, $s, new JsonStore(tmpdir())))->describeImage(TINY_PNG, 'image/png', 'این چیست؟');
    T::ok($r['ok']);
    T::eq('یک گربه', $r['text']);
    T::eq('gapgpt', $r['provider']);
    $body = $http->bodies('chat/completions')[0];
    T::ok(str_starts_with($body['messages'][1]['content'][1]['image_url']['url'], 'data:image/png;base64,'));
});

test('media limits: day and month, failures do not count', function () {
    $s = Settings::fromConfig(mediaConfig(tmpdir()));
    $db = mediaDb();
    $m = new Media($db, new GuzzleHttp\Client(), $s, new JsonStore(tmpdir()));
    $limits = ['image_per_day' => 2, 'image_per_month' => 3, 'vision_per_day' => 1];
    T::eq(null, $m->refusal(7, 'image', $limits));
    $failed = $m->begin(7, 'image');
    $m->finish($failed, ['ok' => false]);
    T::eq(0, $m->used(7, 'image', date('Y-m-d 00:00:00')), 'a failed request is free');
    $m->finish($m->begin(7, 'image'), ['ok' => true, 'provider' => 'metis', 'model' => 'x']);
    $m->begin(7, 'image'); // still running: counts, so parallel requests cannot pass the limit
    T::ok(str_contains((string) $m->refusal(7, 'image', $limits), '2 تصویر'), 'daily limit');
    T::eq(null, $m->refusal(8, 'image', $limits), 'other users are not affected');
    $weekly = ['image_per_day' => 1, 'image_per_week' => 1, 'image_per_month' => 0, 'vision_per_day' => 0];
    T::eq(null, $m->refusal(9, 'image', $weekly));
    $m->finish($m->begin(9, 'image'), ['ok' => true]);
    $db->exec("UPDATE v2_media_usage SET created_at = '" . date('Y-m-d H:i:s', time() - 3 * 86400) . "' WHERE telegram_user_id = 9");
    T::ok(str_contains((string) $m->refusal(9, 'image', $weekly), 'این هفته'), 'weekly limit holds on later days');

    $old = date('Y-m-d H:i:s', strtotime('first day of this month 00:00:00') + 1);
    $db->exec("UPDATE v2_media_usage SET created_at = '{$old}'");
    if (date('j') !== '1') {
        T::eq(null, $m->refusal(7, 'image', $limits + []), 'a new day resets the daily count');
        $m->finish($m->begin(7, 'image'), ['ok' => true]);
        T::ok(str_contains((string) $m->refusal(7, 'image', ['image_per_day' => 9] + $limits), '3 تصویر'), 'monthly limit');
    }
    T::ok($m->refusal(7, 'image', ['image_per_day' => 0] + $limits) !== null, 'a plan without images is refused');
    T::ok($m->refusal(7, 'vision', ['vision_per_day' => 0] + $limits) !== null, 'a plan without vision is refused');
    $s['media']['images_enabled'] = false;
    T::ok((new Media($db, new GuzzleHttp\Client(), $s, new JsonStore(tmpdir())))->refusal(99, 'image', $limits) !== null, 'the global switch wins');
});

test('image description step: pending once, then cleared', function () {
    $m = new Media(null, new GuzzleHttp\Client(), Settings::defaults(), new JsonStore(tmpdir()));
    T::ok(!$m->takePending(5));
    $m->setPending(5);
    T::ok($m->takePending(5));
    T::ok(!$m->takePending(5), 'the next message is normal chat again');
});

test('voice: MetisAI failing falls back to GapGPT, plan decides access and minutes', function () {
    $s = Settings::fromConfig(mediaConfig(tmpdir()));
    T::eq(['metis', 'gapgpt'], array_column($s['transcription']['providers'], 'name'));
    T::eq('whisper-1', $s['transcription']['providers'][1]['model']);

    $run = function (int $minutes, array $message) use ($s) {
        $db = voiceDb();
        $http = new FakeHttp(function ($req) {
            $u = (string) $req->getUri();
            if (str_contains($u, 'getFile')) {
                return json(200, ['ok' => true, 'result' => ['file_path' => 'v.ogg']]);
            }
            if (str_contains($u, '/file/bot')) {
                return new GuzzleHttp\Psr7\Response(200, [], OGG);
            }
            return $req->getUri()->getHost() === 'metis.example' ? orError(404, 'schema not found') : json(200, ['text' => 'سلام']);
        });
        $sent = [];
        $store = new JsonStore(tmpdir());
        $v = new VoiceTranscriber($db, $http->client, $s, new Bot\Entitlements($db, 'v2_'), $store, 'tok', function ($to, $t) use (&$sent) {
            $sent[] = $t;
        });
        $out = $v->withAccess(fn ($id) => $minutes)->handle($message, 1, 1);
        return [$out, $sent, $http, $db];
    };
    $msg = ['chat' => ['id' => 5, 'type' => 'private'], 'from' => ['id' => 5], 'voice' => ['file_id' => 'f', 'duration' => 60, 'file_size' => 3000, 'mime_type' => 'audio/ogg']];

    [$out, $sent, $http, $db] = $run(30, $msg);
    T::eq('transcribed', $out);
    T::ok(str_contains(end($sent), 'سلام'));
    T::eq(1, $http->count('metis.example'), 'MetisAI tried first');
    T::eq(1, $http->count('gap.example'), 'GapGPT answered');
    T::eq('gapgpt', $db->query('SELECT provider FROM v2_voice_usage')->fetchColumn());

    [$out, , $http] = $run(0, $msg);
    T::eq('denied', $out, 'no minutes in the plan: no access');
    T::eq(0, count($http->history), 'denied before any download or provider call');

    $long = $msg;
    $long['voice']['duration'] = 120;
    $long['voice']['file_size'] = 240000;
    T::eq('quota_exceeded', $run(1, $long)[0], 'the plan minutes are the limit');
});
