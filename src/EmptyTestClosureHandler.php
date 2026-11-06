<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestEmptyTestClosure;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;

/**
 * Reports an `it()` / `test()` whose closure has no statements (comments do not count): the test looks
 * written but checks nothing of its own. Arrow functions are out of scope by design, and a `static` closure
 * is left to {@see StaticTestClosureHandler}, which already reports it.
 *
 * Chain methods that mark the emptiness as deliberate exempt a test: `->todo()`, every `->skip*()` and
 * `->onlyOn*()`, and `->throwsNoExceptions()`. They count on the test's own chain, on the chain of an
 * enclosing `describe()` at any depth (`DescribeCall::__call()` hands them to a `BeforeEachCall`, which
 * replays them on each test inside), and on the chain of a `beforeEach()` that reaches the test: Pest
 * registers it at the end of its statement and applies it to the tests created after it in the file
 * (top level) or in its own `describe()`, nested ones included. A call assigned to a variable that gets one
 * of those methods anywhere in the file (`$test->skip()`), or to anything but a plain variable, is exempt
 * too. When unsure the scan exempts: a hook in a condition stays on for the rest of its scope, and a hook in
 * a loop body reaches the whole body.
 *
 * {@see self::beforeAnalyzeFile()} reads that once per file and marks the test calls it could place,
 * with a node attribute, as reportable or not. Only top-level statements, namespace and declare blocks,
 * control structures and `describe()` closure bodies are read; a test anywhere else (a function, a
 * method, any other closure) stays unmarked and is never reported. {@see self::afterFunctionCallAnalysis()}
 * then reports through Psalm's resolved function id, so only Pest's own `it()` / `test()` count, and
 * with the statement's suppressions, so `@psalm-suppress PestEmptyTestClosure` on it works.
 */
final class EmptyTestClosureHandler implements AfterFunctionCallAnalysisInterface, BeforeFileAnalysisInterface
{
    /** Node attribute on a test call: true when nothing parks it. */
    private const ATTRIBUTE = 'pestEmptyTestClosureReportable';

    /** Function id => name as written. */
    private const FUNCTIONS = ['it' => 'it', 'test' => 'test'];

    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $stmts = $event->getStmts();
        $codebase = $event->getCodebase();

