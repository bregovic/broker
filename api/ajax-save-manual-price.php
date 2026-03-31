<?php
/**
 * AJAX endpoint pro uložení manuální ceny do broker_live_quotes
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

// Parse request
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['ticker']) || !isset($data['price']) || !isset($data['currency'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing required fields: ticker, price, currency']);
    exit;
}

$ticker = strtoupper(trim($data['ticker']));
$price = (float)$data['price'];
$currency = strtoupper(trim($data['currency']));
$companyName = trim($data['company_name'] ?? $ticker);

// Validation
if (empty($ticker) || $price <= 0 || empty($currency)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid input values']);
    exit;
}

try {
    // Insert or update in broker_live_quotes
    $sql = "INSERT INTO broker_live_quotes 
                (id, source, current_price, currency, company_name, last_fetched, status)
            VALUES 
                (:ticker, 'manual', :price, :currency, :company, NOW(), 'active')
            ON DUPLICATE KEY UPDATE
                current_price = :price,
                currency = :currency,
                company_name = :company,
                last_fetched = NOW(),
                source = 'manual',
                status = 'active'";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':ticker' => $ticker,
        ':price' => $price,
        ':currency' => $currency,
        ':company' => $companyName
    ]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => "Cena pro $ticker úspěšně uložena",
        'data' => [
            'ticker' => $ticker,
            'price' => $price,
            'currency' => $currency
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
