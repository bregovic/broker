<?php
/**
 * 1. Sets up the 'changerequest_history' table for audit logging.
 * 2. Adds today's development achievements to 'development_history'.
 */
header("Cache-Control: no-cache");
header("Content-Type: text/plain; charset=utf-8");

// Load env
$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) die("env not found");

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. CREATE AUDIT LOG TABLE
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS changerequest_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            user_id INT NULL,
            username VARCHAR(100),
            change_type VARCHAR(50) NOT NULL, -- 'status', 'priority', 'assignee', 'description', 'created'
            old_value TEXT,
            new_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_req (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Table 'changerequest_history' created/verified.\n";

    // 2. ADD TODAY'S ENTRIES TO GLOBAL HISTORY
    $todayUpdates = [
        ['2024-12-18', 'Redesign Správy požadavků', 'Kompletní přepracování UI, nové rozložení detailu, moderní vzhled, integrace Fluent UI.', 'improvement', null],
        ['2024-12-18', 'Inline obrázky', 'Podpora vkládání screenshotů (Ctrl+V) přímo do komentářů a popisu požadavku. Markdown + Live Preview.', 'feature', null],
        ['2024-12-18', 'Editace popisu požadavku', 'Možnost upravovat popis existujícího požadavku s podporou formátování.', 'feature', null],
        ['2024-12-18', 'Přiřazování řešitelů', 'Přidána funkce pro přiřazení požadavku konkrétnímu uživateli (Assignee).', 'feature', null],
        ['2024-12-18', 'Audit Log požadavků', 'Automatické zaznamenávání historie změn (stav, priorita, řešitel) u jednotlivých požadavků.', 'feature', null]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO development_history (date, title, description, category, related_task_id) 
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE description = VALUES(description)
    ");

    foreach ($todayUpdates as $entry) {
        $stmt->execute($entry);
    }
    echo "✅ Added " . count($todayUpdates) . " entries to development_history.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
