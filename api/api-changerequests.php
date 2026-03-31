<?php
/**
 * API for Change Requests / Issue Reporting
 */

// Start output buffering to catch any warnings/errors
ob_start();

session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

function returnJson($data) {
    ob_end_clean(); // Discard any previous output/warnings
    echo json_encode($data);
    exit;
}

// Log change history helper
function logHistory($pdo, $reqId, $userId, $type, $old, $new) {
    if ($old == $new) return; // No change
    try {
        // Fetch username if user is logged in
        $un = 'System';
        if ($userId > 0) {
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $un = $stmt->fetchColumn() ?: 'Unknown';
        }
        
        $sql = "INSERT INTO changerequest_history (request_id, user_id, username, change_type, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$reqId, $userId, $un, $type, (string)$old, (string)$new]);
    } catch (Exception $e) { 
        // Silent fail on history log to not break the app
    }
}

// Auth Check
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    returnJson(['error' => 'Unauthorized']);
}

// Database Connection
$pdo = null;
try {
    $paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/../env.php'];
    $envLoaded = false;
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $envLoaded = true; break; } }
    
    if (!$envLoaded) {
        throw new Exception("Env file not found");
    }
    
    if (defined('DB_HOST')) {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } else {
        throw new Exception("Config missing from env");
    }
} catch (Exception $e) {
    http_response_code(500);
    returnJson(['error' => 'DB Connection failed: ' . $e->getMessage()]);
}

// Helper to find User ID in session
function resolveUserId() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    if (isset($_SESSION['user'])) {
        $u = $_SESSION['user'];
        if (is_array($u)) {
            foreach ($candidates as $k) if (isset($u[$k]) && is_numeric($u[$k])) return (int)$u[$k];
        } elseif (is_object($u)) {
            foreach ($candidates as $k) if (isset($u->$k) && is_numeric($u->$k)) return (int)$u->$k;
        }
    }
    return 0;
}

// Get User ID and Role
$userId = resolveUserId();
// Fetch role to be sure
$stmtUser = $pdo->prepare("SELECT role, username FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$userRow = $stmtUser->fetch();
if (!$userRow) {
    returnJson(['error' => 'User not found in DB (ID resolved: ' . $userId . ')']);
}
$role = $userRow['role']; 

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// --- ACTION: CREATE (POST) ---
if ($action === 'create' && $method === 'POST') {
    $subject = $_POST['subject'] ?? '';
    $description = $_POST['description'] ?? '';
    $priority = $_POST['priority'] ?? 'medium';
    
    if (empty($subject)) returnJson(['error' => 'Předmět je povinný']);
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO changerequest_log (user_id, subject, description, priority, attachment_path) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $subject, $description, $priority, null]);
        $reqId = $pdo->lastInsertId();

        // Log history
        logHistory($pdo, $reqId, $userId, 'created', '', 'Request created');

        $uploadDir = __DIR__ . '/uploads/changerequests';
        if (!file_exists($uploadDir)) { if (!mkdir($uploadDir, 0777, true)) { /* error */ } }

        $uploadedPaths = [];
        
        // Helper
        $processFile = function($file, $reqId) use ($pdo, $uploadDir, &$uploadedPaths, $userId) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                logHistory($pdo, $reqId, $userId, 'debug', '', "File upload error: " . $file['error'] . " for " . $file['name']);
                return false;
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'log', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'xml', 'zip', 'rar', '7z'];
            if (!in_array($ext, $allowed)) {
                logHistory($pdo, $reqId, $userId, 'debug', '', "Extension not allowed: $ext for " . $file['name']);
                return false;
            }

            $filename = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $file['name']);
            $dest = $uploadDir . '/' . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $path = 'uploads/changerequests/' . $filename;
                $stmtAtt = $pdo->prepare("INSERT INTO changerequest_attachments (request_id, file_path, filename, filesize) VALUES (?, ?, ?, ?)");
                $stmtAtt->execute([$reqId, $path, $file['name'], $file['size']]);
                $uploadedPaths[] = $path;
                return true;
            } else {
                logHistory($pdo, $reqId, $userId, 'debug', '', "Failed to move file to $dest");
                return false;
            }
        };

        // Detect and process ALL files in $_FILES
        foreach ($_FILES as $key => $fileData) {
            if (is_array($fileData['name'])) {
                // Multiple files structure: name => [0 => 'f1', 1 => 'f2']
                $count = count($fileData['name']);
                for ($i = 0; $i < $count; $i++) {
                    $f = [
                        'name'     => $fileData['name'][$i],
                        'type'     => $fileData['type'][$i],
                        'tmp_name' => $fileData['tmp_name'][$i],
                        'error'    => $fileData['error'][$i],
                        'size'     => $fileData['size'][$i]
                    ];
                    $processFile($f, $reqId);
                }
            } else {
                // Single file structure
                $processFile($fileData, $reqId);
            }
        }

        // Log count of uploaded files for debugging
        if (count($uploadedPaths) > 0) {
             logHistory($pdo, $reqId, $userId, 'debug', '', "Files detected: " . count($uploadedPaths));
             // Backward compatibility with legacy attachment_path
             $pdo->prepare("UPDATE changerequest_log SET attachment_path = ? WHERE id = ?")->execute([$uploadedPaths[0], $reqId]);
        }

        $pdo->commit();
        returnJson(['success' => true, 'id' => $reqId]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        returnJson(['error' => 'DB Insert Error: ' . $e->getMessage()]);
    }
}

