<?php
// cli_manage_issues.php
// Usage:
//   php cli_manage_issues.php list
//   php cli_manage_issues.php update <id> <status> "<comment>"

require_once 'env.local.php'; // or env.php, logic below

if (!defined('DB_HOST')) {
    $paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/../env.php'];
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; break; } }
}

if (!defined('DB_HOST')) {
    die("Error: Could not load env config.\n");
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection Error: " . $e->getMessage() . "\n");
}

$cmd = $argv[1] ?? 'help';

if ($cmd === 'list') {
    // List Development items sorted by Priority
    $sql = "SELECT id, subject, description, priority, attachment_path, created_at FROM changerequest_log 
            WHERE status = 'Development' 
            ORDER BY FIELD(priority, 'High', 'Medium', 'Low'), created_at ASC";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($rows)) {
        echo "No items in 'Development' status.\n";
        exit;
    }

    echo "--- TASKS IN DEVELOPMENT ---\n";
    foreach ($rows as $r) {
        echo "[ID: {$r['id']}] [Priority: {$r['priority']}] {$r['subject']}\n";
        echo "       Desc: " . substr(str_replace(["\r", "\n"], ' ', $r['description']), 0, 100) . "...\n";
        if ($r['attachment_path']) echo "       Attach: {$r['attachment_path']}\n";
        echo "--------------------------\n";
    }
} elseif ($cmd === 'update') {
    $id = $argv[2] ?? null;
    $status = $argv[3] ?? null;
    $comment = $argv[4] ?? '';

    if (!$id || !$status) {
        die("Usage: php cli_manage_issues.php update <id> <status> [comment]\n");
    }

    // Append comment to admin_notes
    $sql = "UPDATE changerequest_log SET status = ?, admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n', ?), updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $note = "[AI Update]: " . $comment;
    $stmt->execute([$status, $note, $id]);
    
    echo "Task ID $id updated to status '$status'.\n";

} else {
    echo "Usage:\n";
    echo "  php cli_manage_issues.php list\n";
    echo "  php cli_manage_issues.php update <id> <status> \"<comment>\"\n";
}
