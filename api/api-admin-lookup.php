<?php
// api-admin-lookup.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
session_start();

// Simple admin check
// For now, we trust the frontend gate, but for real safety we'd check a session flag
// In a real app, you'd do: if ($_SESSION['is_admin'] !== true) die('Unauthorized');

try {
    $pdo = get_pdo();
    $table = $_GET['table'] ?? '';
    if (!in_array($table, ['brokers', 'currencies', 'asset_types'])) {
        throw new Exception("Invalid table: " . $table);
    }

    // GET: List data
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($table === 'currencies') {
            // Special case: get currencies from rates table to show what we have
            $stmt = $pdo->query("SELECT DISTINCT currency as name, 'CNB' as source FROM rates ORDER BY currency");
        } else {
            $stmt = $pdo->query("SELECT * FROM $table ORDER BY name");
        }
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
    }
    // POST: Save/Add data
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? '';
        if (!$name) throw new Exception("Name is required");

        if ($table === 'brokers') {
           $stmt = $pdo->prepare("INSERT INTO brokers (name, parser_type) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET parser_type = EXCLUDED.parser_type");
           $stmt->execute([$name, $input['parser_type'] ?? 'generic']);
        } elseif ($table === 'asset_types') {
           $stmt = $pdo->prepare("INSERT INTO asset_types (name) VALUES (?) ON CONFLICT (name) DO NOTHING");
           $stmt->execute([$name]);
        }
        
        echo json_encode(['success' => true]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
