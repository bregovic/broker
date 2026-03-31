<?php
/**
 * Manual alias setter for specific tickers
 * Usage: set_alias.php?old=GOLD&new=B
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

$oldTicker = strtoupper(trim($_GET['old'] ?? ''));
$newTicker = strtoupper(trim($_GET['new'] ?? ''));

if (empty($oldTicker) || empty($newTicker)) {
    echo "=== Set Ticker Alias ===\n\n";
    echo "Usage: set_alias.php?old=GOLD&new=B\n\n";
    echo "This will set GOLD as an alias of B (meaning GOLD is the old ticker, B is current).\n";
    echo "When fetching prices for GOLD, the system will use prices from B instead.\n\n";
    
    // List existing aliases
    echo "=== Current Aliases ===\n";
    $stmt = $pdo->query("SELECT ticker, alias_of, company_name FROM ticker_mapping WHERE alias_of IS NOT NULL AND alias_of != '' ORDER BY alias_of, ticker");
    $aliases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($aliases)) {
        echo "No aliases defined yet.\n";
    } else {
        foreach ($aliases as $a) {
            echo "  {$a['ticker']} -> {$a['alias_of']} ({$a['company_name']})\n";
        }
    }
    exit;
}

echo "=== Setting Alias: $oldTicker -> $newTicker ===\n\n";

// Get company name from new ticker (if available)
$stmt = $pdo->prepare("SELECT company_name FROM live_quotes WHERE id = ?");
$stmt->execute([$newTicker]);
$companyName = $stmt->fetchColumn() ?: '';

// Ensure old ticker exists in mapping
$sql = "INSERT INTO ticker_mapping (ticker, company_name, alias_of, status, last_verified)
        VALUES (?, ?, ?, 'verified', NOW())
        ON DUPLICATE KEY UPDATE 
            alias_of = ?,
            company_name = COALESCE(NULLIF(?, ''), company_name),
            last_verified = NOW()";

$pdo->prepare($sql)->execute([$oldTicker, $companyName, $newTicker, $newTicker, $companyName]);
echo "✓ Set $oldTicker as alias of $newTicker\n";

// Ensure new ticker exists in mapping (as canonical)
$sql2 = "INSERT INTO ticker_mapping (ticker, company_name, status, last_verified)
         VALUES (?, ?, 'verified', NOW())
         ON DUPLICATE KEY UPDATE 
             company_name = COALESCE(NULLIF(?, ''), company_name),
             last_verified = NOW()";

$pdo->prepare($sql2)->execute([$newTicker, $companyName, $companyName]);
echo "✓ Ensured $newTicker exists as canonical ticker\n";

// Copy latest price from new ticker to old ticker in live_quotes (so old transactions show correct value)
$stmt = $pdo->prepare("SELECT current_price, currency, company_name, exchange FROM live_quotes WHERE id = ?");
$stmt->execute([$newTicker]);
$priceData = $stmt->fetch(PDO::FETCH_ASSOC);

if ($priceData && $priceData['current_price'] > 0) {
    $sqlCopy = "INSERT INTO live_quotes (id, current_price, currency, company_name, exchange, last_fetched, status)
                VALUES (?, ?, ?, ?, ?, NOW(), 'active')
                ON DUPLICATE KEY UPDATE 
                    current_price = VALUES(current_price),
                    currency = VALUES(currency),
                    company_name = VALUES(company_name),
                    exchange = VALUES(exchange),
                    last_fetched = NOW()";
    
    $pdo->prepare($sqlCopy)->execute([
        $oldTicker,
        $priceData['current_price'],
        $priceData['currency'],
        $priceData['company_name'],
        $priceData['exchange']
    ]);
    
    echo "✓ Copied price data from $newTicker to $oldTicker:\n";
    echo "  Price: {$priceData['current_price']} {$priceData['currency']}\n";
    echo "  Company: {$priceData['company_name']}\n";
} else {
    echo "⚠ No price data found for $newTicker to copy\n";
}

echo "\n=== Done ===\n";
echo "Now when calculating portfolio for $oldTicker, it will use $newTicker's price.\n";
