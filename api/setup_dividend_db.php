<?php
/**
 * setup_dividend_db.php
 * Standalone & inline migration script to create dividend tables and columns.
 */

// If run directly via CLI/Web
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: text/plain; charset=utf-8');
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    require_once __DIR__ . '/config.php';

    try {
        $pdo = get_pdo();
        ensure_dividend_db_setup($pdo);
        echo "ALL SCHEMAS VERIFIED AND UPDATED SUCCESSFULLY.\n";
    } catch (Throwable $e) {
        http_response_code(500);
        echo "FATAL ERROR: " . $e->getMessage() . "\n";
    }
}

/**
 * Self-healing DB Setup function
 */
function ensure_dividend_db_setup(PDO $pdo) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // 1. Create dividend_history table
    $sqlDivHist = "CREATE TABLE IF NOT EXISTS dividend_history (
        ticker VARCHAR(20) NOT NULL,
        ex_date DATE NOT NULL,
        amount DECIMAL(18, 8) NOT NULL,
        currency VARCHAR(10) NOT NULL,
        source VARCHAR(50) DEFAULT 'yahoo',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ticker, ex_date)
    )";
    $pdo->exec($sqlDivHist);

    // 2. Add columns to live_quotes table safely
    $liveQuotesCols = [
        'ex_dividend_date' => 'DATE',
        'payout_ratio' => 'DECIMAL(18, 8)',
        'dividend_yield' => 'DECIMAL(18, 8)',
        'dividend_rate' => 'DECIMAL(18, 8)',
        'dividend_frequency' => 'VARCHAR(50)',
        'five_year_avg_yield' => 'DECIMAL(18, 8)'
    ];

    foreach ($liveQuotesCols as $col => $def) {
        try {
            // Check if column already exists
            $exists = false;
            if ($driver === 'pgsql') {
                $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name='live_quotes' AND column_name=?");
                $stmt->execute([$col]);
                $exists = (bool)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM live_quotes LIKE ?");
                $stmt->execute([$col]);
                $exists = (bool)$stmt->fetchColumn();
            }

            if (!$exists) {
                $pdo->exec("ALTER TABLE live_quotes ADD COLUMN $col $def");
            }
        } catch (Exception $e) {
            // Column might already exist or table lock, ignore
            error_log("setup_dividend_db: Warning adding $col - " . $e->getMessage());
        }
    }

    // 3. Self-healing check for tickers_history source column
    try {
        $hasSource = false;
        if ($driver === 'pgsql') {
            $stmtCol = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name='tickers_history' AND column_name='source'");
            $stmtCol->execute();
            $hasSource = (bool)$stmtCol->fetchColumn();
        } else {
            $stmtCol = $pdo->prepare("SHOW COLUMNS FROM tickers_history LIKE 'source'");
            $stmtCol->execute();
            $hasSource = (bool)$stmtCol->fetchColumn();
        }
        if (!$hasSource) {
            $pdo->exec("ALTER TABLE tickers_history ADD COLUMN source VARCHAR(50)");
        }
    } catch (Exception $colEx) {
        error_log("setup_dividend_db: Warning checking/adding source column to tickers_history - " . $colEx->getMessage());
    }

    // 4. Self-healing check for ticker_mapping isin column
    try {
        $hasIsin = false;
        if ($driver === 'pgsql') {
            $stmtCol = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name='ticker_mapping' AND column_name='isin'");
            $stmtCol->execute();
            $hasIsin = (bool)$stmtCol->fetchColumn();
        } else {
            $stmtCol = $pdo->prepare("SHOW COLUMNS FROM ticker_mapping LIKE 'isin'");
            $stmtCol->execute();
            $hasIsin = (bool)$stmtCol->fetchColumn();
        }
        if (!$hasIsin) {
            $pdo->exec("ALTER TABLE ticker_mapping ADD COLUMN isin VARCHAR(12) NULL");
        }
    } catch (Exception $colEx) {
        error_log("setup_dividend_db: Warning checking/adding isin column to ticker_mapping - " . $colEx->getMessage());
    }
}

