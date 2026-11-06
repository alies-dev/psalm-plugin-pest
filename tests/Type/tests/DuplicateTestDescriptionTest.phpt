--FILE--
<?php declare(strict_types=1);

namespace PestDuplicateFixture {
    function it(string $description, \Closure $_test): void
    {
        echo $description;
    }

    it('is a project function, not Pest', fn() => 1);
    it('is a project function, not Pest', fn() => 1);
}

namespace {
    it('logs in', fn () => null);
    it('logs in', fn () => null)->group('auth')->skip();
    test('logs in', fn () => null);
    test('logs in', fn () => null);
    todo('logs in');
    test('it logs in', fn () => null); // same key as it('logs in')

    test('plain', fn () => null);
    todo('plain');

    describe('auth', function (): void {
        it('logs in', fn () => null);
        it('logs in', fn () => null);
        it('logs out', fn () => null);

        describe('nested', function (): void {
            it('logs in', fn () => null);
        });
    });

    describe('auth', function (): void {
        it('logs out', fn () => null);
    });

    describe('other', function (): void {
        it('logs in', fn () => null);
    });

    $dynamic = 'dynamic';
    it($dynamic, fn () => null);
    it($dynamic, fn () => null);
    it("interpolated {$dynamic}", fn () => null);
    it("interpolated {$dynamic}", fn () => null);

    foreach ([1, 2] as $_) {
        it('in a loop', fn () => null);
    }

    it('in a loop', fn () => null);
}
?>
--EXPECTF--
PestDuplicateTestDescription on line %d: Pest throws TestAlreadyExist because the description 'it logs in' is already registered in this file; give the test a different description.
PestDuplicateTestDescription on line %d: Pest throws TestAlreadyExist because the description 'logs in' is already registered in this file; give the test a different description.
PestDuplicateTestDescription on line %d: Pest throws TestAlreadyExist because the description 'logs in' is already registered in this file; give the test a different description.
PestDuplicateTestDescription on line %d: Pest throws TestAlreadyExist because the description 'it logs in' is already registered in this file; give the test a different description.
PestDuplicateTestDescription on line %d: Pest throws TestAlreadyExist because the description 'plain' is already registered in this file; give the test a different description.
PestDuplicateTestDescription on line %d: Pest throws TestAlreadyExist because the description '`auth` → it logs in' is already registered in this file; give the test a different description.
PestDuplicateTestDescription on line %d: Pest throws TestAlreadyExist because the description '`auth` → it logs out' is already registered in this file; give the test a different description.
