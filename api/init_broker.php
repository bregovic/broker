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
    $rules = [
        ['revolut_trading_pdf', 'Revolut Trading (PDF)', 'Broker\\V3\\Import\\Pdf\\RevolutTradingPdfParser', 'revolut.*trading|trading-account-statement|account-statement', 'Account Statement|USD Transactions|Trade.*-.*(Market|Limit)|Dividend|Výpis z účtu|Transakce v USD|Obchod|Dividenda'],
        ['revolut_crypto_pdf', 'Revolut Crypto (PDF)', 'Broker\\V3\\Import\\Pdf\\RevolutCryptoPdfParser', 'revolut.*crypto|account-statement.*crypto', 'Výpis z účtu s kryptomĕnami|Crypto.*Statement|Staking rewards?|Odměna za staking|Kryptoměny'],
        ['revolut_commodity_pdf', 'Revolut Commodity (PDF)', 'Broker\\V3\\Import\\Pdf\\RevolutCommodityPdfParser', 'revolut.*commodity|account-statement.*commodity', 'Výpis v.*(XAU|XAG|XPT|XPD)|Smĕněno na.*(XAU|XAG|XPT|XPD)|Exchanged to.*(XAU|XAG|XPT|XPD)|Drahé kovy|Komodity']
    ];

    $stmt = $pdo->prepare("INSERT INTO broker_import_rules (config_name, broker_name, parser_class, file_pattern, content_regex) 
                           VALUES (?, ?, ?, ?, ?) 
                           ON CONFLICT (config_name) DO UPDATE SET 
                           broker_name = EXCLUDED.broker_name,
                           parser_class = EXCLUDED.parser_class,
                           file_pattern = EXCLUDED.file_pattern,
                           content_regex = EXCLUDED.content_regex");
    foreach ($rules as $rule) {
        $stmt->execute($rule);
    }

    echo "ALL SCHEMAS VERIFIED AND UPDATED.";

} catch (Throwable $e) {
    http_response_code(500);
    echo "FATAL ERROR: " . $e->getMessage();
}
