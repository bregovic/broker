<?php
header("Content-Type: text/plain; charset=utf-8");
$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) die("env not found");

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "--- REQUESTS ---\n";
    $reqs = $pdo->query("SELECT id, subject, created_at FROM changerequest_log ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reqs as $r) {
        $attCount = $pdo->query("SELECT count(*) FROM changerequest_attachments WHERE request_id = " . $r['id'])->fetchColumn();
        echo "REQ #{$r['id']}: {$r['subject']} ({$r['created_at']}) | Attachments: $attCount\n";
        if ($attCount > 0) {
            $atts = $pdo->query("SELECT filename, file_path FROM changerequest_attachments WHERE request_id = " . $r['id'])->fetchAll(PDO::FETCH_ASSOC);
            foreach ($atts as $a) {
                echo "  - {$a['filename']} -> {$a['file_path']} (" . (file_exists(__DIR__ . '/' . $a['file_path']) ? "exists on disk" : "FILE MISSING ON DISK") . ")\n";
            }
        }
    }

    echo "\n--- DIRECTORIES ---\n";
    foreach (['uploads', 'uploads/changerequests', 'uploads/content'] as $d) {
        $p = __DIR__ . '/' . $d;
        echo "$d: " . (is_dir($p) ? "DIR" : "NOT DIR") . " | Writable: " . (is_writable($p) ? "YES" : "NO") . "\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
