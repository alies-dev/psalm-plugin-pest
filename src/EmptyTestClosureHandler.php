<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestEmptyTestClosure;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;

/**
 * Reports an `it()` / `test()` whose closure has no statements (comments do not count): the test looks
 * written but checks nothing of its own. Arrow functions are out of scope by design, and a `static` closure
 * is left to {@see StaticTestClosureHandler}, which already reports it.
 *
 * Chains that mark the emptiness as deliberate are left alone: `->todo()` (a placeholder),
 * `->throwsNoExceptions()` (the test expects to perform no assertions), and every `->skip*()` /
 * `->onlyOn*()` (a parked test). Only an unconditional `skip()` keeps the closure from running;
 * `skip(false)`, `skip($callable)`, `skipOnWindows()`, `onlyOnLinux()` and the like still run it on some
 * hosts, so those empty tests are a known false negative.
 *
 * Tests inside `describe()` are reached the same way, as Psalm analyses the closure body statement by statement.
 */
final class EmptyTestClosureHandler implements AfterStatementAnalysisInterface
{
    private const FUNCTIONS = ['it', 'test'];

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();
        $source = $event->getStatementsSource();
        if (!$stmt instanceof Stmt\Expression || !$source instanceof StatementsAnalyzer) {
            return null;
        }

        $call = $stmt->expr;
        while ($call instanceof MethodCall) {
            $method = $call->name instanceof Identifier ? $call->name->toLowerString() : '';
            $deliberate = $method === 'todo'
                || $method === 'throwsnoexceptions'
                || \str_starts_with($method, 'skip')
                || \str_starts_with($method, 'onlyon');
            if ($deliberate && !$call->isFirstClassCallable()) {
                return null;
            }

            $call = $call->var;
        }

        if (!$call instanceof FuncCall || !$call->name instanceof Name || $call->isFirstClassCallable()) {
            return null;
        }

        $functions = $event->getCodebase()->functions;
        $name = $call->name->toLowerString();
        $id = \strtolower($functions->getFullyQualifiedFunctionNameFromString($call->name->toCodeString(), $source));
        $function = match (true) {
            \in_array($id, self::FUNCTIONS, true) => $id,
            \in_array($name, self::FUNCTIONS, true) && !$functions->functionExists($source, $id) => $name,
            default => null,
        };
        if ($function === null || !$functions->hasStubbedFunction($function)) {
            return null;
        }

        $storage = $functions->getStorage(null, $function);
        if (!isset($storage->signature_return_type?->getAtomicTypes()[PestApi::TEST_CALL])) {
            return null;
        }

        foreach ($storage->params as $offset => $param) {
            if ($param->closure_this_type?->getId() !== PestApi::TEST_CALL) {
                continue;
            }

            foreach ($call->getArgs() as $index => $arg) {
                if (($arg->name === null ? $index === $offset : $arg->name->name === $param->name)
                    && $arg->value instanceof Closure
                    && !$arg->value->static
                    && \array_filter($arg->value->stmts, static fn(Stmt $inner): bool => !$inner instanceof Stmt\Nop) === []
                ) {
                    IssueBuffer::maybeAdd(
                        new PestEmptyTestClosure(
                            "The closure passed to {$function}() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().",
                            new CodeLocation($source, $arg->value),
                        ),
                        $event->getStatementsSource()->getSuppressedIssues(),
                    );
                }
            }

            break;
        }

        return null;
    }
}
