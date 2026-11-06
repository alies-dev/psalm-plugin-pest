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
 * Reports an `it()` / `test()` whose closure has no statements (comments do not count): the test runs,
 * and PHPUnit marks it risky for performing no assertions. Arrow functions always return a value, so they
 * are never empty.
 *
 * A chain with `->todo()` is a deliberate placeholder. A chain with `->skip*()` never runs the closure, so
 * the risky warning never fires; both are left alone, `skip` being the one case Pest's PHPStan rule reports.
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
            if (($method === 'todo' || \str_starts_with($method, 'skip')) && !$call->isFirstClassCallable()) {
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
        $returns = $function !== null && $functions->hasStubbedFunction($function)
            ? $functions->getStorage(null, $function)->signature_return_type?->getAtomicTypes()
            : null;
        if (!isset($returns[PestApi::TEST_CALL])) {
            return null;
        }

        foreach ($call->getArgs() as $index => $arg) {
            if (($arg->name === null ? $index === 1 : $arg->name->name === 'closure')
                && $arg->value instanceof Closure
                && \array_filter($arg->value->stmts, static fn(Stmt $inner): bool => !$inner instanceof Stmt\Nop) === []
            ) {
                $message = "The closure passed to {$function}() is empty, so PHPUnit marks the test risky for performing no assertions; add assertions or mark the test ->todo().";
                IssueBuffer::maybeAdd(
                    new PestEmptyTestClosure($message, new CodeLocation($source, $arg->value)),
                    $event->getStatementsSource()->getSuppressedIssues(),
                );
            }
        }

        return null;
    }
}
