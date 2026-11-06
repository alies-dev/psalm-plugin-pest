<?php

declare(strict_types=1);

function deepFlag(): bool
{
    return true;
}

// Any boot file may register expectations, not only Pest.php.
expect()->extend('toBeDeep', function (): void {
    $this->toBeTrue();
});
