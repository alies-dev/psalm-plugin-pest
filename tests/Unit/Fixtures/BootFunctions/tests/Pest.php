<?php

declare(strict_types=1);

// A file Pest.php requires counts as a boot file too: its functions reach every test.
require_once __DIR__ . '/Support/required.php';

function buildThing(): int
{
    return 1;
}

// Registered here and used from every test file, whatever the value's type.
expect()->extend('toBePrice', function (float $expected): void {
    $this->toBe($expected);
});
