<?php
$paths = [__DIR__ . '/env.local.php', __DIR__ . '/env.php', __DIR__ . '/../env.php'];
$loaded = false;
foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $loaded = true; break; } }
if (!$loaded) {
    // Try absolute fallback if needed or just error
    die("env.php not found. DIR: " . __DIR__);
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) { die($e->getMessage()); }

$data = [
    'filter_watched_on' => ['cs' => 'Jen sledované', 'en' => 'Watched Only'],
    'filter_watched_off' => ['cs' => 'Všechny tituly', 'en' => 'All Tickers'],
    'from_max' => ['cs' => 'od Max', 'en' => 'from Max'],
    'from_min' => ['cs' => 'od Min', 'en' => 'from Min'],
    'trend_ema' => ['cs' => 'EMA', 'en' => 'EMA']
];

foreach ($data as $key => $trans) {
    foreach ($trans as $lang => $text) {
        $stmt = $pdo->prepare("INSERT INTO translations (key_name, lang_code, translation_text) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE translation_text = ?");
        $stmt->execute([$key, $lang, $text, $text]);
    }
}
echo "Translations updated v2.";
