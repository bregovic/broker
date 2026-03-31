<?php
/**
 * DB INITIALIZER (Ultra-Safe Alter Version)
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

function addColumnSafe($pdo, $table, $column, $definition) {
    try {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        echo "ADDED COLUMN: $column to $table\n";
    } catch (Exception $e) {
        // Ignorujeme, pokud už sloupec existuje
    }
}

try {
    $pdo = get_pdo();
    echo "CONNECTED TO: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

    // 1. ENSURE TRANSACTIONS TABLE & COLUMNS
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (trans_id SERIAL PRIMARY KEY)");
    addColumnSafe($pdo, 'transactions', 'ticker', 'VARCHAR(20)');
    addColumnSafe($pdo, 'transactions', 'transaction_date', 'DATE');
    addColumnSafe($pdo, 'transactions', 'type', 'VARCHAR(20)');
    addColumnSafe($pdo, 'transactions', 'quantity', 'DECIMAL(18, 8)');
    addColumnSafe($pdo, 'transactions', 'price_per_unit', 'DECIMAL(18, 8)');
    addColumnSafe($pdo, 'transactions', 'currency', 'VARCHAR(10)');
    addColumnSafe($pdo, 'transactions', 'fee', 'DECIMAL(18, 8)');
    addColumnSafe($pdo, 'transactions', 'total_amount', 'DECIMAL(18, 8)');
    addColumnSafe($pdo, 'transactions', 'source_broker', 'VARCHAR(50)');
    addColumnSafe($pdo, 'transactions', 'broker_trade_id', 'VARCHAR(100)');
    addColumnSafe($pdo, 'transactions', 'metadata', 'JSONB');
    
    // Add unique constraint separately to avoid crash if exists
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_broker_trade_id ON transactions (broker_trade_id)");
    } catch (Exception $e) {}

    // 2. ENSURE IMPORT RULES TABLE & COLUMNS
    $pdo->exec("CREATE TABLE IF NOT EXISTS broker_import_rules (id SERIAL PRIMARY KEY)");
    addColumnSafe($pdo, 'broker_import_rules', 'config_name', 'VARCHAR(100) UNIQUE');
    addColumnSafe($pdo, 'broker_import_rules', 'broker_name', 'VARCHAR(100)');
    addColumnSafe($pdo, 'broker_import_rules', 'parser_class', 'VARCHAR(255)');
    addColumnSafe($pdo, 'broker_import_rules', 'file_pattern', 'TEXT');
    addColumnSafe($pdo, 'broker_import_rules', 'content_regex', 'TEXT');

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
        echo "RE-SEEDED RULE: {$rule[1]}\n";
    }

    echo "\nALL DONE! APP IS READY AND MODERNIZED.";

} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFATAL ERROR: " . $e->getMessage();
}
