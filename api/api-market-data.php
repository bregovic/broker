<?php
/**
 * API Endpoint for Market Data (JSON)
 * Serves data to React Frontend
 */
session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json; charset=UTF-8");

// Database connection
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
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB Connection failed']);
    exit;
}

// Fetch Market Data
// Similar logic to market.php but cleaner query
// Resolve User for Watchlist
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

// 1. Get watchlist
$watch = $pdo->query("SELECT ticker FROM watch WHERE user_id=$userId")->fetchAll(PDO::FETCH_COLUMN);

// 2. Get tickers with meta
// Improved query: Source tickers from transactions + watch, join mapping/quotes
$sql = "SELECT DISTINCT src.id as ticker, 
               COALESCE(t.company_name, src.id) as company_name, 
               COALESCE(l.current_price, q.price) as current_price, 
               l.change_percent as change_percent,
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
            SELECT DISTINCT id FROM transactions WHERE user_id=:uid
            UNION 
            SELECT ticker as id FROM watch WHERE user_id=:uid
            UNION
            SELECT id FROM live_quotes WHERE status = 'active' OR status IS NULL
        ) src
        LEFT JOIN ticker_mapping t ON CONVERT(src.id USING utf8mb4) = CONVERT(t.ticker USING utf8mb4) COLLATE utf8mb4_unicode_ci
        LEFT JOIN live_quotes l ON CONVERT(src.id USING utf8mb4) = CONVERT(l.id USING utf8mb4) COLLATE utf8mb4_unicode_ci
        LEFT JOIN tickers_history q ON CONVERT(src.id USING utf8mb4) = CONVERT(q.ticker USING utf8mb4) COLLATE utf8mb4_unicode_ci AND q.date = (SELECT MAX(date) FROM tickers_history WHERE CONVERT(ticker USING utf8mb4)=CONVERT(src.id USING utf8mb4) COLLATE utf8mb4_unicode_ci)
        LEFT JOIN watch w ON CONVERT(src.id USING utf8mb4) = CONVERT(w.ticker USING utf8mb4) COLLATE utf8mb4_unicode_ci AND w.user_id=:uid
        WHERE src.id NOT LIKE 'CASH_%' 
          AND src.id NOT LIKE 'FEE_%' 
          AND src.id NOT LIKE 'FX_%' 
          AND src.id NOT LIKE 'CORP_%'
    ORDER BY src.id ASC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['data' => $rows]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
exit;
?>
