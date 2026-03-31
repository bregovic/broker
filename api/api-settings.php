<?php
// api-settings.php
// Ukládá/Načítá nastavení uživatele (jazyk, theme...)

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();

    // Hardcoded User ID 1 for now if no auth logic is passed
    // V reálu bychom měli brát $_SESSION['user_id'] nebo podobně
    $userId = 1; 

    // POST: Save settings
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $lang = $input['language'] ?? 'cs';
        $theme = $input['theme'] ?? 'light';

        // PostgreSQL syntax: ON CONFLICT
        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, language, theme) VALUES (?, ?, ?)
                               ON CONFLICT (user_id) DO UPDATE SET language = EXCLUDED.language, theme = EXCLUDED.theme");
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

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
