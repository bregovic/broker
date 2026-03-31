<?php
/**
 * init_broker.php - THE NUCLEAR RESET
 * Only run when you want to wipe EVERYTHING (Postgres / MySQL)
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "CONNECTED TO: $driver\n\n";

    // --- NUCLEAR STEP FOR POSTGRES ---
    if ($driver === 'pgsql') {
        echo "Postgres detected. Dropping Entire Public Schema...\n";
        $pdo->exec("DROP SCHEMA public CASCADE");
        $pdo->exec("CREATE SCHEMA public");
        $pdo->exec("GRANT ALL ON SCHEMA public TO postgres");
        $pdo->exec("GRANT ALL ON SCHEMA public TO public");
        echo "Public schema RECREATED.\n";
    }

    $isMysql = ($driver === 'mysql');
    $pk = $isMysql ? "INT AUTO_INCREMENT PRIMARY KEY" : "SERIAL PRIMARY KEY";

    $tables = [
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
            high_52w DECIMAL(18, 8), low_52w DECIMAL(18, 8), ema_212 DECIMAL(18, 8), resilience_score INTEGER
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

    foreach ($tables as $name => $sql) {
        $pdo->exec($sql);
        echo "CREATED: $name\n";
    }

    // --- SEEDING ---
    $pdo->exec("INSERT INTO users (username, password, role) VALUES ('admin', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'admin') ON CONFLICT DO NOTHING");
    
    // Seed system config
    $config = [
        ['google_finance_url', 'https://finance.google.com/finance/quote/', 'URL prefix for Google Finance'],
        ['yahoo_finance_api_key', 'YOUR_YAHOO_API_KEY', 'API Key for Yahoo Finance'],
        ['yahoo_finance_region', 'US', 'Default region for Yahoo Finance']
    ];
    $cfgStmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value, description) VALUES (?, ?, ?) ON CONFLICT DO NOTHING");
    foreach ($config as $c) $cfgStmt->execute($c);
    
    $labels = [
        ['nav_market', 'Trh'], ['nav_portfolio', 'Portfolio'], ['nav_dividends', 'Dividendy'], ['nav_pnl', 'Zisk/Ztráta'],
        ['nav_balances', 'Zůstatky'], ['nav_rates', 'Kurzy'], ['nav_import', 'Import'], ['loading_data', 'Načítám data...'],
        ['loading_pnl', 'Načítám zisky/ztráty...'], ['loading_dividends', 'Načítám dividendy...'],
        ['btn_new', 'Nový'], ['btn_refresh', 'Obnovit'], ['btn_update_prices', 'Aktualizovat ceny'],
        ['filter_watched_off', 'Sledované: Vše'], ['filter_watched_on', 'Sledované: Pouze']
    ];
    $stmt = $pdo->prepare("INSERT INTO translations (label_key, lang, translation) VALUES (?, 'cs', ?) ON CONFLICT DO NOTHING");
    foreach ($labels as $l) $stmt->execute($l);

    echo "\n--- RESET SUCCESSFUL ---";

} catch (Exception $e) {
    echo "\nFATAL ERROR: " . $e->getMessage();
}
