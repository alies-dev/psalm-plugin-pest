<?php

declare(strict_types=1);

/** @return list<int> */
function helperList(): array
{
    return [1, 2];
}

// Runs after boot, when Pest.php is loaded too.
function helperUsingPest(): int
{
    $_thing = buildThing();
    /** @psalm-check-type-exact $_thing = int */

    return $_thing;
}
