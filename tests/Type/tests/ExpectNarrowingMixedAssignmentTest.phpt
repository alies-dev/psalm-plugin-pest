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
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $compared is being assigned to
MixedAssignment on line %d: Unable to determine the type that $notNull is being assigned to
