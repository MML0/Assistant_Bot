<?php
// migration2.php: Add 'baned' column to users table if not exists, with admin password protection
$config = require 'config.php';

$input = $_GET['pass'] ?? '';
if ($input !== $config['admin']['password']) {
    die("❌ Wrong admin password. Migration aborted.\n");
}
echo "✔ Admin authenticated.\n";

try {
    $db = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']}",
        $config['database']['username'],
        $config['database']['password']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if 'baned' column exists
    $result = $db->query("SHOW COLUMNS FROM users LIKE 'baned'");
    if ($result->rowCount() === 0) {
        $db->exec("ALTER TABLE users ADD COLUMN baned TINYINT(1) NOT NULL DEFAULT 0");
        echo "Column 'baned' added successfully.\n";
    } else {
        echo "Column 'baned' already exists.\n";
    }
} catch (PDOException $e) {
    echo "Migration error: " . $e->getMessage();
}
