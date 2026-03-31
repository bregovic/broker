<?php
/**
 * AJAX endpoint pro kontrolu které tickery nemají dnešní cenu
 * Vrací seznam tickerů který potřebují manuální zadání
 */
session_start();

// Authentication check
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

if (!$isLoggedIn) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get user ID
$currentUserId = $_SESSION['user_id'] ?? 13;

// Database connection
$pdo = null;
try {
    $paths = [
        __DIR__ . '/../env.local.php',
        __DIR__ . '/env.local.php',
        __DIR__ . '/php/env.local.php',
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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

try {
    // Get all unique tickers from user's current positions
    $sql = "SELECT DISTINCT bt.id as ticker, bt.currency, 
                   COALESCE(btm.company_name, bt.id) as company_name
            FROM transactions bt
            LEFT JOIN ticker_mapping btm ON bt.id = btm.ticker
            WHERE bt.user_id = ?
              AND (bt.product_type = 'Stock' OR bt.product_type = 'Crypto')
              AND (bt.trans_type = 'Buy' OR bt.trans_type = 'Sell' OR bt.trans_type = 'Revenue')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$currentUserId]);
    $tickers = $stmt->fetchAll();
    
    $missingPrices = [];
    $today = date('Y-m-d');
    
    foreach ($tickers as $item) {
        $ticker = $item['ticker'];
        
        // Calculate if position is still open
        $sqlQty = "SELECT SUM(CASE 
                        WHEN trans_type = 'Buy' OR trans_type = 'Revenue' THEN amount 
                        WHEN trans_type = 'Sell' THEN -amount 
                        ELSE 0 
                    END) as total_qty
                   FROM transactions
                   WHERE user_id = ? AND id = ?";
        $stmtQty = $pdo->prepare($sqlQty);
        $stmtQty->execute([$currentUserId, $ticker]);
        $qtyResult = $stmtQty->fetch();
        
        // Skip if position is closed
        if (!$qtyResult || $qtyResult['total_qty'] <= 0) {
            continue;
        }
        
        // Check if we have today's price
        $sqlPrice = "SELECT id, company_name FROM live_quotes
                     WHERE id = ?
                       AND currency = ?
                       AND DATE(last_fetched) = ?
                       AND status = 'active'
                       AND current_price IS NOT NULL
                       AND current_price > 0";
        $stmtPrice = $pdo->prepare($sqlPrice);
        $stmtPrice->execute([$ticker, $item['currency'], $today]);
        $priceRow = $stmtPrice->fetch();
        
        $isMissing = false;
        
        if (!$priceRow) {
            $isMissing = true;
        } else {
            // Validate company name if mapping exists
            // If mapping has a name, and live quote has a name, they should be somewhat similar
            $mappedName = trim($item['company_name'] ?? '');
            $liveName = trim($priceRow['company_name'] ?? '');
            
            if (!empty($mappedName) && !empty($liveName) && $mappedName !== $ticker) {
                // Simple check: if the first word is completely different, it might be wrong
                // Or use levenshtein for more robust check
                // Here we use a simple heuristic: if one contains the other, it's likely OK.
                // If not, check similarity.
                
                $mappedLower = mb_strtolower($mappedName);
                $liveLower = mb_strtolower($liveName);
                
                // Remove common suffixes for comparison
                $suffixes = [' inc', ' corp', ' ag', ' se', ' plc', ' ltd', ' s.a.', ' corporation', ' incorporated'];
                foreach ($suffixes as $s) {
                    $mappedLower = str_replace($s, '', $mappedLower);
                    $liveLower = str_replace($s, '', $liveLower);
                }
                
                // Check if one contains the other
                if (strpos($mappedLower, $liveLower) === false && strpos($liveLower, $mappedLower) === false) {
                    // If completely different, calculate similarity
                    $sim = 0;
                    similar_text($mappedLower, $liveLower, $sim);
                    if ($sim < 40) { // Less than 40% similarity -> likely wrong company
                        $isMissing = true;
                    }
                }
            }
        }
        
        if ($isMissing) {
            $missingPrices[] = [
                'ticker' => $ticker,
                'currency' => $item['currency'],
                'company_name' => $item['company_name']
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'missing_prices' => $missingPrices,
        'count' => count($missingPrices)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
