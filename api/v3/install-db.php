<?php
namespace Broker\V3;

// Zahrneme DB layer
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

// Bezpečnostní token (shodný se Shanonem, můžeš si pak změnit)
$token = $_GET['token'] ?? '';
if ($token !== 'investyx2026') {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

try {
    $db = DB::connect();
    $messages = [];

    // --- DEFINICE TABULEK ---
    
    $migrations = [
        '001_transactions' => "
            CREATE TABLE IF NOT EXISTS transactions (
                rec_id SERIAL PRIMARY KEY,
                ticker VARCHAR(20) NOT NULL,
                transaction_date DATE NOT NULL,
                type VARCHAR(20) NOT NULL, -- BUY, SELL, DIVIDEND, FEE, TAX
                quantity NUMERIC(18, 8) DEFAULT 0,
                price_per_unit NUMERIC(18, 8) DEFAULT 0,
                currency VARCHAR(10) NOT NULL, -- USD, CZK, EUR
                fee NUMERIC(18, 8) DEFAULT 0,
                total_amount NUMERIC(18, 8) NOT NULL,
                source_broker VARCHAR(50), -- Fio, Revolut, XTB...
                broker_trade_id VARCHAR(100), -- Unikátní ID z výpisu brokera pro de-duplikaci
                metadata JSONB DEFAULT '{}', -- Ostatní extra data
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_trans_ticker ON transactions(ticker);
            CREATE INDEX IF NOT EXISTS idx_trans_date ON transactions(transaction_date);
            CREATE INDEX IF NOT EXISTS idx_trans_broker_id ON transactions(broker_trade_id);
        ",
        
        '002_dividends' => "
            CREATE TABLE IF NOT EXISTS dividends (
                rec_id SERIAL PRIMARY KEY,
                ticker VARCHAR(20) NOT NULL,
                ex_date DATE,
                pay_date DATE NOT NULL,
                gross_amount NUMERIC(18, 8) NOT NULL,
                tax_amount NUMERIC(18, 8) DEFAULT 0,
                net_amount NUMERIC(18, 8) NOT NULL,
                currency VARCHAR(10) NOT NULL,
                broker_dividend_id VARCHAR(100),
                metadata JSONB DEFAULT '{}',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_div_ticker ON dividends(ticker);
            CREATE INDEX IF NOT EXISTS idx_div_pay_date ON dividends(pay_date);
        ",

        '003_market_data' => "
            CREATE TABLE IF NOT EXISTS market_quotes (
                ticker VARCHAR(20) PRIMARY KEY,
                price NUMERIC(18, 8) NOT NULL,
                currency VARCHAR(10) NOT NULL,
                last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                change_percent NUMERIC(8, 4),
                metadata JSONB DEFAULT '{}'
            );
        ",

        '004_fx_rates' => "
            CREATE TABLE IF NOT EXISTS fx_rates (
                currency_from VARCHAR(5) NOT NULL,
                currency_to VARCHAR(5) NOT NULL,
                rate_date DATE NOT NULL,
                rate NUMERIC(18, 8) NOT NULL,
                source VARCHAR(20) DEFAULT 'CNB',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (currency_from, currency_to, rate_date)
            );
            CREATE INDEX IF NOT EXISTS idx_fx_date ON fx_rates(rate_date);
        ",

        '005_ticker_aliases' => "
            CREATE TABLE IF NOT EXISTS ticker_aliases (
                alias VARCHAR(50) PRIMARY KEY, -- Např. 'BRK.B', 'BRKB'
                actual_ticker VARCHAR(20) NOT NULL, -- Např. 'BRK-B'
                description TEXT
            );
        ",

        '006_system_labels' => "
            CREATE TABLE IF NOT EXISTS sys_labels (
                label_key VARCHAR(100) NOT NULL,
                lang VARCHAR(5) NOT NULL,
                translation TEXT NOT NULL,
                PRIMARY KEY (label_key, lang)
            );
        "
    ];

    foreach ($migrations as $name => $sql) {
        $db->exec($sql);
        $messages[] = "Migrace $name dokončena.";
    }

    echo json_encode([
        'success' => true,
        'message' => 'Databáze v3 byla úspěšně inicializována v PostgreSQL.',
        'details' => $messages
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
