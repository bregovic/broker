<?php
// calculate_metrics.php - Calculates 52w High/Low and EMA 212 for all tickers
// AND updates schema if needed.

ini_set('max_execution_time', 300);
header('Content-Type: text/plain; charset=utf-8');

// Robust DB Connection
$envPaths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
    __DIR__ . '/../../env.local.php',
    __DIR__ . '/php/env.local.php',
    __DIR__ . '/env.php',
    __DIR__ . '/../env.php',
    __DIR__ . '/../../env.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.php'
];

foreach ($envPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

try {
    if (!defined('DB_HOST')) die("DB Config missing");

    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 1. Ensure Columns Exist
    echo "Checking schema...\n";
    $cols = $pdo->query("SHOW COLUMNS FROM live_quotes")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('high_52w', $cols)) {
        $pdo->exec("ALTER TABLE live_quotes ADD COLUMN high_52w DECIMAL(20,8) DEFAULT NULL");
        echo "Added column high_52w.\n";
    }
    if (!in_array('low_52w', $cols)) {
        $pdo->exec("ALTER TABLE live_quotes ADD COLUMN low_52w DECIMAL(20,8) DEFAULT NULL");
        echo "Added column low_52w.\n";
    }
    if (!in_array('ema_212', $cols)) {
        $pdo->exec("ALTER TABLE live_quotes ADD COLUMN ema_212 DECIMAL(20,8) DEFAULT NULL");
        echo "Added column ema_212.\n";
    }
    if (!in_array('asset_type', $cols)) {
        $pdo->exec("ALTER TABLE live_quotes ADD COLUMN asset_type VARCHAR(20) DEFAULT 'stock'");
        echo "Added column asset_type.\n";
    }

    // 2. Calculate Metrics
    echo "Calculating metrics...\n";
    $stmt = $pdo->query("SELECT id FROM live_quotes WHERE status='active'");
    $tickers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tickers as $ticker) {
        // Fetch History
        $histStmt = $pdo->prepare("SELECT date, price FROM tickers_history WHERE ticker = ? ORDER BY date ASC");
        $histStmt->execute([$ticker]);
        $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($history) === 0) continue;

        // 52 Week High/Low
        $oneYearAgo = date('Y-m-d', strtotime('-1 year'));
        $high = null;
        $low = null;

        foreach ($history as $h) {
            if ($h['date'] >= $oneYearAgo) {
                $p = (float)$h['price'];
                if ($high === null || $p > $high) $high = $p;
                if ($low === null || $p < $low) $low = $p;
            }
        }

        // EMA 212
        $period = 212;
        $ema = null;

        if (count($history) >= $period) {
            // Seed with SMA
            $sum = 0;
            for ($i = 0; $i < $period; $i++) {
                $sum += (float)$history[$i]['price'];
            }
            $ema = $sum / $period;
            $k = 2 / ($period + 1);

            // Calculate EMA for the rest
            for ($i = $period; $i < count($history); $i++) {
                $price = (float)$history[$i]['price'];
                $ema = ($price * $k) + ($ema * (1 - $k));
            }
        }

        // Update DB
        $upd = $pdo->prepare("UPDATE live_quotes SET high_52w = ?, low_52w = ?, ema_212 = ? WHERE id = ?");
        $upd->execute([$high, $low, $ema, $ticker]);
        echo "Updated $ticker: High=$high, Low=$low, EMA=" . ($ema ? number_format($ema, 2) : 'N/A') . "\n";
    }

    echo "Done.";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
