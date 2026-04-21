<?php
require_once __DIR__ . '/db.php';
use Broker\V3\DB;

header('Content-Type: application/json');

try {
    // Fixed column names: trans_id and transaction_date
    $sql = "SELECT * FROM transactions ORDER BY transaction_date DESC, trans_id DESC LIMIT 500";
    $stmt = DB::query($sql);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count' => count($transactions),
        'data' => $transactions
    ], JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
