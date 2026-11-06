<?php

declare(strict_types=1);

namespace Tests\AliesDev\PsalmPluginPest\Unit;

use AliesDev\PsalmPluginPest\ClosureThisHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for the `tests/Pest.php` path of {@see ClosureThisHandler}. psalm-tester
 * `.phpt` files run from temp files outside any `tests/` tree, so only the in-file `uses()` path is
 * reachable there. This forks a real Psalm over a fixture project whose `tests/Pest.php` maps
 * `Feature/` to a custom TestCase: the Feature test reads a protected member of it (clean, and its
 * `@psalm-check-type-exact` assertions hold), while the Unit test falls back to PHPUnit's TestCase
 * and gets the one expected `UndefinedThisPropertyFetch`. Two more Feature tests share that TestCase:
 * one assigns `$this->setupState` in `beforeEach()` (clean), the other reads it and must still be
 * reported, since a `beforeEach()` property is declared for its own file only. The Pest.php chain
 * also binds a trait and a `beforeEach()` property to `Feature/`: Feature/Shared.php reads both
 * (clean), also through a `static` return of the trait, while the Unit test does not get them. An
 * undefined property in a Feature file reports only itself, as the trait is no intersection member.
 * Dataset rows: those in a test file get its TestCase, and so do those of a shared dataset file
 * below a directory Pest.php maps, while a shared dataset under the fallback TestCase (in
 * Unit/Datasets/ or in Pest.php itself) is bound to that fallback: its own members are clean, a
 * subclass member is reported on it. Each finding names its file.
 */
#[CoversClass(ClosureThisHandler::class)]
final class ClosureThisEmissionTest extends TestCase
{
    #[Test]
    public function binds_the_pest_php_test_case_per_directory(): void
    {
        $projectRoot = \dirname(__DIR__, 2);
        $process = new Process(
            [\PHP_BINARY, $projectRoot . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            __DIR__ . '/Fixtures/ClosureThis',
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$process->getOutput()}\nstderr:\n{$process->getErrorOutput()}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && \is_string($finding['file_name'] ?? null) && \is_string($finding['type'] ?? null) && \is_string($finding['message'] ?? null)) {
                $findings[] = $finding['file_name'] . ': ' . $finding['type'] . ': ' . $finding['message'];
            }
        }

        \sort($findings);
        $this->assertSame(
            [
                'tests/Feature/Isolated.php: UndefinedThisPropertyFetch: Instance property PestClosureThisFixture\FeatureTestCase::$setupState is not defined',
                'tests/Pest.php: UndefinedThisPropertyFetch: Instance property PHPUnit\Framework\TestCase::$bootOnly is not defined',
                'tests/Unit/Defaults.php: UndefinedMethod: Method PHPUnit\Framework\TestCase::createUser does not exist',
                'tests/Unit/Defaults.php: UndefinedThisPropertyFetch: Instance property PHPUnit\Framework\TestCase::$defaultOnly is not defined',
                'tests/Unit/Defaults.php: UndefinedThisPropertyFetch: Instance property PHPUnit\Framework\TestCase::$featureOnly is not defined',
                'tests/Unit/Defaults.php: UndefinedThisPropertyFetch: Instance property PHPUnit\Framework\TestCase::$shared is not defined',
            ],
            $findings,
        );
    }
}
