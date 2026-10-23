<?php
// Offline test suite: every HTTP call is mocked, so no free quota or paid credit is used.
require __DIR__ . '/bootstrap.php';

foreach (['UnitTest', 'CatalogTest', 'RouterTest', 'VoiceTest'] as $file) {
    echo "\n{$file}\n";
    require __DIR__ . "/{$file}.php";
}

echo "\n" . T::$pass . " assertions passed, " . count(T::$fail) . " failed\n";
foreach (T::$fail as $f) {
    echo "  FAIL: {$f}\n";
}
exit(T::$fail ? 1 : 0);
