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

// Database connection
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

// Fallback: try direct include if in path
if (!defined('DB_HOST') && stream_resolve_include_path('env.local.php')) {
    require_once 'env.local.php';
}

if (!defined('DB_HOST')) {
    $scanned = implode(', ', $envPaths);
    die(json_encode(['success' => false, 'message' => "Chyba konfigurace databáze. CWD: " . getcwd() . ". Scanned: $scanned"]));
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
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
            $isin = ''; // Google Finance usually doesn't return ISIN, keep empty or null
            
            $sqlMap = "INSERT INTO ticker_mapping (ticker, company_name, isin, currency, status, last_verified)
                       VALUES (:ticker, :company, :isin, :currency, 'verified', NOW())
                       ON DUPLICATE KEY UPDATE
                           company_name = VALUES(company_name),
                           currency = VALUES(currency),
                           last_verified = NOW(),
                           status = 'verified'";
            $stmtMap = $pdo->prepare($sqlMap);
            $stmtMap->execute([
                ':ticker' => $ticker,
                ':company' => $company,
                ':isin' => $isin,
                ':currency' => $currency
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
            if (!$userId && isset($_SESSION['user'])) {
                $u = $_SESSION['user'];
                if (is_array($u)) foreach ($candidates as $k) if (isset($u[$k]) && is_numeric($u[$k])) $userId = (int)$u[$k];
                elseif (is_object($u)) foreach ($candidates as $k) if (isset($u->$k) && is_numeric($u->$k)) $userId = (int)$u->$k;
            }

            if ($userId) {
                // Add to watchlist
                $pdo->exec("CREATE TABLE IF NOT EXISTS watch (
                    user_id INT NOT NULL,
                    ticker VARCHAR(20) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, ticker)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmtWatch = $pdo->prepare("INSERT IGNORE INTO watch (user_id, ticker) VALUES (?, ?)");
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
            // Service couldn't get data
            echo json_encode([
                'success' => false,
                'message' => "GoogleFinanceService nemohl získat data pro {$ticker}"
            ]);
            exit;
        }
    } else {
        // GoogleFinanceService not available
        echo json_encode([
            'success' => false,
            'message' => 'GoogleFinanceService není dostupný. Zkontrolujte, že soubor googlefinanceservice.php existuje.'
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