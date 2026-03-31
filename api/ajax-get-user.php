<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

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
    return null;
}

function resolveName() {
    if (isset($_SESSION['user_name'])) return $_SESSION['user_name'];
    if (isset($_SESSION['username'])) return $_SESSION['username'];
    if (isset($_SESSION['name'])) return $_SESSION['name'];
    
    if (isset($_SESSION['user'])) {
        $u = $_SESSION['user'];
        if (is_array($u)) {
            if (isset($u['username'])) return $u['username'];
            if (isset($u['name'])) return $u['name'];
            if (isset($u['login'])) return $u['login'];
        } elseif (is_object($u)) {
            if (isset($u->username)) return $u->username;
            if (isset($u->name)) return $u->name;
            if (isset($u->login)) return $u->login;
        }
    }
    return 'User';
}

function resolveRole() {
    // Check direct session keys
    if (isset($_SESSION['role'])) return $_SESSION['role'];
    if (isset($_SESSION['user_role'])) return $_SESSION['user_role'];
    
    // Check in user object
    if (isset($_SESSION['user'])) {
        $u = $_SESSION['user'];
        if (is_array($u)) {
            if (isset($u['role'])) return $u['role'];
            if (isset($u['user_role'])) return $u['user_role'];
        } elseif (is_object($u)) {
            if (isset($u->role)) return $u->role;
            if (isset($u->user_role)) return $u->user_role;
        }
    }
    
    // Fallback: check if user is admin by ID (ID 1 = admin, or check database)
    $userId = resolveUserId();
    if ($userId) {
        // Load from database if available
        $envPaths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/../env.php'];
        foreach ($envPaths as $p) {
            if (file_exists($p)) {
                require_once $p;
                try {
                    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
                    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && isset($row['role'])) {
                        return $row['role'];
                    }
                } catch (Exception $e) {
                    // Fallback if DB query fails
                }
                break;
            }
        }
    }
    
    return 'user'; // Default role
}

$id = resolveUserId();
$name = resolveName();
$role = resolveRole();

// Initials
$parts = explode(' ', $name);
$initials = '';
if(count($parts) >= 2) {
    $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
} else {
    $initials = strtoupper(substr($name, 0, 2));
}

$assignedCount = 0;
if ($id) {
    // We already have $pdo if resolveRole connected to DB, but let's ensure it.
    if (!isset($pdo)) {
        $envPaths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/../env.php'];
        foreach ($envPaths as $p) {
            if (file_exists($p)) {
                require_once $p;
                try {
                    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
                } catch (Exception $e) { }
                break;
            }
        }
    }
    if (isset($pdo)) {
        try {
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM changerequest_log WHERE assigned_to = ? AND status NOT IN ('Done', 'Canceled', 'Duplicity')");
            $stmtCount->execute([$id]);
            $assignedCount = (int)$stmtCount->fetchColumn();
        } catch (Exception $e) { }
    }
}

echo json_encode([
    'success' => !!$id,
    'user' => [
        'id' => $id,
        'name' => $name,
        'role' => $role,
        'initials' => $initials,
        'assigned_tasks_count' => $assignedCount
    ]
]);

