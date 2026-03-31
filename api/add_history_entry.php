<?php
header("Content-Type: text/plain; charset=utf-8");

$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) die("env not found");

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("
        INSERT INTO development_history (date, title, description, category, related_task_id) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        '2024-12-18',
        'Komentáře k požadavkům',
        'Přidána možnost přidávat komentáře s timestampem a uživatelem, podporuje paste screenshotu (Ctrl+V).',
        'feature',
        null
    ]);
    
    echo "✅ History entry added!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
