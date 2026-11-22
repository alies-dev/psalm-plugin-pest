<?php

declare(strict_types=1);

// Stands in for the vendor/autoload.php Composer generates: Pest first, then the `files` autoloads of
// composer/autoload_files.php, which is what makes Psalm scan a package's registrations.
$loader = require __DIR__ . '/../../../../../vendor/autoload.php';

foreach (require __DIR__ . '/composer/autoload_files.php' as $file) {
    require_once $file;
}

return $loader;
