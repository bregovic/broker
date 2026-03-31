<?php
// cleanup_final.php
$files = ['rename_tables.php', 'debug_ticker.php', 'cleanup.php'];
foreach ($files as $f) {
    if (file_exists($f)) {
        unlink($f);
        echo "Deleted $f<br>";
    } else {
        echo "File $f not found<br>";
    }
}
unlink(__FILE__);
echo "Cleanup finished.";
?>
