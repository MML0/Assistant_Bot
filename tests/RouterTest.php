<?php

use Bot\ErrorClassifier as E;
use Bot\Router;

function msg(string $k): string
{
    return Bot\Settings::defaults()['messages'][$k];
}
use GuzzleHttp\Exception\ConnectException;

$three = [freeModel('best/a:free', 70), freeModel('good/b:free', 60), freeModel('ok/c:free', 50)];

test('best free model succeeds with a single request', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => chatOk('Hi Sara!', modelOf($req), 0.0));
    $r = $rig->router->complete(convo());
    T::ok($r['ok']);
    T::eq('free', $r['tier']);
    T::eq('best/a:free', $r['model']);
    T::eq(true, $r['free_confirmed'], 'provider reported cost 0');
    T::eq(1, count($rig->http->history), 'exactly one HTTP call');
    T::eq(0, $rig->paidCalls());
});

test('rate-limited best model → next model, cooldown remembered', function () use ($three) {
    $rig = new RouterRig($three, function ($req) {
        return modelOf($req) === 'best/a:free'
            ? orError(429, 'Provider returned error', ['metadata' => ['raw' => 'best/a:free is temporarily rate-limited upstream']])
            : chatOk('answer', modelOf($req));
    });
    $r = $rig->router->complete(convo());
    T::eq('good/b:free', $r['model']);
    T::eq(['best/a:free', 'good/b:free'], $rig->attemptedModels());
    T::ok(!$rig->health->isAvailable('best/a:free'), 'cooling down');
    $rig->t += 10;
    $rig->router->complete(convo('second message'));
    T::eq(['best/a:free', 'good/b:free', 'good/b:free'], $rig->attemptedModels(), 'cooled-down model skipped without a request');
    $rig->t += 4000;
    T::ok($rig->health->isAvailable('best/a:free'), 'eligible again after cooldown (no probe requests)');
});

test('provider unavailable → next model', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => modelOf($req) === 'best/a:free' ? orError(503, 'upstream overloaded') : chatOk('ok', modelOf($req)));
    $r = $rig->router->complete(convo());
    T::ok($r['ok']);
    T::eq('good/b:free', $r['model']);
});

test('all free attempts fail → paid fallback, bounded attempts', function () {
    $many = [];
    for ($i = 0; $i < 10; $i++) {
        $many[] = freeModel("m/model-$i:free", 90 - $i);
    }
    $rig = new RouterRig($many, function ($req) {
        if (str_contains((string) $req->getUri(), 'paid.example')) {
            return json(200, ['choices' => [['message' => ['content' => 'paid answer']]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]]);
        }
        return orError(502, 'bad gateway');
    });
    $r = $rig->router->complete(convo(), ['paid_model' => 'gpt-4.1-mini']);
    T::ok($r['ok']);
    T::eq('paid', $r['tier']);
    T::eq('paid answer', $r['content']);
    T::eq(3, $rig->freeCalls(), 'stops at max_free_attempts (no fan-out to all 10)');
    T::eq(1, $rig->paidCalls());
    T::eq(false, $r['free_confirmed'], 'paid answers are never reported as free');
    T::eq('free_attempt_limit', $r['fallback_reason']);
    $budget = $rig->budget->status();
    T::eq(1, $budget['requests']);
    T::ok($budget['cost'] > 0 && $budget['cost'] < 0.01, 'cost settled to estimate');
});

test('paid fallback disabled → useful error, paid API never called', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => orError(503, 'down'), ['paid' => ['fallback_enabled' => false]]);
    $r = $rig->router->complete(convo());
    T::ok(!$r['ok']);
    T::eq(msg('unavailable'), $r['user_message']);
    T::eq(0, $rig->paidCalls());
    T::eq(3, $rig->freeCalls());
});

test('cost_min mode never uses paid fallback', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => orError(503, 'down'), ['routing' => ['mode' => 'cost_min']]);
    $r = $rig->router->complete(convo());
    T::ok(!$r['ok']);
    T::eq(0, $rig->paidCalls());
});

