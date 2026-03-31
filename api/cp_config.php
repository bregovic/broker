<?php
// cp_config.php
// Copies ../php/env.local.php to ./env.local.php

$src = __DIR__ . '/../php/env.local.php';
$dest = __DIR__ . '/env.local.php';

if (copy($src, $dest)) {
    echo "Config copied to " . $dest . "\n";
    // Check content
    echo substr(file_get_contents($dest), 0, 100);
} else {
    echo "Failed to copy config. Src exists? " . (file_exists($src)?'YES':'NO');
}
