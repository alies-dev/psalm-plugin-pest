--FILE--
<?php declare(strict_types=1);

namespace PestHigherOrderExpectFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
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

    // Pest binds a skip() condition to null, so `$this` is not available there.
    it('keeps skip() unbound')->skip(fn (): mixed => $this->cart);

    // Outside a higher-order chain `and()` only wraps its argument: the closure is not run on the TestCase.
    expect(1)->and(fn (): mixed => $this->cart);
}
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a non-class context
InvalidScope on line %d: Invalid reference to $this in a non-class context
