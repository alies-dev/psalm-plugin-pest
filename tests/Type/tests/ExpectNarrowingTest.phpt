--FILE--
<?php declare(strict_types=1);

namespace PestExpectNarrowingFixture {
    final class Foo {}

    final class Holder
    {
        public ?string $prop = null;
    }

    // The issue: asserting non-null makes the value returnable as `object`.
    function anyAd(?\stdClass $ad): object
    {
        expect($ad)->not->toBeNull();
        /** @psalm-check-type-exact $ad = \stdClass */

        return $ad;
    }

    function positive(null|int|string $value, bool $flag, object $object): void
    {
        expect($value)->toBeNull();
        /** @psalm-check-type-exact $value = null */

        expect($flag)->toBeTrue();
        /** @psalm-check-type-exact $flag = true */

        expect($object)->toBeInstanceOf(Foo::class);
        /** @psalm-check-type-exact $object = Foo */
    }

    function negated(int|string $value, int|string $other): void
    {
        expect($value)->toBeString();
        /** @psalm-check-type-exact $value = string */

        expect($other)->not->toBeString();
        /** @psalm-check-type-exact $other = int */
    }

    function chained(null|int|string $a, null|int|string $b, string $c): void
    {
        expect($a)->not->toBeNull()->and($b)->toBeInt();
        /** @psalm-check-type-exact $a = int|string */
        /** @psalm-check-type-exact $b = int */

        // Other assertions keep the subject, and a negation lasts for one assertion.
        expect($c)->toBeString()->not->toBeEmpty();
        /** @psalm-check-type-exact $c = string */
    }

    function ends(null|int|string $a, null|int|string $b, Holder $holder): void
    {
        // `each` asserts on the items, not on the variable.
        expect($a)->each->toBeString();
        /** @psalm-check-type-exact $a = int|string|null */

        // A property path is not a plain variable: nothing to narrow, nothing reported.
        expect($holder->prop)->toBeString();
        /** @psalm-check-type-exact $holder = Holder */

        // `and()` of a non-variable ends the walk, after what came before it applied.
        expect($b)->toBeInt()->and($holder->prop)->toBeNull();
        /** @psalm-check-type-exact $b = int */
    }

    function silent(string $s): void
    {
        expect($s)->toBeString();
    }

    // A first-class callable builds a Closure; nothing is asserted.
    function firstClassCallable(null|int|string $a, null|int|string $b): void
    {
        expect($a)->not->toBeNull(...);
        /** @psalm-check-type-exact $a = int|string|null */

        // What ran before the Closure still applies.
        expect($b)->toBeInt()->toBeNull(...);
        /** @psalm-check-type-exact $b = int */
    }

    // Method names dispatch case-insensitively.
    function casing(null|int|string $a, null|int|string $b, null|int|string $c): void
    {
        expect($a)->NOT->tobenull()->AND($b)->TOBEINT();
        /** @psalm-check-type-exact $a = int|string */
        /** @psalm-check-type-exact $b = int */

        expect($c)->NoT()->ToBeString();
        /** @psalm-check-type-exact $c = int|null */
    }

    // The message argument of `toBeInstanceOf()` is optional and does not change the class.
    /** @param class-string $class */
    function instanceOfMessage(object $object, object $dynamic, object $spread, string $class): void
    {
        expect($object)->toBeInstanceOf(Foo::class, 'not a Foo');
        /** @psalm-check-type-exact $object = Foo */

        expect($dynamic)->toBeInstanceOf($class, 'who knows');
        /** @psalm-check-type-exact $dynamic = object */

        // A spread may fill any position: nothing is narrowed.
        expect($spread)->toBeInstanceOf(Foo::class, ...['x']);
        /** @psalm-check-type-exact $spread = object */
    }

    // Only Pest's own `expect()` names the subject: a helper may assert on anything.
    /** @return \Pest\Expectation<mixed> */
    function assertThat(mixed $value): \Pest\Expectation
    {
        return expect($value ?? 'fallback');
    }

    function helper(null|int|string $x): void
    {
        assertThat($x)->toBeString();
        /** @psalm-check-type-exact $x = int|string|null */
    }

    // The subject is reassigned inside the chain: the assertion ran on something else.
    function reassigned(null|int|string $value, bool $flag, null|int|string $other): void
    {
        expect($value)->toBeString()->and($value = $flag ? null : 'x');
        /** @psalm-check-type-exact $value = 'x'|null */

        expect($other)->toBeInt()->and($other)->toBe($other = null);
        /** @psalm-check-type-exact $other = null */
    }
}

namespace PestExpectNarrowingFixture\Own {
    /** @return \Pest\Expectation<mixed> */
    function expect(mixed $value): \Pest\Expectation
    {
        return \expect($value);
    }

    // A namespaced `expect()` shadows Pest's.
    function shadowed(null|int|string $x, null|int|string $y): void
    {
        expect($x)->toBeString();
        /** @psalm-check-type-exact $x = int|string|null */

        \expect($y)->toBeString();
        /** @psalm-check-type-exact $y = string */
    }
}

namespace PestExpectNarrowingFixture\Aliased {
    use function expect as check;

    function aliased(null|int|string $x): void
    {
        check($x)->toBeString();
        /** @psalm-check-type-exact $x = string */
    }
}
?>
--EXPECTF--
