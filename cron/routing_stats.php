<?php
// Prints the routing summary: php cron/routing_stats.php [days]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

$services = new Bot\Services($config);
echo $services->statsText(max(1, (int) ($argv[1] ?? 1))) . "\n";
