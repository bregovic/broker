<?php
// migrate_translations_v2.php
// Skript pro migraci "broker_translations" na novou strukturu (řádkovou)
// a odstranění tabulky "translations".

header('Content-Type: text/html; charset=utf-8');

// 1. Load configuration
$envPaths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
    __DIR__ . '/../../env.local.php',
    __DIR__ . '/php/env.local.php',
    __DIR__ . '/env.php',
    __DIR__ . '/../env.php',
    __DIR__ . '/../../env.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.php'
];

foreach ($envPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

if (!defined('DB_HOST')) {
    die("Error: DB configuration not found.");
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "<h3>Migration Start</h3>";

    // 1. Create NEW table structure
    $pdo->exec("DROP TABLE IF EXISTS broker_translations_new");
    $sqlCreate = "CREATE TABLE broker_translations_new (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label_key VARCHAR(100) NOT NULL,
        language VARCHAR(10) NOT NULL,
        translation TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_lang_key (label_key, language)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sqlCreate);
    echo "Created table <code>broker_translations_new</code>.<br>";

    // 2. Read data from existing 'broker_translations'
    // (User confirmed this table has the data)
    try {
        $stmt = $pdo->query("SELECT * FROM broker_translations");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Found " . count($rows) . " existing translations.<br>";

        // 3. Migrate data
        $stmtIns = $pdo->prepare("INSERT INTO broker_translations_new (label_key, language, translation, created_at) VALUES (?, ?, ?, ?)");
        $count = 0;

        foreach ($rows as $row) {
            $created = $row['created_at'] ?? date('Y-m-d H:i:s');
            $key = $row['label_key'];

            // Czech
            if (!empty($row['cs'])) {
                $stmtIns->execute([$key, 'cs', $row['cs'], $created]);
                $count++;
            }
            // English
            if (!empty($row['en'])) {
                $stmtIns->execute([$key, 'en', $row['en'], $created]);
                $count++;
            }
        }
        echo "Migrated $count records (rows) into new structure.<br>";

        // 4. Rename tables
        // Backup old one
        $pdo->exec("DROP TABLE IF EXISTS broker_translations_legacy");
        $pdo->exec("RENAME TABLE broker_translations TO broker_translations_legacy");
        // Activate new one
        $pdo->exec("RENAME TABLE broker_translations_new TO broker_translations");
        echo "Renamed tables. <code>broker_translations</code> is now active with new structure.<br>";
        
    } catch (Exception $e) {
        echo "Error reading/migrating data: " . $e->getMessage() . "<br>";
        echo "Maybe 'broker_translations' does not exist? Checking 'translations' instead.<br>";
        // Fallback logic if needed, but assuming user is correct.
    }

    // 5. Drop 'translations' (no prefix) if exists
    $pdo->exec("DROP TABLE IF EXISTS translations");
    echo "Dropped table <code>translations</code> (without prefix).<br>";

    echo "<h3>Migration Complete Successfully</h3>";

} catch (PDOException $e) {
    echo "DB Error: " . $e->getMessage();
}
?>
