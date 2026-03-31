<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

// 1. Load Config
$envPaths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
    __DIR__ . '/../../env.local.php',
    __DIR__ . '/php/env.local.php',
    __DIR__ . '/env.php',
    __DIR__ . '/../env.php',
    __DIR__ . '/../../env.php',
];

$config = null;
foreach ($envPaths as $path) {
    if (file_exists($path)) {
        $config = require $path;
        break;
    }
}

if (!$config) {
    echo json_encode(['success' => false, 'error' => 'Config not found']);
    exit;
}

// 2. Connect
$mysqli = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
if ($mysqli->connect_error) {
    echo json_encode(['success' => false, 'error' => 'DB Connection failed']);
    exit;
}
$mysqli->set_charset("utf8mb4");

// 3. Create Table
$sql = "CREATE TABLE IF NOT EXISTS broker_settings (
    user_id INT NOT NULL,
    language VARCHAR(10) DEFAULT 'cs',
    theme VARCHAR(20) DEFAULT 'light',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if ($mysqli->query($sql) === TRUE) {
    echo json_encode(['success' => true, 'message' => 'Table broker_settings checked/created.']);
} else {
    echo json_encode(['success' => false, 'error' => $mysqli->error]);
}
