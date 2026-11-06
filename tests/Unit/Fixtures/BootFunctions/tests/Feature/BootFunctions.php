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

test('calls expectations registered in the boot files', function (): void {
    $_price = expect(\floatval('19.99'))->toBePrice(19.99);
    /** @psalm-check-type-exact $_price = \Pest\Expectation<float> */

    $_deep = expect(\mt_rand() > 0)->toBeDeep();
    /** @psalm-check-type-exact $_deep = \Pest\Expectation<bool> */

    expect(1.0)->toBePrice();
    expect(1.0)->toBeNowhere();

    expect([1])->toBeCounted();
    expect('a')->toBeLabelled();
    expect(1.0)->toBeOverridden(1.0);
});

expect()->extend('toBeOverridden', function (string $label): void {
    $this->toBe($label);
});

test('still reports a function nobody declares', function (): void {
    undefinedEverywhere();
});