test('paid budget: no cap by default (like the old bot); a configured cap that is used up stops paid', function () use ($three) {
    $open = new RouterRig($three, fn ($req) => str_contains((string) $req->getUri(), 'paid.example') ? json(200, ['choices' => [['message' => ['content' => 'paid']]]]) : orError(503, 'down'));
    for ($i = 0; $i < 3; $i++) {
        T::ok($open->router->complete(convo())['ok'], 'default: paid always answers');
    }
    T::eq(0, $open->budget->status()['max_requests'], 'no daily request cap by default');

    $rig = new RouterRig($three, fn ($req) => orError(503, 'down'), ['paid' => ['max_requests_per_day' => 1]]);
    $rig->budget->reserve(0.0); // today's only paid request is already used
    $r = $rig->router->complete(convo());
    T::ok(!$r['ok']);
    T::eq(E::BUDGET, $r['error_class']);
    T::eq(msg('budget'), $r['user_message']);
    T::eq(0, $rig->paidCalls());
    T::ok((bool) array_filter($rig->alerts, fn ($a) => str_contains($a, 'Paid budget')), 'admin alerted');

    $rig2 = new RouterRig($three, fn ($req) => orError(503, 'down'), ['paid' => ['max_cost_per_day_usd' => 0.0000001]]);
    $r2 = $rig2->router->complete(convo());
    T::eq(E::BUDGET, $r2['error_class'], 'cost cap enforced before spending');
    T::eq(0, $rig2->paidCalls());
});

test('unknown quota status is handled conservatively', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => chatOk('ok', modelOf($req), null));
    $r = $rig->router->complete(convo());
    T::ok($r['ok']);
    T::eq(null, $rig->catalog->keyInfo(), 'no key info');
    $s = $rig->log->summary(1, $rig->health->snapshot(), $rig->budget->status(), $rig->catalog->state());
    T::eq('unknown', $s['catalog']['is_free_tier']);
    T::eq(true, $r['free_confirmed'], 'verified by catalogue pricing when cost not reported');
});

test('invalid credentials: free chain stops after one attempt and stays paused', function () use ($three) {
    $rig = new RouterRig($three, function ($req) {
        if (str_contains((string) $req->getUri(), 'paid.example')) {
            return json(200, ['choices' => [['message' => ['content' => 'paid']]]]);
        }
        return orError(401, 'No auth credentials found');
    });
    $r = $rig->router->complete(convo());
    T::eq(1, $rig->freeCalls(), 'auth error is not retried on other models');
    T::eq('paid', $r['tier']);
    T::ok((bool) array_filter($rig->alerts, fn ($a) => str_contains($a, 'auth')), 'admin alerted');
    $rig->t += 5;
    $rig->router->complete(convo('again'));
    T::eq(1, $rig->freeCalls(), 'free route paused, no new free requests');
});

test('paid errors are tried once more, like the old bot (MetisAI often serves the next try)', function () use ($three) {
    $paid = 0;
    $rig = new RouterRig($three, function ($req) use (&$paid) {
        if (!str_contains((string) $req->getUri(), 'paid.example')) {
            return orError(503, 'down');
        }
        return ++$paid === 1 ? orError(403, 'upstream key rejected') : json(200, ['choices' => [['message' => ['content' => 'paid ok']]]]);
    });
    $r = $rig->router->complete(convo());
    T::ok($r['ok'] && $r['content'] === 'paid ok', 'second try answers');
    T::eq(2, $rig->paidCalls());

    $bad = new RouterRig($three, fn ($req) => str_contains((string) $req->getUri(), 'paid.example') ? orError(401, 'bad key') : orError(503, 'down'));
    $r = $bad->router->complete(convo());
    T::ok(!$r['ok']);
    T::eq(2, $bad->paidCalls(), 'a real auth problem: two tries, no more');
    T::eq(msg('unavailable'), $r['user_message'], 'no internal error shown to user');
    $stats = Bot\RoutingLog::format($bad->log->summary(1, $bad->health->snapshot(), $bad->budget->status(), $bad->catalog->state()));
    T::ok(str_contains($stats, 'auth ×2') && str_contains($stats, 'HTTP 401') && str_contains($stats, 'bad key'), '/routingstats shows what the API answered');
});

test('invalid model id is skipped and not retried', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => modelOf($req) === 'best/a:free' ? orError(400, 'best/a:free is not a valid model ID') : chatOk('ok', modelOf($req)));
    $r = $rig->router->complete(convo());
    T::eq('good/b:free', $r['model']);
    $rig->t += 7200;
    $rig->router->complete(convo('next'));
    T::eq(['best/a:free', 'good/b:free', 'good/b:free'], $rig->attemptedModels());
});

