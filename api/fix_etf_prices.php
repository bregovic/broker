<?php
// fix_etf_prices.php
// Resetuje last_fetched pro specifické ETF a pokusí se je stáhnout s vylepšenou logikou burz
header('Content-Type: text/plain; charset=utf-8');

// 1. Enviroment
$envPaths = [
    __DIR__ . '/../env.local.php',
    __DIR__ . '/env.local.php',
    __DIR__ . '/php/env.local.php',
    '../env.local.php',
    'php/env.local.php',
    '../php/env.local.php'
];
foreach ($envPaths as $path) { if (file_exists($path)) { require_once $path; break; } }
require_once __DIR__ . '/googlefinanceservice.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Seznam ETF, které dělají problémy
    $etfs = ['ZPRV', 'CNDX', 'VWRA', 'CSPX', 'IWVL'];
    
    echo "1. Resetování data stažení pro ETF: " . implode(', ', $etfs) . "\n";
    
    $placeholders = implode(',', array_fill(0, count($etfs), '?'));
    $sql = "UPDATE live_quotes SET last_fetched = '2000-01-01 00:00:00' WHERE id IN ($placeholders)";
    $pdo->prepare($sql)->execute($etfs);
    
    echo "   -> Hotovo.\n\n";
    
    echo "2. Testovací stahování (Live Debug):\n";
    $service = new GoogleFinanceService($pdo, 0);
    
    foreach ($etfs as $ticker) {
        echo "   Fetching $ticker ... ";
        $data = $service->getQuote($ticker, true);
        
        if ($data && !empty($data['current_price'])) {
            echo "OK! Cena: {$data['current_price']} {$data['currency']} (Burza: {$data['exchange']})\n";
        } else {
            echo "SELHALO.\n";
        }
    }
    
    echo "\nHotovo. Obnovte stránku portfolia (F5).";

} catch (Exception $e) {
    echo "CHYBA: " . $e->getMessage();
}
