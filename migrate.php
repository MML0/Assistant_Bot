<?php
$config = require 'config.php';

// Example: ask for admin password before running migration
echo "Enter admin password: ";
$handle = fopen("php://stdin", "r");
$input = trim(fgets($handle));

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

    echo "Starting fresh migration...\n";

    // ------------------------------
    // DROP OLD TABLES SAFELY
    // ------------------------------
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");

    $db->exec("DROP TABLE IF EXISTS messages;");
    $db->exec("DROP TABLE IF EXISTS users;");

    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "Old tables dropped.\n";

    // ------------------------------
    // CREATE USERS TABLE
    // ------------------------------
    $sql_users = "
    CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chat_id BIGINT NOT NULL UNIQUE,
        username VARCHAR(255),
        first_name VARCHAR(255),
        last_name VARCHAR(255),
        is_pro TINYINT(1) DEFAULT 0,
        pro_expire DATETIME DEFAULT NULL,
        model VARCHAR(50) NOT NULL DEFAULT 'gpt-4.1-mini',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $db->exec($sql_users);
    echo "Users table created.\n";

    // ------------------------------
    // CREATE MESSAGES TABLE
    // ------------------------------
    $sql_messages = "
    CREATE TABLE messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        type ENUM('USER','AI','SUMMARY') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $db->exec($sql_messages);
    echo "Messages table created.\n";

    echo "Fresh migration completed successfully!";
} catch (PDOException $e) {
    echo "Migration error: " . $e->getMessage();
}
