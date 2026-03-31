<?php
// setup_translations_db.php
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
        ['nav_pnl', 'Realizované P&L', 'Realized P&L'],
        ['nav_import', 'Import', 'Import'],
        ['logout', 'Odhlásit se', 'Logout'],

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
        ['btn_prices_live', 'Rychlé Ceny (Google)', 'Live Prices (Google)'],
        ['btn_analysis_yahoo', 'Analýza a Data (Yahoo)', 'Analysis & Data (Yahoo)'],
        ['search_placeholder', 'Hledat...', 'Search...'],
        
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
