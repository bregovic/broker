<?php
// api-admin-lookup.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
session_start();

try {
    $pdo = get_pdo();
    $table = $_GET['table'] ?? '';
    if (!in_array($table, ['brokers', 'currencies', 'asset_types', 'admin.asset_types'])) {
        throw new Exception("Invalid table: " . $table);
    }
    
    // Map frontend label key back to table name if needed
    if ($table === 'admin.asset_types') $table = 'asset_types';

    // GET: List data
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($table === 'currencies') {
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
    // DELETE: Remove record
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) throw new Exception("ID is required for deletion");

        // First, get the name of the item being deleted to check against string-based transaction columns
        $getStmt = $pdo->prepare("SELECT name FROM $table WHERE id = ?");
        $getStmt->execute([$id]);
        $itemName = $getStmt->fetchColumn();

        if (!$itemName) throw new Exception("Záznam nebyl nalezen.");

        // Check for dependencies (legacy transactions use product_type and platform strings)
        if ($table === 'brokers') {
            $check = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE platform = ?");
            $check->execute([$itemName]);
            if ($check->fetchColumn() > 0) throw new Exception("Nelze smazat - existují transakce u tohoto poskytovatele.");
        } elseif ($table === 'asset_types') {
            $check = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE product_type = ?");
            $check->execute([$itemName]);
            if ($check->fetchColumn() > 0) throw new Exception("Nelze smazat - existují transakce s tímto typem aktiva.");
        }

        $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
