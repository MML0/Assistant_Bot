<?php
// Non-destructive upgrade: adds the premium entitlement and voice usage tables.
// Run once:  php migrate_upgrade.php   (or GET migrate_upgrade.php?pass=ADMIN_PASSWORD)
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Entitlements.php';

if (PHP_SAPI !== 'cli') {
    $input = $_GET['pass'] ?? '';
    if (!hash_equals((string) $config['admin']['password'], (string) $input)) {
        http_response_code(403);
        die("❌ Wrong admin password. Migration aborted.\n");
    }
}

try {
    $db = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']}",
        $config['database']['username'],
        $config['database']['password']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (Bot\Entitlements::mysqlSchema() as $sql) {
        $db->exec($sql);
    }
    echo "✔ premium_entitlements and voice_usage are ready (existing data untouched).\n";
} catch (PDOException $e) {
    http_response_code(500);
    echo "Migration error.\n";
    error_log('[migrate_upgrade] ' . $e->getMessage());
}
