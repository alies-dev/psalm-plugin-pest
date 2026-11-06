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

    function either(bool $pest, \Pest\PendingCalls\TestCall $test): void
    {
        $call = $pest ? $test : new Own();
        $call->repeat(0);
    }
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

        test('test zero', function (): void {})->repeat(0);
        it('mixed case', function (): void {})->REPEAT(0);
        if ($n < 1) {
            it('narrowed', function (): void {})->repeat($n);
        }

        it('every literal', function (): void {})->repeat($both);
        // Psalm rejects binding a list<int> spread to `int $times`; the spread is never read as the count.
        /** @psalm-suppress InvalidScalarArgument */
        it('after unpack', function (): void {})->repeat(...$args, times: 0);

        describe('group', function (): void {})->repeat(0);
        describe('group', function (): void {})->flaky(-1);
        describe('group', function (): void {})->group('g')->repeat(times: -1);
        describe('group', function (): void {})->flaky(tries: 0);

        beforeEach(function (): void {})->repeat(0);
        beforeEach(function (): void {})->flaky(tries: -1);
        beforeEach(function (): void {})->group('g')->repeat(0);

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
        describe('group', function (): void {})->repeat(2);
        it('chained', function (): void {})->group('a')->repeat(2)->flaky(5);
        beforeEach(function (): void {})->repeat(2);

        foreach ([1, 2, 3] as $times) {
            it('foreach', function (): void {})->repeat($times);
        }
        for ($i = 1; $i <= 3; $i++) {
            it('for', function (): void {})->repeat($i);
        }
        $k = 3;
        while ($k > 0) {
            it('while', function (): void {})->repeat($k);
            $k--;
        }
    }
}
?>
--EXPECTF--
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives 0.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives -1.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but flaky() receives 0.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives 0.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives int<min, 0>.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but flaky() receives int<min, -1>.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives 0.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives 0.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives int<min, 0>.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives -2|0.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives 0.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives 0.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but flaky() receives -1.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives -1.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but flaky() receives 0.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives 0.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but flaky() receives -1.
PestInvalidRepeatCount on line %d: Pest rejects a count below 1, but repeat() receives 0.
