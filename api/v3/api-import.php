<?php
namespace Broker\V3;

use Broker\V3\Import\ImportManager;
use Broker\V3\Import\TransactionDTO;
use Throwable;

// Error reporting only for debugging phase
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

try {
    require_once 'db.php';
    require_once 'Import/TransactionDTO.php';
    require_once 'Import/AbstractParser.php';
    require_once 'Import/ImportManager.php';

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
            echo json_encode(['success' => false, 'message' => 'Nebyly zaslány žádné soubory (prázdné $_FILES).']);
            exit;
        }

        $results = [];
        $tempDir = sys_get_temp_dir() . '/investyx_import';
        if (!is_dir($tempDir)) {
            if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
                throw new \Exception("Nelze vytvořit dočasný adresář: $tempDir");
            }
        }

        // --- UNIFIED FILE COLLECTION ---
        $filesArr = [];
        foreach ($_FILES as $inputName => $info) {
            if (is_array($info['name'])) {
                // Multi-file array (e.g. files[] or multiple fields)
                foreach ($info['name'] as $i => $name) {
                    $filesArr[] = [
                        'name' => $name,
                        'tmp_name' => $info['tmp_name'][$i],
                        'error' => $info['error'][$i],
                        'size' => $info['size'][$i]
                    ];
                }
            } else {
                // Single file upload
                $filesArr[] = $info;
            }
        }

        if (empty($filesArr)) {
            echo json_encode(['success' => false, 'message' => 'Nalezen $_FILES, ale žádné platné soubory. Klíče: ' . implode(', ', array_keys($_FILES))]);
            exit;
        }

        foreach ($filesArr as $file) {
            $analysis = [
                'filename' => $file['name'],
                'extension' => strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)),
                'broker' => 'Neznámý',
                'parser' => 'Neznámý',
                'parser_class' => null,
                'tx_count' => 0,
                'rule_id' => null,
                'asset_type' => 'Neznámý',
                'temp_file' => '',
                'success' => false,
                'error' => null
            ];

            try {
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new \Exception("Chyba uploadu: " . ($file['error'] ?? 'unknown'));
                }

                $tempName = uniqid('imp_') . '_' . preg_replace('/[^a-z0-9.]/i', '_', $file['name']);
                $tempPath = $tempDir . '/' . $tempName;
                
                if (move_uploaded_file($file['tmp_name'], $tempPath)) {
                    $details = $manager->analyzeFile($tempPath, $file['name']);
                    $analysis = array_merge($analysis, $details);
                    $analysis['temp_file'] = $tempName;
                    $analysis['success'] = true;
                } else {
                    throw new \Exception("Chyba při přesunu do tempu.");
                }
            } catch (Throwable $e) {
                $analysis['error'] = $e->getMessage();
            }
            $results[] = $analysis;
        }

        echo json_encode([
            'success' => true, 
            'data' => $results, 
            'debug' => [
                'files_count' => count($filesArr),
                'php_post_max' => ini_get('post_max_size'),
                'php_upload_max' => ini_get('upload_max_filesize'),
                'php_memory_limit' => ini_get('memory_limit'),
                'raw_files_struct' => array_map(function($f) { 
                    return [
                        'name' => is_array($f['name']) ? 'ARRAY(' . count($f['name']) . ')' : $f['name'],
                        'error' => is_array($f['error']) ? 'ARRAY' : $f['error']
                    ]; 
                }, $_FILES)
            ]
        ]);
        exit;
    }

    // 2. IMPORT (Stage 2) - Final Execution
    if ($action === 'import') {
        $input = json_decode(file_get_contents('php://input'), true);
        $items = $input['items'] ?? []; 

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
                
                @unlink($tempPath);
            }

            $db->commit();
            echo json_encode(['success' => true, 'summary' => $summary]);

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        exit;
    }

    // LEGACY / SINGLE STEP Fallback
    if (isset($_FILES['file'])) {
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
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Neznámá akce nebo chybějící data.']);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'KRITICKÁ CHYBA: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
