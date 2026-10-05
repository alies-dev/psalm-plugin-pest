--FILE--
<?php declare(strict_types=1);

namespace PestCurrentTestHelperFixture {
    final class Response
    {
        public string $location = '/';
    }

    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        /** @psalm-pure */
        public function get(string $_uri): Response
        {
            return new Response();
        }

        /** @psalm-pure */
        protected function withoutVite(): string
        {
            return 'vite';
        }

        /** @psalm-pure */
        private function secret(): int
        {
            return 1;
        }
    }

    final class Plain
    {
        public function method(): void
        {
            $_current = test();
            /** @psalm-check-type-exact $_current = \Pest\PendingCalls\TestCall|\Pest\Support\HigherOrderTapProxy */
        }
    }
}

namespace {
    uses(PestCurrentTestHelperFixture\TestCase::class);

    // Helpers called from tests run on the bound TestCase.
    function helper(): string
    {
        $current = test();
        /** @psalm-check-type-exact $current = PestCurrentTestHelperFixture\TestCase */
        echo $current::class;

        return test()->get('/x')->location;
    }

    // Pest calls through reflection, so non-public methods are fine on test() only.
    function helperNonPublic(PestCurrentTestHelperFixture\TestCase $other): void
    {
        $_vite = test()->withoutVite();
        /** @psalm-check-type-exact $_vite = string */
        $_secret = test()
            ->secret();
        /** @psalm-check-type-exact $_secret = int */
        echo $other->withoutVite();
    }

    function helperWithDescription(): void
    {
        $_call = test('desc');
        /** @psalm-check-type-exact $_call = Pest\PendingCalls\TestCall */
    }

    // Top level and methods are not inside a running test.
    $_top = test();
    /** @psalm-check-type-exact $_top = Pest\PendingCalls\TestCall|Pest\Support\HigherOrderTapProxy */
    $_call = test('desc');
    /** @psalm-check-type-exact $_call = Pest\PendingCalls\TestCall */
}
?>
--EXPECTF--
InaccessibleMethod on line %d: Cannot access protected method PestCurrentTestHelperFixture\TestCase::withoutvite
