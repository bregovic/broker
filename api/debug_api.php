<?php
// debug_api.php
// Diagnostic script to check data availability for User ID 1 (Demo)
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1); // We want to see errors here

// 1. Load Config
$envPaths = [__DIR__.'/env.local.php', '../env.local.php', $_SERVER['DOCUMENT_ROOT'].'/env.local.php'];
foreach ($envPaths as $p) { if(file_exists($p)) { require_once $p; break; } }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    $res = [
        'info' => 'Debug API Data',
        'db_name' => DB_NAME,
        'user_id_simulated' => 1
    ];

    // 2. Check Transactions
    $stmt = $pdo->query("SELECT COUNT(*) FROM broker_trans WHERE user_id=1");
    $res['broker_trans_count'] = $stmt->fetchColumn();

    // Check actual User IDs in trans
    $stmtUsers = $pdo->query("SELECT DISTINCT user_id FROM broker_trans");
    $res['actual_user_ids_in_trans'] = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);

    // 3. Check Watchlist
    $stmt = $pdo->query("SELECT COUNT(*) FROM broker_watch WHERE user_id=1");
    $res['broker_watch_count'] = $stmt->fetchColumn();

    // 4. Check Market Data Query logic
    $sql = "SELECT DISTINCT t.ticker 
            FROM broker_ticker_mapping t
            WHERE CONVERT(t.ticker USING utf8mb4) IN (SELECT DISTINCT CONVERT(id USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM broker_trans WHERE user_id=1)
               OR CONVERT(t.ticker USING utf8mb4) IN (SELECT CONVERT(ticker USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM broker_watch WHERE user_id=1)";
    $stmt = $pdo->query($sql);
    $tickers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $res['market_tickers_found'] = count($tickers);
    $res['market_tickers_sample'] = array_slice($tickers, 0, 5);

    // 5. Check PnL logic (Sales)
    $stmt = $pdo->query("SELECT COUNT(*) FROM broker_trans WHERE user_id=1 AND trans_type='Sell'");
    $res['sells_count'] = $stmt->fetchColumn();

    // 6. Check Rates
    $stmt = $pdo->query("SELECT COUNT(*) FROM rates");
    $res['rates_count'] = $stmt->fetchColumn();
    
    // 7. Session check
    $res['session_current'] = $_SESSION;

    echo json_encode($res, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
