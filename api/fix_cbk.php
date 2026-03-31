<?php
/**
 * Fix CBK (Commerzbank) - update currency to EUR in live_quotes and ticker_mapping
 * CBK was incorrectly marked as USD/crypto, should be EUR stock
 */

$paths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/env.php', 
    __DIR__ . '/../env.php'
];

foreach ($paths as $p) {
    if (file_exists($p)) {
        require_once $p;
        break;
    }
}

if (!defined('DB_HOST')) {
    die("Error: Could not load env config.\n");
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection Error: " . $e->getMessage() . "\n");
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== Fixing CBK (Commerzbank) ===\n\n";

// 1. Fix live_quotes
echo "1. Updating live_quotes...\n";
$sql = "UPDATE live_quotes SET 
            currency = 'EUR', 
            asset_type = 'stock',
            current_price = NULL,
            last_fetched = NULL
        WHERE id = 'CBK'";
$affected = $pdo->exec($sql);
echo "   Affected rows: $affected\n";

// 2. Fix ticker_mapping
echo "\n2. Updating ticker_mapping...\n";
$sql = "INSERT INTO ticker_mapping (ticker, company_name, isin, exchange, currency, google_finance_code, status)
        VALUES ('CBK', 'Commerzbank AG', 'DE000CBK1001', 'FRA', 'EUR', 'CBK:ETR', 'verified')
        ON DUPLICATE KEY UPDATE
            company_name = 'Commerzbank AG',
            isin = 'DE000CBK1001',
            exchange = 'FRA',
            currency = 'EUR',
            google_finance_code = 'CBK:ETR',
            status = 'verified'";
$affected = $pdo->exec($sql);
echo "   Affected rows: $affected\n";

// 3. Try to fetch fresh price using GoogleFinanceService
echo "\n3. Trying to fetch fresh price for CBK...\n";

try {
    require_once __DIR__ . '/googlefinanceservice.php';
    $service = new GoogleFinanceService($pdo, 0);
    $data = $service->getQuote('CBK', true, 'EUR');
    
    if ($data && $data['current_price']) {
        echo "   Success! Price: " . $data['current_price'] . " " . ($data['currency'] ?? 'EUR') . "\n";
    } else {
        echo "   Could not fetch price (Yahoo rate limiting possible). Will retry later.\n";
    }
} catch (Exception $e) {
    echo "   Error fetching price: " . $e->getMessage() . "\n";
}

echo "\n=== Done ===\n";
echo "CBK should now be correctly recognized as Commerzbank (EUR stock).\n";
echo "If price is still not loading, wait a few minutes and try again.\n";
