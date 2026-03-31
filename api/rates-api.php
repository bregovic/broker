<?php
session_start();
header('Content-Type: application/json');

// Check authentication
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;
if (!$isLoggedIn && !$isAnonymous) {
    http_response_code(401);
    echo JSON_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

// DB Connection
$pdo = null;
try {
    $envPaths = [
        __DIR__ . '/../env.local.php',
        __DIR__ . '/env.local.php',
        __DIR__ . '/php/env.local.php',
        '../env.local.php',
        'php/env.local.php',
        '../php/env.local.php'
    ];
    foreach ($envPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
    if (defined('DB_HOST')) {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo JSON_encode(['ok' => false, 'message' => 'DB Connection failed']);
    exit;
}

if (!$pdo) {
    http_response_code(500);
    echo JSON_encode(['ok' => false, 'message' => 'DB Connection failed']);
    exit;
}

// Helper to get rate
function findRate($pdo, $currency, $date, $nearest = false) {
    if ($currency === 'CZK') return 1;
    
    // Try exact match first
    $stmt = $pdo->prepare("SELECT rate, amount FROM rates WHERE currency = ? AND date = ? LIMIT 1");
    $stmt->execute([$currency, $date]);
    $row = $stmt->fetch();
    
    if ($row) {
        return $row['amount'] > 0 ? $row['rate'] / $row['amount'] : $row['rate'];
    }
    
    if ($nearest) {
        // Find nearest past rate
        $stmt = $pdo->prepare("SELECT rate, amount FROM rates WHERE currency = ? AND date <= ? ORDER BY date DESC LIMIT 1");
        $stmt->execute([$currency, $date]);
        $row = $stmt->fetch();
        if ($row) {
            return $row['amount'] > 0 ? $row['rate'] / $row['amount'] : $row['rate'];
        }
    }
    
    return null;
}

// Handle Requests
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Single rate: ?currency=EUR&date=2023-01-01&nearest=1
    $currency = $_GET['currency'] ?? '';
    $date = $_GET['date'] ?? '';
    $nearest = !empty($_GET['nearest']);
    
    if (!$currency || !$date) {
        echo JSON_encode(['ok' => false, 'message' => 'Missing params']);
        exit;
    }
    
    $rate = findRate($pdo, $currency, $date, $nearest);
    
    if ($rate !== null) {
        echo JSON_encode(['ok' => true, 'rate' => $rate]);
    } else {
        echo JSON_encode(['ok' => false, 'message' => 'Rate not found']);
    }
    
} elseif ($method === 'POST') {
    // Bulk rates: { requests: [{date, currency}, ...], nearest: true }
    $input = json_decode(file_get_contents('php://input'), true);
    $requests = $input['requests'] ?? [];
    $nearest = !empty($input['nearest']);
    
    $results = [];
    
    foreach ($requests as $req) {
        $cur = $req['currency'] ?? '';
        $d = $req['date'] ?? '';
        if (!$cur || !$d) continue;
        
        $key = "{$d}|{$cur}";
        $rate = findRate($pdo, $cur, $d, $nearest);
        
        if ($rate !== null) {
            $results[$key] = $rate;
        }
    }
    
    echo JSON_encode(['ok' => true, 'rates' => $results]);
} else {
    http_response_code(405);
    echo JSON_encode(['ok' => false, 'message' => 'Method not allowed']);
}
