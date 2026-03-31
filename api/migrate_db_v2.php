<?php
// migrate_db_v2.php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(1800);
ini_set('memory_limit', '512M');

// 1. Load OLD configuration
echo "DEBUG INFO:<br>";
echo "__DIR__: " . __DIR__ . "<br>";
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "Allowed Paths (open_basedir): " . ini_get('open_basedir') . "<br><br>";

$parentDir = __DIR__ . '/../php/';
if (is_dir($parentDir)) {
    echo "Listing php dir ($parentDir):<br>";
    echo "<pre>" . print_r(scandir($parentDir), true) . "</pre><br>";
} else {
    echo "Parent dir is not accessible.<br>";
}

$configPaths = [
    __DIR__ . '/../php/env.local.php', // Confirmed location!
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php', // This was missing and is likely the correct one
    $_SERVER['DOCUMENT_ROOT'] . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/broker/env.local.php',
    '/data/web/virtuals/322503/virtual/www/broker/env.local.php'
];

$loaded = false;
echo "Start config load loop...<br>";

foreach ($configPaths as $path) {
    echo "Checking: $path ... ";
    if (file_exists($path)) {
        echo "FOUND! Loading... <br>";
        require_once $path;
        $loaded = true;
        echo "Loaded config from $path<br>";
        break;
    } else {
        echo "not found.<br>";
    }
}

if (!$loaded) {
    die("Configuration file not found. Checked paths: " . implode(", ", $configPaths));
}

echo "DB_HOST defined? " . (defined('DB_HOST') ? 'YES' : 'NO') . "<br>";
echo "DB_NAME defined? " . (defined('DB_NAME') ? 'YES' : 'NO') . "<br>";
echo "Connecting to OLD DB...<br>";

// Old DB Connection (using CONSTANTS from env.local.php)
try {
    // Assuming env.local.php defines DB_HOST, DB_NAME, DB_USER, DB_PASS
    $dsnOld = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $pdoOld = new PDO($dsnOld, DB_USER, DB_PASS);
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
    $pdoNew->exec("SET sql_mode=''");
} catch (Exception $e) {
    die("Failed to connect to NEW DB: " . $e->getMessage());
}

echo "Connected to both databases.\n";
echo "Source: ".DB_NAME." (Old)\n";
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

    try {
        $pdoOld->query("SELECT 1 FROM `$oldName` LIMIT 1");
    } catch (Exception $e) {
        echo "Old table $oldName not found. Skipping.\n";
        continue;
    }

    // Get Create Table
    $stmt = $pdoOld->query("SHOW CREATE TABLE `$oldName`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $createSql = $row['Create Table'];

    // Modify Create Syntax
    $pattern = "/CREATE TABLE\s+`?" . preg_quote($oldName) . "`?/i";
    $replacement = "CREATE TABLE `$newName`";
    $createSql = preg_replace($pattern, $replacement, $createSql, 1);
    
    // Create
    $pdoNew->exec("DROP TABLE IF EXISTS `$newName`");
    $pdoNew->exec($createSql);
    echo "Table structure created.\n";

    // Copy Data
    $totalRows = $pdoOld->query("SELECT COUNT(*) FROM `$oldName`")->fetchColumn();
    echo "Rows to copy: $totalRows\n";

    if ($totalRows > 0) {
        $chunkSize = 1000;
        $offset = 0;
        $copied = 0;

        $sample = $pdoOld->query("SELECT * FROM `$oldName` LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $columns = array_keys($sample);
        $colsStr = "`" . implode("`, `", $columns) . "`";
        
        while ($offset < $totalRows) {
            $chunkStmt = $pdoOld->prepare("SELECT * FROM `$oldName` LIMIT :limit OFFSET :offset");
            $chunkStmt->bindValue(':limit', $chunkSize, PDO::PARAM_INT);
            $chunkStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $chunkStmt->execute();
            $rows = $chunkStmt->fetchAll(PDO::FETCH_NUM);
            
            if (!$rows) break;

            $pdoNew->beginTransaction();
            try {
                $placeholders = "(" . implode(", ", array_fill(0, count($columns), "?")) . ")";
                $sql = "INSERT INTO `$newName` ($colsStr) VALUES " . $placeholders;
                $stmtInsert = $pdoNew->prepare($sql);
                
                foreach ($rows as $row) {
                    $stmtInsert->execute($row);
                    $copied++;
                }
                $pdoNew->commit();
            } catch (Exception $e) {
                $pdoNew->rollBack();
                echo "Error inserting chunk: " . $e->getMessage() . "\n";
                break;
            }
            $offset += $chunkSize;
        }
        echo "Copied: $copied\n";
    }
}

echo "\n--------------------------------------------------\n";
echo "MIGRATION FINISHED.\n";
