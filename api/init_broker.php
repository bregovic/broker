<?php
/**
 * DB INITIALIZER (Modernized V3 Version)
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();
    echo "CONNECTED TO: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

    // 1. MODERN TRANSACTIONS SCHEMA (Ensuring both old and new columns for compatibility)
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        trans_id SERIAL PRIMARY KEY,
        user_id INTEGER,
        ticker VARCHAR(20),
        transaction_date DATE,
        type VARCHAR(20),
        quantity DECIMAL(18, 8),
        price_per_unit DECIMAL(18, 8),
        currency VARCHAR(10),
        fee DECIMAL(18, 8),
        total_amount DECIMAL(18, 8),
        source_broker VARCHAR(50),
        broker_trade_id VARCHAR(100) UNIQUE,
        metadata JSONB,
        -- Keep legacy columns for compatibility
        date DATE,
        amount DECIMAL(18, 8),
        price DECIMAL(18, 8),
        platform VARCHAR(50)
    )");
    echo "VERIFIED: transactions (v3 structure)\n";

    // 2. IMPORT RULES SCHEMA
    $pdo->exec("CREATE TABLE IF NOT EXISTS broker_import_rules (
        id SERIAL PRIMARY KEY,
        config_name VARCHAR(100) UNIQUE NOT NULL,
        broker_name VARCHAR(100),
        parser_class VARCHAR(255) NOT NULL,
        file_pattern TEXT,
        content_regex TEXT
    )");
    echo "VERIFIED: broker_import_rules\n";

    // 3. SEEDING RULES
    $rules = [
        ['revolut_trading_pdf', 'Revolut Trading (PDF)', 'Broker\\V3\\Import\\Pdf\\RevolutTradingPdfParser', 'revolut.*trading|account-statement.*cs-cz', 'Account Statement|USD Transactions|Trade.*-.*(Market|Limit)|Dividend|Výpis z účtu|Transakce v USD|Obchod|Dividenda'],
        ['revolut_crypto_pdf', 'Revolut Crypto (PDF)', 'Broker\\V3\\Import\\Pdf\\RevolutCryptoPdfParser', 'revolut.*crypto|account-statement.*crypto', 'Výpis z účtu s kryptomĕnami|Crypto.*Statement|Staking rewards?|Odměna za staking|Kryptoměny'],
        ['revolut_commodity_pdf', 'Revolut Commodity (PDF)', 'Broker\\V3\\Import\\Pdf\\RevolutCommodityPdfParser', 'revolut.*commodity|account-statement.*commodity', 'Výpis v.*(XAU|XAG|XPT|XPD)|Smĕněno na.*(XAU|XAG|XPT|XPD)|Exchanged to.*(XAU|XAG|XPT|XPD)|Drahé kovy|Komodity']
    ];

    $stmt = $pdo->prepare("INSERT INTO broker_import_rules (config_name, broker_name, parser_class, file_pattern, content_regex) 
                           VALUES (?, ?, ?, ?, ?) 
                           ON CONFLICT (config_name) DO UPDATE SET 
                           broker_name=EXCLUDED.broker_name, 
                           parser_class=EXCLUDED.parser_class, 
                           file_pattern=EXCLUDED.file_pattern, 
                           content_regex=EXCLUDED.content_regex");
    
    foreach ($rules as $rule) {
        $stmt->execute($rule);
        echo "SEEDED RULE: {$rule[1]}\n";
    }

    echo "\nDATABASE IS MODERNIZED AND READY!";

} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFATAL ERROR: " . $e->getMessage();
}
