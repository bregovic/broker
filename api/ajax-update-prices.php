<?php
// ajax-update-prices.php
header('Content-Type: application/json');
set_time_limit(600); // 10 minutes
ignore_user_abort(true);
ini_set('memory_limit', '512M');

// 1. Zjistíme připojení k DB
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
if (!defined('DB_HOST')) require_once __DIR__.'/db.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Načteme službu
    require_once __DIR__ . '/googlefinanceservice.php';
    $service = new GoogleFinanceService($pdo, 0); // TTL 0 = vždy čerstvé
    
    // Získáme seznam všech aktivních tickerů s měnou z transakcí
    // Transactions are source of truth for currency
    $stmt = $pdo->query("
        SELECT DISTINCT lq.id, 
               (SELECT currency FROM transactions WHERE id = lq.id LIMIT 1) as tx_currency
        FROM live_quotes lq 
        WHERE lq.status = 'active'
    ");
    $tickerData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [
        'total' => count($tickerData),
        'updated' => 0,
        'failed' => 0,
        'details' => []
    ];
    
    foreach ($tickerData as $row) {
        $ticker = $row['id'];
        $txCurrency = $row['tx_currency']; // NULL if no transactions exist
        
        try {
            // getQuote s forceFresh = true
            // Pass transaction currency so correct exchange is used for fetching
            $data = $service->getQuote($ticker, true, $txCurrency);
            if ($data) {
                $results['updated']++;
            } else {
                $results['failed']++;
                $results['details'][] = "$ticker: Failed to fetch";
            }
        } catch (Exception $e) {
            $results['failed']++;
            $results['details'][] = "$ticker: " . $e->getMessage();
        }
        
        // Pauza aby nás Google neblokl
        usleep(100000); // 100ms
    }
    
    echo json_encode(['success' => true, 'message' => "Hotovo. Aktualizováno: {$results['updated']}, Chyby: {$results['failed']}"]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
