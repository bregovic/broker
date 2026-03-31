<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

function resolveUserId() {
    // ... Simplified ...
    if (isset($_SESSION['user_id'])) return $_SESSION['user_id'];
    return null; 
}
// Using robust resolve if possible, copying from others...
function resolveUserIdRobust() {
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
$userId = resolveUserIdRobust();

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
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $currency = trim($input['currency'] ?? '');
    $date = trim($input['date'] ?? '');
    $rate = floatval($input['rate'] ?? 0);
    $amount = floatval($input['amount'] ?? 1);
    
    if (!$currency || !$date || $rate <= 0 || $amount <= 0) {
        throw new Exception("Invalid input.");
    }
    
    // Convert to rate logic if needed (rate is CZK for amount)
    
    $sql = "INSERT INTO rates (date, currency, rate, amount, source, created_at) VALUES (?, ?, ?, ?, 'Manual', NOW()) ON DUPLICATE KEY UPDATE rate=VALUES(rate), amount=VALUES(amount)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date, $currency, $rate, $amount]);
    
    echo json_encode(['success'=>true]);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
