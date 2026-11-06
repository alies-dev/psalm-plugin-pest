<?php

declare(strict_types=1);

namespace Tests\AliesDev\PsalmPluginPest\Unit;

use AliesDev\PsalmPluginPest\BootFileFunctionsHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for {@see BootFileFunctionsHandler}. psalm-tester `.phpt` files run from temp
 * files outside any `tests/` tree, so a `tests/Pest.php` is only reachable through a fixture
 * project. Its `Pest.php` (plus a file it requires), `Helpers.php` and `Helpers/Nested/Deep.php`
 * declare functions that a Feature test calls (clean, and its `@psalm-check-type-exact` assertions
 * hold: the declared return types come along). Boot files see each other's functions from inside their
 * function bodies (run after boot). The only findings are a function nobody declares and a call to a
 * boot file function from `src/`, which is outside the tests directory.
 */
#[CoversClass(BootFileFunctionsHandler::class)]
final class BootFileFunctionsEmissionTest extends TestCase
{
    #[Test]
    public function boot_file_functions_resolve_in_the_tests_directory_only(): void
    {
        $projectRoot = \dirname(__DIR__, 2);
        $process = new Process(
            [\PHP_BINARY, $projectRoot . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            __DIR__ . '/Fixtures/BootFunctions',
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
                'src/outside.php: UndefinedFunction: Function buildThing does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases',
                'tests/Feature/BootFunctions.php: UndefinedFunction: Function undefinedEverywhere does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases',
            ],
            $findings,
        );
    }
}
