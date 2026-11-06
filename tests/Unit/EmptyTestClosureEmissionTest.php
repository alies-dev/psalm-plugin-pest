<?php

declare(strict_types=1);

namespace Tests\AliesDev\PsalmPluginPest\Unit;

use AliesDev\PsalmPluginPest\EmptyTestClosureHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * psalm-tester `.phpt` files cannot swap the declaration of a global function, so a fixture project
 * stubs its own `test()` over Pest's: {@see EmptyTestClosureHandler} must stay silent on its empty callback,
 * while the project's `it()` is still Pest's and its empty closure is reported.
 */
#[CoversClass(EmptyTestClosureHandler::class)]
final class EmptyTestClosureEmissionTest extends TestCase
{
    #[Test]
    public function reports_only_the_test_function_that_is_pests(): void
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 2) . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            __DIR__ . '/Fixtures/CustomTestFunction',
        );
        $process->setTimeout(300);
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$process->getOutput()}\nstderr:\n{$process->getErrorOutput()}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && \is_string($finding['type'] ?? null) && \is_string($finding['file_name'] ?? null) && \is_int($finding['line_from'] ?? null)) {
                $findings[] = $finding['file_name'] . ':' . $finding['line_from'] . ': ' . $finding['type'];
            }
        }

        $this->assertSame(['tests/Namespaced.php:8: PestEmptyTestClosure'], $findings);
    }
}
