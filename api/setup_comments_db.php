<?php
/**
 * Setup Comments table for Change Requests
 */
header("Cache-Control: no-cache");
header("Content-Type: text/plain; charset=utf-8");

$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) die("env not found");

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create comments table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS changerequest_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            user_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            comment TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_request (request_id),
            INDEX idx_created (created_at DESC),
            FOREIGN KEY (request_id) REFERENCES changerequest_log(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Table 'changerequest_comments' created\n";

    // Create comment_attachments table for inline images
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS changerequest_comment_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            comment_id INT NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_name VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_comment (comment_id),
            FOREIGN KEY (comment_id) REFERENCES changerequest_comments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Table 'changerequest_comment_attachments' created\n";

    echo "\n✅ Setup complete!";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
