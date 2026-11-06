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
}

namespace {
    uses(PestDatasetRowsFixture\TestCase::class);

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
    ]);

    dataset(dataset: ['n' => fn (): string => $this->token], name: 'reversed');

    it('binds rows of with()', function (string $_name): void {})->with([
        'arrow row' => fn (): string => $this->user,
        'nested row' => [fn (): string => test()->token, 'x'],
        'call row' => [fn (): string => test()->label()],
    ]);

    it('binds rows of every with() argument', function (string $_name): void {})
        ->with(['a' => fn (): string => $this->token], ['b' => fn (): string => test()->token]);

    it('still binds a closure dataset', function (string $_name): void {})->with(fn (): array => [$this->user]);

    describe('in a block', function (): void {
        it('binds rows of describe()->with()', function (string $_name): void {});
    })->with(['row' => fn (): string => $this->token]);

    // Rows deeper than Pest binds them are not bound.
    it('leaves deeper closures alone', function (string $_name): void {})->with([
        [[fn (): string => $this->token]],
    ]);
}
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a non-class context
