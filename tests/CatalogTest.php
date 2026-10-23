<?php

use Bot\ModelCatalog;

function apiModel(string $id, array $pricing, int $ctx = 131072, array $extra = []): array
{
    return $extra + [
        'id' => $id, 'name' => $id, 'canonical_slug' => preg_replace('~:.*$~', '', $id),
        'context_length' => $ctx, 'pricing' => $pricing,
        'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
        'top_provider' => ['max_completion_tokens' => 4096], 'supported_parameters' => ['max_tokens', 'tools'],
    ];
}

$zero = ['prompt' => '0', 'completion' => '0', 'request' => '0', 'image' => '0'];
$modelsPayload = ['data' => [
    apiModel('acme/genius:free', $zero),
    apiModel('acme/genius', ['prompt' => '0.000002', 'completion' => '0.000008']),
    apiModel('cheap/free-sounding-model', ['prompt' => '0.0000001', 'completion' => '0.0000001']), // "free" only in name
    apiModel('openrouter/auto', ['prompt' => '-1', 'completion' => '-1']),
    apiModel('meta/llama-smart:free', $zero),
    apiModel('tiny/model:free', $zero, 4096),                                                    // context too small
    apiModel('mystery/new-model:free', $zero),                                                   // no benchmark yet
    apiModel('img/only:free', $zero, 32000, ['architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['image']]]),
    apiModel('old/retiring:free', $zero, 32000, ['expiration_date' => '2020-01-01']),
    apiModel('bad id with spaces', $zero),
]];
$benchPayload = ['data' => [
    ['model_permaslug' => 'acme/genius', 'intelligence_index' => 60, 'coding_index' => 55],
    ['model_permaslug' => 'meta/llama-smart', 'intelligence_index' => 40, 'coding_index' => 30],
], 'meta' => ['as_of' => '2026-10-01']];

test('discovery: only genuinely free text models, ranked by benchmarks', function () use ($modelsPayload, $benchPayload) {
    $rig = new RouterRig([], function ($req) use ($modelsPayload, $benchPayload) {
        $u = (string) $req->getUri();
        if (str_contains($u, '/models')) {
            return json(200, $modelsPayload);
        }
        if (str_contains($u, '/benchmarks')) {
            return json(200, $benchPayload);
        }
        if (str_contains($u, '/key')) {
            return json(200, ['data' => ['is_free_tier' => true, 'limit' => null, 'usage' => 0, 'label' => 'sk-or-v1-abc...']]);
        }
        return json(404, []);
    });
    T::ok($rig->catalog->refresh(), 'refresh succeeded');
    $ids = array_column($rig->catalog->models(), 'id');
    // Unknown model is estimated at median(60, 40) * 0.9 = 45: between the two benchmarked ones.
    T::eq(['acme/genius:free', 'mystery/new-model:free', 'meta/llama-smart:free'], $ids, 'filtered + ranked');
    T::eq(1, $rig->http->count('sort=intelligence-high-to-low'), 'intelligence sort tried first');
    $m = $rig->catalog->models();
    T::ok($m[0]['intelligence'] == 60, 'benchmark mapped via canonical slug');
    T::ok(abs($m[1]['rank_quality'] - 45) < 0.001, 'missing benchmark estimated, not zero');
    T::ok($m[1]['quality_estimated'], 'missing benchmark flagged as estimate');
    T::eq(false, $m[2]['quality_estimated']);
    T::eq(true, $rig->catalog->keyInfo()['is_free_tier']);
    T::ok(!isset($rig->catalog->keyInfo()['label']), 'key label not stored');
    T::eq(0, $rig->freeCalls(), 'discovery never sends chat requests');
});

test('discovery: unsorted fallback when the sort parameter is rejected', function () use ($modelsPayload) {
    $rig = new RouterRig([], function ($req) use ($modelsPayload) {
        $u = (string) $req->getUri();
        if (str_contains($u, 'sort=')) {
            return json(400, ['error' => ['message' => 'invalid sort']]);
        }
        if (str_contains($u, '/models')) {
            return json(200, $modelsPayload);
        }
        return json(500, []);
    });
    T::ok($rig->catalog->refresh());
    T::eq(3, count($rig->catalog->models()), 'still discovered without benchmarks');
});

test('discovery failure keeps last known-good list and backs off', function () {
    $good = [freeModel('good/model:free', 50)];
    $rig = new RouterRig($good, fn () => new GuzzleHttp\Exception\ConnectException('cURL error 6: Could not resolve host', new GuzzleHttp\Psr7\Request('GET', 'x')));
    $rig->t += 30000; // catalogue is stale
    T::ok($rig->catalog->isDue());
    T::ok(!$rig->catalog->refresh(), 'refresh failed');
    T::eq(['good/model:free'], array_column($rig->catalog->models(), 'id'), 'last known-good kept');
    T::ok(!empty($rig->catalog->state()['last_error']));
    T::ok(!$rig->catalog->isDue(), 'backs off after failure');
    $calls = count($rig->http->history);
    $rig->catalog->refreshIfDue();
    T::eq($calls, count($rig->http->history), 'no request during backoff');
    $rig->t += 1000;
    T::ok($rig->catalog->isDue(), 'retries after backoff');
});

test('discovery returning zero free models does not wipe the list', function () {
    $rig = new RouterRig([freeModel('good/model:free')], fn ($req) => json(200, ['data' => [apiModel('paid/only', ['prompt' => '0.1', 'completion' => '0.1'])]]));
    T::ok(!$rig->catalog->refresh());
    T::eq(['good/model:free'], array_column($rig->catalog->models(), 'id'));
});

test('cold start uses configured seed model without any network call', function () {
    $rig = new RouterRig([], fn ($req) => chatOk('hi', 'arcee-ai/trinity-large-preview:free', null), ['gpt' => ['free_model' => 'arcee-ai/trinity-large-preview:free']]);
    T::eq(['arcee-ai/trinity-large-preview:free'], array_column($rig->catalog->models(), 'id'));
    $r = $rig->router->complete(convo());
    T::ok($r['ok']);
    T::eq(null, $r['free_confirmed'], 'unverified seed is never claimed free without pricing/cost');
    T::eq(1, count($rig->http->history), 'no discovery on the request path');
});

test('genuinely-free check', function () {
    T::ok(ModelCatalog::isGenuinelyFree(['pricing' => ['prompt' => '0', 'completion' => '0']]));
    T::ok(!ModelCatalog::isGenuinelyFree(['id' => 'x:free', 'pricing' => ['prompt' => '0', 'completion' => '0.000001']]));
    T::ok(!ModelCatalog::isGenuinelyFree(['pricing' => ['prompt' => '0', 'completion' => '0', 'request' => '0.01']]));
    T::ok(!ModelCatalog::isGenuinelyFree(['pricing' => ['prompt' => '-1', 'completion' => '-1']]));
    T::ok(!ModelCatalog::isGenuinelyFree(['id' => 'free/free:free']));
});
