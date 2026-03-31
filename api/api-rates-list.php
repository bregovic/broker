<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// 1. Resolve User ID
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
    return null;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']);
    exit;
}

// 2. DB Connect
$paths = [
    __DIR__.'/env.local.php', 
    __DIR__.'/php/env.local.php', 
    __DIR__.'/../env.local.php', 
    __DIR__.'/../../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
    __DIR__.'/env.php',
    __DIR__.'/../env.php',
    __DIR__.'/../../env.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.php'
];
foreach($paths as $p) { if(file_exists($p)) { require_once $p; break; } }

if (!defined('DB_HOST')) {
    echo json_encode(['success'=>false, 'error'=>'DB Config Missing']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>'Database connection failed']);
    exit;
}

// 3. Logic
$currency = $_GET['currency'] ?? '';
$dateFrom = $_GET['date_from'] ?? ''; // Removed default limit
$dateTo = $_GET['date_to'] ?? '';

try {
    // Get Currencies
    $stmtC = $pdo->query("SELECT DISTINCT currency FROM rates ORDER BY currency");
    $currencies = $stmtC->fetchAll(PDO::FETCH_COLUMN);

    // Get Rates
    $sql = "SELECT rate_id, date, currency, rate, amount, source FROM rates WHERE 1=1";
    $params = [];
    
    if($currency){ $sql .= " AND currency=?"; $params[]=$currency; }
    if($dateFrom){ $sql .= " AND date>=?"; $params[]=$dateFrom; }
    if($dateTo){ $sql .= " AND date<=?"; $params[]=$dateTo; }
    
    $sql .= " ORDER BY date DESC, currency ASC LIMIT 50000";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rates = $stmt->fetchAll();
    
    $data = array_map(function($r){
        return [
            'id' => (int)$r['rate_id'],
            'date' => $r['date'],
            'currency' => $r['currency'],
            'rate' => (float)$r['rate'],
            'amount' => (float)$r['amount'],
            'rate_per_1' => $r['amount'] > 0 ? (float)$r['rate'] / (float)$r['amount'] : 0,
            'source' => $r['source']
        ];
    }, $rates);

    echo json_encode([
        'success' => true,
        'currencies' => $currencies,
        'data' => $data
    ]);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
