<?php
require_once __DIR__ . '/v3/Import/ImportManager.php';
require_once __DIR__ . '/../init_broker.php'; // To get DB connection if needed, though we can just mock it or use the real one

use Broker\V3\Import\ImportManager;

// We need a PDO connection to test the real DB rules
try {
    require_once __DIR__ . '/../env.local.php';
    $pdo = new PDO("pgsql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}

$manager = new ImportManager($pdo);
$testDir = 'C:/Users/Wendulka/Documents/Webhry/broker 3.0 (railway)/Import';
$files = scandir($testDir);

echo "Starting discovery test...\n";
echo str_repeat("=", 50) . "\n";

foreach ($files as $file) {
    if ($file === '.' || $file === '..' || is_dir($testDir . '/' . $file)) continue;
    
    echo "File: $file\n";
    try {
        $result = $manager->processFile($testDir . '/' . $file, $file);
        echo "  - Identified as: " . $result['broker'] . " (" . $result['config'] . ")\n";
        echo "  - Parser: " . $result['parser'] . "\n";
    } catch (Exception $e) {
        echo "  - ERROR: " . $e->getMessage() . "\n";
    }
    echo str_repeat("-", 30) . "\n";
}
