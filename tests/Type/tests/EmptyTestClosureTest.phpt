--FILE--
<?php declare(strict_types=1);

namespace PestEmptyClosureFixture {
    function it(string $description, \Closure $closure): void
    {
        $closure->call(new \stdClass(), $description);
    }

    // A namespaced it() of the file's own is not Pest's global function.
    it('own function', function (): void {
    });
}

namespace PestEmptyClosureOther {
    // No it() of its own here: the call falls back to Pest's global function.
    it('namespaced', function (): void {}); // reported
}

namespace {
    use function it as spec;

    it('registers users', function (): void {}); // reported
    test('only a comment', function (): void { /* nothing */ }); // reported
    it('named argument', closure: function (): void { // reported
    });
    it('chained', function (): void { // reported
    })->group('a')->throws(\RuntimeException::class);
    spec('aliased', function (): void {}); // reported
    $test = it('assigned', function (): void {}); // reported
    $test->group('a');
    if (\getenv('CI') !== false) {
        it('in a condition', function (): void {}); // reported
    }
    it('todo as a callable', function (): void {})->todo(...); // reported
    it('reported once when static', static function (): void {}); // static only

    it('todo', function (): void {})->todo();
    it('todo in the middle', function (): void {})->todo()->group('a');
    it('skipped', function (): void {})->skip();
    it('skipped conditionally', function (): void {})->skip(false);
    it('skipped on php', function (): void {})->skipOnPhp('<8.0');
    it('skipped on windows', function (): void {})->skipOnWindows();
    it('skipped on mac', function (): void {})->skipOnMac();
    it('skipped on linux', function (): void {})->skipOnLinux();
    it('skipped on ci', function (): void {})->skipOnCI();
    it('skipped locally', function (): void {})->skipLocally();
    it('only on windows', function (): void {})->onlyOnWindows();
    it('only on mac', function (): void {})->onlyOnMac();
    it('only on linux', function (): void {})->onlyOnLinux();
    it('expects no assertions', function (): void {})->throwsNoExceptions();
    $parked = it('skipped later', function (): void {});
    $parked->skip();

    /** @psalm-suppress PestEmptyTestClosure */
    it('a smoke test', function (): void {});

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

    function helper(): void
    {
        it('in a function', function (): void {});
    }
    \array_map(static function (int $_): void {
        it('in another closure', function (): void {});
    }, [1]);
}
?>
--EXPECTF--
PestEmptyTestClosure on line 17: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 23: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 24: The closure passed to test() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 25: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 27: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 29: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 30: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 33: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 35: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestStaticTestClosure on line 36: Pest cannot bind $this to a static closure and aborts the run when it() receives one; remove the `static` keyword.
