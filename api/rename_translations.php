<?php
// rename_translations.php
// Rename broker_translations to translations.

header('Content-Type: text/html; charset=utf-8');

// Load config
$envPaths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
    __DIR__ . '/../../env.local.php',
    __DIR__ . '/php/env.local.php',
    __DIR__ . '/env.php',
    __DIR__ . '/../env.php',
    __DIR__ . '/../../env.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.php'
];

foreach ($envPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "<h3>Renaming Table</h3>";
    
    // Check if 'translations' already exists (maybe created by older script?)
    // We want the data from 'broker_translations' which we just migrated.
    
    // First, ensure target is clear
    $pdo->exec("DROP TABLE IF EXISTS translations");
    echo "Dropped target 'translations' if it existed.<br>";
    
    // Rename
    $pdo->exec("RENAME TABLE broker_translations TO translations");
    echo "Renamed <code>broker_translations</code> to <code>translations</code>.<br>";
    
    echo "Done. Table is now 'translations' (no prefix).";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
