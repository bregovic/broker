<?php
/**
 * Broker / Investyx 2.0 - Railway CLI Cron Task
 * 
 * Tento skript je určen výhradně pro spouštění z příkazové řádky (CLI).
 * Zajišťuje automatickou aktualizaci kurzů měn z ČNB a cen aktivních tickerů z Google Finance.
 * Ukládá logy o spuštění do databáze a volitelně podporuje reporty na Discord přes Webhook.
 * 
 * Spuštění:
 *   php api/cron-task.php rates   - Aktualizace kurzů ČNB
 *   php api/cron-task.php prices  - Aktualizace cen akcií
 */

// 1. Bezpečnostní kontrola - povolit pouze CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: Tento skript lze spustit pouze z příkazové řádky (CLI).\n";
    exit(1);
}

// Zvýšení limitů pro dlouhé importy
set_time_limit(900); // 15 minut
ini_set('memory_limit', '512M');

// 2. Připojení databáze a načtení konfigurace
$baseDir = __DIR__;
require_once $baseDir . '/config.php';

// Získání parametru akce
$action = $argv[1] ?? '';
if (!in_array($action, ['rates', 'prices'])) {
    echo "Chyba: Neplatný parametr. Použití:\n";
    echo "  php api/cron-task.php rates   - pro aktualizaci kurzů ČNB\n";
    echo "  php api/cron-task.php prices  - pro aktualizaci cen akcií\n";
    exit(1);
}

try {
    $pdo = get_pdo();
    // Vytvoříme logovací tabulku, pokud neexistuje
    ensureCronLogsTableExists($pdo);
} catch (Exception $e) {
    echo "Chyba DB: " . $e->getMessage() . "\n";
    exit(1);
}

// Spuštění vybrané akce
if ($action === 'rates') {
    runRatesImport($pdo);
} elseif ($action === 'prices') {
    runPricesUpdate($pdo);
}

/**
 * -----------------------------------------------------------------------------
 * 1. IMPORT KURZŮ ČNB
 * -----------------------------------------------------------------------------
 */
function runRatesImport(PDO $pdo) {
    echo "[Cron] Spouštím import kurzů z ČNB...\n";
    $startTime = microtime(true);
    
    $ymd = date('Y-m-d');
    $dmy = date('d.m.Y');
    
    $ins = 0;
    $upd = 0;
    $tried = [];
    $success = false;
    $sourceUsed = '';
    
    // Zkusíme nejdřív XML verzi
    $xmlUrls = [
        'https://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy_devizoveho_trhu/denni_kurz.xml?date=' . $dmy,
        'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/denni_kurz.xml?date=' . $dmy,
        'http://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy-devizoveho-trhu/denni_kurz.xml?date=' . $dmy,
    ];
    
    foreach ($xmlUrls as $url) {
        list($body, $info, $err) = httpGetRequest($url);
        $tried[] = ['url' => $url, 'http_code' => $info['http_code'] ?? 0, 'err' => $err];
        
        if ($body !== false && ($info['http_code'] ?? 0) < 400) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            if ($xml === false) {
                // Konverze kódování pro případné Win-1250 potíže
                $body2 = @mb_convert_encoding($body, 'UTF-8', 'Windows-1250,ISO-8859-2,UTF-8');
                $xml = simplexml_load_string($body2);
            }
            
            if ($xml !== false) {
                // Dynamicky získáme přesné datum platnosti kurzů přímo z XML ČNB
                $dateAttr = (string)$xml['datum'];
                if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $dateAttr, $m)) {
                    $ymd = "{$m[3]}-{$m[2]}-{$m[1]}";
                }
                
                $rows = $xml->xpath('//radek');
                if ($rows && count($rows) > 0) {
                    // Vložíme základní CZK
                    upsertRate($pdo, $ymd, 'CZK', 1, 1.0, 'CNB', $ins, $upd);
                    
                    foreach ($rows as $row) {
                        $code = (string)$row['kod'];
                        $amount = (int)$row['mnozstvi'];
                        $rate = (float)str_replace(',', '.', (string)$row['kurz']);
                        if (!$code || $amount <= 0 || $rate <= 0) continue;
                        upsertRate($pdo, $ymd, $code, $amount, $rate, 'CNB', $ins, $upd);
                    }
                    $success = true;
                    $sourceUsed = 'CNB XML';
                    break;
                }
            }
        }
    }
    
    // Pokud XML selhalo, zkusíme TXT verzi jako fallback
    if (!$success) {
        echo "[Cron] XML verze selhala, zkouším TXT fallback...\n";
        $txtUrls = [
            'https://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy_devizoveho_trhu/denni_kurz.txt?date=' . $dmy,
            'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/denni_kurz.txt?date=' . $dmy,
            'http://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy-devizoveho-trhu/denni_kurz.txt?date=' . $dmy,
        ];
        
        foreach ($txtUrls as $url) {
            list($body, $info, $err) = httpGetRequest($url);
            $tried[] = ['url' => $url, 'http_code' => $info['http_code'] ?? 0, 'err' => $err];
            
            if ($body !== false && ($info['http_code'] ?? 0) < 400) {
                $lines = preg_split('/\r\n|\r|\n/', trim($body));
                if (count($lines) >= 3) {
                    // Dynamicky získáme datum z prvního řádku TXT
                    if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $lines[0], $m)) {
                        $ymd = "{$m[3]}-{$m[2]}-{$m[1]}";
                    }
                    
                    $rows = array_slice($lines, 2);
                    upsertRate($pdo, $ymd, 'CZK', 1, 1.0, 'CNB', $ins, $upd);
                    
                    foreach ($rows as $line) {
                        if (!trim($line)) continue;
                        $parts = explode('|', $line);
                        if (count($parts) < 5) continue;
                        $amount = (int)trim($parts[2]);
                        $code = trim($parts[3]);
                        $rate = (float)str_replace(',', '.', trim($parts[4]));
                        if (!$code || $amount <= 0 || $rate <= 0) continue;
                        upsertRate($pdo, $ymd, $code, $amount, $rate, 'CNB', $ins, $upd);
                    }
                    $success = true;
                    $sourceUsed = 'CNB TXT';
                    break;
                }
            }
        }
    }
    
    $elapsed = round(microtime(true) - $startTime, 2);
    
    if ($success) {
        $msg = "💱 **Import kurzů ČNB úspěšně dokončen**\n";
        $msg .= "• **Zdroj:** {$sourceUsed}\n";
        $msg .= "• **Datum:** {$ymd}\n";
        $msg .= "• **Vloženo nově:** {$ins}\n";
        $msg .= "• **Aktualizováno:** {$upd}\n";
        $msg .= "• **Doba běhu:** {$elapsed} s";
        
        echo "[Cron] Import úspěšný: Vloženo {$ins}, Aktualizováno {$upd} za {$elapsed}s\n";
        sendNotification($msg);
        
        // Zápis do DB
        $dbMsg = "Zdroj: {$sourceUsed}, Datum: {$ymd}, Vloženo: {$ins}, Aktualizováno: {$upd}";
        logCronExecution($pdo, 'rates', 'success', $dbMsg, $elapsed);
    } else {
        $msg = "❌ **Import kurzů ČNB selhal!**\n";
        $msg .= "Nepodařilo se stáhnout data z žádného URL.\n";
        $msg .= "• **Zkoušené URL:**\n";
        foreach ($tried as $t) {
            $msg .= "  - `{$t['url']}` (HTTP: {$t['http_code']}, Chyba: {$t['err']})\n";
        }
        
        echo "[Cron] Import selhal!\n";
        sendNotification($msg);
        
        // Zápis do DB
        $dbMsg = "Nepodařilo se stáhnout kurzovní lístek z ČNB. Zkoušené adresy:\n";
        foreach ($tried as $t) {
            $dbMsg .= "- {$t['url']} (HTTP: {$t['http_code']}, Chyba: {$t['err']})\n";
        }
        logCronExecution($pdo, 'rates', 'error', $dbMsg, $elapsed);
        exit(1);
    }
}

