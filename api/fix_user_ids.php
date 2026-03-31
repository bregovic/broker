<?php
// fix_user_ids.php
header('Content-Type: text/plain; charset=utf-8');
require_once 'env.local.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // 1. Transactions
    $count = $pdo->exec("UPDATE broker_trans SET user_id = 1 WHERE user_id != 1");
    echo "Updated $count transactions to User ID 1.\n";

    // 2. Watchlist (handle duplicates)
    // First delete duplicates if any
    // Or just UPDATE IGNORE
    try {
        $countW = $pdo->exec("UPDATE IGNORE broker_watch SET user_id = 1 WHERE user_id != 1");
        echo "Updated $countW watchlist items to User ID 1.\n";
    } catch (PDOException $e) {
        echo "Watchlist update warning: " . $e->getMessage() . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
