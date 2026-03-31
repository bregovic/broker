<?php
// cleanup_bad_ticker.php
// Removes 'INDUSTRIES' ticker from database.

header('Content-Type: text/html; charset=utf-8');

// Load config
$envPaths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
    __DIR__ . '/../../env.local.php',
    __DIR__ . '/php/env.local.php',
    __DIR__ . '/env.php',
    __DIR__ . '/../env.php',
    __DIR__ . '/../../env.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.php'
];

foreach ($envPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "<h3>Cleanup Bad Ticker: INDUSTRIES</h3>";
    
    $ticker = 'INDUSTRIES';
    
    // 1. Transactions
    $stmt = $pdo->prepare("DELETE FROM broker_transactions WHERE id = ?");
    $stmt->execute([$ticker]);
    echo "Deleted transactions count: " . $stmt->rowCount() . "<br>";
    
    // 2. Live Quotes
    $stmt = $pdo->prepare("DELETE FROM broker_live_quotes WHERE id = ?");
    $stmt->execute([$ticker]);
    echo "Deleted from live_quotes count: " . $stmt->rowCount() . "<br>";
    
    // 3. Price History (if exists)
    $pdo->exec("CREATE TABLE IF NOT EXISTS broker_price_history (id INT)"); // Mock to avoid error if missing
    $stmt = $pdo->prepare("DELETE FROM broker_price_history WHERE ticker = ?");
    try {
        $stmt->execute([$ticker]);
        echo "Deleted from price_history count: " . $stmt->rowCount() . "<br>";
    } catch(Exception $e) { /* Table might not have ticker column or exist properly */ }
    
    // 4. Watchlist
    $pdo->exec("CREATE TABLE IF NOT EXISTS broker_watch (user_id INT)");
    $stmt = $pdo->prepare("DELETE FROM broker_watch WHERE ticker = ?");
    try {
        $stmt->execute([$ticker]);
        echo "Deleted from watchlist count: " . $stmt->rowCount() . "<br>";
    } catch(Exception $e) { }

    echo "Cleanup Complete.";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
