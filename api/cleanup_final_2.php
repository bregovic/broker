<?php
// cleanup_final_2.php
$files = ['fix_user_ids.php', 'debug_api.php', 'db_check.php', 'cleanup_final.php'];
foreach ($files as $f) {
    if (file_exists($f)) {
        unlink($f);
        echo "Deleted $f<br>";
    }
}
unlink(__FILE__);
echo "Cleanup 2 finished.";
?>
