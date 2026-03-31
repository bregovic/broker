<?php
/**
 * DB INITIALIZER (Safe Version - No data loss for core tables)
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();
    echo "CONNECTED TO: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

    // 1. NON-DESTRUCTIVE SCHEMA (Core Tables)
    $core_schema = [
        'users' => "CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        'user_settings' => "CREATE TABLE IF NOT EXISTS user_settings (
            user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            base_currency VARCHAR(10) DEFAULT 'CZK',
            language VARCHAR(10) DEFAULT 'cs',
            theme VARCHAR(20) DEFAULT 'light',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        'transactions' => "CREATE TABLE IF NOT EXISTS transactions (
            trans_id SERIAL PRIMARY KEY,
            user_id INTEGER REFERENCES users(id),
            date DATE NOT NULL,
            ticker VARCHAR(20),
            trans_type VARCHAR(20),
            amount DECIMAL(18, 8),
            price DECIMAL(18, 8),
            currency VARCHAR(10),
            ex_rate DECIMAL(18, 8),
            fees DECIMAL(18, 8),
            amount_czk DECIMAL(18, 8),
            platform VARCHAR(50),
            product_type VARCHAR(20)
        )",
        'dividends' => "CREATE TABLE IF NOT EXISTS dividends (
            div_id SERIAL PRIMARY KEY,
            user_id INTEGER REFERENCES users(id),
            date DATE NOT NULL,
            ticker VARCHAR(20),
            amount DECIMAL(18, 8),
            tax DECIMAL(18, 8),
            currency VARCHAR(10),
            ex_rate DECIMAL(18, 8),
            amount_czk DECIMAL(18, 8),
            platform VARCHAR(50)
        )"
    ];

    foreach ($core_schema as $name => $sql) {
        $pdo->exec($sql);
        echo "VERIFIED: $name\n";
    }

    // 2. REFRESHABLE SCHEMA (Lookups and caches - Safe to drop/recreate if needed, but using IF NOT EXISTS)
    $lookup_schema = [
        'live_quotes' => "CREATE TABLE IF NOT EXISTS live_quotes (
            ticker VARCHAR(20) PRIMARY KEY,
            price DECIMAL(18, 8) NOT NULL,
            currency VARCHAR(10) NOT NULL,
            last_fetched TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            change_percent DECIMAL(10, 4),
            high_52w DECIMAL(18, 8), low_52w DECIMAL(18, 8), 
            all_time_high DECIMAL(18, 8), all_time_low DECIMAL(18, 8)
        )",
        'rates' => "CREATE TABLE IF NOT EXISTS rates (
            currency VARCHAR(10) NOT NULL,
            rate DECIMAL(18, 8) NOT NULL,
            date DATE NOT NULL,
            amount INTEGER DEFAULT 1,
            PRIMARY KEY (currency, date)
        )",
        'translations' => "CREATE TABLE IF NOT EXISTS translations (
            label_key VARCHAR(100) NOT NULL,
            lang VARCHAR(10) NOT NULL,
            translation TEXT,
            PRIMARY KEY (label_key, lang)
        )",
        'brokers' => "CREATE TABLE IF NOT EXISTS brokers (
            broker_id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            parser_type VARCHAR(50)
        )",
        'asset_types' => "CREATE TABLE IF NOT EXISTS asset_types (
            type_id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL
        )",
        'system_config' => "CREATE TABLE IF NOT EXISTS system_config (
            config_key VARCHAR(100) PRIMARY KEY,
            config_value TEXT,
            description TEXT
        )"
    ];

    foreach ($lookup_schema as $name => $sql) {
        $pdo->exec($sql);
        echo "VERIFIED: $name\n";
    }

    // 3. SEEDING (Extensive Translations)
    $labels = [
        ['nav_market', 'Trh'], ['nav_portfolio', 'Portfolio'], ['nav_dividends', 'Dividendy'], ['nav_pnl', 'Zisk/Ztráta'],
        ['nav_balances', 'Zůstatky'], ['nav_rates', 'Kurzy'], ['nav_import', 'Import'], ['loading_data', 'Načítám data...'],
        ['loading_pnl', 'Načítám zisky/ztráty...'], ['loading_dividends', 'Načítám dividendy...'], ['loading_rates', 'Načítám kurzy...'],
        ['btn_new', 'Nový'], ['btn_refresh', 'Obnovit'], ['btn_update_prices', 'Aktualizovat ceny'], ['btn_importing', 'Importuji...'],
        ['settings.title', 'Nastavení'], ['settings.language', 'Jazyk'], ['settings.currency', 'Základní měna'],
        ['settings.admin', 'Administrace'], ['common.save', 'Uložit'], ['common.cancel', 'Zrušit'], ['common.close', 'Zavřít'],
        ['common.admin_pass', 'Heslo administrátora'], ['admin.config', 'Konfigurace systému'],
        ['admin.brokers', 'Služby (Brokeri)'], ['admin.currencies', 'Měny'], ['admin.assets', 'Tituly'],
        ['btn_add_rate', 'Přidat kurz'], ['btn_import_cnb', 'Import ČNB'], ['btn_import', 'Importovat'],
        ['filter_currency', 'Měna'], ['all', 'Vše'], ['locale', 'cs-CZ'],
        ['col_date', 'Datum'], ['col_currency', 'Měna'], ['col_quantity', 'Množství'],
        ['col_rate_czk', 'Kurz CZK'], ['col_unit', 'Za jednotku'], ['col_source', 'Zdroj'],
        ['add_rate_title', 'Přidat ruční kurz'], ['import_cnb_title', 'Import kurzů ČNB'],
        ['select_year', 'Vyberte rok'], ['import_cnb_desc', 'Tato akce stáhne kompletní kurzovní lístek ČNB pro vybraný rok.'],
        ['import.drop_title', 'Přetáhněte soubory sem'], ['import.supported', 'Podporované formáty: CSV, XLSX, PDF (vybrané)'],
        ['import.add_btn', 'Přidat soubor'], ['import.working', 'Zpracovávám...'],
        ['import.unsupported.title', 'Nepodporovaný formát'], ['import.unsupported.desc', 'Tento formát zatím neumíme automaticky parsovat.']
    ];
    $stmtT = $pdo->prepare("INSERT INTO translations (label_key, lang, translation) VALUES (?, 'cs', ?) ON CONFLICT DO NOTHING");
    foreach ($labels as $l) $stmtT->execute($l);

    $pdo->exec("INSERT INTO brokers (name, parser_type) VALUES 
        ('Revolut', 'revolut'), ('Fio banka', 'fio'), ('Coinbase', 'coinbase'), 
        ('eToro', 'etoro'), ('Trading212', 't212'), ('Degiro', 'degiro') ON CONFLICT DO NOTHING");
    
    $pdo->exec("INSERT INTO asset_types (name) VALUES 
        ('Akcie'), ('ETF'), ('Kryptoměny'), ('Komodity'), ('Valuty'), ('Ostatní') ON CONFLICT DO NOTHING");

    $pdo->exec("INSERT INTO system_config (config_key, config_value, description) VALUES 
        ('google_finance_url', 'https://www.google.com/finance/quote/', 'Base URL for Google Finance scraping'),
        ('base_currency', 'CZK', 'Default portfolio currency')
        ON CONFLICT (config_key) DO NOTHING");

    echo "\nALL DONE! APP IS READY AND SAFE.";

} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFATAL ERROR: " . $e->getMessage();
}
