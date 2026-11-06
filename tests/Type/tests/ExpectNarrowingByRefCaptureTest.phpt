--FILE--
<?php declare(strict_types=1);

namespace PestExpectNarrowingByRefFixture {
    final class Ex
    {
        public function __construct(public string $answer) {}
    }

    final class Bus
    {
        /** @var list<callable(Ex): void> */
        private static array $handlers = [];

        /** @param callable(Ex): void $handler */
        public static function on(callable $handler): void
        {
            self::$handlers[] = $handler;
        }

        public static function fire(): void
        {
            foreach (self::$handlers as $handler) {
                $handler(new Ex('a'));
            }
        }
    }

    final class Foo {}

    // The issue: a closure binding `$seen` by reference changes it after the assertion.
    it('observes a by-reference capture', function () {
        $seen = null;
        Bus::on(function (Ex $e) use (&$seen): void {
            $seen = $e;
        });

        expect($seen)->toBeNull();
        /** @psalm-check-type-exact $seen = Ex|null */

        Bus::fire();

        expect(1)->toBe(1)->and($seen)->toBeInstanceOf(Ex::class);
        expect($seen?->answer)->toBe('a');
    });

    // Control: without a by-reference capture the narrowing holds.
    it('narrows a variable nothing captures', function (?Foo $x) {
        expect($x)->toBeNull();
        /** @psalm-check-type-exact $x = null */

        expect($x)->toBeInstanceOf(Foo::class);
    });

    // A by-value capture cannot change the variable.
    it('narrows a variable captured by value', function (?Foo $x) {
        $callback = function () use ($x): ?Foo {
            return $x;
        };
        $callback();

        expect($x)->toBeNull();
        /** @psalm-check-type-exact $x = null */
    });

    // The capture belongs to the closure that holds it: a sibling test keeps its narrowing.
    it('captures by reference', function () {
        $seen = null;
        $callback = function () use (&$seen): void {
            $seen = new Ex('b');
        };
        $callback();

        expect($seen)->toBeNull();
        /** @psalm-check-type-exact $seen = Ex|null */
    });

    it('narrows next to a test that captures', function (?Foo $seen) {
        expect($seen)->toBeNull();
        /** @psalm-check-type-exact $seen = null */
    });

    // Only the captured variable is left alone.
    it('narrows the other variables', function (?Foo $other) {
        $seen = null;
        $callback = function () use (&$seen): void {
            $seen = 1;
        };
        $callback();

        expect($other)->toBeNull();
        /** @psalm-check-type-exact $other = null */
    });
    // A closure in between with its own `$x` makes the deeper capture another variable.
    it('narrows past a callback with its own parameter', function (?Foo $x) {
        $callback = function (?Foo $x): void {
            $deeper = function () use (&$x): void {
                $x = new Foo();
            };
            $deeper();
        };
        $callback(null);

        expect($x)->toBeNull();
        /** @psalm-check-type-exact $x = null */
    });

    it('narrows past a callback capturing by value', function (?Foo $x) {
        $callback = function () use ($x): void {
            $deeper = function () use (&$x): void {
                $x = new Foo();
            };
            $deeper();
        };
        $callback();

        expect($x)->toBeNull();
        /** @psalm-check-type-exact $x = null */
    });

    // Every closure of the chain binds it by reference: the test's variable can change.
    it('does not narrow along a chain of by-reference captures', function () {
        $x = null;
        $callback = function () use (&$x): void {
            $deeper = function () use (&$x): void {
                $x = new Foo();
            };
            $deeper();
        };
        $callback();

        expect($x)->toBeNull();
        /** @psalm-check-type-exact $x = Foo|null */
    });
}
?>
--EXPECTF--
PestImpossibleExpectation on line %d: Pest's toBeInstanceOf() is a strict type check that always fails for null; assert the type the value has or remove the assertion.
