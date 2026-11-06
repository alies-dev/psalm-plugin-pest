--FILE--
<?php declare(strict_types=1);

namespace PestDatasetRowsFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        public string $token = '';
    }

    trait Helpers
    {
        public function helper(): string
        {
            return 'h';
        }
    }
}

namespace {
    uses(PestDatasetRowsFixture\TestCase::class, PestDatasetRowsFixture\Helpers::class);

    beforeEach(function (): void {
        $this->user = 'bob';
    });

    // Pest binds every closure that is a row, or a value of an array row, to the running test.
    it('binds rows of with()', function (string $_name): void {})->with([
        fn (): string => $this->user,
        'keyed row' => function (): string {
            $_this = $this;
            /** @psalm-check-type-exact $_this = PestDatasetRowsFixture\TestCase */
            return $this->token;
        },
        'nested row' => [fn (): string => $this->user, 'x'],
        'test() row' => function (): string {
            $test = test();
            /** @psalm-check-type-exact $test = PestDatasetRowsFixture\TestCase */
            return $test->token;
        },
        'trait row' => fn (): string => $this->helper(),
        'unpacked row' => [...[fn (): string => test()->user]],
    ]);

    // The parameter type is not looked at: a closure Pest passes as is is bound, too.
    it('binds rows of every with() call', function (\Closure $_a, string $_b): void {})
        ->with([fn (): string => $this->token])
        ->with(['b' => fn (): string => test()->token], [fn (): string => $this->helper()]);

    describe('in a block', function (): void {
        it('binds rows of describe()->with()', function (string $_name): void {});

        beforeEach()->with(['row' => function (): string {
            $_this = $this;
            /** @psalm-check-type-exact $_this = PestDatasetRowsFixture\TestCase */
            return test()->token;
        }]);
    })->with(['row' => fn (): string => $this->token, [fn (): string => test()->user, 1]]);

    // A lazy dataset: Pest calls the closure itself unbound, then binds the rows it returns or yields.
    it('binds rows of a lazy dataset', function (string $_name): void {})
        ->with(fn (): array => [fn (): string => $this->token, 'k' => [fn (): string => test()->user]])
        ->with(function (): \Generator {
            yield fn (): string => $this->token;
            yield 'k' => [fn (): string => test()->helper(), 'x'];
            yield from [fn (): string => $this->user];

            return [fn (): string => $this->token];
        });

    describe('in a block with a lazy dataset', function (): void {
        it('binds its rows', function (string $_name): void {});
    })->with(fn (): array => [fn (): string => $this->token]);

    dataset('named', [
        'arrow row' => fn (): string => $this->user,
        'nested row' => [fn (): string => test()->token, 'x'],
        'trait row' => fn (): string => $this->helper(),
    ]);

    dataset(dataset: ['n' => fn (): string => $this->token], name: 'reversed');

    dataset('lazy', function (): array {
        if (\random_int(0, 1) === 1) {
            return ['a' => fn (): string => $this->token];
        }

        return ['b' => [fn (): string => test()->user]];
    });

    dataset('lazy arrow', fn (): array => [fn (): string => $this->helper()]);

    dataset('generator', function (): \Generator {
        yield 'k' => fn (): string => test()->token;
    });

    // A static closure cannot be bound: Pest's Closure::bind() fails on it.
    it('leaves a static row unbound', function (string $_name): void {})
        ->with([static fn (): string => $this->token]);

    // Pest binds no closure deeper than a value of an array row.
    it('leaves deeper closures unbound', function (array $_row): void {})->with([
        [[fn (): string => $this->token]],
    ]);
}
?>
--EXPECTF--
InvalidScope on line %d: %s
InvalidScope on line %d: %s
