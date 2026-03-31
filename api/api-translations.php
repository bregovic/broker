<?php
// api-translations.php
// Vrací JSON s překlady pro daný jazyk

header('Content-Type: application/json; charset=utf-8');

// 1. Konfigurace a DB
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

// Zjistíme jazyk z GET parametru, nebo session, nebo default 'cs'
$lang = $_GET['lang'] ?? 'cs';
if (!in_array($lang, ['cs', 'en'])) {
    $lang = 'cs';
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Vybereme překlady pro daný jazyk z nové struktury
    $stmt = $pdo->prepare("SELECT label_key, translation FROM translations WHERE language = ?");
    $stmt->execute([$lang]);
    
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[$row['label_key']] = $row['translation'];
    }

    echo json_encode([
        'success' => true,
        'lang' => $lang,
        'translations' => $result
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
