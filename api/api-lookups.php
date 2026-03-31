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
    $action = $_GET['action'] ?? 'list';
    $type = $_GET['type'] ?? ''; // currencies, brokers, asset_classes, system_config

    $allowedTypes = ['currencies', 'brokers', 'asset_classes', 'system_config'];
    if (!in_array($type, $allowedTypes)) {
        echo json_encode(['success'=>false, 'error'=>'Invalid type']);
        exit;
    }

    if ($action === 'list') {
        $stmt = $pdo->query("SELECT * FROM $type ORDER BY 1");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true, 'data'=>$rows]);
    } 
    elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) throw new Exception("No data provided");

        if ($type === 'system_config') {
            $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value) VALUES (?, ?) 
                                   ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value");
            $stmt->execute([$data['config_key'], $data['config_value']]);
        }
        // More update logic for other lookups can be added here
        
        echo json_encode(['success'=>true]);
    }

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
