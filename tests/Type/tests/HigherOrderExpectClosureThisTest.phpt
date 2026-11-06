--FILE--
<?php declare(strict_types=1);

namespace PestHigherOrderExpectFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
    }
}

namespace Other {
    /** @return \Pest\Expectation<mixed> */
    function test(string $description): \Pest\Expectation
    {
        return new \Pest\Expectation($description);
    }
}

namespace {
    uses(PestHigherOrderExpectFixture\TestCase::class);

    beforeEach(function (): void {
        $this->cart = new ArrayObject();
    });

    // Pest replays `expect()` / `and()` through HigherOrderCallables, which binds the closure to the TestCase.
    it('binds an arrow function')->expect(fn () => $this->cart->count())->toBe(0);
    test('binds a test() chain')->expect(fn () => $this->cart->count())->toBe(0);
    it('binds a closure block')->expect(function () {
        /** @psalm-check-type-exact $this = PestHigherOrderExpectFixture\TestCase */
        return $this->cart->count();
    })->toBe(0);
    it('binds and() after expect()')->expect(1)->and(fn () => $this->cart->count())->toBe(0);
    it('binds and() further down the chain')->expect(1)->toBe(1)->and(function () {
        /** @psalm-check-type-exact $this = PestHigherOrderExpectFixture\TestCase */
        return $this->cart->count();
    })->toBe(0);
    it('binds and() after a not chain')->expect(1)->not->toBe(2)->and(function () {
        /** @psalm-check-type-exact $this = PestHigherOrderExpectFixture\TestCase */
        return $this->cart->count();
    })->toBe(0);

    // Pest binds a skip() condition to null, so `$this` is not available there.
    it('keeps skip() unbound')->skip(fn (): mixed => $this->cart);

    // Outside a higher-order chain `and()` only wraps its argument: the closure is not run on the TestCase.
    expect(1)->and(fn (): mixed => $this->cart);

    // Pest looks `expect` / `and` up case-sensitively, so a differently cased name binds nothing.
    it('keeps And() unbound')->expect(1)->And(fn (): mixed => $this->cart)->toBe(0);
    it('keeps Expect() unbound')->Expect(fn (): mixed => $this->cart);

    // A project's own `test()` is not Pest's: its Expectation is a plain value.
    \Other\test('x')->and(fn (): mixed => $this->cart);
}
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a non-class context
InvalidScope on line %d: Invalid reference to $this in a non-class context
InvalidScope on line %d: Invalid reference to $this in a non-class context
InvalidScope on line %d: Invalid reference to $this in a non-class context
InvalidScope on line %d: Invalid reference to $this in a non-class context
