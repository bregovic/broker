<?php
/**
 * Deployment Hook
 * Called by GitHub Actions after successful deployment.
 * ?token=...&commit_msg=...&commit_sha=...&author=...
 */
header("Content-Type: application/json; charset=utf-8");

$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) { echo json_encode(['error' => 'env not found']); exit; }

// Simple security
$token = $_GET['token'] ?? '';
$expectedToken = defined('DEPLOY_TOKEN') ? DEPLOY_TOKEN : 'investyx_secret_123';
if ($token !== $expectedToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $msg = $_GET['commit_msg'] ?? 'Deployment';
    $sha = $_GET['commit_sha'] ?? '';
    $author = $_GET['author'] ?? 'GitHub Actions';
    
    // 1. Log to development_history
    $title = "Nasazení: " . $msg;
    if (strlen($title) > 100) $title = substr($title, 0, 97) . "...";

    $description = "Autor: " . $author . "\nCommit: " . substr($sha, 0, 8) . "\n\nZpráva:\n" . $msg;
    
    $stmt = $pdo->prepare("INSERT INTO development_history (date, title, description, category) VALUES (NOW(), ?, ?, 'deployment')");
    $stmt->execute([$title, $description]);
    $historyId = $pdo->lastInsertId();

    // 2. Scan for #ID in commit message to update Helpdesk
    preg_match_all('/#(\d+)/', $msg, $matches);
    $updatedTasks = [];
    if (!empty($matches[1])) {
        foreach ($matches[1] as $taskId) {
            $taskId = (int)$taskId;
            // Check if task exists and update status to 'Testing'
            $check = $pdo->prepare("SELECT id, status, subject FROM changerequest_log WHERE id = ?");
            $check->execute([$taskId]);
            $task = $check->fetch();
            
            if ($task) {
                $oldStatus = $task['status'];
                $newStatus = 'Testing'; 
                
                // Update history title if we found a task
                $newTitle = "Nasazení: " . $task['subject'] . " (#" . $taskId . ")";
                $pdo->prepare("UPDATE development_history SET title = ?, related_task_id = ? WHERE id = ?")->execute([$newTitle, $taskId, $historyId]);

                if ($oldStatus !== $newStatus) {
                    $update = $pdo->prepare("UPDATE changerequest_log SET status = ?, updated_at = NOW() WHERE id = ?");
                    $update->execute([$newStatus, $taskId]);
                    
                    // Log to history
                    $log = $pdo->prepare("INSERT INTO changerequest_history (request_id, user_id, username, change_type, old_value, new_value) VALUES (?, 0, 'System', 'status', ?, ?)");
                    $log->execute([$taskId, $oldStatus, $newStatus]);
                    
                    // Add a comment to the task discussion
                    $commentText = "**Automatické nasazení**\n\nAutor: " . $author . "\nZpráva: " . $msg . "\nVerze: " . substr($sha, 0, 8);
                    $stmtComment = $pdo->prepare("INSERT INTO changerequest_comments (request_id, user_id, username, comment, created_at) VALUES (?, 0, 'System', ?, NOW())");
                    $stmtComment->execute([$taskId, $commentText]);
                    
                    $updatedTasks[] = $taskId;
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'history_id' => $historyId,
        'updated_tasks' => $updatedTasks
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