/**
 * Pomocný upsert pro kurzy
 */
function upsertRate(PDO $pdo, $date, $code, $amount, $rate, $src, &$ins, &$upd) {
    $s = $pdo->prepare("SELECT rate_id FROM rates WHERE date=? AND currency=? LIMIT 1");
    $s->execute([$date, $code]);
    $id = $s->fetchColumn();
    
    if ($id) {
        $u = $pdo->prepare("UPDATE rates SET rate=?, amount=?, source=?, updated_at=CURRENT_TIMESTAMP WHERE rate_id=?");
        $u->execute([$rate, $amount, $src, $id]);
        $upd++;
    } else {
        $i = $pdo->prepare("INSERT INTO rates (date, currency, rate, amount, source) VALUES (?, ?, ?, ?, ?)");
        $i->execute([$date, $code, $rate, $amount, $src]);
        $ins++;
    }
}

/**
 * -----------------------------------------------------------------------------
 * 2. AKTUALIZACE CEN AKCIÍ
 * -----------------------------------------------------------------------------
 */
function runPricesUpdate(PDO $pdo) {
    echo "[Cron] Spouštím aktualizaci cen akcií...\n";
    $startTime = microtime(true);
    
    // Načteme Google Finance Service
    $serviceFile = __DIR__ . '/googlefinanceservice.php';
    if (!file_exists($serviceFile)) {
        $msg = "❌ **Chyba v Cronu: Soubor `googlefinanceservice.php` nebyl nalezen!**";
        echo $msg . "\n";
        sendNotification($msg);
        logCronExecution($pdo, 'prices', 'error', 'Soubor googlefinanceservice.php nebyl nalezen', 0);
        exit(1);
    }
    
    require_once $serviceFile;
    $service = new GoogleFinanceService($pdo, 0); // TTL 0 = vynutit čerstvá data
    
    // Získáme seznam všech aktivních tickerů (s měnou z transakcí)
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT lq.id, 
                   (SELECT currency FROM transactions WHERE ticker = lq.id LIMIT 1) as tx_currency
            FROM live_quotes lq 
            WHERE lq.status = 'active'
        ");
        $tickerData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $msg = "❌ **Chyba v Cronu při načítání tickerů z DB:**\n" . $e->getMessage();
        echo $msg . "\n";
        sendNotification($msg);
        logCronExecution($pdo, 'prices', 'error', 'Chyba načítání tickerů z DB: ' . $e->getMessage(), 0);
        exit(1);
    }
    
    $total = count($tickerData);
    $updated = 0;
    $failed = 0;
    $failures = [];
    
    echo "[Cron] Nalezeno {$total} aktivních tickerů k aktualizaci.\n";
    
    foreach ($tickerData as $row) {
        $ticker = $row['id'];
        $txCurrency = $row['tx_currency'];
        
        echo "  - Aktualizuji {$ticker}... ";
        
        try {
            // getQuote s forceFresh = true
            $data = $service->getQuote($ticker, true, $txCurrency);
            if ($data) {
                $updated++;
                echo "OK (Cena: " . ($data['current_price'] ?? 'N/A') . " " . ($data['currency'] ?? '') . ")\n";
            } else {
                $failed++;
                $failures[] = "{$ticker} (Nenalezeny žádné data)";
                echo "SELHALO\n";
            }
        } catch (Exception $e) {
            $failed++;
            $failures[] = "{$ticker} (" . $e->getMessage() . ")";
            echo "CHYBA: " . $e->getMessage() . "\n";
        }
        
        // Drobná pauza pro zamezení blokování ze strany Google/Yahoo
        usleep(150000); // 150ms
    }
    
    $elapsed = round(microtime(true) - $startTime, 2);
    
    $msg = "📈 **Aktualizace cen akcií dokončena**\n";
    $msg .= "• **Celkem tickerů:** {$total}\n";
    $msg .= "• **Úspěšně aktualizováno:** ✅ {$updated}\n";
    
    $dbMsg = "Celkem: {$total}, Úspěšně aktualizováno: {$updated}";
    
    if ($failed > 0) {
        $msg .= "• **Chybné tickery:** ❌ {$failed}\n";
        $msg .= "• **Chyby u:**\n";
        
        $dbMsg .= ", Chyby: {$failed}. Chybné tickery:\n";
        // Vypíšeme max prvních 10 chyb, ať nezahltíme zprávu
        $errorList = array_slice($failures, 0, 10);
        foreach ($errorList as $f) {
            $msg .= "  - `{$f}`\n";
            $dbMsg .= "- {$f}\n";
        }
        if (count($failures) > 10) {
            $msg .= "  - *... a " . (count($failures) - 10) . " dalších*\n";
            $dbMsg .= "- ... a " . (count($failures) - 10) . " dalších\n";
        }
    } else {
        $msg .= "• **Chyby:** Žádné! Vše v pořádku. 🎉\n";
        $dbMsg .= ", Vše v pořádku bez chyb.";
    }
    
    $msg .= "• **Doba běhu:** {$elapsed} s";
    
    echo "[Cron] Aktualizace dokončena: {$updated} OK, {$failed} chyb za {$elapsed}s\n";
    sendNotification($msg);
    
    // Zápis do DB (Stav 'error' dáme pouze v případě, že selhaly kompletně všechny tickery)
    $status = ($failed === $total && $total > 0) ? 'error' : 'success';
    logCronExecution($pdo, 'prices', $status, $dbMsg, $elapsed);
}

