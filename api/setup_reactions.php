<?php
/**
 * Setup reactions table
 */
header("Content-Type: text/plain; charset=utf-8");
$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/../env.php'];
$envLoaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $envLoaded = true; break; } }
if (!$envLoaded) { echo "❌ Env not found"; exit; }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS changerequest_comment_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            comment_id INT NOT NULL,
            user_id INT NOT NULL,
            reaction_type VARCHAR(50) NOT NULL, -- 'smile', 'check', 'cross', 'heart'
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE idx_user_reaction (comment_id, user_id, reaction_type),
            INDEX idx_comment (comment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Table 'changerequest_comment_reactions' created.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
