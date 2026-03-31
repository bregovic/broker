<?php
/**
 * Setup script to add assigned_to column to changerequest_log
 */
header('Content-Type: text/plain');

    $paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/../env.php'];
    $envLoaded = false;
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $envLoaded = true; break; } }
    
    if (!$envLoaded) {
        throw new Exception("Env file not found in paths: " . implode(', ', $paths));
    }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Check if column exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM changerequest_log LIKE 'assigned_to'");
    $stmt->execute();
    if ($stmt->fetch()) {
        echo "Column 'assigned_to' already exists.\n";
    } else {
        echo "Adding 'assigned_to' column...\n";
        $pdo->exec("ALTER TABLE changerequest_log ADD COLUMN assigned_to INT NULL AFTER priority");
        
        // Add foreign key constraint if desired, but for now just index
        $pdo->exec("CREATE INDEX idx_assigned_to ON changerequest_log(assigned_to)");
        
        echo "Column added successfully.\n";
    }

} catch (PDOException $e) {
    echo "DB Error: " . $e->getMessage();
}
