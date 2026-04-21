<?php
// ajax_import_ticker.php - Handler pro import tickeru pomocí GoogleFinanceService
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
session_start();

// Check authentication
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    if (!isset($_SESSION['anonymous']) || $_SESSION['anonymous'] !== true) {
        http_response_code(401);
        die(json_encode(['success' => false, 'message' => 'Neautorizovaný přístup']));
    }
}

// Set JSON header
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$ticker = isset($input['ticker']) ? strtoupper(trim($input['ticker'])) : '';

if (empty($ticker)) {
    die(json_encode(['success' => false, 'message' => 'Ticker je povinný']));
}

require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    // Try to use GoogleFinanceService
    $servicePaths = [
        __DIR__ . '/googlefinanceservice.php',
        __DIR__ . '/GoogleFinanceService.php',
        __DIR__ . '/lib/GoogleFinanceService.php',
        __DIR__ . '/includes/googlefinanceservice.php'
    ];
    
    // Load service
    $serviceLoaded = false;
    foreach ($servicePaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $serviceLoaded = true;
            break;
        }
    }

    if ($serviceLoaded && class_exists('GoogleFinanceService')) {
        // Use the service
        $service = new GoogleFinanceService($pdo, 0);
        $data = $service->getQuote($ticker, true); // Force refresh
        
        if ($data && isset($data['current_price']) && $data['current_price'] > 0) {
            
            // 1. Save to Ticker Mapping using fetched Data
            $company = $data['company_name'] ?? $data['ticker'];
            $currency = $data['currency'] ?? 'USD';
            $isin = ''; 
            
            if ($driver === 'pgsql') {
                $sqlMap = "INSERT INTO ticker_mapping (ticker, company_name, isin, currency, status, last_verified)
                           VALUES (:ticker, :company, :isin, :currency, 'verified', NOW())
                           ON CONFLICT (ticker) DO UPDATE SET
                               company_name = EXCLUDED.company_name,
                               currency = EXCLUDED.currency,
                               last_verified = NOW(),
                               status = 'verified'";
            } else {
                $sqlMap = "INSERT INTO ticker_mapping (ticker, company_name, isin, currency, status, last_verified)
                           VALUES (:ticker, :company, :isin, :currency, 'verified', NOW())
                           ON DUPLICATE KEY UPDATE
                               company_name = VALUES(company_name),
                               currency = VALUES(currency),
                               last_verified = NOW(),
                               status = 'verified'";
            }
            
            $stmtMap = $pdo->prepare($sqlMap);
            $stmtMap->execute([
                ':ticker' => $ticker,
                ':company' => $company,
                ':isin' => $isin,
                ':currency' => $currency
            ]);

            // Save to live_quotes too so it appears in market overview immediately
            if ($driver === 'pgsql') {
                $sqlLive = "INSERT INTO live_quotes (ticker, price, change_percent, currency, last_fetched, exchange)
                            VALUES (?, ?, ?, ?, NOW(), ?)
                            ON CONFLICT (ticker) DO UPDATE SET
                                price = EXCLUDED.price,
                                change_percent = EXCLUDED.change_percent,
                                last_fetched = NOW()";
            } else {
                $sqlLive = "INSERT INTO live_quotes (ticker, price, change_percent, currency, last_fetched, exchange)
                            VALUES (?, ?, ?, ?, NOW(), ?)
                            ON DUPLICATE KEY UPDATE
                                price = VALUES(price),
                                change_percent = VALUES(change_percent),
                                last_fetched = NOW()";
            }
            $stmtLive = $pdo->prepare($sqlLive);
            $stmtLive->execute([
                $ticker, 
                $data['current_price'], 
                $data['change_percent'] ?? 0, 
                $currency,
                $data['exchange'] ?? 'UNKNOWN'
            ]);

            // 2. Resolve User ID for Watchlist
            $userId = null;
            $candidates = ['user_id','uid','userid','id'];
            foreach ($candidates as $k) {
                if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) {
                    $userId = (int)$_SESSION[$k]; 
                    break;
                }
            }

            if ($userId) {
                // Add to watchlist
                if ($driver === 'pgsql') {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS watch (
                        user_id INT NOT NULL,
                        ticker VARCHAR(20) NOT NULL,
                        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (user_id, ticker)
                    )");
                    $stmtWatch = $pdo->prepare("INSERT INTO watch (user_id, ticker) VALUES (?, ?) ON CONFLICT DO NOTHING");
                } else {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS watch (
                        user_id INT NOT NULL,
                        ticker VARCHAR(20) NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (user_id, ticker)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $stmtWatch = $pdo->prepare("INSERT IGNORE INTO watch (user_id, ticker) VALUES (?, ?)");
                }
                $stmtWatch->execute([$userId, $ticker]);
            }

            // Success response
            echo json_encode([
                'success' => true,
                'message' => 'Data úspěšně importována a přidána do sledování',
                'data' => [
                    'ticker' => $ticker,
                    'price' => number_format($data['current_price'], 2, '.', ''),
                    'company' => $data['company_name'] ?? $ticker,
                    'change' => isset($data['change_percent']) ? number_format($data['change_percent'], 2, '.', '') : 0,
                    'exchange' => $data['exchange'] ?? 'UNKNOWN'
                ]
            ]);
            exit;
        } else {
            echo json_encode([
                'success' => false,
                'message' => "GoogleFinanceService nemohl získat data pro {$ticker}"
            ]);
            exit;
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'GoogleFinanceService není dostupný.'
        ]);
        exit;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Chyba: ' . $e->getMessage()
    ]);
    exit;
}
?>