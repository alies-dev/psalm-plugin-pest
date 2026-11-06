--FILE--
<?php declare(strict_types=1);

namespace PestDeliberateFailureFixture {
    function sendsNothing(): void {}

    /** An assertion that cannot pass is the point of a `->fails()` test. */
    function failsChain(): void
    {
        it('rejects a bad signature', function (): void {
            expect(true)->toBeFalse();
            expect(42)->toBeString();
            expect(fn () => expect(1)->toBeNull());
        })->fails();

        it('rejects with a message', fn () => expect(1)->toBeString())->with([1])->fails('boom');

        test('rejects in any chain order', function (): void {
            expect(false)->toBeTrue();
        })->group('x')->FAILS()->skip(false);

        it('rejects through property links', fn () => expect(1)->toBeString())->expect(1)->not->toBeInt()->fails();
    }

    function manualFailure(): void
    {
        it('does not throw on send', function (): void {
            try {
                sendsNothing();
            } catch (\Throwable) {
                expect(false)->toBeTrue('send() should not throw an exception');
                expect(true)->toBeFalse(message: 'unreachable');
            }
        });
    }

    function stillReported(): void
    {
        expect(true)->toBeFalse();
        expect(false)->toBeTrue();

        // A message is no excuse for a non-literal value or for another matcher.
        $never = false;
        expect($never)->toBeTrue('flag is false');
        expect(42)->toBeString('x');

        // Only the test the chain ends in is expected to fail.
        it('is a plain test', function (): void {
            expect(true)->toBeFalse();
        });

        it('is a plain test with a sibling chain', function (): void {
            expect(true)->toBeFalse();
        })->group('x');

        // The `->fails()` of an earlier chain does not leak into later statements.
        it('fails', fn () => 1)->fails();
        expect(1)->toBeString();
    }
}

namespace PestDeliberateFailureOther {
    final class Check
    {
        public function fails(): void {}
    }

    function it(\Closure $closure): Check
    {
        $closure();

        return new Check();
    }

    /** @return \Pest\Expectation<string> */
    function expectStatus(bool $ok): \Pest\Expectation
    {
        return expect($ok ? 'ok' : 'failed');
    }

    /** A namespaced `it()` is not Pest's, and a boolean argument of another helper is not the subject. */
    function lookalikes(): void
    {
        it(fn () => expect(true)->toBeFalse())->fails();
        namespace\it(fn () => expect(true)->toBeFalse())->fails();
        expectStatus(true)->toBeTrue('x');
    }
}
?>
--EXPECTF--
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeTrue() is a strict type check that always fails for false; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeTrue() is a strict type check that always fails for false; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeString() is a strict type check that always fails for 42; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeString() is a strict type check that always fails for 1; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeFalse() is a strict type check that always fails for true; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line %d: Pest's toBeTrue() is a strict type check that always fails for string; assert the type the value has or remove the assertion.
