<?php
// agent_api.php
// Private API for Antigravity Agent to manage tasks
header('Content-Type: application/json');

$SECRET = 'AgntKey_998877'; // Simple protection
if (($_GET['key'] ?? '') !== $SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

// DB Connection
// removed price-fallback-helper.php dependency
$db_host = null;
$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/../env.php'];
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; break; } }

if (!defined('DB_HOST')) {
    echo json_encode(['error' => 'Config missing']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'list_dev') {
    $sql = "SELECT id, subject, description, priority, attachment_path 
            FROM changerequest_log 
            WHERE status = 'Development' 
            ORDER BY FIELD(priority, 'High', 'Medium', 'Low'), created_at ASC";
    $stmt = $pdo->query($sql);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($action === 'update') {
    $id = $_GET['id'] ?? 0;
    $status = $_GET['status'] ?? '';
    $note = $_GET['note'] ?? '';

    if (!$id || !$status) {
        echo json_encode(['error' => 'Missing params']);
        exit;
    }

    $sql = "UPDATE changerequest_log SET status = ?, admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n', ?), updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $fullNote = "[Agent]: " . $note;
    $stmt->execute([$status, $fullNote, $id]);
    echo json_encode(['success' => true]);

} else {
    echo json_encode(['error' => 'Invalid action']);
}
