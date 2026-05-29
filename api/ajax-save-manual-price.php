<?php
/**
 * AJAX endpoint pro uložení manuální ceny do broker_live_quotes / live_quotes
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
require_once __DIR__ . '/config.php';
try {
    $pdo = get_pdo();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
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
    // Determine if we should use 'live_quotes' or 'broker_live_quotes'
    $tableName = 'live_quotes';
    try {
        $pdo->query("SELECT 1 FROM live_quotes LIMIT 1");
    } catch (Exception $ex) {
        $tableName = 'broker_live_quotes';
    }

    // Check if ticker already exists in the table
    $stmtCheck = $pdo->prepare("SELECT 1 FROM $tableName WHERE id = ? LIMIT 1");
    $stmtCheck->execute([$ticker]);
    $exists = (bool)$stmtCheck->fetchColumn();

    if ($exists) {
        // Update
        $sql = "UPDATE $tableName SET 
                    current_price = ?,
                    currency = ?,
                    company_name = ?,
                    last_fetched = NOW(),
                    source = 'manual',
                    status = 'active'
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$price, $currency, $companyName, $ticker]);
    } else {
        // Insert
        $sql = "INSERT INTO $tableName 
                    (id, source, current_price, currency, company_name, last_fetched, status)
                VALUES 
                    (?, 'manual', ?, ?, ?, NOW(), 'active')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ticker, $price, $currency, $companyName]);
    }
    
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
