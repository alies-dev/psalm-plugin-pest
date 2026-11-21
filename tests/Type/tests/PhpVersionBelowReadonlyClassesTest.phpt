--ARGS--
--php-version=8.0
--FILE--
<?php declare(strict_types=1);

namespace PestPhpVersionBelowReadonlyFixture {
    final class Invoice
    {
        public function __construct(public int $total) {}
    }

    // Pest declares `final readonly class OppositeExpectation`, which a PHP 8.0 parser rejects.
    function check(?Invoice $invoice): void
    {
        it('checks an invoice', function () use ($invoice) {
            expect($invoice)->not->toBeNull();
            expect($invoice->total)->toBe(10)->not->toBe(0);
        });
    }
}
?>
--EXPECT--
