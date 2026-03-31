<?php
/**
 * init_broker.php
 * Initializes the database schema for the Broker application.
 * Supports both MySQL and PostgreSQL.
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Connected to $driver database.\n\n";

    $isMysql = ($driver === 'mysql');

    // Helper for AUTO_INCREMENT
    $pk = $isMysql ? "INT AUTO_INCREMENT PRIMARY KEY" : "SERIAL PRIMARY KEY";
    $text = $isMysql ? "TEXT" : "TEXT"; 
    $json = $isMysql ? "JSON" : "JSONB";

    $tables = [
        'users' => "CREATE TABLE IF NOT EXISTS users (
            id $pk,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) DEFAULT 'user',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        'transactions' => "CREATE TABLE IF NOT EXISTS transactions (
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
            broker_trade_id VARCHAR(100),
            metadata $json,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        'dividends' => "CREATE TABLE IF NOT EXISTS dividends (
            div_id $pk,
            user_id INTEGER,
            ticker VARCHAR(20) NOT NULL,
            date DATE NOT NULL,
            amount DECIMAL(18, 8) DEFAULT 0,
            tax DECIMAL(18, 8) DEFAULT 0,
            net DECIMAL(18, 8) DEFAULT 0,
            currency VARCHAR(10) NOT NULL,
            platform VARCHAR(50),
            broker_dividend_id VARCHAR(100),
            metadata $json,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        'live_quotes' => "CREATE TABLE IF NOT EXISTS live_quotes (
            ticker VARCHAR(20) PRIMARY KEY,
            price DECIMAL(18, 8) NOT NULL,
            currency VARCHAR(10) NOT NULL,
            last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            change_percent DECIMAL(8, 4),
            metadata $json
        )",

        'rates' => "CREATE TABLE IF NOT EXISTS rates (
            currency_from VARCHAR(5) NOT NULL,
            currency_to VARCHAR(5) NOT NULL,
            rate_date DATE NOT NULL,
            rate DECIMAL(18, 8) NOT NULL,
            source VARCHAR(20) DEFAULT 'CNB',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (currency_from, currency_to, rate_date)
        )",

        'ticker_mapping' => "CREATE TABLE IF NOT EXISTS ticker_mapping (
            alias VARCHAR(50) PRIMARY KEY,
            actual_ticker VARCHAR(20) NOT NULL,
            description $text
        )",

        'translations' => "CREATE TABLE IF NOT EXISTS translations (
            label_key VARCHAR(100) NOT NULL,
            lang VARCHAR(5) NOT NULL,
            translation $text NOT NULL,
            PRIMARY KEY (label_key, lang)
        )",

        'user_settings' => "CREATE TABLE IF NOT EXISTS user_settings (
            user_id INTEGER PRIMARY KEY,
            lang VARCHAR(5) DEFAULT 'cs',
            theme VARCHAR(20) DEFAULT 'dark',
            base_currency VARCHAR(3) DEFAULT 'CZK',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        'currencies' => "CREATE TABLE IF NOT EXISTS currencies (
            code VARCHAR(3) PRIMARY KEY,
            name VARCHAR(50),
            symbol VARCHAR(5),
            is_active BOOLEAN DEFAULT TRUE
        )",

        'brokers' => "CREATE TABLE IF NOT EXISTS brokers (
            id $pk,
            name VARCHAR(50) NOT NULL UNIQUE,
            code VARCHAR(20),
            parser_type VARCHAR(50),
            icon VARCHAR(50),
            is_active BOOLEAN DEFAULT TRUE
        )",

        'asset_classes' => "CREATE TABLE IF NOT EXISTS asset_classes (
            id $pk,
            name VARCHAR(50) NOT NULL UNIQUE,
            code VARCHAR(20)
        )",

        'tickers_history' => "CREATE TABLE IF NOT EXISTS tickers_history (
            ticker VARCHAR(20) NOT NULL,
            history_date DATE NOT NULL,
            price DECIMAL(18, 8) NOT NULL,
            PRIMARY KEY (ticker, history_date)
        )",

        'watch' => "CREATE TABLE IF NOT EXISTS watch (
            user_id INT NOT NULL,
            ticker VARCHAR(20) NOT NULL,
            PRIMARY KEY (user_id, ticker)
        )"
    ];

    // Pro jistotu smažeme tabulky, které se nám v průběhu vývoje změnily, 
    // aby se vytvořily znovu se správnou strukturou (v produkci by se dělala migrace, 
    // ale tady u testu je čistý reset jistota).
    // Pro jistotu smažeme tabulky, které se nám v průběhu vývoje změnily, 
    // aby se vytvořily znovu se správnou strukturou.
    $pdo->exec("DROP TABLE IF EXISTS user_settings");
    $pdo->exec("DROP TABLE IF EXISTS translations");
    $pdo->exec("DROP TABLE IF EXISTS transactions");
    $pdo->exec("DROP TABLE IF EXISTS dividends");
    $pdo->exec("DROP TABLE IF EXISTS live_quotes");
    $pdo->exec("DROP TABLE IF EXISTS tickers_history");
    $pdo->exec("DROP TABLE IF EXISTS watch");

    foreach ($tables as $name => $sql) {
        echo "Creating table '$name'... ";
        $pdo->exec($sql);
        echo "OK.\n";
    }

    // Default admin user
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES ('admin', ?, 'admin')");
        $stmt->execute([$hash]);
        echo "\nDefault admin user created (admin / admin123).\n";
    }

    // 4. Seznam základních popisků (Translations)
    $labels = [
        // Navigation
        ['cs', 'nav_market', 'Trh'],
        ['cs', 'nav_portfolio', 'Portfolio'],
        ['cs', 'nav_dividends', 'Dividendy'],
        ['cs', 'nav_pnl', 'Zisk/Ztráta'],
        ['cs', 'nav_balances', 'Zůstatky'],
        ['cs', 'nav_rates', 'Kurzy'],
        ['cs', 'nav_import', 'Import'],
        
        // Buttons & Actions
        ['cs', 'btn_new', 'Nový'],
        ['cs', 'btn_refresh', 'Obnovit'],
        ['cs', 'btn_update_prices', 'Aktualizovat ceny'],
        ['cs', 'filter_watched_off', 'Sledované: Vše'],
        ['cs', 'filter_watched_on', 'Sledované: Pouze'],
        
        // General
        ['cs', 'loading_data', 'Načítám data...'],
        ['cs', 'login_title', 'Přihlášení'],
        ['cs', 'email_label', 'E-mailová adresa'],
        ['cs', 'password_label', 'Heslo'],
        ['cs', 'login_btn', 'Přihlásit se'],
        ['cs', 'register_link', 'Nemáte účet? Zaregistrujte se'],
        ['cs', 'register_title', 'Registrace'],
        ['cs', 'save', 'Uložit'],
        ['cs', 'cancel', 'Zrušit'],
        ['cs', 'delete', 'Smazat'],
        ['cs', 'settings', 'Nastavení'],
        ['cs', 'import.drop_title', 'Nahrajte soubor s výpisem brokera'],
        ['cs', 'import.supported', 'Podporované formáty: Fio CSV, XTB (v přípravě), Interactive Brokers (v přípravě)']
    ];

    $stmt = $pdo->prepare("INSERT INTO translations (lang, label_key, translation) VALUES (?, ?, ?) 
                           ON CONFLICT (label_key, lang) DO UPDATE SET translation = EXCLUDED.translation");
    foreach ($labels as $label) {
        $stmt->execute($label);
    }
    echo "Labels initialized.<br>";

    // 5. Seed Currencies
    $currencies = [
        ['CZK', 'Česká koruna', 'Kč'],
        ['EUR', 'Euro', '€'],
        ['USD', 'Americký dolar', '$'],
        ['GBP', 'Britská libra', '£']
    ];
    $stmt = $pdo->prepare("INSERT INTO currencies (code, name, symbol) VALUES (?, ?, ?) ON CONFLICT (code) DO NOTHING");
    foreach ($currencies as $c) $stmt->execute($c);
    echo "Currencies seeded.<br>";

    // 6. Seed Brokers
    $brokers = [
        ['Fio banka', 'fio', 'FioCsvParser', 'bank'],
        ['Revolut', 'revolut', 'RevolutCsvParser', 'credit-card'],
        ['Coinbase', 'coinbase', 'CoinbaseParser', 'bitcoin'],
        ['eToro', 'etoro', 'EtoroParser', 'trending-up']
    ];
    $stmt = $pdo->prepare("INSERT INTO brokers (name, code, parser_type, icon) VALUES (?, ?, ?, ?) ON CONFLICT (name) DO NOTHING");
    foreach ($brokers as $b) $stmt->execute($b);
    echo "Brokers seeded.<br>";

    // 7. Seed Asset Classes
    $assets = [
        ['Akcie', 'stocks'],
        ['Komodity', 'commodities'],
        ['Kryptoměny', 'crypto'],
        ['Valuty', 'forex']
    ];
    $stmt = $pdo->prepare("INSERT INTO asset_classes (name, code) VALUES (?, ?) ON CONFLICT (name) DO NOTHING");
    foreach ($assets as $a) $stmt->execute($a);
    echo "Asset classes seeded.<br>";

    echo "<h3>Database initialization complete!</h3>";
    echo "<p>You can now log in with: <b>admin / admin123</b></p>";

} catch (Exception $e) {
    die("\nERROR: " . $e->getMessage());
}
