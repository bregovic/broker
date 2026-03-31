<?php
// fix_data_50.php
header('Content-Type: text/plain; charset=utf-8');
$envPaths = [__DIR__ . '/env.local.php', __DIR__ . '/../env.local.php'];
foreach ($envPaths as $path) { if (file_exists($path)) { require_once $path; break; } }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS);
    
    // 1. Reset -50% change
    $count = $pdo->exec("UPDATE live_quotes SET change_percent = 0 WHERE change_percent = -50.00 OR change_percent = -50");
    echo "Reset $count rows with -50% change.\n";

    // 2. Reset 0 prices to NULL to force thorough update
    $count2 = $pdo->exec("UPDATE live_quotes SET current_price = NULL WHERE current_price = 0");
    echo "Reset $count2 rows with 0 price.\n";
    
    // 3. Optional: Clean extreme values in history if any
    
    echo "Done. Please click 'Rychlé Ceny' again.";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