// --- ACTION: DELETE_ATTACHMENT (POST) ---
if ($action === 'delete_attachment' && $method === 'POST') {
    if ($role !== 'admin') returnJson(['error' => 'Unauthorized']);
    $attId = (int)($_POST['id'] ?? 0);
    if (!$attId) returnJson(['error' => 'Missing attachment ID']);
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM changerequest_attachments WHERE id = ?");
        $stmt->execute([$attId]);
        $att = $stmt->fetch();
        if (!$att) returnJson(['error' => 'Attachment not found']);
        
        $reqId = $att['request_id'];
        $filePath = __DIR__ . '/' . $att['file_path'];
        
        $pdo->prepare("DELETE FROM changerequest_attachments WHERE id = ?")->execute([$attId]);
        if (file_exists($filePath)) unlink($filePath);
        
        logHistory($pdo, $reqId, $userId, 'attachment_deleted', $att['filename'], 'Příloha smazána');
        
        returnJson(['success' => true]);
    } catch (Exception $e) { returnJson(['error' => $e->getMessage()]); }
}

// --- ACTION: LIST_ATTACHMENTS (GET) ---
if ($action === 'list_attachments') {
    $reqId = (int)($_GET['request_id'] ?? 0);
    if (!$reqId) returnJson(['error' => 'Missing request_id']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM changerequest_attachments WHERE request_id = ? ORDER BY created_at DESC");
        $stmt->execute([$reqId]);
        returnJson(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) { returnJson(['error' => $e->getMessage()]); }
}

// --- ACTION: UPLOAD_ATTACHMENT (POST) ---
if ($action === 'upload_attachment' && $method === 'POST') {
    $reqId = (int)($_POST['request_id'] ?? 0);
    if (!$reqId) returnJson(['error' => 'Missing request_id']);

    $uploadDir = __DIR__ . '/uploads/changerequests';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

    $uploadedPaths = [];
    $processFile = function($file, $reqId) use ($pdo, $uploadDir, &$uploadedPaths, $userId) {
        if ($file['error'] !== UPLOAD_ERR_OK) return false;
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'log', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'xml', 'zip', 'rar', '7z'];
        if (!in_array($ext, $allowed)) return false;

        $filename = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $file['name']);
        $dest = $uploadDir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $dest)) {
            $path = 'uploads/changerequests/' . $filename;
            $stmtAtt = $pdo->prepare("INSERT INTO changerequest_attachments (request_id, file_path, filename, filesize) VALUES (?, ?, ?, ?)");
            $stmtAtt->execute([$reqId, $path, $file['name'], $file['size']]);
            $uploadedPaths[] = $path;
            logHistory($pdo, $reqId, $userId, 'attachment', '', $file['name']);
            return true;
        }
        return false;
    };

    if (!empty($_FILES)) {
        foreach ($_FILES as $key => $fileData) {
            if (is_array($fileData['name'])) {
                $count = count($fileData['name']);
                for ($i = 0; $i < $count; $i++) {
                    $f = [
                        'name'     => $fileData['name'][$i],
                        'type'     => $fileData['type'][$i],
                        'tmp_name' => $fileData['tmp_name'][$i],
                        'error'    => $fileData['error'][$i],
                        'size'     => $fileData['size'][$i]
                    ];
                    $processFile($f, $reqId);
                }
            } else {
                $processFile($fileData, $reqId);
            }
        }
        returnJson(['success' => true, 'count' => count($uploadedPaths)]);
    } else {
        returnJson(['error' => 'No files sent']);
    }
}

