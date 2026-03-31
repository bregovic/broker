<?php
/**
 * init_broker.php - AGGRESSIVE RESET
 */
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "CONNECTED TO: $driver\n\n";

    // Seznam všech tabulek, které chceme totálně zlikvidovat
    $tablesToDrop = [
        'users', 'user_settings', 'transactions', 'dividends', 'live_quotes', 
        'rates', 'translations', 'watch', 'ticker_mapping', 'tickers_history', 
        'currencies', 'brokers', 'system_config'
    ];

    echo "--- CLEANING PHASE ---\n";
    foreach ($tablesToDrop as $tbl) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS $tbl CASCADE");
            echo "DROPPED: $tbl (if existed)\n";
        } catch (Exception $e) {
            echo "FAILED TO DROP $tbl: " . $e->getMessage() . "\n";
        }
    }

    echo "\n--- CREATION PHASE ---\n";
    $isMysql = ($driver === 'mysql');
    $pk = $isMysql ? "INT AUTO_INCREMENT PRIMARY KEY" : "SERIAL PRIMARY KEY";

    $schema = [
        'users' => "CREATE TABLE users (
            id $pk,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) DEFAULT 'user',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        'user_settings' => "CREATE TABLE user_settings (
            user_id INTEGER PRIMARY KEY,
            lang VARCHAR(5) DEFAULT 'cs',
            theme VARCHAR(20) DEFAULT 'dark',
            base_currency VARCHAR(3) DEFAULT 'CZK'
        )",
        'transactions' => "CREATE TABLE transactions (
            trans_id $pk,
            user_id INTEGER,
            date DATE NOT NULL,
            ticker VARCHAR(20) NOT NULL,
            trans_type VARCHAR(20) NOT NULL,
            amount DECIMAL(18, 8) DEFAULT 0,
            price DECIMAL(18, 8) DEFAULT 0,
            currency VARCHAR(10) NOT NULL,
            fees DECIMAL(18, 8) DEFAULT 0,
            amount_czk DECIMAL(18, 8) NOT NULL,
            ex_rate DECIMAL(18, 8) DEFAULT 1,
            amount_cur DECIMAL(18, 8) DEFAULT 0,
            platform VARCHAR(50),
            product_type VARCHAR(50),
            broker_trade_id VARCHAR(100)
        )",
        'dividends' => "CREATE TABLE dividends (
            div_id $pk,
            user_id INTEGER,
            ticker VARCHAR(20) NOT NULL,
            date DATE NOT NULL,
            amount DECIMAL(18, 8) DEFAULT 0,
            tax DECIMAL(18, 8) DEFAULT 0,
            net DECIMAL(18, 8) DEFAULT 0,
            currency VARCHAR(10) NOT NULL,
            platform VARCHAR(50),
            broker_dividend_id VARCHAR(100)
        )",
        'live_quotes' => "CREATE TABLE live_quotes (
            ticker VARCHAR(20) PRIMARY KEY,
            price DECIMAL(18, 8) NOT NULL,
            currency VARCHAR(10) NOT NULL,
            last_fetched TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            change_percent DECIMAL(10, 4),
            change_amount DECIMAL(18, 8),
            exchange VARCHAR(50),
            asset_type VARCHAR(20),
            high_52w DECIMAL(18, 8), 
            low_52w DECIMAL(18, 8), 
            all_time_high DECIMAL(18, 8),
            all_time_low DECIMAL(18, 8),
            ema_212 DECIMAL(18, 8), 
            resilience_score INTEGER
        )",
        'rates' => "CREATE TABLE rates (
            currency VARCHAR(10) NOT NULL,
            rate DECIMAL(18, 8) NOT NULL,
            date DATE NOT NULL,
            amount INTEGER DEFAULT 1,
            PRIMARY KEY (currency, date)
        )",
        'translations' => "CREATE TABLE translations (
            label_key VARCHAR(100) NOT NULL,
            lang VARCHAR(5) NOT NULL,
            translation TEXT NOT NULL,
            PRIMARY KEY (label_key, lang)
        )",
        'watch' => "CREATE TABLE watch (
            user_id INTEGER NOT NULL,
            ticker VARCHAR(20) NOT NULL,
            PRIMARY KEY (user_id, ticker)
        )",
        'ticker_mapping' => "CREATE TABLE ticker_mapping (
            ticker VARCHAR(20) PRIMARY KEY, company_name VARCHAR(255), currency VARCHAR(10)
        )",
        'tickers_history' => "CREATE TABLE tickers_history (
            ticker VARCHAR(20) NOT NULL, history_date DATE NOT NULL, price DECIMAL(18, 8) NOT NULL, PRIMARY KEY (ticker, history_date)
        )",
        'currencies' => "CREATE TABLE currencies (
            code VARCHAR(3) PRIMARY KEY, name VARCHAR(50), symbol VARCHAR(5), is_active BOOLEAN DEFAULT TRUE
        )",
        'brokers' => "CREATE TABLE brokers (
            id $pk, name VARCHAR(50) UNIQUE, code VARCHAR(20), parser_type VARCHAR(50), icon VARCHAR(50)
        )",
        'system_config' => "CREATE TABLE system_config (
            config_key VARCHAR(50) PRIMARY KEY,
            config_value TEXT,
            description TEXT
        )"
    ];

    foreach ($schema as $name => $sql) {
        $pdo->exec($sql);
        echo "CREATED: $name\n";
    }

    // --- SEEDING ---
    echo "\n--- SEEDING PHASE ---\n";
    $pdo->exec("INSERT INTO users (username, password, role) VALUES ('admin', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'admin') ON CONFLICT DO NOTHING");
    echo "USER: admin created\n";

    $labels = [
        ['nav_market', 'Trh'], ['nav_portfolio', 'Portfolio'], ['nav_dividends', 'Dividendy'], ['nav_pnl', 'Zisk/Ztráta'],
        ['nav_balances', 'Zůstatky'], ['nav_rates', 'Kurzy'], ['nav_import', 'Import'], ['loading_data', 'Načítám data...'],
        ['loading_pnl', 'Načítám zisky/ztráty...'], ['loading_dividends', 'Načítám dividendy...'],
        ['btn_new', 'Nový'], ['btn_refresh', 'Obnovit'], ['btn_update_prices', 'Aktualizovat ceny'],
        ['filter_watched_off', 'Sledované: Vše'], ['filter_watched_on', 'Sledované: Pouze'],
        ['settings.title', 'Nastavení'], ['settings.language', 'Jazyk'], ['settings.currency', 'Základní měna'],
        ['settings.admin', 'Administrace'], ['common.save', 'Uložit'], ['common.cancel', 'Zrušit'],
        ['common.admin_pass', 'Heslo administrátora'], ['admin.config', 'Konfigurace systému'],
        ['admin.brokers', 'Služby (Brokeri)'], ['admin.currencies', 'Měny'], ['admin.assets', 'Tituly'],
        ['btn_add_rate', 'Přidat kurz'], ['btn_import_cnb', 'Import ČNB'], ['filter_currency', 'Měna'], ['loading_rates', 'Načítám kurzy...']
    ];
    $stmt = $pdo->prepare("INSERT INTO translations (label_key, lang, translation) VALUES (?, 'cs', ?) ON CONFLICT DO NOTHING");
    foreach ($labels as $l) {
        $stmt->execute($l);
    }
    echo "TRANSLATIONS: seeded\n";

    // Seed Lookups
    $pdo->exec("INSERT INTO brokers (name, parser_type) VALUES 
        ('Revolut', 'revolut'), ('Fio banka', 'fio'), ('Coinbase', 'coinbase'), 
        ('eToro', 'etoro'), ('Trading212', 't212'), ('Degiro', 'degiro') 
        ON CONFLICT DO NOTHING");
    
    $pdo->exec("INSERT INTO asset_types (name) VALUES 
        ('Akcie'), ('ETF'), ('Kryptoměny'), ('Komodity'), ('Valuty'), ('Ostatní') 
        ON CONFLICT DO NOTHING");

    $pdo->exec("INSERT INTO system_config (config_key, config_value, description) VALUES 
        ('google_finance_url', 'https://www.google.com/finance/quote/', 'Base URL for Google Finance scraping'),
        ('yahoo_api_key', '', 'API Key for Yahoo Finance (if needed)'),
        ('base_currency', 'CZK', 'Default portfolio currency')
        ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value");

    echo "DONE! APP IS READY. <a href='/'>Go to App</a>";
} catch (Throwable $e) {
    echo "\nFATAL ERROR: " . $e->getMessage();
}
