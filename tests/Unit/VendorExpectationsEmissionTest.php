<?php

declare(strict_types=1);

namespace Tests\AliesDev\PsalmPluginPest\Unit;

use AliesDev\PsalmPluginPest\CustomExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for the `expect()->extend()` calls of a package's Composer `files` autoload (as in
 * `spatie/pest-plugin-snapshots`). The fixture has its own vendor directory (`deps`, set in its composer.json) with
 * a hand-written `composer/installed.json` (the plugin reads the packages' `files` autoloads from it; Psalm
 * reads `autoload_files.php` and the fixture's composer.json), a package that registers `toBeSnapshotLike()` and one that
 * registers nothing. A test file calls the expectation (also through `->not`): clean, but checked against the
 * package's closure. A misspelled name is still reported.
 */
#[CoversClass(CustomExpectations::class)]
final class VendorExpectationsEmissionTest extends TestCase
{
    #[Test]
    public function expectations_of_a_package_files_autoload_are_known(): void
    {
        $projectRoot = \dirname(__DIR__, 2);
        $process = new Process(
            [\PHP_BINARY, $projectRoot . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            __DIR__ . '/Fixtures/VendorExpectations',
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$process->getOutput()}\nstderr:\n{$process->getErrorOutput()}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && \is_string($finding['type'] ?? null) && \is_string($finding['message'] ?? null) && \is_string($finding['file_name'] ?? null)) {
                $findings[] = $finding['file_name'] . ': ' . $finding['type'] . ': ' . $finding['message'];
            }
        }

        \sort($findings);
        $this->assertSame(
            [
                'tests/Feature/VendorExpectations.php: TooFewArguments: Too few arguments for Pest\\Expectation::tobesnapshotlike - expecting label to be passed',
                "tests/Feature/VendorExpectations.php: UndefinedMagicMethod: Magic method 'x'::tobesnapshotlikee does not exist",
            ],
            $findings,
        );
    }
}
