<?php

declare(strict_types=1);

dataset('prices', [[1.0], [2.0]]);

// Pest loads the datasets before any test as well.
expect()->extend('toBeLabelled', function (string $label): void {
    $this->toBe($label);
});
