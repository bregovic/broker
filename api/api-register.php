<?php
// api-register.php
session_start();
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// Config
if (file_exists(__DIR__ . '/env.local.php')) {
    require_once __DIR__ . '/env.local.php';
} elseif (file_exists(__DIR__ . '/../php/env.local.php')) {
    require_once __DIR__ . '/../php/env.local.php';
} else {
    echo json_encode(['success' => false, 'error' => 'Config missing']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Username and password are required']);
        exit;
    }

    if (strlen($username) < 3) {
        echo json_encode(['success' => false, 'error' => 'Username must be at least 3 characters']);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
        exit;
    }

    // Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Username already taken']);
        exit;
    }

    // Register user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'user')");
    $stmt->execute([$username, $hash]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    // Log error internally if possible, don't expose sensitive DB info
    echo json_encode(['success' => false, 'error' => 'Database error occurred']); 
}
