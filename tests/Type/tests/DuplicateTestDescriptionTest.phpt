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
    it('logs in', function (): void { expect(true)->toBeTrue(); });
    it('logs in', function (): void {})->group('auth')->skip();
    test('logs in', function (): void { expect(true)->toBeTrue(); });
    test('logs in', function (): void { expect(true)->toBeTrue(); });
    todo('logs in');
    test('it logs in', function (): void { expect(true)->toBeTrue(); }); // same key as it('logs in')

    test('plain', function (): void { expect(true)->toBeTrue(); });
    todo('plain');

    describe('auth', function (): void {
        it('logs in', function (): void { expect(true)->toBeTrue(); });
        it('logs in', function (): void { expect(true)->toBeTrue(); });
        it('logs out', function (): void { expect(true)->toBeTrue(); });

        describe('nested', function (): void {
            it('logs in', function (): void { expect(true)->toBeTrue(); });
        });
    });

    describe('auth', function (): void {
        it('logs out', function (): void { expect(true)->toBeTrue(); });
    });

    describe('other', function (): void {
        it('logs in', function (): void { expect(true)->toBeTrue(); });
    });

    $dynamic = 'dynamic';
    it($dynamic, function (): void { expect(true)->toBeTrue(); });
    it($dynamic, function (): void { expect(true)->toBeTrue(); });
    it("interpolated {$dynamic}", function (): void { expect(true)->toBeTrue(); });
    it("interpolated {$dynamic}", function (): void { expect(true)->toBeTrue(); });

    foreach ([1, 2] as $_) {
        it('in a loop', function (): void { expect(true)->toBeTrue(); });
    }

    it('in a loop', function (): void { expect(true)->toBeTrue(); });
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
