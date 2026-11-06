--FILE--
<?php declare(strict_types=1);

// The value of the expectation a custom matcher runs on is unknown when it is declared: `$this` is `Expectation<mixed>`, not `Expectation<null>` (#77).
expect()->extend('toBeKnown', function (): void {
    /** @psalm-check-type-exact $this = \Pest\Expectation<mixed> */
    $_value = $this->value;
    /** @psalm-check-type-exact $_value = mixed */

    // A type matcher on an unknown value is not impossible.
    $this->toBeString();
    $this->toBeInstanceOf(\stdClass::class);
    $this->toBeNull();
});

expect()->extend('toBeBoth', function (): void {
    $this->toBeInt()->toBe(1);
});

// An impossible assertion on a known value inside the closure is still reported.
expect()->extend('toBeImpossible', function (): void {
    expect(42)->toBeString();
});
?>
--EXPECTF--
PestImpossibleExpectation on line %d: Pest's toBeString() is a strict type check that always fails for 42; assert the type the value has or remove the assertion.
