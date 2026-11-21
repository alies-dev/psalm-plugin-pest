<?php

declare(strict_types=1);

namespace Tests\AliesDev\PsalmPluginPest\Unit;

use AliesDev\PsalmPluginPest\CliOnlyFileHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for {@see CliOnlyFileHandler}. The fixture requires `phpunit/phpunit`, so Psalm
 * enables psalm/plugin-phpunit, which marks every TestCase subclass CLI-only. Calls to a TestCase
 * method through `$this` / `test()` in the closures of a Pest test file (an `it()` test and a
 * `beforeEach()` hook) are clean, since Pest only runs from the command line. The same call from
 * `src/` is still reported as `CliOnlyCall`.
 */
#[CoversClass(CliOnlyFileHandler::class)]
final class CliOnlyEmissionTest extends TestCase
{
    #[Test]
    public function test_files_are_cli_only_and_other_files_are_not(): void
    {
        $projectRoot = \dirname(__DIR__, 2);
        $process = new Process(
            [\PHP_BINARY, $projectRoot . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            __DIR__ . '/Fixtures/CliOnly',
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$process->getOutput()}\nstderr:\n{$process->getErrorOutput()}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && \is_string($finding['type'] ?? null) && \is_string($finding['message'] ?? null) && \is_string($finding['file_name'] ?? null)) {
                $findings[] = $finding['file_name'] . ': ' . $finding['type'] . ': ' . \strstr($finding['message'], ' (@psalm-cli-only)', true);
            }
        }

        \sort($findings);
        $this->assertSame(
            ['src/outside.php: CliOnlyCall: The method PestCliOnlyFixture\AppTestCase::staticHelper() only runs from the command line'],
            $findings,
        );
    }
}
