<?php
// api-translations.php
// Vrací JSON s překlady pro daný jazyk

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

// Zjistíme jazyk z GET parametru
$lang = $_GET['lang'] ?? 'cs';
if (!in_array($lang, ['cs', 'en'])) {
    $lang = 'cs';
}

try {
    $pdo = get_pdo();

    // Vybereme překlady pro daný jazyk
    // V tabulce v init_broker.php používáme 'lang'
    $stmt = $pdo->prepare("SELECT label_key, translation FROM translations WHERE lang = ?");
    $stmt->execute([$lang]);
    
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[$row['label_key']] = $row['translation'];
    }

    // Pokud je prázdno, zkusíme aspoň základní fallback
    if (empty($result)) {
        $result = [
            "loading" => "Načítám...",
            "login" => "Přihlásit se",
            "register" => "Registrace"
        ];
    }

    echo json_encode([
        'success' => true,
        'lang' => $lang,
        'translations' => $result
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
