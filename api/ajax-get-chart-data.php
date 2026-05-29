<?php
// ajax-get-chart-data.php
// Vrátí historická data pro graf (Chart.js)

header('Content-Type: application/json; charset=utf-8');

// DB připojení přes jednotný adaptér (MySQL lokálně / PostgreSQL na Railway)
require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();

    $ticker = $_GET['ticker'] ?? '';
    
    if (empty($ticker)) {
        throw new Exception("Chybí ticker");
    }

    // Načteme data seřazená podle času
    $stmt = $pdo->prepare("SELECT history_date AS date, price FROM tickers_history WHERE ticker = ? ORDER BY history_date ASC");
    $stmt->execute([$ticker]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $data = [];

    foreach ($rows as $row) {
        $labels[] = $row['date'];
        $data[] = (float)$row['price'];
    }

    echo json_encode([
        'success' => true,
        'ticker' => $ticker,
        'labels' => $labels,
        'data' => $data
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
