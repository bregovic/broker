<?php
/**
 * Setup Development History table
 * Run once to create the table and populate with initial data
 */
header("Cache-Control: no-cache");
header("Content-Type: text/plain; charset=utf-8");

$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) die("env not found");

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS development_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            category ENUM('feature', 'bugfix', 'improvement', 'refactor', 'deployment') DEFAULT 'feature',
            related_task_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_date (date DESC),
            INDEX idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Table 'development_history' created\n\n";

    // Populate with historical data
    $history = [
        // December 2024 development
        ['2024-12-18', 'P&L statistiky respektují filtr', 'Přidán callback handleFilteredDataChange do PnLPage - statistiky nad gridem se nyní přepočítávají podle filtru.', 'feature', 10],
        ['2024-12-18', 'Zarovnání sloupců vlevo', 'Odstraněno align: right ze všech datových sloupců pro konzistentní levé zarovnání.', 'improvement', null],
        ['2024-12-18', 'Oprava React Hooks pravidel', 'Přesun columns a getRowId definic před podmíněné returny v PnLPage, PortfolioPage, BalancePage.', 'bugfix', null],
        ['2024-12-18', 'Překlad col_qty', 'Přidán chybějící překlad pro sloupec Množství.', 'bugfix', null],
        ['2024-12-18', 'Filtr stavů ve Správě požadavků', 'Přidán filtr lišta s checkboxy pro stavy, výchozí zobrazení aktivních.', 'feature', 9],
        ['2024-12-18', 'Oprava API_BASE v AuthContext', 'Opravena cesta z /broker/broker 2.0 na /investyx.', 'bugfix', 6],
        ['2024-12-18', 'Oprava filtru Sledované/Všechny', 'Fix Number() konverze pro is_watched a přidání všech tickerů z live_quotes do API.', 'bugfix', null],
        ['2024-12-18', 'Import do watchlistu', 'Přidána funkce addToWatchlist do import-handler.php - automatické přidání importovaných tickerů.', 'feature', 8],
        
        ['2024-12-17', 'Vylepšení FeedbackModal', 'Rozšíření dialogu na 1400px, inline editace stavu/priority, podpora více příloh.', 'feature', 4],
        ['2024-12-17', 'Oprava SmartDataGrid re-render loop', 'Fix nekonečné smyčky pomocí useRef a kontroly referenční identity processedItems.', 'bugfix', 7],
        ['2024-12-17', 'API pro multiple attachments', 'Úprava api-changerequests.php pro více příloh, nová tabulka changerequest_attachments.', 'feature', 4],
        
        ['2024-12-16', 'Přepínač Sledované/Vše', 'Přidán Switch do MarketPage pro filtrování pouze sledovaných titulů.', 'feature', 3],
        ['2024-12-16', 'Agent API pro automatizaci', 'Vytvořeno agent_api.php pro vzdálenou správu development tasků.', 'feature', 2],
        ['2024-12-16', 'Oprava starého brokeru', 'Vytvoření views broker_trans a broker_exrates v nové databázi.', 'bugfix', null],
        ['2024-12-16', 'Client-side paginace SmartDataGrid', 'Implementace virtualizace pro velké datasety.', 'improvement', null],
        
        ['2024-12-15', 'D365FO-style filtering', 'Implementace filtrování a řazení ve SmartDataGrid komponentě.', 'feature', null],
        ['2024-12-15', 'Reusable SmartDataGrid', 'Vytvoření univerzální komponenty pro datové gridy s filtry.', 'feature', null],
        
        ['2024-12-14', 'Oprava EMA výpočtu', 'Obnovení výpočtu Exponential Moving Average pro tickery.', 'bugfix', null],
        
        ['2024-12-13', 'Oprava ATH/ATL zobrazení', 'Fix pro zobrazení a řazení u kryptoměn (BTC, ETH).', 'bugfix', null],
        
        ['2024-12-12', 'Fio Parser vylepšení', 'Oprava parsování dividend a fallback mechanismů.', 'improvement', null],
        
        ['2024-12-11', 'Revolut Parser', 'Implementace parseru pro Revolut Trading výpisy.', 'feature', null],
        
        ['2024-12-10', 'Import system refaktoring', 'Přepracování import-handler.php a RecognizerFactory.', 'refactor', null],
        
        ['2024-12-09', 'Ticker mapping', 'Implementace mapování tickerů mezi platformami.', 'feature', null],
        
        ['2024-12-08', 'Live prices loader', 'Implementace živých cen z Google Finance.', 'feature', null],
        
        ['2024-12-07', 'Initial React app', 'Vytvoření broker-client React aplikace s Fluent UI.', 'deployment', null],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO development_history (date, title, description, category, related_task_id) 
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description)
    ");

    $count = 0;
    foreach ($history as $entry) {
        $stmt->execute($entry);
        $count++;
    }

    echo "✅ Inserted $count history entries\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
