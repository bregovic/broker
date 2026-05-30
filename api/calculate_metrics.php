<?php
// calculate_metrics.php - Calculates 52w High/Low and EMA 212 for all tickers
// AND updates schema if needed.

ini_set('max_execution_time', 300);
header('Content-Type: text/plain; charset=utf-8');

// DB připojení přes jednotný adaptér (MySQL lokálně / PostgreSQL na Railway)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/quality_score.php';

try {
    $pdo = get_pdo();

    // 1. Ensure Columns Exist (idempotent, PostgreSQL-compatible)
    echo "Checking schema...\n";
    foreach ([
        "ALTER TABLE live_quotes ADD COLUMN IF NOT EXISTS high_52w DECIMAL(20,8) DEFAULT NULL",
        "ALTER TABLE live_quotes ADD COLUMN IF NOT EXISTS low_52w DECIMAL(20,8) DEFAULT NULL",
        "ALTER TABLE live_quotes ADD COLUMN IF NOT EXISTS ema_212 DECIMAL(20,8) DEFAULT NULL",
        "ALTER TABLE live_quotes ADD COLUMN IF NOT EXISTS asset_type VARCHAR(20) DEFAULT 'stock'",
    ] as $ddl) { try { $pdo->exec($ddl); } catch (Exception $e) {} }

    // 2. Calculate Metrics
    echo "Calculating metrics...\n";
    $stmt = $pdo->query("SELECT id, dividend_yield FROM live_quotes WHERE status='active'");
    $tickers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tickers as $row) {
        $ticker = $row['id'];
        $divYield = (float)($row['dividend_yield'] ?? 0);
        // Fetch History
        $histStmt = $pdo->prepare("SELECT history_date AS date, price FROM tickers_history WHERE ticker = ? ORDER BY history_date ASC");
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

        // All-time high/low + resilience (drawdown/recovery cycles) from full history.
        // Same algorithm as ajax-fetch-history.php so batch & lazy paths agree.
        $allPrices = array_map(function ($h) { return (float)$h['price']; }, $history);
        $ath = null; $atl = null;
        foreach ($allPrices as $p) {
            if ($p <= 0) continue;
            if ($ath === null || $p > $ath) $ath = $p;
            if ($atl === null || $p < $atl) $atl = $p;
        }
        // Composite quality score (growth + stability + longevity + resilience bonus).
        $resilience = quality_score($allPrices, $divYield);

        // Update DB
        $upd = $pdo->prepare("UPDATE live_quotes SET high_52w = ?, low_52w = ?, ema_212 = ?, all_time_high = ?, all_time_low = ?, resilience_score = ? WHERE id = ?");
        $upd->execute([$high, $low, $ema, $ath, $atl, $resilience, $ticker]);
        echo "Updated $ticker: 52wH=$high 52wL=$low ATH=$ath EMA=" . ($ema ? number_format($ema, 2) : 'N/A') . " Resil=$resilience\n";
    }

    echo "Done.";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
