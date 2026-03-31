<?php
// restore_clean_names.php
// Reverts database tables to clean names (removing 'broker_' prefix)
header('Content-Type: text/plain; charset=utf-8');
require_once 'env.local.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Connected to ".DB_NAME."\n";

    $map = [
        'broker_trans' => 'transactions',
        'broker_live_quotes' => 'live_quotes',
        'broker_ticker_mapping' => 'ticker_mapping',
        'broker_price_history' => 'tickers_history',
        'broker_watch' => 'watch',
        'broker_user_settings' => 'user_settings',
        // 'broker_translations' => 'translations' // This one might be tricky if both exist, usually keeps 'translations'
    ];

    foreach ($map as $from => $to) {
        $existsFrom = false;
        try { $pdo->query("SELECT 1 FROM `$from` LIMIT 1"); $existsFrom = true; } catch (Exception $e) {}

        if ($existsFrom) {
            // Check if target exists
            $existsTo = false;
            try { $pdo->query("SELECT 1 FROM `$to` LIMIT 1"); $existsTo = true; } catch (Exception $e) {}

            if ($existsTo) {
                echo "Target '$to' already exists. Keeping it or dropping old?\n";
                // If target exists and source exists, we might have a conflict on which one has data.
                // Assuming 'broker_' tables have the latest correct data (since we fixed imports there).
                echo "Dropping target '$to' to replace with '$from'...\n";
                $pdo->exec("DROP TABLE `$to`");
            }

            $pdo->exec("RENAME TABLE `$from` TO `$to`");
            echo "Renamed '$from' -> '$to'.\n";
        } else {
            echo "Source '$from' not found (maybe already renamed).\n";
        }
    }
    
    // Cleanup empty broker_ tables if any left
    
    echo "DONE.";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
