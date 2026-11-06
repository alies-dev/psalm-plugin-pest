--FILE--
<?php declare(strict_types=1);

namespace PestDatasetBeforeEachFixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        public string $token = '';
    }
}

namespace {
    uses(PestDatasetBeforeEachFixture\TestCase::class);

    // Pest puts the beforeEach()->with() rows first, so the rows of a test's own with() start later.
    beforeEach(function (): void {})->with([fn () => fn () => 1]);

    it('binds rows after a beforeEach() dataset', function (\Closure $_c, string $_s): void {})
        ->with([fn (): string => $this->token]);
}
?>
--EXPECTF--
