<?php
// setup_changerequests_db.php
header('Content-Type: text/plain; charset=utf-8');

// Load Env
$paths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../php/env.local.php',
    __DIR__ . '/../../env.local.php',
    __DIR__ . '/env.php'
];
foreach ($paths as $p) {
    if (file_exists($p)) { require_once $p; break; }
}

if (!defined('DB_HOST')) die("DB_HOST not defined");

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "Connected to database.\n";

    // Create table changerequest_log
    $sql = "CREATE TABLE IF NOT EXISTS changerequest_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
        description TEXT NOT NULL,
        status ENUM('New', 'Analysis', 'Development', 'Testing', 'Done', 'Approved', 'Canceled') NOT NULL DEFAULT 'New',
        attachment_path VARCHAR(255) NULL,
        admin_notes TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    echo "Table 'changerequest_log' created successfully.\n";

    // Ensure upload directory exists (though web server user needs rights)
    $uploadDir = __DIR__ . '/uploads/changerequests';
    if (!file_exists($uploadDir)) {
        if (mkdir($uploadDir, 0777, true)) {
            echo "Directory 'uploads/changerequests' created.\n";
        } else {
            echo "Failed to create directory 'uploads/changerequests'. Please create manually and set permissions.\n";
        }
    } else {
        echo "Directory 'uploads/changerequests' exists.\n";
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
