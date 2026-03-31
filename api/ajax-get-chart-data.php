<?php
// ajax-get-chart-data.php
// Vrátí historická data pro graf (Chart.js)

header('Content-Type: application/json; charset=utf-8');

// Robustní připojení k DB
$envPaths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
    __DIR__ . '/../../env.local.php',
    __DIR__ . '/php/env.local.php',
    __DIR__ . '/env.php',
    __DIR__ . '/../env.php',
    __DIR__ . '/../../env.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.php'
];
foreach ($envPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

if (!defined('DB_HOST')) {
    if (file_exists(__DIR__ . '/db.php')) require_once __DIR__ . '/db.php';
    else if (file_exists(__DIR__ . '/php/db.php')) require_once __DIR__ . '/php/db.php';
}

if (!defined('DB_HOST')) {
    echo json_encode(['success' => false, 'message' => 'DB Config not found']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $ticker = $_GET['ticker'] ?? '';
    
    if (empty($ticker)) {
        throw new Exception("Chybí ticker");
    }

    // Načteme data seřazená podle času
    $stmt = $pdo->prepare("SELECT date, price FROM tickers_history WHERE ticker = ? ORDER BY date ASC");
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