test('context overflow: small model skipped without a request; 400 context → next model', function () {
    $models = [freeModel('small/ctx:free', 80, ['context_length' => 8192]), freeModel('big/ctx:free', 60, ['context_length' => 200000])];
    $rig = new RouterRig($models, fn ($req) => chatOk('ok', modelOf($req)));
    $long = convo(str_repeat('long text ', 4000));
    $r = $rig->router->complete($long);
    T::eq('big/ctx:free', $r['model']);
    T::eq(['big/ctx:free'], $rig->attemptedModels(), 'no request wasted on the small model');

    $rig2 = new RouterRig([freeModel('a/a:free', 80), freeModel('b/b:free', 60)], fn ($req) => modelOf($req) === 'a/a:free'
        ? orError(400, "This endpoint's maximum context length is 32768 tokens") : chatOk('ok', modelOf($req)));
    $r2 = $rig2->router->complete(convo());
    T::eq('b/b:free', $r2['model']);
    T::ok($rig2->health->isAvailable('a/a:free'), 'context error does not put the model on cooldown');
});

test('history trimmed to fit, newest message and system prompt preserved', function () {
    $rig = new RouterRig([freeModel('mid/ctx:free', 70, ['context_length' => 9000])], fn ($req) => chatOk('ok', modelOf($req)));
    $msgs = [['role' => 'system', 'content' => 'SYSTEM']];
    for ($i = 0; $i < 30; $i++) {
        $msgs[] = ['role' => $i % 2 ? 'assistant' : 'user', 'content' => str_repeat("turn $i ", 120)];
    }
    $msgs[] = ['role' => 'user', 'content' => 'NEWEST'];
    $rig->router->complete($msgs);
    $sent = $rig->http->bodies('chat/completions')[0]['messages'];
    T::eq('SYSTEM', $sent[0]['content']);
    T::eq('NEWEST', end($sent)['content']);
    T::ok(count($sent) < count($msgs), 'older turns dropped');
});

test('image / tool requests only go to compatible models', function () {
    $models = [freeModel('text/only:free', 90), freeModel('vision/model:free', 60, ['input_modalities' => ['text', 'image']])];
    $rig = new RouterRig($models, fn ($req) => chatOk('I see a cat', modelOf($req)));
    $img = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'what is this?'], ['type' => 'image_url', 'image_url' => ['url' => 'https://x/cat.png']]]]];
    $r = $rig->router->complete($img);
    T::eq('vision/model:free', $r['model']);
    T::eq(['vision/model:free'], $rig->attemptedModels(), 'never sent to text-only model');

    $rig2 = new RouterRig([freeModel('text/only:free', 90)], fn ($req) => str_contains((string) $req->getUri(), 'paid.example') ? json(200, ['choices' => [['message' => ['content' => 'paid']]]]) : chatOk('x', 'x'));
    $r2 = $rig2->router->complete(convo(), ['tools' => [['type' => 'function', 'function' => ['name' => 'f']]]]);
    T::eq(0, $rig2->freeCalls(), 'no tool-capable free model → none called');
    T::eq('no_eligible_free_model', $r2['fallback_reason']);
});

test('free model timeout → answer with paid at once, free route paused (slow or filtered host)', function () use ($three) {
    $rig = new RouterRig($three, function ($req) {
        if (str_contains((string) $req->getUri(), 'paid.example')) {
            return json(200, ['choices' => [['message' => ['content' => 'paid']]]]);
        }
        if (modelOf($req) === 'best/a:free') {
            return new ConnectException('cURL error 28: Operation timed out after 20000 milliseconds with 0 bytes received', $req);
        }
        return chatOk('ok', modelOf($req));
    });
    $r = $rig->router->complete(convo());
    T::ok($r['ok'] && $r['tier'] === 'paid', 'answered by the paid model right after the timeout');
    T::eq('free_timeout', $r['fallback_reason']);
    T::eq(1, $rig->health->localAccountCount(60), 'only one free request: no second free wait');
    T::ok(!$rig->health->isAvailable('best/a:free') && $rig->health->accountBlockedUntil() > 0, 'model cooled down and free route paused');
    $attempts = array_values(array_filter($rig->log->records(1), fn ($x) => $x['type'] === 'attempt'));
    T::eq(true, $attempts[0]['possibly_processed']);
    $r2 = $rig->router->complete(convo());
    T::ok($r2['ok'] && $r2['tier'] === 'paid' && str_starts_with((string) $r2['fallback_reason'], 'free_route_paused'), 'next message: paid directly, no waiting');
    T::eq(1, $rig->health->localAccountCount(60), 'no free request while paused');
});

