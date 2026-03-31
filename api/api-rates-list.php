<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function resolveUserId() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    return 0;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']);
    exit;
}

try {
    $pdo = get_pdo();

    $currency = $_GET['currency'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';

    // Get Currencies
    $stmtC = $pdo->query("SELECT DISTINCT currency FROM rates ORDER BY currency");
    $currencies = $stmtC->fetchAll(PDO::FETCH_COLUMN);

    // Get Rates (Postgres uses auto-increment IDs differently, but rates might not even have rate_id in some schemas, using ctid as fallback if missing or just skipping ID if not needed for frontend)
    $sql = "SELECT date, currency, rate, amount FROM rates WHERE 1=1";
    $params = [];
    
    if($currency){ $sql .= " AND currency=?"; $params[]=$currency; }
    if($dateFrom){ $sql .= " AND date>=?"; $params[]=$dateFrom; }
    if($dateTo){ $sql .= " AND date<=?"; $params[]=$dateTo; }
    
    $sql .= " ORDER BY date DESC, currency ASC LIMIT 5000";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $data = array_map(function($r, $idx){
        return [
            'id' => $idx, // Generic ID for grid
            'date' => $r['date'],
            'currency' => $r['currency'],
            'rate' => (float)$r['rate'],
            'amount' => (float)$r['amount'],
            'rate_per_1' => (float)$r['amount'] > 0 ? (float)$r['rate'] / (float)$r['amount'] : 0,
            'source' => $r['source'] ?? 'CNB'
        ];
    }, $rates, array_keys($rates));

    echo json_encode([
        'success' => true,
        'currencies' => $currencies,
        'data' => $data
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
