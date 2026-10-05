<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestStaticTestClosure;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionCallAnalysisEvent;

/**
 * Reports a `static` closure passed to `test()` / `it()` (Pest throws `TestClosureMustNotBeStatic`)
 * or to `beforeEach()` / `afterEach()` (Pest cannot bind `$this` to it and aborts the run).
 *
 * Gated like {@see ClosureThisHandler}: only the scanned function carrying Pest's
 * `@param-closure-this TestCall` tag counts. `beforeAll()`, `afterAll()` and `describe()` accept
 * static closures, so they are not reported.
 */
final class StaticTestClosureHandler implements AfterFunctionCallAnalysisInterface
{
    /** Function id => name as written. */
    private const FUNCTIONS = ['test' => 'test', 'it' => 'it', 'beforeeach' => 'beforeEach', 'aftereach' => 'afterEach'];

    #[\Override]
    public static function afterFunctionCallAnalysis(AfterFunctionCallAnalysisEvent $event): void
    {
        $functionId = \strtolower($event->getFunctionId());
        $functions = $event->getCodebase()->functions;
        if (!isset(self::FUNCTIONS[$functionId]) || !$functions->hasStubbedFunction($functionId)) {
            return;
        }

        foreach ($functions->getStorage(null, $functionId)->params as $offset => $param) {
            if ($param->closure_this_type?->getId() !== PestApi::TEST_CALL) {
                continue;
            }

            foreach ($event->getExpr()->getArgs() as $index => $arg) {
                $isClosureArg = $arg->name === null ? $index === $offset : $arg->name->name === $param->name;
                if ($isClosureArg
                    && ($arg->value instanceof Closure || $arg->value instanceof ArrowFunction)
                    && $arg->value->static
                ) {
                    $source = $event->getStatementsSource();
                    IssueBuffer::maybeAdd(
                        new PestStaticTestClosure(
                            \sprintf('Pest cannot bind $this to a static closure and aborts the run when %s() receives one; remove the `static` keyword.', self::FUNCTIONS[$functionId]),
                            new CodeLocation($source, $arg->value),
                        ),
                        $source->getSuppressedIssues(),
                    );
                }
            }

            return;
        }
    }
}
