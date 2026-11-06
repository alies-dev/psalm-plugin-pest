--FILE--
<?php declare(strict_types=1);

namespace PestDatasetRowsFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        public string $token = '';

        public function label(): string
        {
            return $this->token;
        }
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

    dataset('named', [
        'arrow row' => fn (): string => $this->user,
        'nested row' => [fn (): string => test()->label(), 'x'],
        'closure row' => function (): string {
            $_this = $this;
            /** @psalm-check-type-exact $_this = PestDatasetRowsFixture\TestCase */
            return $this->token;
        },
        'trait row' => fn (): string => $this->helper(),
    ]);

    dataset(dataset: ['n' => fn (): string => $this->token], name: 'reversed');

    it('binds rows of with()', function (string $_name): void {})->with([
        'arrow row' => fn (): string => $this->user,
        'nested row' => [fn (): string => test()->token, 'x'],
        'call row' => [fn (): string => test()->label()],
        'trait row' => fn (): string => $this->helper(),
        'static row' => static fn (): string => 'x',
    ]);

    it('binds rows of every with() argument', function (string $_name, string $_other): void {})
        ->with(['a' => fn (): string => $this->token], ['b' => fn (): string => test()->token]);

    it('binds a lone row for a mixed parameter', function (mixed $_value): void {})
        ->with([fn (): string => $this->token]);

    it('binds a typed parameter of several', function (string $_a, int $_b): void {})
        ->with([[fn (): string => $this->token, 1]]);

    // An earlier with() fills the first parameters, so the rows of this one start later.
    it('binds rows after an earlier with()', function (\Closure $_c, string $_s): void {})
        ->with([fn () => fn () => 1])
        ->with([fn (): string => $this->token]);

    // describe()->with() rows come first, so the rows of a with() in the block start later.
    describe('with a block dataset', function (): void {
        it('binds rows after the block dataset', function (\Closure $_c, string $_s): void {})
            ->with([fn (): string => $this->token]);
    })->with([fn () => fn () => 1]);

    describe('in a block', function (): void {
        it('binds rows of describe()->with()', function (string $_name): void {});
    })->with(['row' => fn (): string => $this->token]);

    // Pest calls a closure that is the whole dataset from its static data provider.
    it('leaves a whole dataset closure unbound', function (string $_name): void {})->with(fn (): array => [$this->user]);

    describe('in a block with a dataset closure', function (): void {
        it('leaves it unbound', function (string $_name): void {});
    })->with(fn (): array => [$this->token]);

    // Pest passes a closure as is to a parameter typed Closure or callable.
    it('leaves a Closure parameter alone', function (\Closure $_c): void {})->with([fn (): string => $this->token]);

    // With several arguments, an untyped or mixed parameter also gets the closure as is.
    it('leaves an untyped parameter of several alone', function ($_a, int $_b): void {})
        ->with([[fn (): string => $this->token, 1]]);

    it('leaves a mixed parameter of several datasets alone', function (mixed $_a, int $_b): void {})
        ->with([fn (): string => $this->token], [1, 2]);

    // A lone named argument is not resolved.
    it('leaves a lone named argument alone', function (string $_a): void {})->with([['_a' => fn (): string => $this->token]]);

    // Rows deeper than Pest binds them are not bound.
    it('leaves deeper closures alone', function (string $_name): void {})->with([
        [[fn (): string => $this->token]],
    ]);
}
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a non-class context
InvalidScope on line %d: Invalid reference to $this in a non-class context
InvalidScope on line %d: Invalid reference to $this in a non-class context
MissingClosureParamType on line %d: Parameter $_a has no provided type
InvalidScope on line %d: Invalid reference to $this in a non-class context
InvalidScope on line %d: Invalid reference to $this in a non-class context
InvalidScope on line %d: Invalid reference to $this in a non-class context
InvalidScope on line %d: Invalid reference to $this in a non-class context
