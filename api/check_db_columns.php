<?php
// check_db_columns.php
header('Content-Type: text/plain');
require_once 'env.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS);
    $stmt = $pdo->query("SHOW COLUMNS FROM changerequest_log");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);
    
    // Test Insert
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO changerequest_log (user_id, subject, description, priority, attachment_path) VALUES (?, ?, ?, ?, ?)");
    // Use user_id 1 safely (assuming admin exists) or find valid user
    $u = $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
    if ($u) {
        $stmt->execute([$u, 'Test Subject', 'Test Desc', 'low', null]);
        echo "\nTest Insert OK. Rollback.\n";
    } else {
        echo "\nNo users found to test insert.\n";
    }
    $pdo->rollBack();

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