test('paid timeout after provider may have processed: budget charged, bounded retries', function () {
    $rig = new RouterRig([], function ($req) {
        return new ConnectException('cURL error 28: Operation timed out after 20000 milliseconds with 0 bytes received', $req);
    }, ['routing' => ['mode' => 'paid_only'], 'paid' => ['max_attempts' => 2]]);
    $r = $rig->router->complete(convo());
    T::ok(!$r['ok']);
    T::ok($rig->paidCalls() <= 2, 'bounded');
    T::ok($rig->budget->status()['cost'] > 0, 'lost responses keep their (estimated) cost');
});

test('account daily quota → free paused until reset, then paid directly', function () use ($three) {
    $rig = new RouterRig($three, function ($req) {
        if (str_contains((string) $req->getUri(), 'paid.example')) {
            return json(200, ['choices' => [['message' => ['content' => 'paid']]]]);
        }
        return orError(429, 'Rate limit exceeded: free-models-per-day', ['metadata' => ['headers' => ['X-RateLimit-Remaining' => '0']]]);
    });
    $r = $rig->router->complete(convo());
    T::eq(1, $rig->freeCalls(), 'account-wide limit stops the chain at once');
    T::eq('paid', $r['tier']);
    $rig->t += 60;
    $r2 = $rig->router->complete(convo('more'));
    T::eq(1, $rig->freeCalls(), 'no free requests while paused');
    T::ok(str_starts_with($r2['fallback_reason'], 'free_route_paused'));
    $rig->t = Bot\ModelHealth::nextUtcMidnight($rig->t) + 1;
    $rig->router->complete(convo('tomorrow'));
    T::eq(2, $rig->freeCalls(), 'free route resumes after the daily reset');
});

test('local per-minute guard avoids exceeding the documented free rpm', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => chatOk('ok', modelOf($req)), ['routing' => ['account_free_rpm' => 2], 'paid' => ['fallback_enabled' => false]]);
    $rig->router->complete(convo('1'));
    $rig->router->complete(convo('2'));
    $r = $rig->router->complete(convo('3'));
    T::eq(2, $rig->freeCalls());
    T::eq('local_rpm_guard', $r['fallback_reason']);
});

test('scarce models are kept for hard tasks', function () {
    $models = [freeModel('elite/genius:free', 90), freeModel('steady/worker:free', 60)];
    $rig = new RouterRig($models, fn ($req) => chatOk('ok', modelOf($req)), ['routing' => ['reserved_model_patterns' => ['elite/*']]]);
    $r = $rig->router->complete(convo('hey, what is up?'));
    T::eq('steady/worker:free', $r['model'], 'ordinary chat does not burn the scarce model');
    $r = $rig->router->complete(convo('Prove step by step that the derivative of x^2 is 2x and analyze the proof'));
    T::eq('elite/genius:free', $r['model'], 'hard reasoning gets the strongest model');
});

test('frequently rate-limited model becomes scarce automatically', function () {
    $models = [freeModel('popular/smart:free', 90), freeModel('quiet/decent:free', 70)];
    $rig = new RouterRig($models, fn ($req) => chatOk('ok', modelOf($req)));
    for ($i = 0; $i < 4; $i++) {
        $rig->health->recordFailure('popular/smart:free', E::fromHttp(429, '{"error":{"message":"rate-limited upstream"}}', [], $rig->t), 100);
    }
    $rig->t += 7200; // cooldown over, but history remains
    $r = $rig->router->complete(convo('hello'));
    T::eq('quiet/decent:free', $r['model']);
});

test('quality_first mode prefers the most intelligent model', function () {
    $models = [freeModel('elite/genius:free', 90), freeModel('steady/worker:free', 60)];
    $rig = new RouterRig($models, fn ($req) => chatOk('ok', modelOf($req)), ['routing' => ['mode' => 'quality_first', 'reserved_model_patterns' => ['elite/*']]]);
    T::eq('elite/genius:free', $rig->router->complete(convo('hi'))['model']);
});

test('paid_only mode and explicit paid requests skip free models', function () use ($three) {
    $paid = fn ($req) => str_contains((string) $req->getUri(), 'paid.example') ? json(200, ['choices' => [['message' => ['content' => 'paid']]]]) : chatOk('free', modelOf($req));
    $rig = new RouterRig($three, $paid, ['routing' => ['mode' => 'paid_only']]);
    T::eq('paid', $rig->router->complete(convo())['tier']);
    T::eq(0, $rig->freeCalls());
    $rig2 = new RouterRig($three, $paid);
    $r = $rig2->router->complete(convo(), ['explicit_paid' => true, 'paid_model' => 'gpt-4o']);
    T::eq('paid', $r['tier']);
    T::eq('explicit_paid_request', $r['fallback_reason']);
    T::eq('gpt-4o', $rig2->http->bodies('paid.example')[0]['model']);
});

