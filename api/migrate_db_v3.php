<?php
// migrate_db_v3.php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(1800);

echo "Starting migration V3...<br>";

// Hardcoded path based on debug finding
$configFile = __DIR__ . '/../php/env.local.php';

if (file_exists($configFile)) {
    echo "Found config at $configFile. Loading...<br>";
    require_once $configFile;
} else {
    // Try absolute path if relative fails due to some weirdness
    $configFile = '/data/web/virtuals/372733/virtual/www/broker/php/env.local.php';
    if (file_exists($configFile)) {
        echo "Found config at absolute path. Loading...<br>";
        require_once $configFile;
    } else {
        die("CRITICAL: Config not found at $configFile");
    }
}

echo "DB Connection OLD...<br>";
try {
    $dsnOld = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $pdoOld = new PDO($dsnOld, DB_USER, DB_PASS);
    $pdoOld->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected OLD.<br>";
    
    // Debug: List tables
    echo "Tables in OLD DB:<br>";
    $stmt = $pdoOld->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        echo $row[0] . "<br>";
    }
    echo "<br>";

} catch (Exception $e) {
    die("Failed OLD: " . $e->getMessage());
}

echo "DB Connection NEW...<br>";
$newHost = 'md390.wedos.net';
$newDb = 'd372733_invest';
$newUser = 'a372733_invest'; // User confirmed this should work now
$newPass = 'Venca123!';

try {
    $dsnNew = "mysql:host={$newHost};dbname={$newDb};charset=utf8mb4";
    $pdoNew = new PDO($dsnNew, $newUser, $newPass);
    $pdoNew->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdoNew->exec("SET sql_mode=''");
    echo "Connected NEW.<br>";
} catch (Exception $e) {
    die("Failed NEW: " . $e->getMessage());
}

// Updated mapping based on SHOW TABLES
$mapping = [
    'broker_trans' => 'transactions', // Was broker_transactions
    // 'broker_dividends' => 'dividends', // NOT FOUND in SHOW TABLES! skipping.
    'broker_live_quotes' => 'live_quotes',
    'broker_exrates' => 'rates', // Was broker_rates
    'broker_ticker_mapping' => 'ticker_mapping',
    'broker_translations' => 'translations',
    'broker_user_settings' => 'user_settings',
    'broker_price_history' => 'tickers_history', // Was broker_tickers_history
    'broker_watch' => 'watch', // Watchlist
];

foreach ($mapping as $oldName => $newName) {
    echo "Migrating $oldName -> $newName ... ";
    try {
        $pdoOld->query("SELECT 1 FROM `$oldName` LIMIT 1");
    } catch (Exception $e) {
        echo "Old table not found in SELECT check. Skipping.<br>";
        continue;
    }

    $stmt = $pdoOld->query("SHOW CREATE TABLE `$oldName`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $createSql = $row['Create Table'];
    
    // Rename table in SQL
    $createSql = preg_replace("/CREATE TABLE\s+`?" . preg_quote($oldName) . "`?/i", "CREATE TABLE IF NOT EXISTS `$newName`", $createSql, 1);
    
    // Remove AUTO_INCREMENT value to start clean? Or keep it? keeping it.

    // Try CREATE (might fail if no perm)
    try {
        // $pdoNew->exec("DROP TABLE IF EXISTS `$newName`"); // No permissions for DROP
        $pdoNew->exec($createSql);
    } catch (Exception $e) {
        echo "Create Table Failed (Permissions?): " . $e->getMessage() . "<br>";
        // If create failed, we can't insert.
        continue;
    }

    $totalRows = $pdoOld->query("SELECT COUNT(*) FROM `$oldName`")->fetchColumn();
    if ($totalRows > 0) {
        $chunkSize = 1000;
        $offset = 0;
        $sample = $pdoOld->query("SELECT * FROM `$oldName` LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $columns = array_keys($sample);
        $colsStr = "`" . implode("`, `", $columns) . "`";
        $placeholders = "(" . implode(", ", array_fill(0, count($columns), "?")) . ")";
        
        while ($offset < $totalRows) {
            $chunkStmt = $pdoOld->prepare("SELECT * FROM `$oldName` LIMIT $chunkSize OFFSET $offset");
            $chunkStmt->execute();
            $rows = $chunkStmt->fetchAll(PDO::FETCH_NUM);
            if (!$rows) break;

            $pdoNew->beginTransaction();
            $sql = "INSERT INTO `$newName` ($colsStr) VALUES $placeholders";
            $stmtInsert = $pdoNew->prepare($sql);
            foreach ($rows as $row) $stmtInsert->execute($row);
            $pdoNew->commit();
            $offset += $chunkSize;
        }
    }
    echo "Done ($totalRows rows).<br>";
}
echo "MIGRATION V3 FINISHED.";
