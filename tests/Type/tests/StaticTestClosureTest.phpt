--FILE--
<?php declare(strict_types=1);

namespace PestStaticClosureFixture {
    function it(string $description, \Closure $closure): void
    {
        $closure->call(new \stdClass(), $description);
    }

    // A namespaced it() is not Pest's global function.
    it('own function', static function (): void {
    });
}

namespace {
    test('static', static function (): void {
        expect(true)->toBeTrue();
    });
    it('static arrow', static fn () => expect(true)->toBeTrue());
    it('named argument', closure: static function (): void {
    });
    beforeEach(static function (): void {
    });
    afterEach(static fn () => null);

    test('plain', function (): void {
    });
    it('plain arrow', fn () => expect(true)->toBeTrue());
    test('static outside the closure argument', function (): void {
        $helper = static fn (): int => 1;
        echo $helper();
    });
    describe('group', static function (): void {
    });
    beforeAll(static function (): void {
    });
    afterAll(static function (): void {
    });
}
?>
--EXPECTF--
PestStaticTestClosure on line %d: Pest cannot bind $this to a static closure and aborts the run when test() receives one; remove the `static` keyword.
PestStaticTestClosure on line %d: Pest cannot bind $this to a static closure and aborts the run when it() receives one; remove the `static` keyword.
PestStaticTestClosure on line %d: Pest cannot bind $this to a static closure and aborts the run when it() receives one; remove the `static` keyword.
PestStaticTestClosure on line %d: Pest cannot bind $this to a static closure and aborts the run when beforeEach() receives one; remove the `static` keyword.
PestStaticTestClosure on line %d: Pest cannot bind $this to a static closure and aborts the run when afterEach() receives one; remove the `static` keyword.