/**
 * -----------------------------------------------------------------------------
 * LOGOVACÍ FUNKCE (SEBE-LÉČÍCÍ SE TABULKA)
 * -----------------------------------------------------------------------------
 */

/**
 * Ujistí se, že existuje tabulka pro logování úkolů
 */
function ensureCronLogsTableExists(PDO $pdo) {
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
    } catch (Exception $e) {
        error_log("[Cron] Error creating cron_logs table: " . $e->getMessage());
    }
}

/**
 * Zapíše spuštění úlohy do DB
 */
function logCronExecution(PDO $pdo, $action, $status, $message, $duration) {
    try {
        $stmt = $pdo->prepare("INSERT INTO cron_logs (action, status, message, duration) VALUES (?, ?, ?, ?)");
        $stmt->execute([$action, $status, $message, $duration]);
    } catch (Exception $e) {
        error_log("[Cron] Error logging execution: " . $e->getMessage());
    }
}

/**
 * -----------------------------------------------------------------------------
 * POMOCNÉ FUNKCE
 * -----------------------------------------------------------------------------
 */

/**
 * Odešle HTTP GET požadavek
 */
function httpGetRequest($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'PortfolioTracker/1.0',
        CURLOPT_ENCODING => ''
    ]);
    
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    return [$body, $info, $err];
}

/**
 * Odešle zprávu na Discord Webhook
 */
function sendNotification($message) {
    $webhookUrl = getenv('DISCORD_WEBHOOK_URL');
    if (!$webhookUrl) {
        return; // Není nastaven webhook, ignorujeme
    }
    
    $payload = json_encode([
        "content" => $message
    ]);
    
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    
    curl_exec($ch);
    curl_close($ch);
}
?>
