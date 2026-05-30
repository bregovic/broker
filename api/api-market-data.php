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

// FX rates (latest CZK per unit, per currency) + the user's accounting currency,
// so every instrument can be shown in CZK / the user's base currency on display.
require_once __DIR__ . '/rate_sync.php';
try { ensure_current_rates($pdo); } catch (Exception $e) {}
$rates = ['CZK' => 1.0];
try {
    $rStmt = $pdo->query("SELECT r.currency, r.rate, r.amount FROM rates r
        INNER JOIN (SELECT currency, MAX(date) max_date FROM rates GROUP BY currency) m
        ON r.currency = m.currency AND r.date = m.max_date");
    while ($r = $rStmt->fetch(PDO::FETCH_ASSOC)) {
        $amt = (float)$r['amount'];
        $rates[strtoupper($r['currency'])] = $amt > 0 ? (float)$r['rate'] / $amt : 0.0;
    }
} catch (Exception $e) {}
$baseCurrency = 'CZK';
try {
    $bStmt = $pdo->prepare("SELECT base_currency FROM user_settings WHERE user_id = ?");
    $bStmt->execute([$userId]);
    $bc = $bStmt->fetchColumn();
    if ($bc) $baseCurrency = strtoupper(trim($bc));
} catch (Exception $e) {}
$baseRate = $rates[$baseCurrency] ?? 1.0; // CZK per 1 unit of the base currency

// 1. Get tickers with meta - matching new schema (ticker instead of id, price instead of current_price)
$sql = "SELECT DISTINCT src.ticker, 
               COALESCE(NULLIF(t.company_name, ''), NULLIF(l.company_name, ''), src.ticker) as company_name,
               COALESCE(NULLIF(l.current_price, 0), NULLIF(l.price, 0), q.price) as current_price,
               l.change_percent,
               l.change_amount as change_absolute,
               l.exchange,
               COALESCE(l.currency, t.currency, 'USD') as currency,
               l.asset_type,
               COALESCE(l.all_time_high, l.high_52w) as high_52w,
               COALESCE(l.all_time_low, l.low_52w) as low_52w,
               l.ema_212,
               l.resilience_score,
               l.ex_dividend_date,
               l.dividend_yield,
               l.dividend_rate,
               l.dividend_frequency,
               l.five_year_avg_yield,
               l.sector,
               l.industry,
               l.market_cap,
               l.pe_ratio,
               (SELECT EXTRACT(YEAR FROM MIN(history_date))::int FROM tickers_history h2 WHERE h2.ticker = src.ticker) AS on_market_since,
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

    // Enrich each row with CZK and base-currency values (single native price in DB,
    // converted on display — no duplicate per-currency storage).
    foreach ($rows as &$row) {
        $cur   = strtoupper($row['currency'] ?? 'USD');
        $rate  = $rates[$cur] ?? 0.0;                 // CZK per 1 unit of the quote currency
        $price = (float)($row['current_price'] ?? 0);
        $czk   = $rate > 0 ? $price * $rate : null;
        $row['rate_to_czk']        = $rate ?: null;
        $row['current_price_czk']  = $czk;
        $row['base_currency']      = $baseCurrency;
        $row['current_price_base'] = ($czk !== null && $baseRate > 0) ? $czk / $baseRate : null;
    }
    unset($row);

    echo json_encode(['data' => $rows, 'base_currency' => $baseCurrency]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
exit;
