<?php
// update_env.php
// Updates env.local.php with new database credentials

header('Content-Type: text/plain; charset=utf-8');

$targetFile = __DIR__ . '/../php/env.local.php';

if (!file_exists($targetFile)) {
    die("Configuration file not found at $targetFile");
}

$content = file_get_contents($targetFile);
if ($content === false) {
    die("Failed to read config file.");
}

$newDb = 'd372733_invest';
$newUser = 'w372733_invest'; // Using WEB user
$newPass = 'Venca123!';
$newHost = 'md390.wedos.net'; // DB Host might need update too

// Regex replacers for robust update
// define('DB_NAME', 'old'); -> define('DB_NAME', 'new');

function updateDefine($key, $newValue, $content) {
    if (preg_match("/define\('$key',\s*'[^']+'\);/", $content)) {
        return preg_replace("/define\('$key',\s*'[^']+'\);/", "define('$key', '$newValue');", $content);
    } elseif (preg_match('/define\("'.$key.'",\s*"[^"]+"\);/', $content)) {
        return preg_replace('/define\("'.$key.'",\s*"[^"]+"\);/', 'define("'.$key.'", "'.$newValue.'");', $content);
    }
    // If not found, append? No, better warn.
    return $content;
}

$newContent = $content;
$newContent = updateDefine('DB_HOST', $newHost, $newContent);
$newContent = updateDefine('DB_NAME', $newDb, $newContent);
$newContent = updateDefine('DB_USER', $newUser, $newContent);
$newContent = updateDefine('DB_PASS', $newPass, $newContent);

if ($newContent === $content) {
    echo "No changes made (regex failed to match defines?).\nContent preview:\n" . substr($content, 0, 500);
} else {
    // Write back
    if (file_put_contents($targetFile, $newContent)) {
        echo "Successfully updated env.local.php with new credentials.\n";
        echo "DB_NAME: $newDb\n";
        echo "DB_USER: $newUser\n";
    } else {
        echo "Failed to write to env.local.php (permissions?).\n";
    }
}
