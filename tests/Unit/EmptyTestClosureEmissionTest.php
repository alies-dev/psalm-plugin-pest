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
 * stubs its own `test()` over Pest's: {@see EmptyTestClosureHandler} must stay silent on its empty callback.
 */
#[CoversClass(EmptyTestClosureHandler::class)]
final class EmptyTestClosureEmissionTest extends TestCase
{
    #[Test]
    public function ignores_a_global_test_function_that_is_not_pests(): void
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 2) . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            __DIR__ . '/Fixtures/CustomTestFunction',
        );
        $process->setTimeout(300);
        $process->run();

        $this->assertSame([], \json_decode($process->getOutput(), true), "stdout:\n{$process->getOutput()}\nstderr:\n{$process->getErrorOutput()}");
    }
}
