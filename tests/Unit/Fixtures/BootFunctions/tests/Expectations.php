<?php

declare(strict_types=1);

// Pest's conventional home for expectations is a boot file too.
expect()->extend('toBeCounted', function (int $count): void {
    $this->toHaveCount($count);
});
