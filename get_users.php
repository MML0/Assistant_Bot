<?php

$config = require 'config.php';

$input = $_GET['pass'] ?? '';

if (!hash_equals((string) $config['admin']['password'], (string) $input)) {
    die(" Wrong admin password.");
}

header('Content-Type: application/json');

try {
    $db = new PDO(
        "mysql:host={$config['database']['host']};dbname={$config['database']['dbname']};charset=utf8mb4",
        $config['database']['username'],
        $config['database']['password']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $table = ($config['database']['table_prefix'] ?? 'v2_') . 'users';
    if (!preg_match('~^[A-Za-z0-9_]+$~', $table)) {
        throw new PDOException('invalid table prefix');
    }
    $stmt = $db->query("SELECT * FROM {$table}");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($users);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

exit;
