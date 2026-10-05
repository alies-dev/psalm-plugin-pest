--FILE--
<?php declare(strict_types=1);

namespace PestHookInDescribeFixture {
    function beforeAll(\Closure $_closure): void { echo $_closure::class; }

    describe('shadowed by a function of the namespace', function (): void {
        beforeAll(function (): void {});
    });
}

namespace {
    beforeAll(function (): void {});
    afterAll(function (): void {});

    describe('users', function (): void {
        beforeEach(function (): void {});
        afterEach(function (): void {});
        beforeAll(function (): void {});
        afterAll(function (): void {});

        if (PHP_VERSION_ID > 0) {
            beforeAll(function (): void {});
        }

        foreach ([1] as $_) {
            \afterAll(function (): void {});
        }

        it('runs the closure later', function (): void {
            beforeAll(function (): void {});
        });

        (function (): void {
            afterAll(function (): void {});
        })();

        describe('nested', function (): void {
            afterAll(function (): void {});
        });
    });

    describe('arrow function', fn() => beforeAll(function (): void {}));
}
?>
--EXPECTF--
PestHookInDescribe on line %d: beforeAll() inside describe() makes Pest throw while loading the file; use beforeEach() instead.
PestHookInDescribe on line %d: afterAll() inside describe() makes Pest throw while loading the file; use afterEach() instead.
PestHookInDescribe on line %d: beforeAll() inside describe() makes Pest throw while loading the file; use beforeEach() instead.
PestHookInDescribe on line %d: afterAll() inside describe() makes Pest throw while loading the file; use afterEach() instead.
PestHookInDescribe on line %d: afterAll() inside describe() makes Pest throw while loading the file; use afterEach() instead.
PestHookInDescribe on line %d: beforeAll() inside describe() makes Pest throw while loading the file; use beforeEach() instead.