test('existing paid behaviour preserved: gpt-5 via responses endpoint', function () {
    $rig = new RouterRig([], fn ($req) => json(200, ['output' => [['content' => [['text' => 'from responses']]]]]), ['routing' => ['mode' => 'paid_only']]);
    $r = $rig->router->complete(convo(), ['paid_model' => 'gpt-5-mini']);
    T::eq('from responses', $r['content']);
    T::eq(1, $rig->http->count('paid.example/openai/v1/responses'));
    T::eq(1000, $rig->http->bodies('responses')[0]['max_output_tokens'], 'output cap applied (1000 as before)');
});

test('content refusal does not escalate to paid', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => orError(403, 'Input was flagged by moderation'));
    $r = $rig->router->complete(convo());
    T::eq(msg('refusal'), $r['user_message']);
    T::eq(1, $rig->freeCalls());
    T::eq(0, $rig->paidCalls());
});

test('conversation stays intact during failover', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => modelOf($req) === 'best/a:free' ? orError(503, 'down') : chatOk('ok', modelOf($req)));
    $msgs = convo('What is my name?');
    $rig->router->complete($msgs);
    $bodies = $rig->http->bodies('chat/completions');
    T::eq($msgs, $bodies[0]['messages']);
    T::eq($msgs, $bodies[1]['messages'], 'second model receives the same full context');
});

test('non-zero reported cost is never claimed as free', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => chatOk('ok', modelOf($req), 0.0004));
    $r = $rig->router->complete(convo());
    T::eq(false, $r['free_confirmed']);
    T::ok((bool) $rig->alerts, 'admin warned');
});

test('empty completion → next model', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => modelOf($req) === 'best/a:free' ? chatOk('', 'best/a:free') : chatOk('real answer', modelOf($req)));
    $r = $rig->router->complete(convo());
    T::eq('real answer', $r['content']);
});

test('reasoning effort only sent to models that support it', function () {
    $models = [freeModel('think/r1:free', 80, ['supported_parameters' => ['reasoning', 'max_tokens']])];
    $rig = new RouterRig($models, fn ($req) => chatOk('ok', modelOf($req)));
    $rig->router->complete(convo('hello'));
    T::eq('low', $rig->http->bodies('chat/completions')[0]['reasoning']['effort']);
    $rig2 = new RouterRig([freeModel('plain/m:free', 80)], fn ($req) => chatOk('ok', modelOf($req)));
    $rig2->router->complete(convo('hello'));
    T::ok(!isset($rig2->http->bodies('chat/completions')[0]['reasoning']));
});

test('routing logs contain no prompt text or keys; summary works', function () use ($three) {
    $rig = new RouterRig($three, fn ($req) => modelOf($req) === 'best/a:free' ? orError(503, 'down sk-or-v1-leakedkey1234567890') : chatOk('SECRET ANSWER', modelOf($req)));
    $rig->router->complete(convo('my private question'));
    $raw = '';
    foreach (glob($rig->store->dir() . '/logs/*.jsonl') as $f) {
        $raw .= file_get_contents($f);
    }
    T::ok(!str_contains($raw, 'private question') && !str_contains($raw, 'SECRET ANSWER'), 'no conversation content');
    T::ok(!str_contains($raw, 'leakedkey1234567890') && !str_contains($raw, 'sk-or-test-key'), 'no keys');
    $s = $rig->log->summary(1, $rig->health->snapshot(), $rig->budget->status(), $rig->catalog->state());
    T::eq(1, $s['requests']);
    T::eq(100.0, $s['free_pct']);
    T::eq(100.0, $s['fallback_pct']);
    T::eq(0.0, $s['by_model']['best/a:free']['success_rate']);
    T::ok(str_contains(Bot\RoutingLog::format($s), 'free 100%'));
});

