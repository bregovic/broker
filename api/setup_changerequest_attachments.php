<?php
// setup_changerequest_attachments.php
$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/../env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) { die("env.php not found"); }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "CREATE TABLE IF NOT EXISTS changerequest_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        filesize INT DEFAULT 0,
        filename VARCHAR(255) DEFAULT '',
        INDEX idx_req (request_id)
        -- Foreign key might fail if table engine differs, so we skip explicit FK constraint for simplicity unless strict
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);
    echo "Table changerequest_attachments created/verified. ";
    
    // Migrate existing
    $rows = $pdo->query("SELECT id, attachment_path FROM changerequest_log WHERE attachment_path IS NOT NULL AND attachment_path != ''")->fetchAll();
    $migrated = 0;
    foreach($rows as $r) {
        $count = $pdo->query("SELECT count(*) FROM changerequest_attachments WHERE request_id = " . intval($r['id']))->fetchColumn();
        if ($count == 0) {
            $filename = basename($r['attachment_path']);
            $stmt = $pdo->prepare("INSERT INTO changerequest_attachments (request_id, file_path, filename) VALUES (?, ?, ?)");
            $stmt->execute([$r['id'], $r['attachment_path'], $filename]);
            $migrated++;
        }
    }
    echo "Migrated $migrated old attachments.";

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
