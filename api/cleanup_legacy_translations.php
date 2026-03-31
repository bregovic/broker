<?php
// cleanup_legacy_translations.php
// Drops the backup table broker_translations_legacy.

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

    echo "<h3>Cleanup Legacy Table</h3>";
    
    $pdo->exec("DROP TABLE IF EXISTS broker_translations_legacy");
    echo "Dropped <code>broker_translations_legacy</code>.<br>";
    
    // Also drop broker_translations if by error it exists and is not the main one?
    // No, rename_script renamed broker_translations to translations.
    // So broker_translations should not exist.
    // But checking if broker_translations exists and is empty might be good? No, risky.
    // Just drop legacy.

    echo "Cleanup Complete.";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
