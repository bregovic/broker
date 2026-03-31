<?php
/**
 * API for Deleting Transactions
 * Supports:
 *   - DELETE by IDs: { "ids": [1,2,3] }
 *   - DELETE by filter: { "filter": { "ticker": "AAPL", "trans_type": "buy", ... } }
 *   - DELETE all: { "all": true } (requires confirmation)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
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
    return null;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load env
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
    echo json_encode(['success' => false, 'error' => 'DB Config Missing']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input)) {
        echo json_encode(['success' => false, 'error' => 'No input provided']);
        exit;
    }
    
    $deletedCount = 0;
    
    // Option 1: Delete by specific IDs
    if (!empty($input['ids']) && is_array($input['ids'])) {
        $ids = array_filter($input['ids'], 'is_numeric');
        if (empty($ids)) {
            echo json_encode(['success' => false, 'error' => 'No valid IDs provided']);
            exit;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM transactions WHERE trans_id IN ($placeholders) AND user_id = ?";
        $params = array_merge($ids, [$userId]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $deletedCount = $stmt->rowCount();
    }
    // Option 2: Delete by filter criteria
    elseif (!empty($input['filter']) && is_array($input['filter'])) {
        $filter = $input['filter'];
        $conditions = ["user_id = ?"];
        $params = [$userId];
        
        // Allowed filter fields
        $allowedFields = ['ticker', 'trans_type', 'currency', 'platform', 'product_type', 'date_from', 'date_to'];
        
        foreach ($filter as $key => $value) {
            if (!in_array($key, $allowedFields) || $value === '' || $value === null) continue;
            
            switch ($key) {
                case 'ticker':
                    $conditions[] = "id = ?";
                    $params[] = strtoupper($value);
                    break;
                case 'trans_type':
                    $conditions[] = "trans_type = ?";
                    $params[] = $value;
                    break;
                case 'currency':
                    $conditions[] = "currency = ?";
                    $params[] = strtoupper($value);
                    break;
                case 'platform':
                    $conditions[] = "platform = ?";
                    $params[] = $value;
                    break;
                case 'product_type':
                    $conditions[] = "product_type = ?";
                    $params[] = $value;
                    break;
                case 'date_from':
                    $conditions[] = "date >= ?";
                    $params[] = $value;
                    break;
                case 'date_to':
                    $conditions[] = "date <= ?";
                    $params[] = $value;
                    break;
            }
        }
        
        // Safety: require at least one filter besides user_id
        if (count($conditions) < 2) {
            echo json_encode(['success' => false, 'error' => 'At least one filter criteria required']);
            exit;
        }
        
        $sql = "DELETE FROM transactions WHERE " . implode(' AND ', $conditions);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $deletedCount = $stmt->rowCount();
    }
    // Option 3: Delete all (with confirmation)
    elseif (!empty($input['all']) && $input['all'] === true) {
        if (empty($input['confirm']) || $input['confirm'] !== 'DELETE_ALL') {
            echo json_encode(['success' => false, 'error' => 'Confirmation required: set confirm to "DELETE_ALL"']);
            exit;
        }
        
        $sql = "DELETE FROM transactions WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $deletedCount = $stmt->rowCount();
    }
    else {
        echo json_encode(['success' => false, 'error' => 'Invalid request. Provide ids, filter, or all']);
        exit;
    }
    
    echo json_encode([
        'success' => true, 
        'deleted' => $deletedCount,
        'message' => "Smazáno $deletedCount transakcí."
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
