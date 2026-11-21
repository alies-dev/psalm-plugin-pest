<?php

declare(strict_types=1);

namespace Tests\AliesDev\PsalmPluginPest\Unit;

use AliesDev\PsalmPluginPest\TestSuiteInternalHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * psalm/plugin-phpunit (enabled by the fixture requiring `phpunit/phpunit`) marks every class of the `phpunit.xml`
 * test suites `@internal`, which a namespace-less Pest test file can never be within. {@see TestSuiteInternalHandler}
 * lets such a file use the test suite's classes, while the `@internal` class of `src/` and the use of a test class
 * from `src/` are still reported.
 */
#[CoversClass(TestSuiteInternalHandler::class)]
final class TestSuiteInternalEmissionTest extends TestCase
{
    #[Test]
    public function pest_tests_use_test_suite_classes_but_not_internal_source_classes(): void
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 2) . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            __DIR__ . '/Fixtures/InternalTestCode',
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

        \sort($findings);

        $this->assertSame([
            'src/Factory.php:13: InternalClass',
            'tests/Renderer.php:15: InternalClass',
            'tests/Renderer.php:16: InternalMethod',
            'tests/Renderer.php:17: InternalProperty',
        ], $findings);
    }
}
