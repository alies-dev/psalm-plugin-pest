--FILE--
<?php declare(strict_types=1);

namespace PestHigherOrderExpectBindingFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
    }
}

namespace {
    uses(PestHigherOrderExpectBindingFixture\TestCase::class);

    beforeEach(function (): void {
        $this->cart = new ArrayObject();
    });

    // Pest replays `expect()` through HigherOrderCallables, which binds the closure to the TestCase.
    it('binds an arrow function')->expect(fn () => $this->cart->count())->toBe(0);
    test('binds a test() chain')->expect(fn () => $this->cart->count())->toBe(0);
    it('binds a closure block')->expect(function () {
        /** @psalm-check-type-exact $this = PestHigherOrderExpectBindingFixture\TestCase */
        return $this->cart->count();
    })->toBe(0);
}
?>
--EXPECTF--