// --- ACTION: GET_HISTORY (GET) ---
if ($action === 'get_history') {
    $reqId = $_GET['request_id'] ?? 0;
    if (!$reqId) returnJson(['error' => 'Missing request_id']);

    try {
        $stmt = $pdo->prepare("SELECT * FROM changerequest_history WHERE request_id = ? ORDER BY created_at DESC");
        $stmt->execute([$reqId]);
        returnJson(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        returnJson(['error' => $e->getMessage()]);
    }
}

// --- ACTION: LIST_USERS (GET) ---
if ($action === 'list_users' && $role === 'admin') {
    try {
        $stmt = $pdo->query("SELECT id, username FROM users ORDER BY username ASC");
        returnJson(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        returnJson(['error' => $e->getMessage()]);
    }
}

// --- ACTION: LIST (GET) ---
if ($action === 'list') {
    $viewAll = ($role === 'admin' && isset($_GET['view']) && $_GET['view'] === 'all');
    try {
        if ($viewAll) {
            $sql = "SELECT c.*, u.username, au.username as assigned_username 
                   FROM changerequest_log c 
                   JOIN users u ON c.user_id = u.id 
                   LEFT JOIN users au ON c.assigned_to = au.id
                   ORDER BY field(c.status, 'New', 'New feature', 'Analysis', 'Development', 'Back to development', 'Testing', 'Testing AI', 'Done', 'Duplicity', 'Canceled'), c.created_at DESC";
            $stmt = $pdo->query($sql);
        } else {
            $sql = "SELECT c.*, u.username, au.username as assigned_username 
                   FROM changerequest_log c 
                   JOIN users u ON c.user_id = u.id 
                   LEFT JOIN users au ON c.assigned_to = au.id
                   WHERE c.assigned_to = ? 
                   ORDER BY c.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
        }
        $data = $stmt->fetchAll();
        returnJson(['success' => true, 'data' => $data, 'role' => $role]);
    } catch (Exception $e) {
        returnJson(['error' => $e->getMessage()]);
    }
}

// --- ACTION: UPDATE (POST/PUT) ---
if ($action === 'update' && ($method === 'POST' || $method === 'PUT')) {
    if ($role !== 'admin') returnJson(['error' => 'Unauthorized']);
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    
    $id = $input['id'] ?? null;
    if (!$id) returnJson(['error' => 'ID required']);

    // Fetch current state for logging
    $currStmt = $pdo->prepare("SELECT * FROM changerequest_log WHERE id = ?");
    $currStmt->execute([$id]);
    $curr = $currStmt->fetch();
    if (!$curr) returnJson(['error' => 'Request not found']);
    
    $updates = [];
    $params = [];
    
    // Status
    if (isset($input['status'])) {
         $statuses = ['New', 'New feature', 'Analysis', 'Development', 'Back to development', 'Testing', 'Testing AI', 'Done', 'Duplicity', 'Canceled'];
         if (in_array($input['status'], $statuses)) {
             $updates[] = "status = ?";
             $params[] = $input['status'];
         }
    }
    
    // Assignee
    if (isset($input['assigned_to'])) {
        $updates[] = "assigned_to = ?";
        $uid = (int)$input['assigned_to'];
        $params[] = $uid > 0 ? $uid : null;
    }
    
    // Subject
    if (isset($input['subject']) && !empty($input['subject'])) {
        $updates[] = "subject = ?";
        $params[] = $input['subject'];
    }
    
    // Desc
    if (isset($input['description'])) {
        $updates[] = "description = ?";
        $params[] = $input['description'];
    }
    
    // Admin Notes
    if (isset($input['admin_notes'])) {
        $updates[] = "admin_notes = ?";
        $params[] = $input['admin_notes'];
    }
    
    if (empty($updates)) returnJson(['error' => 'No fields to update']);
    
    $updates[] = "updated_at = NOW()";

    try {
        $sql = "UPDATE changerequest_log SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $id;
        
        $pdo->prepare($sql)->execute($params);

        // LOGGING
        if (isset($input['status']) && $input['status'] !== $curr['status']) {
            logHistory($pdo, $id, $userId, 'status', $curr['status'], $input['status']);
        }
        if (isset($input['assigned_to'])) {
            $newAssigned = (int)$input['assigned_to'] > 0 ? (int)$input['assigned_to'] : null;
            if ($newAssigned != $curr['assigned_to']) {
                $oldU = $curr['assigned_to'] ? 'UserID:'.$curr['assigned_to'] : 'Unassigned';
                $newU = $newAssigned ? 'UserID:'.$newAssigned : 'Unassigned';
                
                // Get username for better log
                if ($newAssigned) {
                   $res = $pdo->query("SELECT username FROM users WHERE id = $newAssigned")->fetchColumn();
                   if ($res) $newU = $res;
                }
                if ($curr['assigned_to']) {
                   $res = $pdo->query("SELECT username FROM users WHERE id = {$curr['assigned_to']}")->fetchColumn();
                   if ($res) $oldU = $res;
                }

                logHistory($pdo, $id, $userId, 'assignee', $oldU, $newU);
            }
        }
        if (isset($input['description']) && $input['description'] !== $curr['description']) {
            logHistory($pdo, $id, $userId, 'description', '(text)', 'Description updated');
        }
        if (isset($input['subject']) && $input['subject'] !== $curr['subject']) {
            logHistory($pdo, $id, $userId, 'subject', $curr['subject'], $input['subject']);
        }
        
        // Return updated assignee name
        $assignedName = null;
        if (isset($input['assigned_to']) && $input['assigned_to'] > 0) {
            $uStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $uStmt->execute([$input['assigned_to']]);
            $assignedName = $uStmt->fetchColumn();
        }
        
        returnJson(['success' => true, 'assigned_username' => $assignedName]);
    } catch (Exception $e) {
        returnJson(['error' => $e->getMessage()]);
    }
}

// --- ACTION: UPLOAD_CONTENT_IMAGE (POST) ---
if ($action === 'upload_content_image' && $method === 'POST') {
    $uploadDir = __DIR__ . '/uploads/content';
    if (!file_exists($uploadDir)) { if (!mkdir($uploadDir, 0777, true)) returnJson(['error' => 'Failed to create dir']); }

    if (!isset($_FILES['image'])) returnJson(['error' => 'No image sent']);
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) returnJson(['error' => 'Upload failed']);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) returnJson(['error' => 'Invalid file type']);

    $filename = time() . '_' . uniqid() . '.' . $ext;
    $dest = $uploadDir . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        returnJson(['success' => true, 'url' => 'uploads/content/' . $filename]);
    } else {
        returnJson(['error' => 'Failed to move file']);
    }
}

// --- ACTION: UPDATE_PRIORITY (POST) ---
if ($action === 'update_priority' && $method === 'POST') {
    if ($role !== 'admin') returnJson(['error' => 'Unauthorized']);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    
    $id = $input['id'] ?? null;
    $priority = $input['priority'] ?? null;
    if (!$id || !$priority) returnJson(['error' => 'Data required']);
    
    $priorities = ['low', 'medium', 'high'];
    if (!in_array($priority, $priorities)) returnJson(['error' => 'Invalid priority']);

    // Log logic checks current state first
    $currStmt = $pdo->prepare("SELECT * FROM changerequest_log WHERE id = ?");
    $currStmt->execute([$id]);
    $curr = $currStmt->fetch();

    try {
        $stmt = $pdo->prepare("UPDATE changerequest_log SET priority = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$priority, $id]);
        
        if ($curr && $curr['priority'] !== $priority) {
            logHistory($pdo, $id, $userId, 'priority', $curr['priority'], $priority);
        }

        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['error' => $e->getMessage()]);
    }
}

returnJson(['error' => 'Invalid action']);
