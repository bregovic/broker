<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';

try {
    $pdo = get_pdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "=== DATABASE DIAGNOSTICS ===\n";
    echo "Driver: $driver\n\n";

    $tables = ['transactions', 'dividends', 'live_quotes', 'watch', 'rates', 'ticker_mapping', 'user_settings', 'tickers_history'];
    echo "=== TABLE ROWS ===\n";
    foreach ($tables as $t) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            echo "- $t: $count rows\n";
        } catch (Exception $e) {
            echo "- $t: FAILED (" . $e->getMessage() . ")\n";
        }
    }
    echo "\n";

    echo "=== UNIQUE TICKERS IN TRANSACTIONS ===\n";
    try {
        $stmt = $pdo->query("SELECT DISTINCT ticker FROM transactions ORDER BY ticker ASC");
        $tickers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Count: " . count($tickers) . "\n";
        echo "Tickers: " . implode(', ', $tickers) . "\n";
    } catch (Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

    echo "=== UNIQUE TICKERS IN LIVE_QUOTES ===\n";
    try {
        $stmt = $pdo->query("SELECT DISTINCT id, ticker, asset_type, current_price, status FROM live_quotes ORDER BY id ASC");
        $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Count: " . count($quotes) . "\n";
        foreach ($quotes as $q) {
            echo "  - ID: {$q['id']} / Ticker: {$q['ticker']} | Type: {$q['asset_type']} | Price: {$q['current_price']} | Status: {$q['status']}\n";
        }
    } catch (Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";

} catch (Exception $e) {
    echo "CRITICAL: " . $e->getMessage() . "\n";
}
