--FILE--
<?php declare(strict_types=1);

namespace PestDuplicateFixture {
    /** @psalm-impure */
    function it(string $description, \Closure $_test): void
    {
        echo $description;
    }

    it('is a project function, not Pest', fn() => 1);
    it('is a project function, not Pest', fn() => 1);
}

namespace {
    it('logs in', function (): void {});
    it('logs in', function (): void {})->group('auth')->skip();
    test('logs in', function (): void {});
    test('logs in', function (): void {});
    todo('logs in');
    test('it logs in', function (): void {}); // same key as it('logs in')

    test('plain', function (): void {});
    todo('plain');

    describe('auth', function (): void {
        it('logs in', function (): void {});
        it('logs in', function (): void {});
        it('logs out', function (): void {});

        describe('nested', function (): void {
            it('logs in', function (): void {});
        });
    });

    describe('auth', function (): void {
        it('logs out', function (): void {});
    });

    describe('other', function (): void {
        it('logs in', function (): void {});
    });

    $dynamic = 'dynamic';
    it($dynamic, function (): void {});
    it($dynamic, function (): void {});
    it("interpolated {$dynamic}", function (): void {});
    it("interpolated {$dynamic}", function (): void {});

    foreach ([1, 2] as $_) {
        it('in a loop', function (): void {});
    }

    it('in a loop', function (): void {});
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
