--FILE--
<?php declare(strict_types=1);

namespace PestClosureMethodsFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        protected int $counter = 0;

        /** @psalm-pure */
        public function number(): int
        {
            return 1;
        }
    }
}

namespace {
    uses(PestClosureMethodsFixture\TestCase::class)
        ->beforeEach(function (): void {
            /** @psalm-check-type-exact $this = PestClosureMethodsFixture\TestCase */
            $this->counter = 1;
        })
        ->afterEach(fn() => $this->counter);

    // Pest binds a dataset closure to the test case, too.
    test('dataset closures are bound', function (int $n): void {
        expect($n)->toBeInt();
    })->with(function () {
        /** @psalm-check-type-exact $this = PestClosureMethodsFixture\TestCase */
        yield $this->number();
    })->with(fn() => [$this->number()]);

    // Arguments are still checked.
    uses(PestClosureMethodsFixture\TestCase::class)->beforeEach('not a closure');
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Pest\PendingCalls\UsesCall::beforeEach expects Closure[impure], but 'not a closure' provided
