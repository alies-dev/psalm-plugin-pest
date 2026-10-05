<?php

declare(strict_types=1);

// Declares `$setupState` for this file only; Feature/Isolated.php shares the TestCase and must not see it.
beforeEach(function (): void {
    $this->setupState = 1;
});

test('reads a property assigned in beforeEach', function (): void {
    $_state = $this->setupState;
    /** @psalm-check-type-exact $_state = int */
});
