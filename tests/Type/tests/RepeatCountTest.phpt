--FILE--
<?php declare(strict_types=1);

namespace PestRepeatFixture {
    final class Own
    {
        public function repeat(int $times): self
        {
            return $this;
        }
    }

    (new Own())->repeat(0);
}

namespace {
    /**
     * @param int<min, 0> $nonPositive
     * @param negative-int $negative
     * @param positive-int $positive
     * @param 0|-2 $both
     * @param 0|2 $mixedSign
     * @param list<int> $args
     */
    function repeatCounts(int $n, int $nonPositive, int $negative, int $positive, int $both, int $mixedSign, mixed $unknown, array $args): void
    {
        it('zero', function (): void {})->repeat(0);
        it('negative', function (): void {})->repeat(-1);
        it('flaky zero', function (): void {})->flaky(0);
        it('named', function (): void {})->repeat(times: 0);
        it('range', function (): void {})->repeat($nonPositive);
        it('negative int', function (): void {})->flaky($negative);

        it('every literal', function (): void {})->repeat($both);
        /** @psalm-suppress InvalidScalarArgument */
        it('after unpack', function (): void {})->repeat(...$args, times: 0);

        it('three', function (): void {})->repeat(3);
        it('int', function (): void {})->repeat($n);
        it('positive', function (): void {})->repeat($positive);
        it('flaky default', function (): void {})->flaky();
        it('flaky int', function (): void {})->flaky($n);
        it('flaky three', function (): void {})->flaky(tries: 3);
        it('some literals', function (): void {})->repeat($mixedSign);
        /** @psalm-suppress MixedArgument */
        it('mixed', function (): void {})->repeat($unknown);
        it('zero or one', function (): void {})->repeat(rand(0, 1) === 0 ? 0 : 1);
        it('chained', function (): void {})->group('a')->repeat(2)->flaky(5);
    }
}
?>
--EXPECTF--
PestInvalidRepeatCount on line %d: Pest throws while loading the file when repeat() receives 0: the count must be greater than 0.
PestInvalidRepeatCount on line %d: Pest throws while loading the file when repeat() receives -1: the count must be greater than 0.
PestInvalidRepeatCount on line %d: Pest throws while loading the file when flaky() receives 0: the count must be greater than 0.
PestInvalidRepeatCount on line %d: Pest throws while loading the file when repeat() receives 0: the count must be greater than 0.
PestInvalidRepeatCount on line %d: Pest throws while loading the file when repeat() receives int<min, 0>: the count must be greater than 0.
PestInvalidRepeatCount on line %d: Pest throws while loading the file when flaky() receives int<min, -1>: the count must be greater than 0.
PestInvalidRepeatCount on line %d: Pest throws while loading the file when repeat() receives -2|0: the count must be greater than 0.
PestInvalidRepeatCount on line %d: Pest throws while loading the file when repeat() receives 0: the count must be greater than 0.
