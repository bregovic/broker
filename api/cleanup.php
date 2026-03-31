<?php
// cleanup.php
// Delete sensitive migration script
if (file_exists('migrate_db_v3_fixed.php')) {
    unlink('migrate_db_v3_fixed.php');
    echo "Deleted migrate_db_v3_fixed.php<br>";
} else {
    echo "File migrate_db_v3_fixed.php not found.<br>";
}

// Delete self
unlink(__FILE__);
echo "Deleted cleanup.php. Bye!";
?>
