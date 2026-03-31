<?php
/**
 * Setup script to add alias_of column to ticker_mapping
 * This allows linking old ticker symbols to their current (canonical) ticker
 * 
 * Example: GOLD -> B (Barnes Group changed ticker)
 */

$paths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/env.php',
    __DIR__ . '/../env.php'
];

foreach ($paths as $p) {
    if (file_exists($p)) {
        require_once $p;
        break;
    }
}

if (!defined('DB_HOST')) {
    die("Error: Could not load env config.\n");
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection Error: " . $e->getMessage() . "\n");
}

echo "=== Ticker Alias Setup ===\n\n";

// 1. Check if column exists
$stmt = $pdo->prepare("SHOW COLUMNS FROM ticker_mapping LIKE 'alias_of'");
$stmt->execute();
$exists = $stmt->fetch();

if ($exists) {
    echo "✓ Column 'alias_of' already exists.\n";
} else {
    echo "Adding 'alias_of' column to ticker_mapping...\n";
    $pdo->exec("ALTER TABLE ticker_mapping ADD COLUMN alias_of VARCHAR(20) NULL AFTER google_finance_code");
    echo "✓ Column 'alias_of' added successfully.\n";
}

// 2. Add index for faster lookups
try {
    $pdo->exec("CREATE INDEX idx_alias_of ON ticker_mapping(alias_of)");
    echo "✓ Index 'idx_alias_of' created.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "✓ Index 'idx_alias_of' already exists.\n";
    } else {
        echo "⚠ Index creation warning: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Setup Complete ===\n";
echo "You can now run detect_ticker_aliases.php to find potential duplicates.\n";
