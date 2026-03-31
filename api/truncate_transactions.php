<?php
// truncate_transactions.php
// Clears all transactions to allow clean import
header('Content-Type: text/plain; charset=utf-8');
require_once 'env.local.php';

// Security check: Only allow if explicitly requested via parameter or logged in admin
// For simplicity in this dev session, just do it but warn.

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Disable foreign key checks just in case (though we shouldn't have strict ones blocking truncate usually)
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    $pdo->exec("TRUNCATE TABLE transactions");
    echo "SUCCESS: Table transactions has been emptied (TRUNCATE).\n";
    
    // Optional: Clear watchlist too? User didn't ask, but maybe?
    // User only said "transactions".
    
    // Optional: Clear ticker mapping?
    // Probably keep mapping.
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
