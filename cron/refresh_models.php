<?php
// Refreshes the cached free-model catalogue (no chat requests, no quota used).
// Cron example (every 6 hours):  0 */6 * * *  php /path/to/bot/cron/refresh_models.php
// Use --force to ignore the refresh interval / failure backoff.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

$services = new Bot\Services($config);
$force = in_array('--force', $argv, true);
$ok = $force ? $services->catalog->refresh() : $services->catalog->refreshIfDue();
$state = $services->catalog->state();

echo ($ok ? "refreshed" : "not refreshed (fresh enough, locked, or failed)") . "\n";
echo "last error: " . ($state['last_error'] ?? 'none') . "\n";
echo "sorted by intelligence: " . (!empty($state['sorted_by_intelligence']) ? 'yes' : 'no') . "\n";
echo "free tier key: " . var_export($state['key_info']['is_free_tier'] ?? null, true) . "\n";
foreach ($state['models'] ?? [] as $i => $m) {
    printf("%2d. %-55s ctx=%-7d intel=%s coding=%s\n", $i + 1, $m['id'], $m['context_length'],
        $m['intelligence'] ?? '?', $m['coding'] ?? '?');
}
exit($ok || !empty($state['models']) ? 0 : 1);
