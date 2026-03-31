<?php
/**
 * Simple Database Exporter
 * Creates a .sql file with structure and data to import to Railway
 */
require_once 'env.local.php';

$backupFile = __DIR__ . '/database_backup.sql';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        // Only export broker tables
        if (strpos($row[0], 'broker_') === 0) {
            $tables[] = $row[0];
        }
    }

    $handle = fopen($backupFile, 'w');
    fwrite($handle, "-- Database Backup for Railway\n");
    fwrite($handle, "-- Created: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tables as $table) {
        // Structure
        fwrite($handle, "-- Structure for $table\n");
        fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
        $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        fwrite($handle, $row[1] . ";\n\n");

        // Data
        fwrite($handle, "-- Data for $table\n");
        $rows = $pdo->query("SELECT * FROM `$table`");
        while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
            $cols = array_keys($r);
            $vals = array_map(function($v) use ($pdo) {
                if ($v === null) return "NULL";
                return $pdo->quote($v);
            }, array_values($r));
            
            $sql = "INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $vals) . ");\n";
            fwrite($handle, $sql);
        }
        fwrite($handle, "\n");
    }
    
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);

    echo "<h1>Záloha vytvořena!</h1>";
    echo "<p>Soubor uložen jako: <strong>$backupFile</strong></p>";
    echo "<p>Tento soubor můžeš použít pro import na Railway.</p>";

} catch (Exception $e) {
    echo "Chyba: " . $e->getMessage();
}
?>
