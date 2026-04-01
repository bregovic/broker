<?php
namespace Broker\V3;

use Broker\V3\Import\ImportManager;
use Broker\V3\Import\TransactionDTO;

require_once 'db.php';
require_once 'Import/TransactionDTO.php';
require_once 'Import/AbstractParser.php';
require_once 'Import/ImportManager.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$db = DB::connect();
$manager = new ImportManager($db);
$action = $_GET['action'] ?? 'process'; 

// 0. LIST RULES (For dropdowns)
if ($action === 'list_rules') {
    echo json_encode(['success' => true, 'rules' => $manager->getAvailableRules()]);
    exit;
}

// 1. ANALYZE (Stage 1) - Multiple files
if ($action === 'analyze') {
    if (empty($_FILES)) {
        echo json_encode(['success' => false, 'message' => 'Nebyly zaslány žádné soubory.']);
        exit;
    }

    $results = [];
    $tempDir = sys_get_temp_dir() . '/investyx_import';
    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);

    // Normalize multiple files
    $files = [];
    if (isset($_FILES['file'])) {
        $files[] = $_FILES['file'];
    } elseif (isset($_FILES['files'])) {
        foreach ($_FILES['files']['name'] as $i => $name) {
            $files[] = [
                'name' => $name,
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'error' => $_FILES['files']['error'][$i],
                'size' => $_FILES['files']['size'][$i]
            ];
        }
    }

    foreach ($files as $file) {
        try {
            $tempName = uniqid('imp_') . '_' . preg_replace('/[^a-z0-9.]/i', '_', $file['name']);
            $tempPath = $tempDir . '/' . $tempName;
            
            if (move_uploaded_file($file['tmp_name'], $tempPath)) {
                $analysis = $manager->analyzeFile($tempPath, $file['name']);
                $analysis['temp_file'] = $tempName;
                $results[] = $analysis;
            } else {
                throw new \Exception("Chyba při ukládání do tempu.");
            }
        } catch (\Throwable $e) {
            $results[] = [
                'filename' => $file['name'],
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    echo json_encode(['success' => true, 'data' => $results]);
    exit;
}

// 2. IMPORT (Stage 2) - Final Execution
if ($action === 'import') {
    $input = json_decode(file_get_contents('php://input'), true);
    $items = $input['items'] ?? []; // [{temp_file, rule_id}, ...]

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Žádné položky k importu.']);
        exit;
    }

    $summary = [];
    $db->beginTransaction();
    
    try {
        foreach ($items as $item) {
            $tempPath = sys_get_temp_dir() . '/investyx_import/' . $item['temp_file'];
            if (!file_exists($tempPath)) continue;

            $ruleId = isset($item['rule_id']) ? (int)$item['rule_id'] : null;
            $result = $manager->processFile($tempPath, '', $ruleId);
            $transactions = $result['transactions'];

            $inserted = 0;
            $skipped = 0;
            foreach ($transactions as $t) {
                /** @var TransactionDTO $t */
                $data = $t->toArray();
                
                $sql = "INSERT INTO transactions 
                        (ticker, transaction_date, type, quantity, price_per_unit, currency, fee, total_amount, source_broker, broker_trade_id, metadata)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON CONFLICT (broker_trade_id) DO NOTHING";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $data['ticker'], $data['transaction_date'], $data['type'], $data['quantity'],
                    $data['price_per_unit'], $data['currency'], $data['fee'], $data['total_amount'],
                    $data['source_broker'], $data['broker_trade_id'], $data['metadata']
                ]);
                
                if ($stmt->rowCount() > 0) $inserted++;
                else $skipped++;
            }

            $summary[] = [
                'filename' => $item['filename'] ?? $item['temp_file'],
                'parser' => $result['parser'],
                'found' => count($transactions),
                'inserted' => $inserted,
                'skipped' => $skipped,
                'success' => true
            ];
            
            // Cleanup
            @unlink($tempPath);
        }

        $db->commit();
        echo json_encode(['success' => true, 'summary' => $summary]);

    } catch (\Throwable $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'CHYBA IMPORTU: ' . $e->getMessage()]);
    }
    exit;
}

// LEGACY / SINGLE STEP Fallback
try {
    if (!isset($_FILES['file'])) {
        throw new \Exception("Žádný soubor nebyl zaslán.");
    }
    $file = $_FILES['file'];
    $result = $manager->processFile($file['tmp_name'], $file['name']);
    $transactions = $result['transactions'];

    $db->beginTransaction();
    $inserted = 0;
    foreach ($transactions as $t) {
        $data = $t->toArray();
        $sql = "INSERT INTO transactions 
                (ticker, transaction_date, type, quantity, price_per_unit, currency, fee, total_amount, source_broker, broker_trade_id, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (broker_trade_id) DO NOTHING";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $data['ticker'], $data['transaction_date'], $data['type'], $data['quantity'],
            $data['price_per_unit'], $data['currency'], $data['fee'], $data['total_amount'],
            $data['source_broker'], $data['broker_trade_id'], $data['metadata']
        ]);
        if ($stmt->rowCount() > 0) $inserted++;
    }
    $db->commit();

    echo json_encode(['success' => true, 'inserted' => $inserted, 'found' => count($transactions), 'parser' => $result['parser']]);
} catch (\Throwable $e) {
    if (isset($db)) $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
