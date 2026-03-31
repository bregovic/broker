<?php
// rename_tables.php
// Fix table names to match legacy code requirements (prefix 'broker_')

header('Content-Type: text/plain; charset=utf-8');

// Load config
$envPaths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php'
];
foreach ($envPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "Connected to ".DB_NAME."<br>";

    $renames = [
        'transactions' => 'broker_trans',
        'live_quotes' => 'broker_live_quotes',
        'tickers_history' => 'broker_price_history'
    ];

    foreach ($renames as $from => $to) {
        // Check if source exists
        $srcExists = false;
        try {
            $pdo->query("SELECT 1 FROM `$from` LIMIT 1");
            $srcExists = true;
        } catch (Exception $e) {
            echo "Source table '$from' does not exist (maybe already renamed?).<br>";
        }

        if ($srcExists) {
            // Check if target exists (to avoid error)
            $tgtExists = false;
            try {
                $pdo->query("SELECT 1 FROM `$to` LIMIT 1");
                $tgtExists = true;
            } catch (Exception $e) {}

            if ($tgtExists) {
                echo "Target table '$to' already exists. DROPPING target first.<br>";
                $pdo->exec("DROP TABLE `$to`");
            }

            try {
                $pdo->exec("RENAME TABLE `$from` TO `$to`");
                echo "Renamed '$from' -> '$to' SUCCESS.<br>";
            } catch (Exception $e) {
                echo "Rename '$from' -> '$to' FAILED: " . $e->getMessage() . "<br>";
            }
        }
    }
    
    echo "DONE.";

} catch (Exception $e) {
    die("DB Error: " . $e->getMessage());
}
?>
