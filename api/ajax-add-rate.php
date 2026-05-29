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

require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $currency = trim($input['currency'] ?? '');
    $date = trim($input['date'] ?? '');
    $rate = floatval($input['rate'] ?? 0);
    $amount = floatval($input['amount'] ?? 1);
    
    if (!$currency || !$date || $rate <= 0 || $amount <= 0) {
        throw new Exception("Invalid input.");
    }
    
    // Check if rate already exists for this currency and date
    $check = $pdo->prepare("SELECT 1 FROM rates WHERE currency=? AND date=? LIMIT 1");
    $check->execute([$currency, $date]);
    $exists = (bool)$check->fetchColumn();

    if ($exists) {
        // Update existing rate
        $sql = "UPDATE rates SET rate=?, amount=?, source='Manual' WHERE currency=? AND date=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rate, $amount, $currency, $date]);
    } else {
        // Insert new rate
        $sql = "INSERT INTO rates (date, currency, rate, amount, source, created_at) VALUES (?, ?, ?, ?, 'Manual', NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date, $currency, $rate, $amount]);
    }
    
    echo json_encode(['success'=>true]);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}

