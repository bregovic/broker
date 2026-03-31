<?php
/**
 * AJAX endpoint for batch fetching live prices
 * Returns JSON with current prices for requested tickers
 */

session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

// Authentication check
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;

if (!$isLoggedIn && !$isAnonymous) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Database connection
$pdo = null;
try {
    $paths = [
        __DIR__ . '/../env.local.php',
        __DIR__ . '/env.local.php',
        __DIR__ . '/php/env.local.php',
        $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
        __DIR__ . '/../../env.local.php',
        __DIR__ . '/env.php',
        __DIR__ . '/../env.php',
        $_SERVER['DOCUMENT_ROOT'] . '/env.php'
    ];
    foreach ($paths as $p) {
        if (file_exists($p)) {
            require_once $p;
            break;
        }
    }
    if (defined('DB_HOST')) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Load Google Finance Service
$googleFinance = null;
$servicePath = __DIR__ . '/googlefinanceservice.php';
if (file_exists($servicePath)) {
    require_once $servicePath;
    try {
$googleFinance = new GoogleFinanceService($pdo, 43200); // 12 hours TTL to be safe, or 0 for today.
// User requested "not download if downloaded that day".
// "Today" (0) logic: DATE(last_fetched) = CURRENT_DATE().
// If I use 0, and I check at 23:59 and then 00:01, it re-fetches. Good.
// If I use 12h, it's safer for timezone mismatches.
// But 0 is what's implemented for "today".
// Let's stick to 0 but ensure consistent usage.
    } catch (Exception $e) {
        error_log('GoogleFinanceService init error: ' . $e->getMessage());
    }
}

// Parse request
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['tickers']) || !is_array($data['tickers'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request format. Expected: {tickers: [{ticker, currency}, ...]}']);
    exit;
}

$tickers = $data['tickers'];
$results = [];

// Process each ticker
$tickerList = array_map(function($item) { return trim($item['ticker'] ?? ''); }, $tickers);
$tickerList = array_filter($tickerList); // remove empty

// Pre-fetch asset types from DB to assist GoogleFinanceService
$assetTypes = [];
if (!empty($tickerList) && $pdo) {
    try {
        $placeholders = implode(',', array_fill(0, count($tickerList), '?'));
        $stmtTypes = $pdo->prepare("SELECT id, asset_type FROM live_quotes WHERE id IN ($placeholders)");
        $stmtTypes->execute($tickerList);
        while ($row = $stmtTypes->fetch(PDO::FETCH_ASSOC)) {
            $assetTypes[strtoupper($row['id'])] = $row['asset_type'];
        }
    } catch (Exception $e) { 
        // Silent fail, default to stock
    }
}

foreach ($tickers as $item) {
    if (!isset($item['ticker']) || !isset($item['currency'])) {
        continue;
    }
    
    $ticker = trim($item['ticker']);
    $currency = trim($item['currency']);
    
    if (empty($ticker)) {
        continue;
    }
    
    $price = null;
    $success = false;
    $error = null;
    $assetType = $assetTypes[strtoupper($ticker)] ?? null;
    
    try {
        if ($googleFinance !== null) {
            // Try Google Finance Service
            // Pass assetType (crypto/stock) to enable correct API selection
            $quoteData = $googleFinance->getQuote($ticker, false, $currency, $assetType); // use cache if available (today's data)
            
            if ($quoteData !== null && isset($quoteData['current_price']) && $quoteData['current_price'] > 0) {
                // Check if currency matches requested currency
                $fetchedCurrency = strtoupper($quoteData['currency'] ?? '');
                $requestedCurrency = strtoupper($currency);
                
                if ($fetchedCurrency === $requestedCurrency) {
                    $price = (float)$quoteData['current_price'];
                    $priceConverted = $price;
                    $success = true;
                } else {
                    // Attempt conversion
                    $price = (float)$quoteData['current_price'];
                    // Get FX Rate for fetchedCurrency (e.g. USD) -> requestedCurrency (e.g. CZK)
                    // Assuming requested is CZK (base). If not, we need pairs.
                    // Simplified for CZK base:
                    if ($requestedCurrency === 'CZK') {
                         $stmtFx = $pdo->prepare("SELECT rate, amount FROM rates WHERE currency = ? ORDER BY date DESC LIMIT 1");
                         $stmtFx->execute([$fetchedCurrency]);
                         $fxRow = $stmtFx->fetch(PDO::FETCH_ASSOC);
                         if ($fxRow) {
                             $rate = (float)$fxRow['rate'];
                             $amt  = (float)($fxRow['amount'] ?: 1);
                             $fxRate = $rate / $amt;
                             $priceConverted = $price * $fxRate;
                             $success = true;
                         } else {
                             $error = "No FX rate found for $fetchedCurrency";
                             $success = false;
                         }
                    } else {
                         // Cross currency not implemented here simply
                         $error = "Currency mismatch: fetched $fetchedCurrency, requested $requestedCurrency (no conversion)";
                         $success = false;
                    }
                }
            } else {
                $error = 'Price not available';
            }
        } else {
            $error = 'Google Finance service not available';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Error fetching price for $ticker: " . $error);
    }
    
    $results[$ticker] = [
        'price' => $price,
        'price_converted' => $priceConverted ?? null,
        'currency' => $fetchedCurrency ?? null,
        'requested_currency' => $currency,
        'success' => $success,
        'error' => $error
    ];
}

// Return results
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
