<?php
// setup_auth.php
// Creates 'users' table and default admin user using ADMIN credentials

header('Content-Type: text/plain; charset=utf-8');

// 1. Config (Load HOST and NAME, ignore USER/PASS)
if (file_exists(__DIR__ . '/env.local.php')) {
    require_once __DIR__ . '/env.local.php';
} elseif (file_exists(__DIR__ . '/../php/env.local.php')) {
    require_once __DIR__ . '/../php/env.local.php';
}

// Override with ADMIN credentials
// Assuming DB_HOST and DB_NAME are correct from config
$adminUser = 'a372733_invest';
$adminPass = 'Venca123!';

// Fallback if config failed loading constants
if (!defined('DB_HOST')) define('DB_HOST', 'md390.wedos.net');
if (!defined('DB_NAME')) define('DB_NAME', 'd372733_invest');

echo "Connecting to ".DB_HOST." / ".DB_NAME." as $adminUser...\n";

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", $adminUser, $adminPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // 2. Create table
    echo "Creating 'users' table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'user') DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
    echo "Table 'users' created or already exists.\n";

    // 3. Create default admin
    $username = 'admin';
    $password = 'admin123'; // Default password

    // Check if exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')");
        $stmt->execute([$username, $hash]);
        echo "User '$username' created with password '$password'.\n";
    } else {
        echo "User '$username' already exists.\n";
    }

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}
