<?php
session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;

if (!$isLoggedIn && !$isAnonymous) {
    header("Location: ../index.html");
    exit;
}

// Zpracování POST requestu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvFile'])) {
    try {
        // DB připojení
        $envPaths = ['../env.local.php', '../env.php', 'php/env.local.php', 'php/env.php'];
        foreach ($envPaths as $path) {
            if (file_exists($path)) { require_once $path; break; }
        }
        
        if (!defined('DB_HOST')) {
            throw new Exception('Chyba DB konfigurace');
        }
        
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        // Čtení CSV
        $csvFile = $_FILES['csvFile']['tmp_name'];
        $csvContent = file_get_contents($csvFile);
        $lines = explode("\n", trim($csvContent));
        
        $imported = 0;
        $errors = 0;
        $pdo->beginTransaction();
        
        // Zpracování řádků (přeskočíme header)
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (!$line) continue;
            
            $parts = str_getcsv($line);
            if (count($parts) >= 4) {
                $rate = floatval($parts[0]);
                $currencyPair = $parts[1];
                $validFrom = $parts[2];
                
                // Extrakce měny (USD z "21.25 USD")
                $currency = preg_replace('/[0-9.\s]/', '', $currencyPair);
                
                // Konverze data M/D/YYYY na YYYY-MM-DD
                if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $validFrom, $matches)) {
                    $validFrom = sprintf('%04d-%02d-%02d', $matches[3], $matches[1], $matches[2]);
                }
                
                if ($rate > 0 && $currency && $validFrom) {
                    $stmt = $pdo->prepare("
                        INSERT INTO broker_exrates (date, currency, rate, amount, source, created_at, updated_at)
                        VALUES (?, ?, ?, 1, 'csv_import', NOW(), NOW())
                        ON DUPLICATE KEY UPDATE 
                        rate = VALUES(rate), source = VALUES(source), updated_at = NOW()
                    ");
                    
                    if ($stmt->execute([$validFrom, strtoupper($currency), $rate])) {
                        $imported++;
                    } else {
                        $errors++;
                    }
                } else {
                    $errors++;
                }
            } else {
                $errors++;
            }
        }
        
        $pdo->commit();
        $message = "Import dokončen! Importováno: $imported kurzů, chyb: $errors";
        $success = true;
        
    } catch (Exception $e) {
        if (isset($pdo)) $pdo->rollBack();
        $message = "Chyba: " . $e->getMessage();
        $success = false;
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Import kurzů</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; text-align: center; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="file"] { width: 100%; padding: 10px; border: 2px dashed #ddd; border-radius: 5px; }
        button { background: #3498db; color: white; padding: 15px 30px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; width: 100%; }
        button:hover { background: #2980b9; }
        .message { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #e7f3ff; color: #004085; border: 1px solid #b3d7ff; padding: 15px; border-radius: 5px; margin-top: 20px; }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #3498db; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 CSV Import kurzů</h1>
        
        <?php if (isset($message)): ?>
            <div class="message <?= $success ? 'success' : 'error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="csvFile">Vyberte CSV soubor:</label>
                <input type="file" name="csvFile" id="csvFile" accept=".csv" required>
            </div>
            
            <button type="submit">🚀 Importovat kurzy</button>
        </form>
        
        <div class="info">
            <strong>Podporovaný formát CSV:</strong><br>
            ExchangeRate,ExchangeRateCurrencyPair,ValidFrom,ValidTo<br><br>
            
            <strong>Příklad:</strong><br>
            21.25,USD,8/4/2025,12/31/2154<br>
            21.57,EUR,8/1/2025,8/3/2025
        </div>
        
        <div class="back-link">
            <a href="rates-admin.php">← Zpět na správu kurzů</a> | 
            <a href="../index_menu.php">Hlavní menu</a>
        </div>
    </div>
</body>
</html>