test('deadline bounds the free chain and keeps time for paid', function () {
    $models = [freeModel('slow/a:free', 90), freeModel('slow/b:free', 80), freeModel('slow/c:free', 70)];
    $rig = null;
    $rig = new RouterRig($models, function ($req, $options) use (&$rig) {
        if (str_contains((string) $req->getUri(), 'paid.example')) {
            return json(200, ['choices' => [['message' => ['content' => 'paid']]]]);
        }
        $rig->t += $options['timeout']; // each free attempt burns its whole timeout
        return new ConnectException('cURL error 28: Operation timed out after 20000 milliseconds with 0 bytes received', $req);
    });
    $r = $rig->router->complete(convo());
    T::ok($rig->freeCalls() < 3, 'stopped early because of the total deadline');
    T::eq('paid', $r['tier']);
});

// ---------------- tiers (normal / smart / extra) ----------------

test('tier list order is used; paid fallback uses the tier paid model', function () {
    $rig = new RouterRig([], function ($req) {
        if (str_contains((string) $req->getUri(), 'paid.example')) {
            return json(200, ['choices' => [['message' => ['content' => 'paid']]]]);
        }
        return orError(503, 'down');
    });
    $r = $rig->router->complete(convo(), ['tier' => 'extra']);
    T::eq(['nvidia/nemotron-3-ultra-550b-a55b:free', 'thinkingmachines/inkling:free', 'dots-studio/dots-3-note-preview:free'], $rig->attemptedModels(), 'extra tier order, max 3 attempts');
    T::eq('paid', $r['tier']);
    T::eq('gpt-4.1', $rig->http->bodies('paid.example')[0]['model'], 'extra → gpt-4.1');

    $rig2 = new RouterRig([], fn ($req) => str_contains((string) $req->getUri(), 'paid.example') ? json(200, ['choices' => [['message' => ['content' => 'p']]]]) : orError(503, 'down'));
    $rig2->router->complete(convo(), ['tier' => 'normal']);
    T::eq('gpt-4.1-nano', $rig2->http->bodies('paid.example')[0]['model'], 'normal → gpt-4.1-nano (as before for free users)');
});

test('tier falls back to lower-tier free models before paying', function () {
    $rig = new RouterRig([], function ($req) {
        return in_array(modelOf($req), ['nvidia/nemotron-3-super-120b-a12b:free', 'thinkingmachines/inkling:free', 'nvidia/nemotron-3.5-super-vl:free'], true)
            ? orError(429, 'rate-limited upstream') : chatOk('from lower tier', modelOf($req));
    }, ['routing' => ['max_free_attempts' => 5]]);
    $r = $rig->router->complete(convo(), ['tier' => 'smart']);
    T::eq('poolside/laguna-s-2.1:free', $r['model'], '4th smart model answers');
    T::eq('free', $r['tier']);
    $rig->t += 5;
    $r2 = $rig->router->complete(convo('again'), ['tier' => 'smart']);
    T::eq('poolside/laguna-s-2.1:free', $r2['model'], 'rate-limited models skipped without requests');
    T::eq(5, $rig->freeCalls());
});

test('live catalogue validates tier models (retired / now-paid ones are skipped)', function () {
    $catalog = [
        freeModel('nvidia/nemotron-3-super-120b-a12b:free', 60),
        freeModel('poolside/laguna-s-2.1:free', 55),
        freeModel('some/other-model:free', 99),
    ];
    $rig = new RouterRig($catalog, fn ($req) => chatOk('ok', modelOf($req), 0.0));
    $r = $rig->router->complete(convo(), ['tier' => 'normal']);
    T::eq('nvidia/nemotron-3-super-120b-a12b:free', $r['model'], 'lightning not listed → skipped');
    T::eq(['nvidia/nemotron-3-super-120b-a12b:free'], $rig->attemptedModels());
    T::eq(true, $r['free_confirmed']);
    $ranked = array_map(fn ($c) => $c['model']['id'], $rig->router->rank('ordinary', TaskClassifierNeeds(), convo(), [], 'free_first', 'normal'));
    T::ok(!in_array('some/other-model:free', $ranked, true), 'models outside the tier list are not used by default');
});

test('use_discovered_fallback adds other free models after the tier list', function () {
    $catalog = [freeModel('poolside/laguna-s-2.1:free', 55), freeModel('some/other-model:free', 99)];
    $rig = new RouterRig($catalog, fn ($req) => modelOf($req) === 'poolside/laguna-s-2.1:free' ? orError(503, 'down') : chatOk('ok', modelOf($req)), ['routing' => ['use_discovered_fallback' => true]]);
    $r = $rig->router->complete(convo(), ['tier' => 'normal']);
    T::eq('some/other-model:free', $r['model']);
});

function TaskClassifierNeeds(): array
{
    return ['image' => false, 'file' => false, 'audio' => false, 'tools' => false];
}
