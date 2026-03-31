<?php
header("Cache-Control: no-cache");
header("Content-Type: text/plain");

$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) die("env not found");

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $translations = [
        ['col_qty', 'cs', 'Množství'],
        ['col_qty', 'en', 'Quantity'],
    ];

    $stmt = $pdo->prepare("INSERT INTO translations (label_key, language, translation) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE translation = VALUES(translation)");
    
    foreach ($translations as $t) {
        $stmt->execute($t);
        echo "Added/Updated: {$t[0]} ({$t[1]}) = {$t[2]}\n";
    }
    
    echo "\nDone!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
