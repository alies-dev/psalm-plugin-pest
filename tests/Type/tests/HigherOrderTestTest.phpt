--FILE--
<?php declare(strict_types=1);

namespace PestHigherOrderFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        /** @psalm-mutation-free */
        public function actingAsAdmin(): static
        {
            return $this;
        }

        /** @psalm-mutation-free */
        public function actingAs(string $_role): static
        {
            return $this;
        }

        /** @psalm-pure */
        private function secret(): int
        {
            return 1;
        }
    }

    trait Impersonates
    {
        /** @psalm-mutation-free */
        public function impersonate(int $_id): static
        {
            return $this;
        }
    }
}

namespace {
    uses(PestHigherOrderFixture\TestCase::class, PestHigherOrderFixture\Impersonates::class);

    $_call = it('forwards unknown methods to the bound TestCase')->actingAsAdmin()->group('g');
    /** @psalm-check-type-exact $_call = Pest\PendingCalls\TestCall */
    it('checks the TestCase method params')->actingAs('admin')->with([1]);
    // Methods of traits bound next to the TestCase resolve too.
    $_traitCall = it('forwards to a bound trait')->impersonate(1);
    /** @psalm-check-type-exact $_traitCall = Pest\PendingCalls\TestCall */
    it('rejects wrong arguments')->actingAs(1);
    it('rejects unknown names')->nope();
    it('rejects private methods')->secret();
    it('starts higher-order expectations')->expect(1)->toBe(1)->and('a')->toBeString();
}
?>
--EXPECTF--
InvalidScalarArgument on line %d: Argument 1 of Pest\PendingCalls\TestCall::actingas expects string, but 1 provided
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\TestCall::nope does not exist
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\TestCall::secret does not exist
