<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../auth_guard.php';
use Broker\V3\DB;

header('Content-Type: application/json');

// Tenhle endpoint vracel 500 transakcí VŠECH uživatelů komukoli bez přihlášení.
// Kontrola přihlášení sama o sobě nestačí — dotaz musí být omezený na user_id,
// jinak by přihlášený uživatel pořád viděl cizí obchody.
$userId = require_login();

try {
    // Fixed column names: trans_id and transaction_date
    $sql = "SELECT * FROM transactions WHERE user_id = :uid ORDER BY transaction_date DESC, trans_id DESC LIMIT 500";
    $stmt = DB::connect()->prepare($sql);
    $stmt->execute([':uid' => $userId]);
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
