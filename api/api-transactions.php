<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

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

if (!defined('DB_HOST')) { echo json_encode(['success'=>false, 'error'=>'DB Config Missing']); exit; }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Updated query to include ex_rate and amount_cur
    $sql = "SELECT trans_id, date, id as ticker, trans_type, amount, price, currency, amount_czk, platform, product_type, fees, ex_rate, amount_cur
            FROM transactions
            WHERE user_id = ?
            ORDER BY date DESC, trans_id DESC
            LIMIT 50000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($data as &$row) {
        $row['amount_czk'] = (float)$row['amount_czk'];
        $row['amount'] = (float)$row['amount'];
        $row['price'] = (float)$row['price'];
        $row['ex_rate'] = (float)$row['ex_rate'];
        $row['amount_cur'] = (float)$row['amount_cur'];
    }

    echo json_encode(['success'=>true, 'data'=>$data]);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
