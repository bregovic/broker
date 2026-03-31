<?php
// update_changerequests_db.php
header('Content-Type: text/plain; charset=utf-8');

// Load Env
$paths = [__DIR__ . '/env.local.php', __DIR__ . '/../php/env.local.php', __DIR__ . '/env.php'];
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; break; } }
if (!defined('DB_HOST')) die("DB_HOST not defined");

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "Connected.\n";

    // Add subject column if not exists
    $cols = $pdo->query("SHOW COLUMNS FROM changerequest_log LIKE 'subject'")->fetchAll();
    if (count($cols) == 0) {
        $pdo->exec("ALTER TABLE changerequest_log ADD COLUMN subject VARCHAR(255) NOT NULL DEFAULT 'No Subject' AFTER user_id");
        echo "Column 'subject' added.\n";
    } else {
        echo "Column 'subject' already exists.\n";
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
