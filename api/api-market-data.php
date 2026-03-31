<?php
/**
 * API Endpoint for Market Data (JSON)
 * Serves data to React Frontend
 */
session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/config.php';
try {
    $pdo = get_pdo();
} catch (Exception $e) {
    echo json_encode(['error' => 'DB Connection failed']);
    exit;
}

function resolveUserId() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    if (isset($_SESSION['user'])) {
        $u = $_SESSION['user'];
        if (is_array($u)) { foreach ($candidates as $k) if (isset($u[$k]) && is_numeric($u[$k])) return (int)$u[$k]; }
        elseif (is_object($u)) { foreach ($candidates as $k) if (isset($u->$k) && is_numeric($u->$k)) return (int)$u->$k; }
    }
    return 0;
}
$userId = resolveUserId();

// 1. Get tickers with meta - matching new schema (ticker instead of id, price instead of current_price)
$sql = "SELECT DISTINCT src.ticker, 
               COALESCE(t.company_name, src.ticker) as company_name, 
               COALESCE(l.price, q.price) as current_price, 
               l.change_percent,
               l.change_amount as change_absolute,
               l.exchange,
               COALESCE(l.currency, t.currency, 'USD') as currency,
               l.asset_type,
               COALESCE(l.all_time_high, l.high_52w) as high_52w,
               COALESCE(l.all_time_low, l.low_52w) as low_52w,
               l.ema_212,
               l.resilience_score,
               l.last_fetched,
               CASE WHEN w.ticker IS NOT NULL THEN 1 ELSE 0 END as is_watched
        FROM (
            SELECT ticker FROM transactions WHERE user_id = :uid
            UNION 
            SELECT ticker FROM watch WHERE user_id = :uid
            UNION
            SELECT ticker FROM live_quotes
        ) src
        LEFT JOIN ticker_mapping t ON src.ticker = t.ticker
        LEFT JOIN live_quotes l ON src.ticker = l.ticker
        LEFT JOIN (
            SELECT ticker, price, history_date
            FROM tickers_history
            WHERE (ticker, history_date) IN (SELECT ticker, MAX(history_date) FROM tickers_history GROUP BY ticker)
        ) q ON src.ticker = q.ticker
        LEFT JOIN watch w ON src.ticker = w.ticker AND w.user_id = :uid
        WHERE src.ticker NOT LIKE 'CASH_%' 
          AND src.ticker NOT LIKE 'FEE_%' 
          AND src.ticker NOT LIKE 'FX_%' 
          AND src.ticker NOT LIKE 'CORP_%'
        ORDER BY src.ticker ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['data' => $rows]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
exit;
