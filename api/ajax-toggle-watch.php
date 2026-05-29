<?php
// ajax-toggle-watch.php
// Toggluje sledování titulu (watchlist) pro přihlášeného uživatele v tabulce `watch`.
// Frontend (MarketPage) volá: POST { ticker }
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

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
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Only POST allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$ticker = strtoupper(trim($input['ticker'] ?? ''));
if ($ticker === '') {
    echo json_encode(['success' => false, 'error' => 'Ticker missing']);
    exit;
}

try {
    $pdo = get_pdo();
    // Toggle: pokud titul uživatel už sleduje, odeber; jinak přidej.
    $st = $pdo->prepare("SELECT 1 FROM watch WHERE user_id = ? AND ticker = ?");
    $st->execute([$userId, $ticker]);
    if ($st->fetch()) {
        $pdo->prepare("DELETE FROM watch WHERE user_id = ? AND ticker = ?")->execute([$userId, $ticker]);
        $op = 'removed';
    } else {
        $pdo->prepare("INSERT INTO watch (user_id, ticker) VALUES (?, ?)")->execute([$userId, $ticker]);
        $op = 'added';
    }
    echo json_encode(['success' => true, 'operation' => $op, 'is_watched' => $op === 'added']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
