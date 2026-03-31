<?php
// fix_legacy_views.php
// Verbose debug version

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'env.local.php';

echo "<h1>DB View Fixer (Debug)</h1>";

if (!defined('DB_HOST')) {
    die("❌ DB_HOST not defined.");
}

echo "<div>Connecting to DB Host: <b>" . DB_HOST . "</b></div>";
echo "<div>Target DB Name: <b>" . DB_NAME . "</b></div>";
echo "<div>Target DB User: <b>" . DB_USER . "</b></div>";

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "<div style='color:green'>✅ Connection successful.</div>";

    // Debug: List tables
    echo "<h3>Existing Tables:</h3><ul>";
    $stmt = $pdo->query("SHOW FULL TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        echo "<li>" . $row[0] . " (" . $row[1] . ")</li>";
    }
    echo "</ul>";

    // 1. broker_trans -> transactions
    echo "<h3>Creating Views...</h3>";
    try {
        $pdo->exec("CREATE OR REPLACE VIEW broker_trans AS SELECT * FROM transactions");
        echo "<div style='color:green'>✅ VIEW 'broker_trans' created.</div>";
    } catch (Exception $e) {
        echo "<div style='color:red'>❌ Failed 'broker_trans': " . $e->getMessage() . "</div>";
    }

    // 2. broker_exrates -> rates
    try {
        $pdo->exec("CREATE OR REPLACE VIEW broker_exrates AS SELECT * FROM rates");
        echo "<div style='color:green'>✅ VIEW 'broker_exrates' created.</div>";
    } catch (Exception $e) {
        echo "<div style='color:red'>❌ Failed 'broker_exrates': " . $e->getMessage() . "</div>";
    }

} catch (Exception $e) {
    echo "<div style='color:red; font-size: 20px; font-weight: bold;'>CRITICAL ERROR: " . $e->getMessage() . "</div>";
}
?>
