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
