<?php
// ============================================================================
// migrate.php — creates the NEW tables (v2_*) used by this version and copies
// users + chat history from the old `users` / `messages` tables into them.
//
// The old tables are only READ, never changed or dropped, so the previous
// version of the bot keeps working with them and you can roll back any time.
//
// Run once after uploading:
//   browser:  https://YOUR-HOST/PATH/migrate.php?pass=ADMIN_PASSWORD
//   or CLI:   php migrate.php
// Running it again is safe: tables are created only if missing and data is
// copied only while the new users table is still empty.
// ============================================================================
$config = require __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');
if (PHP_SAPI !== 'cli') {
    $input = (string) ($_GET['pass'] ?? '');
    if (!hash_equals((string) $config['admin']['password'], $input)) {
        http_response_code(403);
        die("❌ Wrong admin password. Migration aborted.\n");
    }
}
@set_time_limit(0);

$p = $config['database']['table_prefix'] ?? 'v2_';
if (!preg_match('~^[A-Za-z0-9_]{1,20}$~', $p)) {
    die("❌ Invalid table_prefix (letters, digits and _ only, and it must not be empty).\n");
}
$U = "{$p}users";
$M = "{$p}messages";

echo "✔ Admin authenticated.\n";
try {
    $db = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
        $config['database']['username'],
        $config['database']['password']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ------------------------------ NEW TABLES ------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS {$U} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chat_id BIGINT NOT NULL UNIQUE,
        username VARCHAR(255),
        first_name VARCHAR(255),
        last_name VARCHAR(255),
        is_pro TINYINT(1) NOT NULL DEFAULT 0,
        baned TINYINT(1) NOT NULL DEFAULT 0,
        pro_expire DATETIME DEFAULT NULL,
        model VARCHAR(50) NOT NULL DEFAULT 'normal',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS {$M} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        chat_memory_id BIGINT NOT NULL,
        message MEDIUMTEXT NOT NULL,
        type ENUM('USER','AI','SUMMARY') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_{$p}msg_memory (chat_memory_id, id),
        INDEX idx_{$p}msg_user_day (user_id, created_at),
        FOREIGN KEY (user_id) REFERENCES {$U}(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Every Bale payment (paid or not), one row per transaction id.
    $db->exec("CREATE TABLE IF NOT EXISTS {$p}payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_user_id BIGINT NOT NULL,
        user_id INT NULL,
        package VARCHAR(32) NOT NULL,
        amount BIGINT NOT NULL,
        currency VARCHAR(8) NOT NULL DEFAULT '',
        payload VARCHAR(255) NOT NULL DEFAULT '',
        charge_id VARCHAR(128) NOT NULL UNIQUE,
        provider_charge_id VARCHAR(128) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL,
        days INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_{$p}pay_user (telegram_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Paid premium (voice-to-text): only verified Bale payments or the admin's /grantpremium.
    $db->exec("CREATE TABLE IF NOT EXISTS {$p}premium_entitlements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_user_id BIGINT NOT NULL,
        feature VARCHAR(32) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        source VARCHAR(32) NOT NULL,
        payment_ref VARCHAR(128) NULL,
        starts_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        granted_by BIGINT NOT NULL,
        created_at DATETIME NOT NULL,
        cancelled_at DATETIME NULL,
        INDEX idx_{$p}ent_user (telegram_user_id, status, expires_at),
        INDEX idx_{$p}ent_ref (payment_ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS {$p}voice_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_user_id BIGINT NOT NULL,
        user_id INT NULL,
        update_id BIGINT NULL,
        duration_seconds INT NOT NULL,
        file_bytes INT NULL,
        provider VARCHAR(64) NOT NULL,
        model VARCHAR(64) NOT NULL,
        status VARCHAR(16) NOT NULL,
        estimated_cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_{$p}voice_user (telegram_user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "✔ New tables ready: {$U}, {$M}, {$p}payments, {$p}premium_entitlements, {$p}voice_usage\n";

    // ------------------------------ COPY OLD DATA ------------------------------
    $tableExists = function (string $name) use ($db): bool {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn() > 0;
    };
    $newUsers = (int) $db->query("SELECT COUNT(*) FROM {$U}")->fetchColumn();

    if ($newUsers > 0) {
        echo "ℹ {$U} already has {$newUsers} users → nothing copied (already migrated).\n";
    } elseif (!$tableExists('users')) {
        echo "ℹ No old `users` table found → starting with empty tables.\n";
    } else {
        $hasBaned = $db->query("SHOW COLUMNS FROM users LIKE 'baned'")->rowCount() > 0;
        $banedSql = $hasBaned ? 'COALESCE(baned, 0)' : '0';

        // Old GPT model names → the three new tiers.
        $tierSql = "CASE
            WHEN model IN ('gpt-4.1', 'gpt-4', 'gpt-5', 'gpt-5.1') THEN 'extra'
            WHEN model IN ('gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini', 'gpt-5-mini') THEN 'smart'
            ELSE 'normal' END";

        $db->beginTransaction();
        $db->exec("INSERT INTO {$U} (id, chat_id, username, first_name, last_name, is_pro, baned, pro_expire, model, created_at)
                   SELECT id, chat_id, username, first_name, last_name, COALESCE(is_pro, 0), {$banedSql}, pro_expire, {$tierSql}, created_at
                   FROM users");
        $copiedUsers = (int) $db->query("SELECT COUNT(*) FROM {$U}")->fetchColumn();

        $copiedMessages = 0;
        if ($tableExists('messages')) {
            $db->exec("INSERT INTO {$M} (id, user_id, chat_memory_id, message, type, created_at)
                       SELECT m.id, m.user_id, m.chat_memory_id, m.message, m.type, m.created_at
                       FROM messages m INNER JOIN {$U} u ON u.id = m.user_id
                       ORDER BY m.id");
            $copiedMessages = (int) $db->query("SELECT COUNT(*) FROM {$M}")->fetchColumn();
        }
        $db->commit();
        echo "✔ Copied {$copiedUsers} users (PRO status, expiry, ban, model tier) and {$copiedMessages} messages (history + summaries).\n";
        echo "  Old tables `users` and `messages` were not modified.\n";
    }

    // ------------------------------ STORAGE ------------------------------
    $storage = __DIR__ . '/storage';
    if (!is_dir($storage)) {
        @mkdir($storage, 0770, true);
    }
    echo is_writable($storage)
        ? "✔ storage/ is writable.\n"
        : "❌ storage/ is NOT writable. Give the web server write access to {$storage}\n";

    echo "\nMigration completed successfully! Next: open check.php?pass=… to test the services.\n";
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo "Migration error: " . $e->getMessage() . "\n";
}
