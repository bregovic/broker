<?php
// debug_market.php
// Debug extraction of market data
header('Content-Type: text/plain; charset=utf-8');
require_once 'env.local.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    $userId = 1; // Assuming user 1
    echo "Simulating User ID: $userId\n\n";

    // 1. Check Watchlist
    $watch = $pdo->query("SELECT ticker FROM watch WHERE user_id=$userId")->fetchAll(PDO::FETCH_COLUMN);
    echo "Watchlist count: " . count($watch) . "\n";
    echo "Sample: " . implode(', ', array_slice($watch, 0, 5)) . "\n\n";

    // 2. Check Ticker Mapping for these
    if (!empty($watch)) {
        $sample = $watch[0];
        $stmt = $pdo->prepare("SELECT * FROM ticker_mapping WHERE ticker = ?");
        $stmt->execute([$sample]);
        $map = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Mapping for '$sample': " . ($map ? "FOUND" : "MISSING") . "\n";
        print_r($map);
        echo "\n";
    }

    // 3. Check Live Quotes for these
    if (!empty($watch)) {
        $sample = $watch[0];
        $stmt = $pdo->prepare("SELECT * FROM live_quotes WHERE id = ?");
        $stmt->execute([$sample]);
        $qt = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Quote for '$sample': " . ($qt ? "FOUND" : "MISSING") . "\n";
        print_r($qt);
        echo "\n";
    }

    // 4. Run the Main Query
    echo "Running Main Query...\n";
    $sql = "SELECT DISTINCT t.ticker, t.company_name, 
                   COALESCE(l.current_price, q.price) as price, 
                   l.change_percent as change_percent,
                   l.current_price as raw_live_price,
                   q.price as raw_hist_price
            FROM ticker_mapping t
            LEFT JOIN live_quotes l ON CONVERT(t.ticker USING utf8mb4) = CONVERT(l.id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            LEFT JOIN tickers_history q ON CONVERT(t.ticker USING utf8mb4) = CONVERT(q.ticker USING utf8mb4) COLLATE utf8mb4_unicode_ci AND q.date = (SELECT MAX(date) FROM tickers_history WHERE CONVERT(ticker USING utf8mb4)=CONVERT(t.ticker USING utf8mb4) COLLATE utf8mb4_unicode_ci)
            WHERE CONVERT(t.ticker USING utf8mb4) IN (SELECT DISTINCT CONVERT(id USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM transactions WHERE user_id=:uid)
               OR CONVERT(t.ticker USING utf8mb4) IN (SELECT CONVERT(ticker USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM watch WHERE user_id=:uid)
        ORDER BY t.ticker ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Rows returned: " . count($rows) . "\n";
    if (count($rows) > 0) {
        print_r($rows[0]);
    } else {
        // Debug why?
        echo "No rows. Checking if any tickers exist in mapping that are in watch...\n";
        $sql2 = "SELECT count(*) FROM ticker_mapping WHERE ticker IN (SELECT ticker FROM watch WHERE user_id=1)";
        echo "Matching count: " . $pdo->query($sql2)->fetchColumn() . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
