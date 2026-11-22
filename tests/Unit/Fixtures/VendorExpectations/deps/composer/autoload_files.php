<?php

// Hand-written stand-in for the autoload_files.php Composer generates. Psalm runs it to scan the packages' files;
// the plugin lists the same files from installed.json.

$vendorDir = dirname(__DIR__);

return [
    '0123456789abcdef0123456789abcdef' => $vendorDir . '/acme/plain/src/functions.php',
    'fedcba9876543210fedcba9876543210' => $vendorDir . '/acme/pest-snapshots/src/Functions.php',
];
