<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestDuplicateTestDescription;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;

/**
 * Reports a test whose description Pest already registered in the file: `TestCaseFactory::addMethod()`
 * throws `TestAlreadyExist`, so the whole file fails to load. Pest keys a test by its description,
 * prefixed with `it ` for `it()` and with the enclosing `describe()` descriptions (`Str::describe()`).
 *
 * Only top-level statements, namespace blocks and `describe()` closure bodies are read, as a test in a
 * loop or condition shares one call site; only string-literal descriptions are compared. Functions are
 * taken as Pest's when the scanned one returns Pest's pending call and the file declares no namespaced
 * function of the same name.
 */
final class DuplicateTestDescriptionHandler implements AfterFileAnalysisInterface
{
    private const DESCRIBE_CALL = 'Pest\PendingCalls\DescribeCall';

    /** Function id => the Pest class its native return type names. */
    private const FUNCTIONS = [
        'test' => PestApi::TEST_CALL,
        'it' => PestApi::TEST_CALL,
        'todo' => PestApi::TEST_CALL,
        'describe' => self::DESCRIBE_CALL,
    ];

    #[\Override]
    public static function afterAnalyzeFile(AfterFileAnalysisEvent $event): void
    {
        $functions = $event->getCodebase()->functions;
        $pest = [];
        foreach (self::FUNCTIONS as $functionId => $class) {
            $pest[$functionId] = $functions->hasStubbedFunction($functionId)
                && isset($functions->getStorage(null, $functionId)->signature_return_type?->getAtomicTypes()[$class]);
        }

        $seen = [];
        self::scan($event, $pest, $event->getFileStorage()->declaring_function_ids, $event->getStmts(), '', $seen);
    }

    /**
     * @param array<string, bool> $pest
     * @param array<string, string> $declaredFunctions the file's function ids
     * @param array<array-key, Stmt> $stmts
     * @param array<string, true> $seen
     */
    private static function scan(AfterFileAnalysisEvent $event, array $pest, array $declaredFunctions, array $stmts, string $prefix, array &$seen): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                self::scan($event, $pest, $declaredFunctions, $stmt->stmts, $prefix, $seen);
                continue;
            }

            $call = $stmt instanceof Stmt\Expression ? self::root($stmt->expr) : null;
            $args = $call?->getArgs() ?? [];
            $first = $args[0] ?? null;
            if (!$call instanceof \PhpParser\Node\Expr\FuncCall || !$call->name instanceof Name || $first === null || $first->name !== null || !$first->value instanceof String_) {
                continue;
            }

            $description = $first->value->value;

            $function = \strtolower(NameResolution::resolved($call->name) ?? $call->name->toString());
            if (!($pest[$function] ?? false) || isset($declaredFunctions[\strtolower(NameResolution::resolved($call->name, 'namespacedName') ?? '')])) {
                continue;
            }

            if ($function === 'describe') {
                $closure = $args[1]->value ?? null;
                if ($closure instanceof Expr\Closure) {
                    self::scan($event, $pest, $declaredFunctions, $closure->stmts, $prefix . '`' . $description . '` → ', $seen);
                }

                continue;
            }

            $key = $prefix . ($function === 'it' ? 'it ' : '') . $description;
            if (isset($seen[$key])) {
                $source = $event->getStatementsSource();
                IssueBuffer::maybeAdd(
                    new PestDuplicateTestDescription(
                        "Pest throws TestAlreadyExist because the description '{$key}' is already registered in this file; give the test a different description.",
                        new CodeLocation($source, $call),
                    ),
                    $source->getSuppressedIssues(),
                );
            }

            $seen[$key] = true;
        }
    }

    /** The call a fluent chain such as `it(...)->group(...)->skip()` starts from. */
    private static function root(Expr $expr): ?FuncCall
    {
        while ($expr instanceof MethodCall) {
            $expr = $expr->var;
        }

        return $expr instanceof FuncCall && !$expr->isFirstClassCallable() ? $expr : null;
    }
}
