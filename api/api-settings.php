<?php
// api-settings.php
// Ukládá/Načítá nastavení uživatele (jazyk, theme, base_currency)

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

session_start();

function resolveUserId() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    return 1; // Fallback for dev
}

try {
    $pdo = get_pdo();
    $userId = resolveUserId();

    // POST: Save settings
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $lang = $input['language'] ?? $input['lang'] ?? 'cs';
        $theme = $input['theme'] ?? 'dark';
        $baseCurrency = $input['base_currency'] ?? 'CZK';

        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, lang, theme, base_currency) VALUES (?, ?, ?, ?) 
                               ON CONFLICT (user_id) DO UPDATE SET lang = EXCLUDED.lang, theme = EXCLUDED.theme, base_currency = EXCLUDED.base_currency");
        $stmt->execute([$userId, $lang, $theme, $baseCurrency]);

        echo json_encode(['success' => true]);
    }
    // GET: Load settings
    else {
        $stmt = $pdo->prepare("SELECT lang, theme, base_currency FROM user_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            echo json_encode(['success' => true, 'settings' => $row]);
        } else {
            echo json_encode(['success' => true, 'settings' => ['lang' => 'cs', 'theme' => 'dark', 'base_currency' => 'CZK']]);
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
