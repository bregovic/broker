<?php
// ajax-toggle-watch.php
// Přepíná stav 'track_history' (sledování) u titulu

header('Content-Type: application/json; charset=utf-8');
// Robustní připojení k DB
$paths = [__DIR__.'/../env.local.php', __DIR__.'/env.local.php', __DIR__.'/php/env.local.php', '../env.local.php', 'php/env.local.php'];
foreach($paths as $p) { if(file_exists($p)) { require_once $p; break; } }
if (!defined('DB_HOST')) {
    $bootstrapCandidates = ['db.php','config/db.php','includes/db.php'];
    foreach ($bootstrapCandidates as $inc) {
        $p = __DIR__ . DIRECTORY_SEPARATOR . $inc;
        if (file_exists($p)) { require_once $p; break; }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$ticker = $input['ticker'] ?? '';
$state = $input['state'] ?? 0; // 1 = watch, 0 = unwatch

if (empty($ticker)) {
    echo json_encode(['success' => false, 'message' => 'Ticker missing']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $stmt = $pdo->prepare("UPDATE broker_live_quotes SET track_history = ? WHERE id = ?");
    $stmt->execute([$state ? 1 : 0, $ticker]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
