--FILE--
<?php declare(strict_types=1);

namespace PestHigherOrderFixture {
    final class Response
    {
        /** @psalm-mutation-free */
        public function assertOk(): static
        {
            return $this;
        }
    }

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
        public function get(string $_uri): Response
        {
            return new Response();
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
    /** @psalm-pure */
    function inProduction(): int
    {
        return 1;
    }

    function tagged(string $_tag): void
    {
    }

    function nothing(): void
    {
    }

    /** @psalm-pure */
    function asResponse(): PestHigherOrderFixture\Response
    {
        return new PestHigherOrderFixture\Response();
    }

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
    // Pest replays each call on the previous call's result.
    it('replays on returned objects')->get('/')->assertOk()->assertOk()->group('g');
    it('rejects names the returned object lacks')->get('/')->get('/again');
    it('starts higher-order expectations')->expect(1)->toBe(1)->and('a')->toBeString();
    // Without a TestCase method of that name, Pest calls the global function, with the chain's arguments.
    $_functionCall = it('calls a global function')->inProduction()->group('g');
    /** @psalm-check-type-exact $_functionCall = Pest\PendingCalls\TestCall */
    it('checks the function params')->tagged('a')->tagged(1);
    it('replays on an object the function returned')->asResponse()->assertOk();
    it('rejects names a function-returned object lacks')->asResponse()->actingAsAdmin();
    it('rejects names that are no function either')->definitelyMissing();
    it('keeps the target after a void function')->nothing()->actingAsAdmin();
    it('has no target after a function returned a scalar')->inProduction()->actingAsAdmin();
}
?>
--EXPECTF--
InvalidScalarArgument on line %d: Argument 1 of Pest\PendingCalls\TestCall::actingas expects string, but 1 provided
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\TestCall::nope does not exist
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\TestCall::secret does not exist
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\TestCall::get does not exist
InvalidScalarArgument on line %d: Argument 1 of Pest\PendingCalls\TestCall::tagged expects string, but 1 provided
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\TestCall::actingasadmin does not exist
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\TestCall::definitelymissing does not exist
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\TestCall::actingasadmin does not exist
