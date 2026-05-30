<?php
/**
 * Broker / Investyx 2.0 - API Endpoint pro načítání logů automatických úloh (Cron)
 * 
 * Vyžaduje aktivní přihlášení uživatele.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;

if (!$isLoggedIn && !$isAnonymous) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Přístup odmítnut. Nejste přihlášen.']);
    exit;
}

try {
    $pdo = get_pdo();
    $method = $_SERVER['REQUEST_METHOD'];

    // Nejprve se ujistíme, že tabulka existuje (samo-léčící chování)
    // To je užitečné, pokud administraci otevřou dříve, než poprvé proběhne skript na pozadí
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cron_logs (
                id SERIAL PRIMARY KEY,
                action VARCHAR(50) NOT NULL,
                status VARCHAR(20) NOT NULL,
                message TEXT,
                duration DECIMAL(10, 2),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cron_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(50) NOT NULL,
                status VARCHAR(20) NOT NULL,
                message TEXT,
                duration DECIMAL(10, 2),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        }
    } catch (Exception $tblEx) {
        // Ignorujeme, pokud by došlo k potížím při tvorbě (např. chybějící oprávnění)
    }

    if ($method === 'GET') {
        // Načteme posledních 100 logů seřazených sestupně od nejnovějších
        $stmt = $pdo->query("SELECT * FROM cron_logs ORDER BY id DESC LIMIT 100");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Převedeme trvání a ID na správné číselné typy
        foreach ($logs as &$log) {
            $log['id'] = (int)$log['id'];
            $log['duration'] = $log['duration'] !== null ? (float)$log['duration'] : null;
        }

        echo json_encode([
            'success' => true,
            'data' => $logs
        ]);
    } 
    
    elseif ($method === 'DELETE') {
        // Vymažeme celou historii logů
        $pdo->exec("DELETE FROM cron_logs");
        
        echo json_encode([
            'success' => true,
            'message' => 'Historie logů úloh byla úspěšně vymazána.'
        ]);
    } 
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Metoda není povolena.']);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Neočekávaná chyba serveru: ' . $e->getMessage()
    ]);
}
?>
