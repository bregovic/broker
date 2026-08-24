<?php
/**
 * DB INITIALIZER (Nuclear Version - Drops non-core tables for clean state)
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    echo "CONNECTED TO: $driver\n";
    echo "VERSION: 1.0.5 (Direct patch)\n\n";

    // 1. Core Tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS broker_import_rules (
        id SERIAL PRIMARY KEY,
        config_name VARCHAR(100) UNIQUE NOT NULL,
        broker_name VARCHAR(100),
        parser_class VARCHAR(255) NOT NULL,
        file_pattern TEXT,
        content_regex TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (trans_id SERIAL PRIMARY KEY)");

    // Ticker aliases: old/changed symbol -> current (canonical). Used by reports.
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticker_aliases (
        alias VARCHAR(20) PRIMARY KEY,
        canonical VARCHAR(20) NOT NULL,
        note VARCHAR(255)
    )");

    // 2. Safely add columns to live_quotes
    $pdo->exec("CREATE TABLE IF NOT EXISTS live_quotes (ticker VARCHAR(20) PRIMARY KEY)");
    
    $liveQuotesCols = [
        'price' => 'DECIMAL(18, 8)',    // Aligned with api-market-data.php
        'current_price' => 'DECIMAL(18, 8)', // Heritage
        'change_amount' => 'DECIMAL(18, 8)',
        'change_percent' => 'DECIMAL(18, 8)',
        'currency' => 'VARCHAR(10)',
        'exchange' => 'VARCHAR(50)',
        'company_name' => 'VARCHAR(255)',
        'asset_type' => 'VARCHAR(20)',
        'source' => 'VARCHAR(50)',
        'all_time_high' => 'DECIMAL(18, 8)',
        'high_52w' => 'DECIMAL(18, 8)',
        'all_time_low' => 'DECIMAL(18, 8)',
        'low_52w' => 'DECIMAL(18, 8)',
        'ema_212' => 'DECIMAL(18, 8)',
        'resilience_score' => 'DECIMAL(18, 8)',
        'last_fetched' => 'TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP',
        'status' => 'VARCHAR(20) DEFAULT \'active\''
    ];

    foreach ($liveQuotesCols as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE live_quotes ADD COLUMN $col $def");
            echo "FIXED: Column '$col' added to live_quotes.\n";
        } catch (Exception $e) { /* already exists */ }
    }

    // 3. Tickers History
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickers_history (
        ticker VARCHAR(20),
        history_date DATE,
        price DECIMAL(18, 8),
        source VARCHAR(50),
        PRIMARY KEY (ticker, history_date)
    )");

    // 4. Seeding Rules
    // Discovery tests rules in `priority` order (lowest first) and takes the first match.
    // Crypto/commodity statements are also valid "account statements", so the trading rule
    // must stay LAST and must not match on generic words alone ("Výpis z účtu", "Dividend",
    // "Transactions") — those made every crypto/commodity file parse as a trading file.
    try {
        $pdo->exec("ALTER TABLE broker_import_rules ADD COLUMN priority INT DEFAULT 50");
        echo "FIXED: Column 'priority' added to broker_import_rules.\n";
    } catch (Exception $e) { /* already exists */ }

    $cs = '(XAU|XAG|XPT|XPD)';
    $rules = [
        // config_name, broker_name, parser_class, file_pattern, content_regex, priority
        ['revolut_crypto_pdf', 'Revolut Crypto (PDF)', 'Broker\\V3\\Import\\Pdf\\RevolutCryptoPdfParser',
            'crypto-account-statement|revolut.*crypto',
            'Výpis z účtu s krypto|Crypto\\s+Account\\s+Statement|Revolut Digital Assets|Odm[ěĕ]ny? (za|ze) staking|Staking rewards?', 10],
        ['revolut_commodity_pdf', 'Revolut Commodity (PDF)', 'Broker\\V3\\Import\\Pdf\\RevolutCommodityPdfParser',
            'commodit(y|ies)-account-statement|revolut.*commodit',
            'Výpis v\\s*' . $cs . '|Sm[ěĕ]něno na\\s*' . $cs . '|Exchanged to\\s*' . $cs, 10],
        ['ibkr_transaction_history_csv', 'Interactive Brokers (Transaction History CSV)', 'Broker\\V3\\Import\\Csv\\IbkrTransactionHistoryCsvParser',
            '\\.TRANSACTIONS\\..*\\.csv',
            'Transaction History,Header,Date|Statement,Data,Title,Transaction History', 15],
        // Activity Statement only — a different layout that IbkrCsvParser cannot read,
        // so it must not claim the Transaction History export above.
        ['ibkr_activity_csv', 'Interactive Brokers (Activity CSV)', 'Broker\\V3\\Import\\Csv\\IbkrCsvParser',
            'U\\d{6,}_\\d{4}_\\d{4}\\.csv',
            'Trades,Header,.*DataDiscriminator', 20],
        ['ibkr_pdf', 'Interactive Brokers (PDF)', 'Broker\\V3\\Import\\Pdf\\IbkrPdfParser',
            'ibkr',
            'Interactive Brokers|TransactionsCZK|Time Period:', 30],
        // No fio_csv rule on purpose: FioCsvParser is still an unfinished stub with a
        // placeholder column mapping, so registering it would only route files into a
        // parser that cannot handle them. Add the rule once the parser is real.
        ['revolut_trading_pdf', 'Revolut Trading (PDF)', 'Broker\\V3\\Import\\Pdf\\RevolutTradingPdfParser',
            '^(revolut.*)?trading-account-statement|^account-statement',
            'Transakce v (USD|EUR|CZK)|(USD|EUR|CZK) Transactions|Obchod\\s*[-–]\\s*(Market|Limit|Tržní|Limitní)|Trade\\s*[-–]\\s*(Market|Limit)', 90]
    ];

    $stmt = $pdo->prepare("INSERT INTO broker_import_rules (config_name, broker_name, parser_class, file_pattern, content_regex, priority)
                           VALUES (?, ?, ?, ?, ?, ?)
                           ON CONFLICT (config_name) DO UPDATE SET
                           broker_name = EXCLUDED.broker_name,
                           parser_class = EXCLUDED.parser_class,
                           file_pattern = EXCLUDED.file_pattern,
                           content_regex = EXCLUDED.content_regex,
                           priority = EXCLUDED.priority");
    foreach ($rules as $rule) {
        $stmt->execute($rule);
    }

    echo "ALL SCHEMAS VERIFIED AND UPDATED.";

} catch (Throwable $e) {
    http_response_code(500);
    echo "FATAL ERROR: " . $e->getMessage();
}
