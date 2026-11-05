<?php

declare(strict_types=1);

test('calls functions declared in the boot files', function (): void {
    $_thing = buildThing();
    /** @psalm-check-type-exact $_thing = int */

    $_name = requiredName();
    /** @psalm-check-type-exact $_name = string */

    $_list = helperList();
    /** @psalm-check-type-exact $_list = list<int> */

    $_flag = deepFlag();
    /** @psalm-check-type-exact $_flag = bool */

    expect(buildThing())->toBe(1);
});

test('still reports a function nobody declares', function (): void {
    undefinedEverywhere();
});
