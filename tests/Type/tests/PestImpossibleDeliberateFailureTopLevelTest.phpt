--FILE--
<?php declare(strict_types=1);

// A test file without a namespace or functions: the chain sits at the top level.
it('fails at the top level', function (): void {
    expect(true)->toBeFalse();
})->fails();

it('throws at the top level', fn () => expect(1)->toBeString())->throws(PHPUnit\Framework\AssertionFailedError::class);

describe('a group', function (): void {
    it('fails inside describe()', function (): void {
        expect(true)->toBeFalse();
    })->fails();

    test('throws inside describe()', fn () => expect(1)->toBeString())->throws(PHPUnit\Framework\ExpectationFailedException::class);

    it('is a plain test inside describe()', function (): void {
        expect(true)->toBeFalse();
    });
});

// A `->fails()` on describe() itself does not mark its tests: the block is no test.
describe('a failing group', function (): void {
    it('is a plain test in a failing group', fn () => expect(1)->toBeString());
})->fails();

it('is a plain test at the top level', function (): void {
    expect(true)->toBeFalse();
});
?>
--EXPECTF--
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeString() is a strict type check that always fails for 1; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
