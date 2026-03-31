<?php
header("Content-Type: text/plain; charset=utf-8");
$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) die("env not found");

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "--- ATTACHMENTS FOR REQ #19 ---\n";
    $stmt = $pdo->prepare("SELECT * FROM changerequest_attachments WHERE request_id = 19");
    $stmt->execute();
    $atts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($atts);

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
