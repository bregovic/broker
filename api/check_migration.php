<?php
// check_migration.php
header('Content-Type: text/plain');

$newHost = 'md390.wedos.net';
$newDb = 'd372733_invest';
$newUser = 'w372733_invest'; // Web user should be able to SELECT
$newPass = 'Venca123!';

try {
    $pdo = new PDO("mysql:host={$newHost};dbname={$newDb};charset=utf8mb4", $newUser, $newPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to NEW DB.\n";
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}

$tables = [
    'transactions',
    'live_quotes',
    'rates',
    'ticker_mapping',
    'translations',
    'user_settings',
    'tickers_history'
];

foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t: $count rows\n";
    } catch (Exception $e) {
        echo "$t: ERROR (Table not found?)\n";
    }
}
