--FILE--
<?php declare(strict_types=1);

namespace PestCurrentTestFixture {
    final class Response
    {
        /** @psalm-pure */
        public function assertOk(): self
        {
            return new self();
        }
    }

    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        public string $token = '';

        /** @psalm-pure */
        public function get(string $_uri): Response
        {
            return new Response();
        }
    }
}

namespace {
    uses(PestCurrentTestFixture\TestCase::class);

    test('test() without arguments is the bound TestCase', function (): void {
        $current = test();
        /** @psalm-check-type-exact $current = PestCurrentTestFixture\TestCase */
        $_response = test()->get('/')->assertOk();
        /** @psalm-check-type-exact $_response = PestCurrentTestFixture\Response */
        $_token = test()->token;
        /** @psalm-check-type-exact $_token = string */
        test()->token = 'set';
        echo $current::class;
    });

    it('works in it()', function (): void {
        $_current = test();
        /** @psalm-check-type-exact $_current = PestCurrentTestFixture\TestCase */
    });

    beforeEach(function (): void {
        $_current = test();
        /** @psalm-check-type-exact $_current = PestCurrentTestFixture\TestCase */
    });

    test('works in arrow functions', fn() => test()->get('/')->assertOk());

    test('works in nested closures', function (): void {
        (function (): void {
            $_current = test();
            /** @psalm-check-type-exact $_current = PestCurrentTestFixture\TestCase */
            echo $_current::class;
        })();
    });

    // With a description it still registers a test.
    $_call = test('declares a test');
    /** @psalm-check-type-exact $_call = Pest\PendingCalls\TestCall */
}
?>
--EXPECTF--
