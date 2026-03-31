<?php
namespace Broker\V3;

use Broker\V3\Import\ImportManager;

require_once 'db.php';
require_once 'Import/TransactionDTO.php';
require_once 'Import/AbstractParser.php';
require_once 'Import/ImportManager.php';

header('Content-Type: application/json; charset=utf-8');

// Protože budeme volat z jiného portu v devu
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => 'Žádný soubor nebyl zaslán.']);
    exit;
}

try {
    $file = $_FILES['file'];
    $db = DB::connect();
    $manager = new ImportManager($db);
    
    // 1. ANALÝZA A PARSOVÁNÍ (Automaticky podle DB pravidel)
    $result = $manager->processFile($file['tmp_name'], $file['name']);
    $transactions = $result['transactions'];

    // 2. ULOŽENÍ DO POSTGRESU
    $db = DB::connect();
    $db->beginTransaction();

    $inserted = 0;
    foreach ($transactions as $t) {
        /** @var \Broker\V3\Import\TransactionDTO $t */
        $data = $t->toArray();
        
        $sql = "INSERT INTO transactions 
                (ticker, transaction_date, type, quantity, price_per_unit, currency, fee, total_amount, source_broker, broker_trade_id, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (broker_trade_id) DO NOTHING"; // Základní de-duplikace
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $data['ticker'],
            $data['transaction_date'],
            $data['type'],
            $data['quantity'],
            $data['price_per_unit'],
            $data['currency'],
            $data['fee'],
            $data['total_amount'],
            $data['source_broker'],
            $data['broker_trade_id'],
            $data['metadata']
        ]);
        
        if ($stmt->rowCount() > 0) {
            $inserted++;
        }
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'parser' => $result['parser'],
        'found' => count($transactions),
        'inserted' => $inserted,
        'message' => "Import přes {$result['parser']} proběhl úspěšně. Uloženo $inserted nových transakcí."
    ]);

} catch (\Exception $e) {
    if (isset($db)) $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
