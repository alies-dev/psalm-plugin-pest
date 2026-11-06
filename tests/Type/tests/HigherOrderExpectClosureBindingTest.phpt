--SKIPIF--
<?php require getcwd() . '/vendor/autoload.php'; if (str_starts_with((string) \Composer\InstalledVersions::getVersion('vimeo/psalm'), '6.')) { echo 'skip Psalm 7 only'; } ?>
--FILE--
<?php declare(strict_types=1);

namespace PestHigherOrderExpectPsalm7Fixture {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
    }
}

namespace {
    uses(PestHigherOrderExpectPsalm7Fixture\TestCase::class);

    beforeEach(function (): void {
        $this->cart = new ArrayObject();
    });

    // Pest replays `expect()` through HigherOrderCallables, which binds the closure to the TestCase.
    it('binds an arrow function')->expect(fn () => $this->cart->count())->toBe(0);
    test('binds a test() chain')->expect(fn () => $this->cart->count())->toBe(0);
    it('binds a closure block')->expect(function () {
        /** @psalm-check-type-exact $this = PestHigherOrderExpectPsalm7Fixture\TestCase */
        return $this->cart->count();
    })->toBe(0);
}
?>
--EXPECTF--
