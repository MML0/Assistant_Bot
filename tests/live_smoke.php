<?php
// OPT-IN live check against the real OpenRouter API. Not part of tests/run.php.
//
//   OPENROUTER_API_KEY=sk-or-... php tests/live_smoke.php           # discovery only: 0 chat requests
//   OPENROUTER_API_KEY=sk-or-... php tests/live_smoke.php --chat    # + ONE tiny chat request
//
// Discovery (/models, /benchmarks, /key) does not consume free-model requests.
// --chat sends a single ~20-token prompt to the top-ranked free model: it uses 1 of your
// daily free requests (50/day on a free-tier key) and never touches the paid API.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

$key = getenv('OPENROUTER_API_KEY');
if (!$key) {
    fwrite(STDERR, "Set OPENROUTER_API_KEY to run the live smoke test.\n");
    exit(2);
}
$dir = sys_get_temp_dir() . '/bot_live_smoke';
$services = new Bot\Services([
    'openrouter' => ['api_key' => $key],
    'storage_dir' => $dir,
    'routing' => ['max_free_attempts' => 1, 'mode' => 'cost_min'], // never paid, one attempt
]);
$ok = $services->catalog->refresh();
$state = $services->catalog->state();
echo 'discovery: ' . ($ok ? 'ok' : 'FAILED (' . ($state['last_error'] ?? '?') . ')') . "\n";
echo 'intelligence sort accepted: ' . (!empty($state['sorted_by_intelligence']) ? 'yes' : 'no') . "\n";
echo 'models listed by API: ' . ($state['total_listed'] ?? 0) . ', benchmark rows matched: ' . count($state['benchmarks'] ?? []) . "\n";
echo 'free tier: ' . var_export($state['key_info']['is_free_tier'] ?? null, true) . "\n";
foreach ($state['models'] ?? [] as $i => $m) {
    printf("%2d. %-55s ctx=%-7d intel=%s%s\n", $i + 1, $m['id'], $m['context_length'], $m['intelligence'] ?? '?', $m['quality_estimated'] ? ' (estimated)' : '');
}
if (in_array('--chat', $argv, true)) {
    $r = $services->router->complete([['role' => 'user', 'content' => 'Reply with the single word: pong']]);
    echo "\nchat: " . ($r['ok'] ? "ok via {$r['model']} (free confirmed: " . var_export($r['free_confirmed'], true) . ")\n  → " . mb_substr($r['content'], 0, 80) : "failed: {$r['error_class']}") . "\n";
}
exit($ok ? 0 : 1);
