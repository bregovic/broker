<?php
// api-admin-lookup.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
session_start();

try {
    $pdo = get_pdo();
    $table = $_GET['table'] ?? $_GET['type'] ?? '';
    if (!in_array($table, ['brokers', 'currencies', 'asset_types', 'admin.asset_types', 'broker_import_rules'])) {
        throw new Exception("Invalid table: " . $table);
    }
    
    // Map frontend label key back to table name if needed
    if ($table === 'admin.asset_types') $table = 'asset_types';

    // GET: List data
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($table === 'currencies') {
            // Get from rates, but can't "delete" from a distinct list easily unless we delete all rates for that currency
            $stmt = $pdo->query("SELECT DISTINCT currency as name, 'CNB' as source, currency as id FROM rates ORDER BY currency");
        } elseif ($table === 'broker_import_rules') {
            $stmt = $pdo->query("SELECT * FROM broker_import_rules ORDER BY broker_name, config_name");
        } else {
            $stmt = $pdo->query("SELECT * FROM $table ORDER BY name");
        }
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
    }
    // DELETE: Remove record
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) throw new Exception("ID is required for deletion");

        if ($table === 'currencies') {
            // ID is the currency code string here
            $check = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE currency = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) throw new Exception("Nelze smazat - existují transakce v této měně.");
            
            $stmt = $pdo->prepare("DELETE FROM rates WHERE currency = ?");
            $stmt->execute([$id]);
        } elseif ($table === 'broker_import_rules') {
            $stmt = $pdo->prepare("DELETE FROM broker_import_rules WHERE id = ?");
            $stmt->execute([$id]);
        } else {
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
        }
        echo json_encode(['success' => true]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
