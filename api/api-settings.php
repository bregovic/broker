<?php
// api-settings.php
// Ukládá/Načítá nastavení uživatele (jazyk, theme...)

header('Content-Type: application/json; charset=utf-8');

// 1. Config logic
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
    if (file_exists($path)) { require_once $path; break; }
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Hardcoded User ID 1 for now if no auth logic is passed
    // V reálu bychom měli brát $_SESSION['user_id'] nebo podobně
    $userId = 1; 

    // POST: Save settings
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $lang = $input['language'] ?? 'cs';
        $theme = $input['theme'] ?? 'light';

        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, language, theme) VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE language = VALUES(language), theme = VALUES(theme)");
        $stmt->execute([$userId, $lang, $theme]);

        echo json_encode(['success' => true]);
    }
    // GET: Load settings
    else {
        $stmt = $pdo->prepare("SELECT language, theme FROM user_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            echo json_encode(['success' => true, 'settings' => $row]);
        } else {
            // Default
            echo json_encode(['success' => true, 'settings' => ['language' => 'cs', 'theme' => 'light']]);
        }
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
