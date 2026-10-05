--FILE--
<?php declare(strict_types=1);

namespace PestExpectationChainFixture {
    final class Requirement
    {
        public string $level = 'required';
    }

    final class Tile
    {
        public string $formatId = 'meta';

        public ?Requirement $requirement = null;
    }

    /**
     * @param list<int> $ids
     * @param list<Tile> $tiles
     */
    function chains(int $id, string $name, array $ids, Tile $tile, array $tiles): void
    {
        // Assertions are declared one @mixin deeper than `->not` / `->each` look.
        $_not = expect($id)->not->toBeNull();
        /** @psalm-check-type-exact $_not = \Pest\Expectation<int|null> */

        $_each = expect($ids)->each->toBeInt();
        /** @psalm-check-type-exact $_each = \Pest\Expectations\EachExpectation<list<int>|null> */

        $_eachChain = expect($ids)->each->toBeInt()->toBeGreaterThan(0);
        /** @psalm-check-type-exact $_eachChain = \Pest\Expectations\EachExpectation<list<int>|null> */

        // An assertion hands back the outer expectation, so `->not` / `->each` stay reachable.
        $_same = expect($id)->toBe(1);
        /** @psalm-check-type-exact $_same = \Pest\Expectation<int|null> */

        $_opposite = expect($id)->not;
        /** @psalm-check-type-exact $_opposite = \Pest\Expectations\OppositeExpectation<int|null> */

        expect($ids)->toBeArray()->not->toBeEmpty();
        expect($name)->toContain('a')->not->toContain('b');
        expect($name)->toBeString()->each->toBeString();
        expect($ids)->each->not->toBeNull()->toBeInt();

        // Any other name is a higher-order expectation over the value's member.
        $_member = expect($tile)->formatId;
        /** @psalm-check-type-exact $_member = \Pest\Expectations\HigherOrderExpectation<\Pest\Expectation<Tile|null>, mixed> */

        $_deep = expect($tile)->formatId->toBe('meta')->requirement->level->toBe('required');
        /** @psalm-check-type-exact $_deep = \Pest\Expectations\HigherOrderExpectation<\Pest\Expectation<Tile|null>, mixed> */
        expect($tile)->formatId->not->toBe('x');

        expect($tiles)->sequence(fn ($tile) => $tile->formatId->toBe('meta')->requirement->toBeObject());

        // Argument checking and unknown assertions survive the proxy.
        expect($id)->not->toBe();
        expect($id)->not->toBeBananas();
    }
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for toBe - expecting expected to be passed
UndefinedMagicMethod on line %d: Magic method Pest\Expectations\OppositeExpectation::tobebananas does not exist