        self::scan($codebase, $event->getFileStorage()->declaring_function_ids, $stmts, false, self::parkedVariables($stmts));
    }

    #[\Override]
    public static function afterFunctionCallAnalysis(AfterFunctionCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();
        $functionId = \strtolower($event->getFunctionId());
        $functions = $event->getCodebase()->functions;
        if ($expr->getAttribute(self::ATTRIBUTE) !== true
            || !isset(self::FUNCTIONS[$functionId])
            || !$functions->hasStubbedFunction($functionId)
        ) {
            return;
        }

        foreach ($functions->getStorage(null, $functionId)->params as $offset => $param) {
            if ($param->closure_this_type?->getId() !== PestApi::TEST_CALL) {
                continue;
            }

            foreach ($expr->getArgs() as $index => $arg) {
                $isClosureArg = $arg->name === null ? $index === $offset : $arg->name->name === $param->name;
                if ($isClosureArg && $arg->value instanceof Closure && !$arg->value->static && self::isEmpty($arg->value)) {
                    $source = $event->getStatementsSource();
                    IssueBuffer::maybeAdd(
                        new PestEmptyTestClosure(
                            \sprintf('The closure passed to %s() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().', self::FUNCTIONS[$functionId]),
                            new CodeLocation($source, $arg->value),
                        ),
                        $source->getSuppressedIssues(),
                    );
                }
            }

            return;
        }
    }

    /**
     * Marks the test calls in `$stmts`, run in order.
     *
     * @param array<string, string> $declaredFunctions the file's function ids
     * @param array<array-key, Stmt> $stmts
     * @param bool $parked whether a `describe()` or `beforeEach()` already parks the tests created here
     * @param array<string, true> $parkedVariables
     * @return bool whether a `beforeEach()` parks the tests created after the statements
     */
    private static function scan(Codebase $codebase, array $declaredFunctions, array $stmts, bool $parked, array $parkedVariables): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\For_ || $stmt instanceof Stmt\Foreach_ || $stmt instanceof Stmt\While_ || $stmt instanceof Stmt\Do_) {
                // A beforeEach() later in the body reaches the tests before it on the next iteration.
                if (self::scan($codebase, $declaredFunctions, $stmt->stmts, $parked, $parkedVariables) && !$parked) {
                    $parked = self::scan($codebase, $declaredFunctions, $stmt->stmts, true, $parkedVariables);
                }

                continue;
            }

            if (!$stmt instanceof Stmt\Expression && !$stmt instanceof Stmt\Return_) {
                foreach (self::blocks($stmt) as $block) {
                    $parked = self::scan($codebase, $declaredFunctions, $block, $parked, $parkedVariables);
                }

                continue;
            }

            $call = $stmt->expr;
            $deliberate = false;
            if ($call instanceof Expr\Assign || $call instanceof Expr\AssignRef) {
                // A later `$var->skip()` parks it too; an unknown target may get any of those calls.
                $deliberate = !$call->var instanceof Expr\Variable
                    || !\is_string($call->var->name)
                    || isset($parkedVariables[$call->var->name]);
                $call = $call->expr;
            }

            while ($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) {
                $deliberate = $deliberate || self::isDeliberate($call);
                $call = $call->var;
            }

            if (!$call instanceof FuncCall || !$call->name instanceof Name || $call->isFirstClassCallable()) {
                continue;
            }

            $function = \strtolower(NameResolution::resolved($call->name) ?? $call->name->toString());
            if ($function === 'it' || $function === 'test') {
                $call->setAttribute(self::ATTRIBUTE, !$parked && !$deliberate);
            } elseif ($function === 'beforeeach') {
                $parked = $parked || $deliberate;
            } elseif ($function === 'describe' && self::isGlobal($codebase, $declaredFunctions, $call->name)) {
                $closure = null;
                foreach ($call->getArgs() as $index => $arg) {
                    if ($arg->name === null ? $index === 1 : $arg->name->name === 'tests') {
                        $closure = $arg->value;
                    }
                }

                if ($closure instanceof Closure) {
                    self::scan($codebase, $declaredFunctions, $closure->stmts, $parked || $deliberate, $parkedVariables);
                }
            }
        }

        return $parked;
    }

    /**
     * Whether `$name` reaches the global function, not one of the project's namespaced ones.
     *
     * @param array<string, string> $declaredFunctions the file's function ids
     */
    private static function isGlobal(Codebase $codebase, array $declaredFunctions, Name $name): bool
    {
        $namespaced = NameResolution::resolved($name, 'namespacedName');

        if ($namespaced === null) {
            return true;
        }

        $namespaced = \strtolower($namespaced);

        return !isset($declaredFunctions[$namespaced]) && !$codebase->functions->hasStubbedFunction($namespaced);
    }

    /**
     * The variables that receive a deliberate chain method anywhere in the file.
     *
     * @param array<array-key, Stmt> $stmts
     * @return array<string, true>
     */
    private static function parkedVariables(array $stmts): array
    {
        $variables = [];
        foreach ((new NodeFinder())->find($stmts, static fn(Node $node): bool => ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && self::isDeliberate($node)) as $call) {
            \assert($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall);
            $root = $call->var;
            while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
                $root = $root->var;
            }

            if ($root instanceof Expr\Variable && \is_string($root->name)) {
                $variables[$root->name] = true;
            }
        }

        return $variables;
    }

    /** A chain method that marks an empty test as intended; a dynamic name may be one. */
    private static function isDeliberate(Expr\MethodCall|Expr\NullsafeMethodCall $call): bool
    {
        if (!$call->name instanceof Identifier) {
            return true;
        }

        $method = $call->name->toLowerString();

        return !$call->isFirstClassCallable()
            && ($method === 'todo'
                || $method === 'throwsnoexceptions'
                || \str_starts_with($method, 'skip')
                || \str_starts_with($method, 'onlyon'));
    }

    private static function isEmpty(Closure $closure): bool
    {
        foreach ($closure->stmts as $stmt) {
            if (!$stmt instanceof Stmt\Nop) {
                return false;
            }
        }

        return true;
    }

    /**
     * The statement lists nested in a namespace or declare block or in a control structure other than a loop.
     *
     * @return array<array-key, array<array-key, Stmt>>
     */
    private static function blocks(Stmt $stmt): array
    {
        return match (true) {
            $stmt instanceof Stmt\Declare_ => [$stmt->stmts ?? []],
            $stmt instanceof Stmt\Namespace_,
            $stmt instanceof Stmt\Block => [$stmt->stmts],
            $stmt instanceof Stmt\If_ => [
                $stmt->stmts,
                ...\array_map(static fn(Stmt\ElseIf_ $elseIf): array => $elseIf->stmts, $stmt->elseifs),
                $stmt->else->stmts ?? [],
            ],
            $stmt instanceof Stmt\TryCatch => [
                $stmt->stmts,
                ...\array_map(static fn(Stmt\Catch_ $catch): array => $catch->stmts, $stmt->catches),
                $stmt->finally->stmts ?? [],
            ],
            $stmt instanceof Stmt\Switch_ => \array_map(static fn(Stmt\Case_ $case): array => $case->stmts, $stmt->cases),
            default => [],
        };
    }
}
