<?php
require_once __DIR__ . '/config.php';
try {
    $pdo = get_pdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "DB Driver: $driver\n";
    
    // Check live_quotes columns
    $stmt = $pdo->query("SELECT * FROM live_quotes LIMIT 0");
    $cols = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $meta = $stmt->getColumnMeta($i);
        $cols[] = $meta['name'];
    }
    echo "Columns in live_quotes: " . implode(', ', $cols) . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
