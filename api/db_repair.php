<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/googlefinanceservice.php';

try {
    $pdo = get_pdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "=== DATABASE SELF-HEALING REPAIR ===\n";
    echo "Driver: $driver\n\n";

    // 1. Get unique tickers from transactions
    $stmtT = $pdo->query("SELECT DISTINCT ticker FROM transactions WHERE ticker IS NOT NULL");
    $transTickers = $stmtT->fetchAll(PDO::FETCH_COLUMN);

    // 2. Get unique tickers from watch
    $stmtW = $pdo->query("SELECT DISTINCT ticker FROM watch WHERE ticker IS NOT NULL");
    $watchTickers = $stmtW->fetchAll(PDO::FETCH_COLUMN);

    // Merge and deduplicate
    $allTickers = array_unique(array_merge($transTickers, $watchTickers));
    
    echo "Found " . count($allTickers) . " unique tickers in transactions & watchlists.\n";

    $addedToQuotes = 0;
    $gService = new GoogleFinanceService($pdo, 0); // Force fresh quotes

    foreach ($allTickers as $t) {
        $t = strtoupper(trim($t));
        if (empty($t) || preg_match('/^(CASH_|FEE_|FX_|CORP_)/', $t)) continue;

        // Check if exists in live_quotes
        $stmtCheck = $pdo->prepare("SELECT 1 FROM live_quotes WHERE id = ? OR ticker = ? LIMIT 1");
        $stmtCheck->execute([$t, $t]);
        $exists = (bool)$stmtCheck->fetchColumn();

        if (!$exists) {
            echo "Registering ticker: $t ... ";
            $assetType = 'stock';
            $knownCrypto = ['BTC','ETH','SOL','ADA','DOT','XRP','LTC','DOGE','USDT'];
            if (in_array($t, $knownCrypto)) {
                $assetType = 'crypto';
            }

            // Insert into live_quotes
            $sqlLQ = "INSERT INTO live_quotes (id, ticker, asset_type, last_fetched, status) 
                      VALUES (?, ?, ?, NOW(), 'active')";
            $pdo->prepare($sqlLQ)->execute([$t, $t, $assetType]);
            
            // Try to fetch fresh quote from Google/Yahoo immediately
            try {
                $gService->getQuote($t, true);
                echo "OK (price updated)\n";
            } catch (Exception $quoteEx) {
                echo "OK (quote update failed: " . $quoteEx->getMessage() . ")\n";
            }
            $addedToQuotes++;
        }
    }

    echo "\nSelf-healing completed. Registered $addedToQuotes new tickers in live_quotes.\n";

} catch (Exception $e) {
    echo "CRITICAL REPAIR ERROR: " . $e->getMessage() . "\n";
}
