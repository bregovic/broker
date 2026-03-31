<?php
// migrate_db.php
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(1800); // 30 minutes
ini_set('memory_limit', '512M');

// 1. Load OLD configuration
$configPaths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/broker/env.local.php',
    '/data/web/virtuals/322503/virtual/www/broker/env.local.php'
];

$config = null;
$configPathUsed = '';

foreach ($configPaths as $path) {
    if (file_exists($path)) {
        $config = include $path;
        $configPathUsed = $path;
        break;
    }
}

if (!$config) {
    // Debug info
    echo "Current Dir: " . __DIR__ . "<br>";
    echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
    die("Configuration file not found. Checked paths: " . implode(", ", $configPaths));
}

$oldConfig = $config; // Assign to oldConfig variable expected hereafter

// Old DB Connection
try {
    $dsnOld = "mysql:host={$oldConfig['db_host']};dbname={$oldConfig['db_name']};charset=utf8mb4";
    $pdoOld = new PDO($dsnOld, $oldConfig['db_user'], $oldConfig['db_pass']);
    $pdoOld->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Failed to connect to OLD DB: " . $e->getMessage());
}

// 2. New DB Connection
$newHost = 'md390.wedos.net';
$newDb = 'd372733_invest';
$newUser = 'a372733_invest';
$newPass = 'Venca123!';

try {
    $dsnNew = "mysql:host={$newHost};dbname={$newDb};charset=utf8mb4";
    $pdoNew = new PDO($dsnNew, $newUser, $newPass);
    $pdoNew->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Disable strict mode to allow smooth migration of potentially messy legacy data
    $pdoNew->exec("SET sql_mode=''");
} catch (Exception $e) {
    die("Failed to connect to NEW DB: " . $e->getMessage());
}

echo "Connected to both databases.\n";
echo "Source: {$oldConfig['db_name']} (Old)\n";
echo "Target: {$newDb} (New)\n\n";

// 3. List tables to migrate
$mapping = [
    'broker_transactions' => 'transactions',
    'broker_dividends' => 'dividends',
    'broker_live_quotes' => 'live_quotes',
    'broker_rates' => 'rates', 
    'broker_ticker_mapping' => 'ticker_mapping',
    'broker_translations' => 'translations',
    'broker_user_settings' => 'user_settings',
    'broker_tickers_history' => 'tickers_history',
];

foreach ($mapping as $oldName => $newName) {
    echo "--------------------------------------------------\n";
    echo "Migrating $oldName -> $newName ...\n";

    // A. Check if old table exists
    try {
        $check = $pdoOld->query("SELECT 1 FROM `$oldName` LIMIT 1");
    } catch (Exception $e) {
        echo "Old table $oldName does not exist (or connection error). Skipping.\n";
        continue;
    }

    // B. Get Create Table Syntax
    try {
        $stmt = $pdoOld->query("SHOW CREATE TABLE `$oldName`");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $createSql = $row['Create Table'];
    } catch (Exception $e) {
        echo "Error getting CREATE TABLE for $oldName: " . $e->getMessage() . "\n";
        continue;
    }

    // C. Modify Create Syntax using Regex to be robust
    // Replace table name `broker_oldName` with `newName`
    // We look for "CREATE TABLE `broker_oldName`"
    $pattern = "/CREATE TABLE\s+`?" . preg_quote($oldName) . "`?/i";
    $replacement = "CREATE TABLE `$newName`";
    $createSql = preg_replace($pattern, $replacement, $createSql, 1);
    
    // Also remove AUTO_INCREMENT=... to start clean or keep it? 
    // Usually better to keep it to preserve ID continuity if we insert explicit IDs.
    
    // Create in New DB
    try {
        $pdoNew->exec("DROP TABLE IF EXISTS `$newName`");
        $pdoNew->exec($createSql);
        echo "Table structure created.\n";
    } catch (Exception $e) {
        echo "Error creating table $newName: " . $e->getMessage() . "\n";
        continue;
    }

    // D. Copy Data
    $totalRows = $pdoOld->query("SELECT COUNT(*) FROM `$oldName`")->fetchColumn();
    echo "Found $totalRows rows to copy.\n";

    if ($totalRows > 0) {
        $chunkSize = 1000;
        $offset = 0;
        $copied = 0;

        // Get columns to build generic insert
        $sample = $pdoOld->query("SELECT * FROM `$oldName` LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $columns = array_keys($sample);
        $colsStr = "`" . implode("`, `", $columns) . "`";
        $placeholders = "(" . implode(", ", array_fill(0, count($columns), "?")) . ")";

        $baseInsertInfo = "INSERT INTO `$newName` ($colsStr) VALUES ";

        while ($offset < $totalRows) {
            $chunkStmt = $pdoOld->prepare("SELECT * FROM `$oldName` LIMIT :limit OFFSET :offset");
            $chunkStmt->bindValue(':limit', $chunkSize, PDO::PARAM_INT);
            $chunkStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $chunkStmt->execute();
            
            $rows = $chunkStmt->fetchAll(PDO::FETCH_NUM);
            if (!$rows) break;

            // Cannot use single query for all if too big, but chunks of 1000 are usually fine for MySQL
            // But PDO prepare binds are safer.
            // Let's use transactions for speed.
            
            $pdoNew->beginTransaction();
            try {
                $sql = "INSERT INTO `$newName` ($colsStr) VALUES " . implode(", ", array_fill(0, count($columns), "?"));
                $stmtInsert = $pdoNew->prepare($sql);
                
                foreach ($rows as $row) {
                    $stmtInsert->execute($row);
                    $copied++;
                }
                $pdoNew->commit();
            } catch (Exception $e) {
                $pdoNew->rollBack();
                echo "Error inserting chunk at offset $offset: " . $e->getMessage() . "\n";
                break;
            }

            $offset += $chunkSize;
            if ($copied % 5000 == 0) echo "  Copied $copied rows...\n";
        }
        echo "Successfully copied $copied / $totalRows rows.\n";
    } else {
        echo "Table is empty, structure only copied.\n";
    }
}

echo "\n--------------------------------------------------\n";
echo "MIGRATION FINISHED.\n";
