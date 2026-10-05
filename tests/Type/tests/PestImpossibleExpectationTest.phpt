--FILE--
<?php declare(strict_types=1);

namespace PestImpossibleExpectationFixture {
    interface Shape {}

    interface Named {}

    class Circle implements Shape {}

    class Square {}

    final class Sealed {}

    final class User
    {
        public string $name = 'Ada';

        public ?User $parent = null;
    }

    /**
     * @param list<int> $list
     * @param array<string, int> $map
     * @param int|string $either
     */
    function impossible(string $string, int $int, array $list, array $map, User $user, $either, Circle $circle, float $float): void
    {
        expect(42)->toBeString();
        expect($string)->toBeInt();
        expect($string)->toBeNull();
        expect($user)->toBeNull();
        expect($user->name)->toBeInt();
        expect($int)->toBeFloat();
        expect($int)->toBeBool();
        expect($int)->toBeArray();
        expect($int)->toBeTrue();
        expect($list)->toBeString();
        expect($map)->toBeList();
        expect($map)->toBeNumeric();
        expect($user)->toBeScalar();
        expect($user)->toBeResource();
        expect($string)->TOBEINT();

        // Pest's `toBeFloat()` is strict: no int passes it.
        expect(1)->toBeFloat();
        expect($float)->toBeInt();

        // A union fails only when no member can pass.
        expect($either)->toBeNull();

        // Two unrelated classes, or a final class and an interface it does not implement.
        expect($circle)->toBeInstanceOf(Square::class);
        expect(new Sealed())->toBeInstanceOf(Shape::class);

        // Higher-order members are checked against the member's type.
        expect($user)->name->toBeInt();
        expect($user)->parent->toBeString();

        // Only the first broken step of a chain is reported.
        expect($string)->toBeInt()->toBeNull()->not->toBeString();

        // Reported without a statement-level narrowing too.
        $_expectation = expect($string)->toBeInt();
    }

    /**
     * @param list<int> $list
     * @param int|string $either
     * @param resource $resource
     * @param class-string $class
     */
    function possible(string $string, string $other, string $class, int $int, array $list, ?User $user, ?User $maybe, $either, Circle $circle, Shape $shape, Square $square, mixed $mixed, $resource, object $object, callable $callable, float|int $number): void
    {
        expect(42)->toBeInt();
        expect($string)->toBeString();
        expect($user)->toBeNull();
        expect($maybe)->toBeInstanceOf(User::class);
        expect($either)->toBeInt();
        expect($mixed)->toBeString();
        expect($number)->toBeFloat();
        expect($string)->toBeNumeric();
        expect($list)->toBeArray();
        expect($list)->toBeList();
        expect($list)->toBeIterable();
        expect($resource)->toBeResource();
        expect($callable)->toBeObject();
        expect($other)->toBeCallable();
        expect($object)->toBeInstanceOf(User::class);

        // A subclass of a non-final class could implement the interface; two interfaces can meet in one class.
        expect($square)->toBeInstanceOf(Shape::class);
        expect($shape)->toBeInstanceOf(Named::class);
        expect($circle)->toBeInstanceOf(Circle::class);
        expect($circle)->toBeInstanceOf($class);

        // Negation and `each` never report: they are not `Expectation` receivers.
        expect($int)->not->toBeString();
        expect(42)->not->toBeInt();
        expect($list)->each->toBeString();
        expect($other)->not->toBeInt()->toBeString();

        // Members unknown to the value are typed `mixed`.
        expect($maybe)->unknown->toBeInt();

        // Other assertions are not type checks.
        expect($other)->toBe(42);
        expect($other)->toBeEmpty();
    }
}
?>
--EXPECTF--
PestImpossibleExpectation on line %d: Pest's toBeString() is a strict type check that always fails for 42; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeInt() is a strict type check that always fails for string; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeNull() is a strict type check that always fails for string; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeNull() is a strict type check that always fails for PestImpossibleExpectationFixture\User; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeInt() is a strict type check that always fails for string; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeFloat() is a strict type check that always fails for int; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeBool() is a strict type check that always fails for int; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeArray() is a strict type check that always fails for int; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeTrue() is a strict type check that always fails for int; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeString() is a strict type check that always fails for list<int>; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeList() is a strict type check that always fails for array<string, int>; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeNumeric() is a strict type check that always fails for array<string, int>; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeScalar() is a strict type check that always fails for PestImpossibleExpectationFixture\User; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeResource() is a strict type check that always fails for PestImpossibleExpectationFixture\User; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's TOBEINT() is a strict type check that always fails for string; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeFloat() is a strict type check that always fails for 1; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeInt() is a strict type check that always fails for float; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeNull() is a strict type check that always fails for int|string; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeInstanceOf() is a strict type check that always fails for PestImpossibleExpectationFixture\Circle; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeInt() is a strict type check that always fails for string; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeString() is a strict type check that always fails for PestImpossibleExpectationFixture\User|null; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeInt() is a strict type check that always fails for string; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeInt() is a strict type check that always fails for string; assert the type the value has or remove the assertion.
