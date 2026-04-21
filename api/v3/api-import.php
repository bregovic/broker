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

    // 1. ANALYZE (Stage 1) - Multiple files or single re-analyze
    if ($action === 'analyze') {
        $tempFileParam = $_GET['temp_file'] ?? $_POST['temp_file'] ?? null;
        $ruleIdParam = $_GET['rule_id'] ?? $_POST['rule_id'] ?? null;
        
        // Fix for empty string being passed as UUID
        if ($tempFileParam === '') $tempFileParam = null;

        // AUTO-CREATE staging table
        $db->exec("CREATE TABLE IF NOT EXISTS import_staging (
            staging_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            filename TEXT NOT NULL,
            file_content BYTEA NOT NULL,
            created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )");

        // Case A: Re-analyze existing staging file
        if ($tempFileParam) {
            $stmt = $db->prepare("SELECT * FROM import_staging WHERE staging_id = ?");
            $stmt->execute([$tempFileParam]);
            $stagingFile = $stmt->fetch();

            if (!$stagingFile) {
                echo json_encode(['success' => false, 'message' => 'Staging file not found: ' . $tempFileParam]);
                exit;
            }

            // Write to a temporary file for the manager to analyze
            $tmpPath = tempnam(sys_get_temp_dir(), 'import_');
            file_put_contents($tmpPath, $stagingFile['file_content']);
            
            $details = $manager->analyzeFile($tmpPath, $stagingFile['filename'], $ruleIdParam);
            unlink($tmpPath);

            echo json_encode([
                'success' => true,
                'data' => [array_merge([
                    'filename' => $stagingFile['filename'],
                    'temp_file' => $tempFileParam,
                    'success' => true
                ], $details)]
            ]);
            exit;
        }

        // Case B: Upload and analyze new files
        if (empty($_FILES)) {
            echo json_encode([
                'success' => false, 
                'message' => 'Nebyly zaslány žádné soubory (prázdné $_FILES).',
                'debug' => ['post_data' => array_keys($_POST), 'server' => $_SERVER['REQUEST_METHOD']]
            ]);
            exit;
        }

        $results = [];
        $filesArr = [];
        foreach ($_FILES as $inputName => $info) {
            if (is_array($info['name'])) {
                foreach ($info['name'] as $i => $name) {
                    $filesArr[] = [
                        'name' => $name,
                        'tmp_name' => $info['tmp_name'][$i],
                        'error' => $info['error'][$i],
                        'size' => $info['size'][$i]
                    ];
                }
            } else {
                $filesArr[] = $info;
            }
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

                $content = file_get_contents($file['tmp_name']);
                if ($content === false) throw new \Exception("Nelze přečíst tmp soubor: " . $file['tmp_name']);

                $stmt = $db->prepare("INSERT INTO import_staging (filename, file_content) VALUES (?, ?) RETURNING staging_id");
                $stmt->execute([$file['name'], $content]);
                $stagingId = $stmt->fetchColumn();

                $details = $manager->analyzeFile($file['tmp_name'], $file['name']);
                $analysis = array_merge($analysis, $details);
                $analysis['temp_file'] = $stagingId;
                $analysis['success'] = true;
                
            } catch (Throwable $e) {
                $analysis['error'] = $e->getMessage();
            }
            $results[] = $analysis;
        }

        echo json_encode(['success' => true, 'data' => $results]);
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
                // FETCH FROM POSTGRES STAGING
                $stmt = $db->prepare("SELECT filename, file_content FROM import_staging WHERE staging_id = ?");
                $stmt->execute([$item['temp_file']]);
                $row = $stmt->fetch();
                
                if (!$row) continue;

                // Write to temp file for pdftotext/parsing
                $tempPath = sys_get_temp_dir() . '/imp_' . $item['temp_file'];
                file_put_contents($tempPath, $row['file_content']);

                $ruleId = isset($item['rule_id']) ? (int)$item['rule_id'] : null;
                $result = $manager->processFile($tempPath, $row['filename'], $ruleId);
                $transactions = $result['transactions'];

                $inserted = 0;
                $skipped = 0;
                foreach ($transactions as $t) {
                    $data = $t->toArray();
                    $sql = "INSERT INTO transactions 
                            (ticker, transaction_date, type, quantity, price_per_unit, currency, fee, total_amount, source_broker, broker_trade_id, metadata)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON CONFLICT (broker_trade_id) DO NOTHING";
                    
                    $stmtIns = $db->prepare($sql);
                    $stmtIns->execute([
                        $data['ticker'], $data['transaction_date'], $data['type'], $data['quantity'],
                        $data['price_per_unit'], $data['currency'], $data['fee'], $data['total_amount'],
                        $data['source_broker'], $data['broker_trade_id'], $data['metadata']
                    ]);
                    
                    if ($stmtIns->rowCount() > 0) $inserted++;
                    else $skipped++;
                }

                $summary[] = [
                    'filename' => $row['filename'],
                    'parser' => $result['parser'],
                    'found' => count($transactions),
                    'inserted' => $inserted,
                    'skipped' => $skipped,
                    'success' => true
                ];
                
                // Cleanup
                @unlink($tempPath);
                $db->prepare("DELETE FROM import_staging WHERE staging_id = ?")->execute([$item['temp_file']]);
            }

            $db->commit();
            echo json_encode(['success' => true, 'summary' => $summary]);

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Neznámá akce nebo chybějící data.']);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'KRITICKÁ CHYBA: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
