--FILE--
<?php declare(strict_types=1);

namespace PestCustomExpectationFixture {
    final class Cart
    {
        public float $total = 1.0;
    }

    /** @param array<int, float> $floats */
    function custom(string $text, ?int $maybe, float $amount, Cart $cart, array $floats): void
    {
        // A scalar value is no reason to report a registered name; the call hands the same expectation back.
        $_price = expect($amount)->toBePrice(19.99);
        /** @psalm-check-type-exact $_price = \Pest\Expectation<float> */

        $_chained = expect($text)->toBeUntyped()->toBeString();
        /** @psalm-check-type-exact $_chained = \Pest\Expectation<string> */

        $_nullable = expect($maybe)->toBePrice(1.0, 2);
        /** @psalm-check-type-exact $_nullable = \Pest\Expectation<int|null> */

        // The closure's own return value is dropped, unless it is an ArchExpectation.
        $_arch = expect($cart)->toBeArchy();
        /** @psalm-check-type-exact $_arch = \Pest\Arch\Contracts\ArchExpectation */

        // `->not`, `->each` and a member of the value run it too.
        $_not = expect($amount)->not->toBePrice(1.0);
        /** @psalm-check-type-exact $_not = \Pest\Expectation<float> */

        $_each = expect($floats)->each->toBePrice(1.0);
        /** @psalm-check-type-exact $_each = \Pest\Expectations\EachExpectation<array<int, float>> */

        $_member = expect($cart)->total->toBePrice(1.0);
        /** @psalm-check-type-exact $_member = \Pest\Expectations\HigherOrderExpectation<\Pest\Expectation<Cart>, Cart> */

        // Arguments are checked against the closure's signature.
        expect(1.0)->toBePrice();
        expect(1.0)->toBePrice('x');
        expect(1.0)->not->toBePrice();

        // A name nobody registered is still reported.
        expect(19.99)->toBeCost(1.0);
        expect(1.0)->not->toBeCost(1.0);
        expect($floats)->each->toBeCost(1.0);

        // Pest's table is case sensitive, and `Other\expect()` is not Pest's.
        expect(1.0)->tobeprice(1.0);
        expect(1.0)->toBeOther();

        // A closure held in a variable has no signature to check against.
        expect(1.0)->toBeVariable(1, 'two');
    }
}

namespace Other {
    function expect(): \Pest\Expectation
    {
        return \expect();
    }

    \Other\expect()->extend('toBeOther', function (): void {});
}

namespace {
    expect()->extend('toBePrice', function (float $expected, ?int $decimal = null): void {
        $this->toBe(\round($expected, $decimal ?? 2));
    });

    $held = function (): void {};
    expect()->extend('toBeVariable', $held);

    expect()->extend('toBeUntyped', fn() => $this->toBeInt());

    expect()->extend('toBeArchy', function (): \Pest\Arch\Contracts\ArchExpectation {
        return expect('x')->toBeClass();
    });
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for Pest\Expectation::tobeprice - expecting expected to be passed
InvalidScalarArgument on line %d: Argument 1 of Pest\Expectation::tobeprice expects float, but 'x' provided
TooFewArguments on line %d: Too few arguments for Pest\Expectations\OppositeExpectation::tobeprice - expecting expected to be passed
UndefinedMagicMethod on line %d: Magic method float(19.99)::tobecost does not exist
UndefinedMagicMethod on line %d: Magic method Pest\Expectations\OppositeExpectation::tobecost does not exist
UndefinedMagicMethod on line %d: Magic method Pest\Expectations\EachExpectation::tobecost does not exist
UndefinedMagicMethod on line %d: Magic method float(1)::tobeprice does not exist
UndefinedMagicMethod on line %d: Magic method float(1)::tobeother does not exist
