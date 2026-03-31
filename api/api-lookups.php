<?php
// api-lookups.php
// Provides data for Currencies, Brokers (Services), and Asset Classes.
// Also handles simple Admin authentication check.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();

    $action = $_GET['action'] ?? 'list';

    // 1. ADMIN CHECK (Simplified for the new section)
    if ($action === 'admin_check') {
        $input = json_decode(file_get_contents('php://input'), true);
        $password = $input['password'] ?? '';
        
        if ($password === 'Admin123') {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid admin password']);
        }
        exit;
    }

    // 2. LIST LOOKUPS
    $data = [
        'currencies' => $pdo->query("SELECT * FROM currencies ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC),
        'brokers' => $pdo->query("SELECT * FROM brokers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC),
        'asset_classes' => $pdo->query("SELECT * FROM asset_classes ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC)
    ];

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
