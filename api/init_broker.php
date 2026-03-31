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
            rec_id $pk,
            ticker VARCHAR(20) NOT NULL,
            transaction_date DATE NOT NULL,
            type VARCHAR(20) NOT NULL,
            quantity DECIMAL(18, 8) DEFAULT 0,
            price_per_unit DECIMAL(18, 8) DEFAULT 0,
            currency VARCHAR(10) NOT NULL,
            fee DECIMAL(18, 8) DEFAULT 0,
            total_amount DECIMAL(18, 8) NOT NULL,
            source_broker VARCHAR(50),
            broker_trade_id VARCHAR(100),
            metadata $json,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        'dividends' => "CREATE TABLE IF NOT EXISTS dividends (
            rec_id $pk,
            ticker VARCHAR(20) NOT NULL,
            ex_date DATE,
            pay_date DATE NOT NULL,
            gross_amount DECIMAL(18, 8) NOT NULL,
            tax_amount DECIMAL(18, 8) DEFAULT 0,
            net_amount DECIMAL(18, 8) NOT NULL,
            currency VARCHAR(10) NOT NULL,
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
            user_id INT NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value $text,
            PRIMARY KEY (user_id, setting_key)
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
        ['cs', 'settings', 'Nastavení']
    ];

    $stmt = $pdo->prepare("INSERT INTO translations (lang, label_key, translation) VALUES (?, ?, ?) 
                           ON CONFLICT (label_key, lang) DO UPDATE SET translation = EXCLUDED.translation");
    foreach ($labels as $label) {
        $stmt->execute($label);
    }
    echo "Labels initialized.<br>";

    echo "<h3>Database initialization complete!</h3>";
    echo "<p>You can now log in with: <b>admin / admin123</b></p>";

} catch (Exception $e) {
    die("\nERROR: " . $e->getMessage());
}
