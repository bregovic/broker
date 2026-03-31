<?php
// cleanup_done.php
// Removes temporary debug and fix scripts

$files = [
    'debug_market.php',
    'restore_clean_names.php',
    'truncate_transactions.php',
    'cleanup_done.php' // Self-delete
];

foreach ($files as $f) {
    if (file_exists(__DIR__ . '/' . $f)) {
        if (unlink(__DIR__ . '/' . $f)) {
            echo "Deleted $f\n";
        } else {
            echo "Failed to delete $f\n";
        }
    } else {
        echo "File $f not found\n";
    }
}
?>
