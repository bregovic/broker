<?php
/**
 * Broker / Investyx 2.0 - Railway CLI Cron Task
 * 
 * Tento skript je určen výhradně pro spouštění z příkazové řádky (CLI).
 * Zajišťuje automatickou aktualizaci kurzů měn z ČNB a cen aktivních tickerů z Google Finance.
 * Podporuje odesílání reportů na Discord přes Webhook.
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
} catch (Exception $e) {
    sendNotification("❌ **Chyba připojení k databázi v Cronu**\nChyba: " . $e->getMessage());
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
    } else {
        $msg = "❌ **Import kurzů ČNB selhal!**\n";
        $msg .= "Nepodařilo se stáhnout data z žádného URL.\n";
        $msg .= "• **Zkoušené URL:**\n";
        foreach ($tried as $t) {
            $msg .= "  - `{$t['url']}` (HTTP: {$t['http_code']}, Chyba: {$t['err']})\n";
        }
        
        echo "[Cron] Import selhal!\n";
        sendNotification($msg);
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
    
    if ($failed > 0) {
        $msg .= "• **Chybné tickery:** ❌ {$failed}\n";
        $msg .= "• **Chyby u:**\n";
        // Vypíšeme max prvních 10 chyb, ať nezahltíme zprávu
        $errorList = array_slice($failures, 0, 10);
        foreach ($errorList as $f) {
            $msg .= "  - `{$f}`\n";
        }
        if (count($failures) > 10) {
            $msg .= "  - *... a " . (count($failures) - 10) . " dalších*\n";
        }
    } else {
        $msg .= "• **Chyby:** Žádné! Vše v pořádku. 🎉\n";
    }
    
    $msg .= "• **Doba běhu:** {$elapsed} s";
    
    echo "[Cron] Aktualizace dokončena: {$updated} OK, {$failed} chyb za {$elapsed}s\n";
    sendNotification($msg);
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
