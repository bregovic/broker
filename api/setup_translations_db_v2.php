<?php
// setup_translations_db_v2.php
// Jednorázový skript pro vytvoření tabulek pro překlady a nastavení uživatelů + naplnění daty.

    // 1. Konfigurace a DB
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
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "Připojeno k DB.<br>";

    // 1. Tabulka pro nastavení uživatele
    $sqlSettings = "CREATE TABLE IF NOT EXISTS broker_user_settings (
        user_id INT NOT NULL PRIMARY KEY,
        language VARCHAR(10) DEFAULT 'cs',
        theme VARCHAR(20) DEFAULT 'light',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $pdo->exec($sqlSettings);
    echo "Tabulka 'broker_user_settings' zkontrolována/vytvořena.<br>";

    // 2. Tabulka pro překlady
    $sqlTrans = "CREATE TABLE IF NOT EXISTS broker_translations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label_key VARCHAR(100) NOT NULL UNIQUE,
        cs TEXT,
        en TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sqlTrans);
    echo "Tabulka 'broker_translations' zkontrolována/vytvořena.<br>";

    // 3. Vložení základních dat (SEED)
    // Pokud klíč existuje, aktualizujeme ho (ON DUPLICATE KEY UPDATE)
    $translations = [
        // Menu
        ['nav_market', 'Přehled trhu', 'Market Overview'],
        ['nav_portfolio', 'Transakce', 'Transactions'],
        ['nav_dividends', 'Dividendy', 'Dividends'],
        ['nav_pnl', 'Realizované P&L', 'Realized P&L'],
        ['nav_balances', 'Zůstatky', 'Balances'],
        ['nav_rates', 'Kurzy', 'Rates'],
        ['nav_import', 'Import', 'Import'],
        ['logout', 'Odhlásit se', 'Logout'],

        // Portfolio Page
        ['loading_transactions', 'Načítám transakce...', 'Loading transactions...'],
        ['col_date', 'Datum', 'Date'],
        ['col_type', 'Typ', 'Type'],
        ['col_quantity', 'Množství', 'Qty'],
        ['col_prices_unit', 'Cena j.', 'Price/Unit'],
        ['col_currency', 'Měna', 'Currency'],
        ['col_total_orig', 'Celkem (Orig)', 'Total (Orig)'],
        ['col_rate', 'Kurz', 'Ex. Rate'],
        ['col_total_czk', 'Celkem (CZK)', 'Total (CZK)'],
        ['col_platform', 'Platforma', 'Platform'],

        // Dividends Page
        ['loading_dividends', 'Načítám dividendy...', 'Loading dividends...'],
        ['col_amount', 'Částka', 'Amount'],
        ['col_czk_gross_tax', 'CZK (Hrubé/Daň)', 'CZK (Gross/Tax)'],
        ['div_gross', 'Celkem hrubého', 'Total Gross'],
        ['div_tax', 'Zaplacená daň', 'Tax Paid'],
        ['div_net', 'Čistá dividenda', 'Net Dividend'],
        ['div_count', 'Počet výplat', 'Payout Count'],
        ['no_dividends', 'Žádné dividendy nenalezeny.', 'No dividends found.'],
        ['type_dividend', 'Dividenda', 'Dividend'],
        ['type_tax', 'Daň', 'Tax'],

        // PnL Page
        ['loading_pnl', 'Počítám zisky...', 'Calculating P&L...'],
        ['col_gross_profit', 'Hrubý Zisk', 'Gross Profit'],
        ['col_net_profit', 'Čistý Zisk', 'Net Profit'],
        ['col_tax_test', 'Daňový test', 'Tax Test'],
        ['col_days', 'Dní', 'Days'],
        ['test_passed', 'Splněno', 'Passed'],
        ['test_failed', 'Nesplněno', 'Failed'],
        ['pnl_net_profit', 'Čistý zisk', 'Net Profit'],
        ['pnl_winning', 'Ziskové obchody (Hrubý)', 'Winning Trades (Gross)'],
        ['pnl_losing', 'Ztrátové obchody', 'Losing Trades'],
        ['pnl_tax_free', 'Osvobozené (3r+)', 'Tax Exempt (3y+)'],
        ['no_sales', 'Žádné prodeje.', 'No sales found.'],
        ['trades_count', 'obchodů', 'trades'],

        // Balance Page
        ['loading_balances', 'Načítám zůstatky...', 'Loading balances...'],
        ['col_symbol', 'Symbol', 'Symbol'],
        ['col_avg_cost_orig', 'Prům. cena (Orig)', 'Avg Cost (Orig)'],
        ['col_curr_price', 'Cena (Akt.)', 'Price (Curr)'],
        ['col_value_czk', 'Hodnota (CZK)', 'Value (CZK)'],
        ['col_pnl_orig', 'P&L (Orig)', 'P&L (Orig)'],
        ['col_pnl_pct_orig', 'P&L % (Orig)', 'P&L % (Orig)'],
        ['col_fx_pnl', 'FX P&L (CZK)', 'FX P&L (CZK)'],
        ['col_pnl_czk', 'P&L (CZK)', 'P&L (CZK)'],
        ['summary_total_value', 'Celková Hodnota', 'Total Value'],
        ['summary_buy_cost', 'Nákupní cena', 'Acquisition Cost'],
        ['summary_pnl_czk', 'Zisk / Ztráta (CZK)', 'Profit / Loss (CZK)'],
        ['summary_count', 'Počet titulů', 'Holdings Count'],

        // Rates Page
        ['loading_rates', 'Načítám kurzy...', 'Loading rates...'],
        ['btn_add_rate', 'Přidat kurz', 'Add Rate'],
        ['btn_import_cnb', 'Import ČNB', 'Import CNB'],
        ['filter_currency', 'Filtr Měny:', 'Filter Currency:'],
        ['all', 'Všechny', 'All'],
        ['add_rate_title', 'Přidat/Upravit kurz', 'Add/Edit Rate'],
        ['col_unit', '1 Jednotka', '1 Unit'],
        ['col_source', 'Zdroj', 'Source'],
        ['col_rate_czk', 'Kurz (CZK)', 'Rate (CZK)'],
        ['import_cnb_title', 'Import ročních kurzů z ČNB', 'Import CNB Annual Rates'],
        ['select_year', 'Vyberte rok', 'Select Year'],
        ['import_cnb_desc', 'Tato akce stáhne roční kurzovní lístek z ČNB a uloží kurzy pro celý rok. Může to trvat několik sekund.', 'This action downloads the annual exchange rate list from CNB and saves rates for the entire year. It may take a few seconds.'],
        ['btn_importing', 'Importuji...', 'Importing...'],
        ['btn_import', 'Importovat', 'Import'],

        // Import Page (checking consistency)
        ['import.drop_title', 'Klikněte nebo přetáhněte soubory', 'Click or drag files here'],
        ['import.supported', 'Podporováno: CSV, PDF, XML', 'Supported: CSV, PDF, XML'],
        ['import.add_btn', 'Přidat další', 'Add more'],
        ['import.upload_btn', 'Nahrát a zpracovat', 'Upload and Process'],
        ['import.working', 'Zpracovávám...', 'Processing...'],

        // Settings Dialog
        ['settings.title', 'Nastavení', 'Settings'],
        ['settings.language', 'Jazyk', 'Language'],
        ['common.save', 'Uložit', 'Save'],
        ['common.cancel', 'Zrušit', 'Cancel'],
        ['common.loading', 'Ukládám...', 'Saving...'],
        ['common.error', 'Chyba při ukládání', 'Error saving'],

        // Market Page - Columns
        ['col_ticker', 'Ticker', 'Ticker'],
        ['col_company', 'Společnost', 'Company'],
        ['col_exchange', 'Burza', 'Exchange'],
        ['col_price', 'Cena', 'Price'],
        ['col_change', 'Změna', 'Change'],
        ['col_change_pct', 'Změna %', 'Change %'],
        ['col_range', 'Rozsah (ATH/ATL)', 'Range (ATH/ATL)'],
        ['col_trend', 'Trend (EMA)', 'Trend (EMA)'],
        ['col_actions', 'Akce', 'Actions'],

        // Market Page - Common
        ['market_title', 'Přehled trhu', 'Market Overview'],
        ['btn_new', 'Nový', 'New'],
        ['btn_refresh', 'Obnovit', 'Refresh'],
        ['btn_quick_prices', 'Rychlé Ceny (Google)', 'Live Prices (Google)'],
        ['btn_analysis_data', 'Analýza a Data (Yahoo)', 'Analysis & Data (Yahoo)'],
        ['btn_cancel', 'Zrušit', 'Cancel'],
        ['btn_add', 'Přidat', 'Add'],
        ['btn_adding', 'Přidávám...', 'Adding...'],
        ['add_ticker_title', 'Přidat nový ticker', 'Add new ticker'],
        ['search_placeholder', 'Hledat...', 'Search...'],
        ['loading_data', 'Načítám data...', 'Loading data...'],
        
        // Chart Modal
        ['chart_title_history', 'Historie ceny', 'Price History'],
        ['btn_download_data', 'Stáhnout data', 'Download Data'],
        ['btn_close', 'Zavřít', 'Close'],
        ['chart_no_data', 'Žádná data pro zvolené období.', 'No data for selected period.'],
        ['chart_loading', 'Načítám graf...', 'Loading chart...'],
        ['chart_legend_price', 'Cena', 'Price'],
        
        // Tooltips / Badges
        ['badge_watched', 'Sledováno', 'Watched'],
        ['from_max', 'od Max', 'from Max'],
        ['from_min', 'od Min', 'from Min'],
        ['trend_ema', 'EMA', 'EMA'] // Exponential Moving Average
    ];

    $stmt = $pdo->prepare("INSERT INTO broker_translations (label_key, cs, en) VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE cs = VALUES(cs), en = VALUES(en)");

    foreach ($translations as $row) {
        $stmt->execute($row);
    }
    
    echo "Vloženo/Aktualizováno " . count($translations) . " překladů.<br>";
    echo "Hotovo.";

} catch (PDOException $e) {
    die("Chyba DB: " . $e->getMessage());
}
?>
