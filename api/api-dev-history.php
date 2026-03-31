<?php
/**
 * API for Development History
 * GET ?action=list - List all history entries
 * POST ?action=add - Add new entry (admin only)
 */
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache");

$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) { echo json_encode(['success' => false, 'error' => 'env not found']); exit; }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DB connection failed']);
    exit;
}

$action = $_REQUEST['action'] ?? 'list';

if ($action === 'list') {
    // List all history entries grouped by month
    $stmt = $pdo->query("
        SELECT 
            id, date, title, description, category, related_task_id,
            DATE_FORMAT(date, '%Y-%m') as month_key,
            DATE_FORMAT(date, '%M %Y') as month_label
        FROM development_history 
        ORDER BY date DESC, id DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by month
    $grouped = [];
    foreach ($rows as $row) {
        $key = $row['month_key'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'month' => $row['month_label'],
                'items' => []
            ];
        }
        $grouped[$key]['items'][] = [
            'id' => (int)$row['id'],
            'date' => $row['date'],
            'title' => $row['title'],
            'description' => $row['description'],
            'category' => $row['category'],
            'task_id' => $row['related_task_id'] ? (int)$row['related_task_id'] : null
        ];
    }
    
    echo json_encode([
        'success' => true, 
        'data' => array_values($grouped),
        'total' => count($rows)
    ]);
    exit;
}

if ($action === 'add') {
    // Add new entry - basic protection
    $date = $_POST['date'] ?? date('Y-m-d');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = $_POST['category'] ?? 'feature';
    $taskId = $_POST['task_id'] ?? null;
    
    if (empty($title)) {
        echo json_encode(['success' => false, 'error' => 'Title is required']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO development_history (date, title, description, category, related_task_id)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$date, $title, $description, $category, $taskId ?: null]);
    
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
