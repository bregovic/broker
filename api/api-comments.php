<?php
/**
 * API for Change Request Comments
 * GET ?action=list&request_id=X - List comments for a request
 * POST ?action=add&request_id=X - Add new comment (with optional image upload from paste)
 */
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache");

session_start();

$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/../env.php'];
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

function getCurrentUser($pdo) {
    $userId = resolveUserId();
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }
    return ['id' => 0, 'username' => 'System'];
}

function logHistory($pdo, $reqId, $userId, $type, $old, $new) {
    try {
        $un = 'System';
        if ($userId > 0) {
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $un = $stmt->fetchColumn() ?: 'Unknown';
        }
        $sql = "INSERT INTO changerequest_history (request_id, user_id, username, change_type, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$reqId, $userId, $un, $type, (string)$old, (string)$new]);
    } catch (Exception $e) {}
}

$action = $_REQUEST['action'] ?? 'list';
$requestId = (int)($_REQUEST['request_id'] ?? 0);

// For axios POST requests with JSON body
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $_POST = json_decode(file_get_contents('php://input'), true) ?? [];
    if (isset($_POST['request_id']) && !$requestId) $requestId = (int)$_POST['request_id'];
    // Merge into REQUEST
    $_REQUEST = array_merge($_REQUEST, $_POST);
}

if ($action === 'list') {
    if (!$requestId) {
        echo json_encode(['success' => false, 'error' => 'request_id is required']);
        exit;
    }

    $currentUser = getCurrentUser($pdo);

    // Get comments with their attachments
    $stmt = $pdo->prepare("
        SELECT c.*, 
               GROUP_CONCAT(DISTINCT ca.file_path SEPARATOR '||') as attachments,
               (SELECT GROUP_CONCAT(CONCAT(reaction_type, ':', user_id) SEPARATOR '||') 
                FROM changerequest_comment_reactions 
                WHERE comment_id = c.id) as reaction_data
        FROM changerequest_comments c
        LEFT JOIN changerequest_comment_attachments ca ON ca.comment_id = c.id
        WHERE c.request_id = ?
        GROUP BY c.id
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$requestId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse data
    foreach ($comments as &$c) {
        $c['attachments'] = $c['attachments'] ? explode('||', $c['attachments']) : [];
        
        $reactions = [];
        if ($c['reaction_data']) {
            $raw = explode('||', $c['reaction_data']);
            foreach ($raw as $r) {
                list($type, $uid) = explode(':', $r);
                if (!isset($reactions[$type])) $reactions[$type] = [];
                $reactions[$type][] = (int)$uid;
            }
        }
        $c['reactions'] = $reactions;
        $c['user_reactions'] = [];
        foreach ($reactions as $type => $uids) {
            if (in_array($currentUser['id'], $uids)) {
                $c['user_reactions'][] = $type;
            }
        }
        unset($c['reaction_data']);
    }

    echo json_encode(['success' => true, 'data' => $comments]);
    exit;
}

if ($action === 'toggle_reaction') {
    $commentId = (int)($_REQUEST['comment_id'] ?? 0);
    $type = $_REQUEST['type'] ?? ''; // smile, check, cross, heart
    $user = getCurrentUser($pdo);

    if (!$commentId || !$type || $user['id'] === 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid params or not logged in']);
        exit;
    }

    // Toggle logic
    $stmt = $pdo->prepare("SELECT id FROM changerequest_comment_reactions WHERE comment_id = ? AND user_id = ? AND reaction_type = ?");
    $stmt->execute([$commentId, $user['id'], $type]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("DELETE FROM changerequest_comment_reactions WHERE id = ?");
        $stmt->execute([$existing['id']]);
        echo json_encode(['success' => true, 'action' => 'removed']);
    } else {
        $stmt = $pdo->prepare("INSERT INTO changerequest_comment_reactions (comment_id, user_id, reaction_type) VALUES (?, ?, ?)");
        $stmt->execute([$commentId, $user['id'], $type]);
        echo json_encode(['success' => true, 'action' => 'added']);
    }
    exit;
}

if ($action === 'add') {
    if (!$requestId) {
        echo json_encode(['success' => false, 'error' => 'request_id is required']);
        exit;
    }

    $user = getCurrentUser($pdo);
    $comment = trim($_POST['comment'] ?? '');
    
    if (empty($comment) && empty($_FILES['images'])) {
        echo json_encode(['success' => false, 'error' => 'Comment text or image is required']);
        exit;
    }

    // If comment is empty but we have images, set a placeholder
    if (empty($comment)) {
        $comment = '[Obrázek]';
    }

    try {
        $pdo->beginTransaction();

        // Insert comment
        $stmt = $pdo->prepare("
            INSERT INTO changerequest_comments (request_id, user_id, username, comment)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$requestId, $user['id'], $user['username'], $comment]);
        $commentId = $pdo->lastInsertId();

        // Handle image uploads (from paste or file input)
        $uploadedImages = [];
        if (!empty($_FILES['images'])) {
            $uploadDir = __DIR__ . '/uploads/comments/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $files = $_FILES['images'];
            $fileCount = is_array($files['name']) ? count($files['name']) : 1;

            for ($i = 0; $i < $fileCount; $i++) {
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

                if ($error === UPLOAD_ERR_OK) {
                    $ext = pathinfo($name, PATHINFO_EXTENSION) ?: 'png';
                    $newName = $commentId . '_' . $i . '_' . time() . '.' . $ext;
                    $destPath = $uploadDir . $newName;
                    
                    if (move_uploaded_file($tmpName, $destPath)) {
                        $relativePath = 'uploads/comments/' . $newName;
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO changerequest_comment_attachments (comment_id, file_path, file_name)
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$commentId, $relativePath, $name]);
                        $uploadedImages[] = $relativePath;
                    }
                }
            }
        }

        $pdo->commit();

        // Log history entry for the comment
        logHistory($pdo, $requestId, $user['id'], 'comment', '', 'Přidal komentář');

        echo json_encode([
            'success' => true,
            'comment_id' => $commentId,
            'attachments' => $uploadedImages
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
