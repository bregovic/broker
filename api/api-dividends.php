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
    return 0;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config.php';
try {
    $pdo = get_pdo();
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>'DB Connection failed']);
    exit;
}

$sql = "SELECT div_id, date, ticker, amount, tax, net, currency, platform FROM dividends WHERE user_id = ? ORDER BY date DESC";
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true, 'data'=>$rows]);
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
?>
