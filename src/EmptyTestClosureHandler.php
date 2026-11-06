<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestEmptyTestClosure;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TClosure;

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
 * Pest replays the TestCall methods of a `describe()` chain on every test inside it, at any depth
 * (`DescribeCall::__call()` forwards them to a `BeforeEachCall`), and those of a `beforeEach()` chain on
 * every test registered after it in the same scope: the whole file at the top level, the enclosing
 * `describe()` otherwise. The same methods on those chains therefore exempt the tests they reach.
 *
 * The file is scanned once: top-level statements, namespace blocks, control structures and `describe()`
 * closure bodies; a test call may be assigned (`$t = it(...)`) or returned. Function and class bodies are
 * not read, as the order their tests register in is unknown. A `beforeEach()` exemption met inside a
 * control structure stays on for the rest of its scope, as the scan cannot tell whether it ran.
 */
final class EmptyTestClosureHandler implements AfterFileAnalysisInterface
{
    /** Function id => the Pest class its native return type names. */
    private const FUNCTIONS = [
        'it' => PestApi::TEST_CALL,
        'test' => PestApi::TEST_CALL,
        'describe' => PestApi::DESCRIBE_CALL,
        'beforeeach' => PestApi::BEFORE_EACH_CALL,
    ];

    #[\Override]
    public static function afterAnalyzeFile(AfterFileAnalysisEvent $event): void
    {
        $functions = $event->getCodebase()->functions;

        /** @var array<string, array{int, string}> $closureParams Pest function id => offset and name of its closure parameter */
        $closureParams = [];
        foreach (self::FUNCTIONS as $functionId => $class) {
            if (!$functions->hasStubbedFunction($functionId)) {
                continue;
            }

            $storage = $functions->getStorage(null, $functionId);
            if (!isset($storage->signature_return_type?->getAtomicTypes()[$class])) {
                continue;
            }

            foreach ($storage->params as $offset => $param) {
                if (\array_filter($param->signature_type?->getAtomicTypes() ?? [], static fn(Atomic $type): bool => $type instanceof TClosure) !== []) {
                    $closureParams[$functionId] = [$offset, $param->name];
                    break;
                }
            }
        }

        self::scan($event, $closureParams, $event->getFileStorage()->declaring_function_ids, $event->getStmts(), false);
    }

    /**
     * @param array<string, array{int, string}> $closureParams
     * @param array<string, string> $declaredFunctions the file's function ids
     * @param array<array-key, Stmt> $stmts
     * @return bool whether a `beforeEach()` exemption is on after the statements
     */
    private static function scan(AfterFileAnalysisEvent $event, array $closureParams, array $declaredFunctions, array $stmts, bool $exempt): bool
    {
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Stmt\Expression && !$stmt instanceof Stmt\Return_) {
                foreach (self::blocks($stmt) as $block) {
                    $exempt = self::scan($event, $closureParams, $declaredFunctions, $block, $exempt);
                }

                continue;
            }

            $call = $stmt->expr;
            if ($call instanceof Expr\Assign || $call instanceof Expr\AssignRef) {
                $call = $call->expr;
            }

            $deliberate = false;
            while ($call instanceof MethodCall) {
                $deliberate = $deliberate || self::isDeliberate($call);
                $call = $call->var;
            }

            if (!$call instanceof FuncCall || !$call->name instanceof Name || $call->isFirstClassCallable()) {
                continue;
            }

            $function = self::pestFunction($call->name, $declaredFunctions);
            if ($function === null || !isset($closureParams[$function])) {
                continue;
            }

            $closure = self::closureArgument($call, ...$closureParams[$function]);
            if ($function === 'beforeeach') {
                $exempt = $exempt || $deliberate;
            } elseif ($function === 'describe') {
                if ($closure instanceof \PhpParser\Node\Expr\Closure) {
                    self::scan($event, $closureParams, $declaredFunctions, $closure->stmts, $exempt || $deliberate);
                }
            } elseif (!$exempt && !$deliberate && $closure instanceof \PhpParser\Node\Expr\Closure && !$closure->static
                && \array_filter($closure->stmts, static fn(Stmt $inner): bool => !$inner instanceof Stmt\Nop) === []
            ) {
                $source = $event->getStatementsSource();
                IssueBuffer::maybeAdd(
                    new PestEmptyTestClosure(
                        "The closure passed to {$function}() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().",
                        new CodeLocation($source, $closure),
                    ),
                    $source->getSuppressedIssues(),
                );
            }
        }

        return $exempt;
    }

    /** A chain method that marks an empty test as intended. */
    private static function isDeliberate(MethodCall $call): bool
    {
        $method = $call->name instanceof Identifier ? $call->name->toLowerString() : '';

        return !$call->isFirstClassCallable()
            && ($method === 'todo'
                || $method === 'throwsnoexceptions'
                || \str_starts_with($method, 'skip')
                || \str_starts_with($method, 'onlyon'));
    }

    /**
     * The id of the Pest function `$name` calls, or null when it calls the file's own namespaced function.
     *
     * @param array<string, string> $declaredFunctions
     */
    private static function pestFunction(Name $name, array $declaredFunctions): ?string
    {
        $namespaced = \strtolower(NameResolution::resolved($name, 'namespacedName') ?? '');
        if ($namespaced !== '' && isset($declaredFunctions[$namespaced])) {
            return null;
        }

        $function = \strtolower(NameResolution::resolved($name) ?? $name->toString());

        return isset(self::FUNCTIONS[$function]) ? $function : null;
    }

    private static function closureArgument(FuncCall $call, int $offset, string $name): ?Closure
    {
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->name === null ? $index === $offset : $arg->name->name === $name) {
                return $arg->value instanceof Closure ? $arg->value : null;
            }
        }

        return null;
    }

    /**
     * The statement lists nested in a namespace block or a control structure.
     *
     * @return array<array-key, array<array-key, Stmt>>
     */
    private static function blocks(Stmt $stmt): array
    {
        return match (true) {
            $stmt instanceof Stmt\Declare_ => [$stmt->stmts ?? []],
            $stmt instanceof Stmt\Namespace_,
            $stmt instanceof Stmt\Block,
            $stmt instanceof Stmt\Foreach_,
            $stmt instanceof Stmt\For_,
            $stmt instanceof Stmt\While_,
            $stmt instanceof Stmt\Do_ => [$stmt->stmts],
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
