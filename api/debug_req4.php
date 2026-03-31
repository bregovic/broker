<?php
header("Content-Type: text/plain; charset=utf-8");
session_start();
$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; break; } }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS changerequest_comment_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            comment_id INT NOT NULL,
            user_id INT NOT NULL,
            reaction_type VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE idx_user_reaction (comment_id, user_id, reaction_type),
            INDEX idx_comment (comment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // FIX STATUS FOR #4
    $pdo->exec("UPDATE changerequest_log SET status = 'Testing' WHERE id = 4 AND (status = '' OR status IS NULL)");
    echo "✅ Status of #4 fixed to 'Testing' if it was empty.\n\n";

    echo "--- SESSION DEBUG ---\n";
    print_r($_SESSION);
    echo "\n";

    echo "--- REQUEST #4 ---\n";
    $stmt = $pdo->prepare("SELECT * FROM changerequest_log WHERE id = 4");
    $stmt->execute();
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($req);

    echo "\n--- COMMENTS FOR #4 ---\n";
    $stmt = $pdo->prepare("SELECT * FROM changerequest_comments WHERE request_id = 4 ORDER BY created_at ASC");
    $stmt->execute();
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($comments);

    echo "\n--- HISTORY FOR #4 ---\n";
    $stmt = $pdo->prepare("SELECT * FROM changerequest_history WHERE request_id = 4 ORDER BY created_at DESC");
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($history);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
