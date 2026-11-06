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

// A step on describe() applies to every test in the block, nested ones too, but not to its hooks.
describe('a failing group', function (): void {
    beforeEach(fn () => expect(true)->toBeFalse());

    it('fails in a failing group', fn () => expect(1)->toBeString());

    describe('a nested group', function (): void {
        test('fails in a nested group', fn () => expect(1)->toBeString());
    });
})->fails();

describe('a throwing group', function (): void {
    it('throws in a throwing group', fn () => expect(1)->toBeString());
})->throws(PHPUnit\Framework\AssertionFailedError::class);

describe('a group with a failing hook', function (): void {
    it('is a plain test before the hook', fn () => expect(1)->toBeString());

    // beforeEach() applies to the tests after it in its block, not to the hook closure itself.
    beforeEach(fn () => expect(true)->toBeFalse())->fails();

    it('fails after the hook', fn () => expect(1)->toBeString());
});

it('is a plain test after a describe() hook', fn () => expect(1)->toBeString());

it('is a plain test at the top level', function (): void {
    expect(true)->toBeFalse();
});

beforeEach()->fails();

it('fails after a top level hook', fn () => expect(1)->toBeString());

describe('a group after a top level hook', function (): void {
    it('fails in a group after a top level hook', fn () => expect(1)->toBeString());
});
?>
--EXPECTF--
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeString() is a strict type check that always fails for 1; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeString() is a strict type check that always fails for 1; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
