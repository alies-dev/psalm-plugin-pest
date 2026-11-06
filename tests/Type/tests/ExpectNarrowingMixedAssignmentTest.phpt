--FILE--
<?php declare(strict_types=1);

namespace PestExpectNarrowingMixedFixture {
    final class Foo {}

    function mixedValue(): mixed
    {
        return null;
    }

    // Like `assertIsArray($a)`, narrowing a mixed variable with expect() drops the MixedAssignment of its first assignment.
    function narrowed(): void
    {
        $array = mixedValue();
        expect($array)->toBeArray();

        $object = mixedValue();
        expect($object)->toBeInstanceOf(Foo::class);
    }

    // An assertion that leaves the variable mixed keeps the MixedAssignment.
    function notNarrowed(): void
    {
        $compared = mixedValue();
        expect($compared)->toBeGreaterThan(1);

        // Excluding null from mixed leaves it mixed: `assertNotNull($mixed)` keeps the MixedAssignment too.
        $notNull = mixedValue();
        expect($notNull)->not->toBeNull();
    }

    function consume(mixed $_): void {}

    // The MixedAssignment is dropped per variable: only the narrowed one loses it.
    function onlyOneNarrowed(): void
    {
        $narrowed = mixedValue();
        $untouched = mixedValue();
        expect($narrowed)->toBeString();
        consume($untouched);
    }

    // Only the first assignment of a narrowed variable loses its MixedAssignment, as with `assertIsInt($mixed)`.
    function reassigned(): void
    {
        $value = mixedValue();
        expect($value)->toBeInt();
        $value = mixedValue();
        consume($value);
    }
}

namespace {
    use function PestExpectNarrowingMixedFixture\mixedValue;

    // The issue's repro: a mixed variable narrowed inside a Pest test closure.
    it('narrows a mixed variable', function (): void {
        $a = mixedValue();
        expect($a)->toBeArray();
    });
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $compared is being assigned to
MixedAssignment on line %d: Unable to determine the type that $notNull is being assigned to
MixedAssignment on line %d: Unable to determine the type that $untouched is being assigned to
MixedAssignment on line %d: Unable to determine the type that $value is being assigned to
