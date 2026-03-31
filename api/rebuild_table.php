<?php
// rebuild_table.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/env.local.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    echo "Zahajuji generální opravu tabulky live_quotes...\n\n";

    // 1. Zjistit aktuální strukturu (pro jistotu, abychom neztratili cizí sloupce)
    // Ale my víme, jaké sloupce chceme. Definujeme je napevno, abychom měli pořádek.
    
    // 2. Vytvoření NOVÉ tabulky se správnou definicí
    echo "1. Vytvářím novou tabulku 'live_quotes_new'...\n";
    $pdo->exec("DROP TABLE IF EXISTS live_quotes_new");
    
    // Definice zohledňuje všechny sloupce, které používáme + naše nové analytické
    $createSql = "CREATE TABLE live_quotes_new (
        id VARCHAR(20) NOT NULL,
        source VARCHAR(50) DEFAULT NULL,
        current_price DECIMAL(12,4) DEFAULT NULL,
        previous_close DECIMAL(12,4) DEFAULT NULL,
        open_price DECIMAL(12,4) DEFAULT NULL,
        day_low DECIMAL(12,4) DEFAULT NULL,
        day_high DECIMAL(12,4) DEFAULT NULL,
        week_52_low DECIMAL(12,4) DEFAULT NULL,
        week_52_high DECIMAL(12,4) DEFAULT NULL,
        change_amount DECIMAL(12,4) DEFAULT NULL,
        change_percent DECIMAL(12,4) DEFAULT NULL,
        volume BIGINT DEFAULT NULL,
        avg_volume BIGINT DEFAULT NULL,
        market_cap BIGINT DEFAULT NULL,
        pe_ratio DECIMAL(10,2) DEFAULT NULL,
        dividend_yield DECIMAL(10,2) DEFAULT NULL,
        eps DECIMAL(10,2) DEFAULT NULL,
        beta DECIMAL(10,4) DEFAULT NULL,
        currency VARCHAR(10) DEFAULT 'USD',
        exchange VARCHAR(20) DEFAULT NULL,
        company_name VARCHAR(100) DEFAULT NULL,
        notes TEXT,
        track_history TINYINT(1) DEFAULT 0,
        last_fetched TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) DEFAULT 'active',
        
        -- Analytické sloupce
        all_time_high DECIMAL(12,4) DEFAULT NULL,
        all_time_low DECIMAL(12,4) DEFAULT NULL,
        ema_212 DECIMAL(12,4) DEFAULT NULL,
        resilience_score INT DEFAULT 0,
        
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($createSql);
    echo "   OK.\n";

    // 3. Kopírování dat (Inteligentní)
    echo "2. Přenáším data...\n";
    $oldRows = $pdo->query("SELECT * FROM live_quotes")->fetchAll(PDO::FETCH_ASSOC);
    
    $inserted = 0;
    $skipped = 0;
    
    // Připravíme statement pro insert
    // Uděláme to jednoduše: pro každé ID najdeme ten "nejlepší" řádek z oldRows
    
    $uniqueTickers = [];
    foreach ($oldRows as $r) {
        $tid = strtoupper(trim($r['id']));
        if (!$tid) continue;
        
        // Skóre kvality
        $score = 0;
        if (!empty($r['company_name'])) $score += 10;
        if (!empty($r['current_price'])) $score += 10;
        if (!empty($r['track_history']) && $r['track_history'] == 1) $score += 5;
        if (!empty($r['source']) && $r['source'] != 'manual_import') $score += 2;
        
        $r['quality_score'] = $score;
        
        if (!isset($uniqueTickers[$tid])) {
            $uniqueTickers[$tid] = $r;
        } else {
            // Máme duplicitu, porovnáme skóre
            if ($r['quality_score'] > $uniqueTickers[$tid]['quality_score']) {
                $uniqueTickers[$tid] = $r; // Přepíšeme lepším
            }
        }
    }
    
    // Vložíme unikátní řádky
    foreach ($uniqueTickers as $ticker => $data) {
        // Build INSERT dynamically based on keys that exist in new table
        unset($data['quality_score']); // remove helper
        
        // Ošetření klíčů, které možná neexistují v $data, ale jsou v DB
        // Pro jistotu použijeme prepared statement na známé sloupce z CREATE
        $cols = ['id','source','current_price','previous_close','change_percent','dividend_yield','currency','exchange','company_name','track_history','last_fetched','status','all_time_high','all_time_low','ema_212','resilience_score'];
        
        $sql = "INSERT INTO live_quotes_new (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
        $stmt = $pdo->prepare($sql);
        
        $vals = [];
        foreach ($cols as $c) {
            $vals[] = $data[$c] ?? null;
        }
        
        try {
            $stmt->execute($vals);
            $inserted++;
        } catch (Exception $e) {
            echo "   Chyba insertu pro $ticker: " . $e->getMessage() . "\n";
        }
    }
    
    echo "   Přeneseno $inserted unikátních záznamů.\n";

    // 4. Prohození tabulek
    echo "3. Aktivuji novou tabulku...\n";
    $pdo->exec("DROP TABLE live_quotes"); // Smažeme starou (s duplicitami)
    $pdo->exec("RENAME TABLE live_quotes_new TO live_quotes"); // Přejmenujeme novou
    echo "   OK.\n";
    
    echo "\nHOTOVO! Duplicity jsou pryč a tabulka má Primary Key, takže se už nevrátí.";

} catch (Exception $e) {
    echo "Kritická chyba: " . $e->getMessage();
}
?>
