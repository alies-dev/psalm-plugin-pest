--FILE--
<?php declare(strict_types=1);

namespace PestEmptyClosureFixture {
    function it(string $description, \Closure $closure): void
    {
        $closure->call(new \stdClass(), $description);
    }

    // A namespaced it() is not Pest's global function.
    it('own function', function (): void {
    });
}

namespace PestEmptyClosureOther {
    // No it() of its own here: the call reaches Pest's global function.
    it('namespaced', function (): void {});
}

namespace {
    use function it as spec;

    it('registers users', function (): void {});
    test('only a comment', function (): void { /* nothing */ });
    it('named argument', closure: function (): void {
    });
    it('chained', function (): void {
    })->group('a')->throws(\RuntimeException::class);
    spec('aliased', function (): void {});
    describe('group', function (): void {
        it('nested', function (): void {
            // later
        });
    });

    it('todo chain', function (): void {})->todo();
    it('todo in the middle', function (): void {})->todo()->group('a');
    it('skipped', function (): void {})->skip();
    it('skipped on a platform', function (): void {})->skipOnWindows();
    todo('registers users');
    it('arrow', fn () => null);
    it('has a body', function (): void {
        expect(true)->toBeTrue();
    });
    it('has a statement without assertions', function (): void {
        echo 1;
    });
    it('no closure');
    describe('empty group', function (): void {
    });
    beforeEach(function (): void {
    });
}
?>
--EXPECTF--
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so PHPUnit marks the test risky for performing no assertions; add assertions or mark the test ->todo().
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so PHPUnit marks the test risky for performing no assertions; add assertions or mark the test ->todo().
PestEmptyTestClosure on line %d: The closure passed to test() is empty, so PHPUnit marks the test risky for performing no assertions; add assertions or mark the test ->todo().
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so PHPUnit marks the test risky for performing no assertions; add assertions or mark the test ->todo().
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so PHPUnit marks the test risky for performing no assertions; add assertions or mark the test ->todo().
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so PHPUnit marks the test risky for performing no assertions; add assertions or mark the test ->todo().
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so PHPUnit marks the test risky for performing no assertions; add assertions or mark the test ->todo().
