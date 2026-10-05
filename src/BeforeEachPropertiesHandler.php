<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Union;

/**
 * Declares the properties a test file assigns as `$this->name = ...` in its `beforeEach()` closures
 * on the TestCase `$this` is bound to ({@see ClosureThisHandler}), for that file only.
 *
 * `beforeEach()` is Pest's setup hook: `$this->parser = new Parser` there is read by every test of
 * the file, which Psalm reports as `UndefinedThisPropertyAssignment` / `UndefinedThisPropertyFetch`
 * on a class that declares no such property. Declaring them on the shared TestCase would leak one
 * file's fixtures into every other file bound to it, so property existence / type / visibility
 * providers answer from the state of the file being analysed and decline (null) for everything else,
 * declared properties included.
 *
 * {@see self::beforeAnalyzeFile()} reads that state off Psalm's own statements, so it is
 * order-independent (a test may precede the `beforeEach()` that sets its state, as at runtime) and
 * rebuilt on every analysis of the file. The `beforeEach` call is recognised by its resolved name
 * (`use function beforeEach as setup` counts; a namespaced `Other\beforeEach()` does not). The type
 * is the union of what Psalm inferred for the assigned expressions, recorded while the closures are
 * analysed; a read analysed before any assignment, or of an untyped value, is `mixed`. Scalar
 * literals are widened (`$this->count = 0` is `int`, not `0`), as a declared property would be.
 * Assignments accept any value: a second `beforeEach()` may assign a different type.
 *
 * Fixtures are instance properties only. Psalm resolves `self::$name` / `static::$name` through the
 * same existence provider and, once it answers true, reads a static property record the TestCase
 * does not have (an undefined-key crash). The event carries neither the access kind nor a reliable
 * node, so the file's static accesses to the TestCase are recorded too and the providers decline
 * those names: Psalm then reports its usual undefined static property issue.
 */
final class BeforeEachPropertiesHandler implements AfterExpressionAnalysisInterface, BeforeFileAnalysisInterface
{
    /** @var array<int, string> start offset of each `$this->name = ...` in a `beforeEach()` closure => name */
    private static array $assignments = [];

    /** @var array<string, true> static accesses as `self::name` or `lowercase\fq\class::name` */
    private static array $statics = [];

    /** @var array<string, Union> */
    private static array $types = [];

    /**
     * Asks for the providers on one bound TestCase; Psalm keeps them per class name and every Pest
     * call in the project reaches here.
     */
    public static function register(Codebase $codebase, string $testCase): void
    {
        $properties = $codebase->properties;
        if ($properties->property_existence_provider->has($testCase)) {
            return;
        }

        $declared = static fn(PropertyExistenceProviderEvent|PropertyVisibilityProviderEvent $event): ?bool => self::declares(
            $codebase,
            $event->getFqClasslikeName(),
            $event->getPropertyName(),
        ) ? true : null;
        $properties->property_existence_provider->registerClosure($testCase, $declared);
        $properties->property_visibility_provider->registerClosure($testCase, $declared);
        $properties->property_type_provider->registerClosure(
            $testCase,
            static function (PropertyTypeProviderEvent $event) use ($codebase): ?Union {
                $name = $event->getPropertyName();
                if (!self::declares($codebase, $event->getFqClasslikeName(), $name)) {
                    return null;
                }

                return ($event->isReadMode() ? self::$types[$name] ?? null : null) ?? Type::getMixed();
            },
        );
    }

    /** Records the type of each `$this->name = <expr>` that sits directly in a `beforeEach()` closure. */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        $name = $expr instanceof Assign ? self::$assignments[$expr->getStartFilePos()] ?? null : null;
        if (!$expr instanceof Assign || $name === null) {
            return null;
        }

        $assigned = self::widen($event->getStatementsSource()->getNodeTypeProvider()->getType($expr->expr) ?? Type::getMixed());
        self::$types[$name] = isset(self::$types[$name])
            ? Type::combineUnionTypes(self::$types[$name], $assigned, $event->getCodebase())
            : $assigned;

        return null;
    }

    /**
     * Rebuilds the file's state from Psalm's name-resolved statements; the types are a union across
     * assignments, so a reused codebase (language server, watch mode) must not add a new analysis to
     * the previous one's.
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        self::$types = [];
        self::$assignments = [];
        self::$statics = [];

        $finder = new NodeFinder();
        $stmts = $event->getStmts();
        $declaredFunctions = $event->getFileStorage()->declaring_function_ids;
        foreach ($finder->findInstanceOf($stmts, FuncCall::class) as $call) {
            $closure = self::beforeEachClosure($call, $declaredFunctions);
            if ($closure === null) {
                continue;
            }

            // A nested class, function or static closure has a `$this` of its own.
            $foreign = [];
            foreach ($finder->find($closure->getStmts(), self::rebindsThis(...)) as $scope) {
                foreach ($finder->findInstanceOf($scope, Assign::class) as $assign) {
                    $foreign[\spl_object_id($assign)] = true;
                }
            }

            foreach ($finder->findInstanceOf($closure->getStmts(), Assign::class) as $assign) {
                $target = $assign->var;
                if ($target instanceof PropertyFetch
                    && $target->var instanceof Variable
                    && $target->var->name === 'this'
                    && $target->name instanceof Identifier
                    && !isset($foreign[\spl_object_id($assign)])
                ) {
                    self::$assignments[$assign->getStartFilePos()] = $target->name->name;
                }
            }
        }

        if (self::$assignments === []) {
            return;
        }

        foreach ($finder->findInstanceOf($stmts, StaticPropertyFetch::class) as $fetch) {
            $class = $fetch->class;
            $class = $class instanceof Name
                ? \strtolower(NameResolution::resolved($class) ?? $class->toString())
                : ($class instanceof Variable && $class->name === 'this' ? 'self' : null);
            if ($class !== null && $fetch->name instanceof Identifier) {
                self::$statics[($class === 'static' ? 'self' : $class) . '::' . $fetch->name->name] = true;
            }
        }
    }

    /**
     * Whether the file's `beforeEach()` declares `$name` on the TestCase. A property the TestCase
     * declares itself keeps resolving through its own storage, so it is never answered here.
     */
    private static function declares(Codebase $codebase, string $testCase, string $name): bool
    {
        return \in_array($name, self::$assignments, true)
            && !isset(self::$statics['self::' . $name])
            && !isset(self::$statics[\strtolower($testCase) . '::' . $name])
            && !isset($codebase->classlike_storage_provider->get($testCase)->appearing_property_ids[$name]);
    }

    /**
     * The closure handed to Pest's `beforeEach(...)` call that keeps the bound `$this`, if any.
     *
     * An unqualified call inside a namespace only falls back to the global function at runtime when
     * the namespace has no function of that name, so a function declared in this file wins.
     *
     * @param array<string, string> $declaredFunctions the file's function ids
     */
    private static function beforeEachClosure(FuncCall $call, array $declaredFunctions): Expr\Closure|Expr\ArrowFunction|null
    {
        if (!$call->name instanceof Name || $call->isFirstClassCallable()) {
            return null;
        }

        [$function, $shadow] = NameResolution::functionName($call->name);
        if ($function !== 'beforeeach' || ($shadow !== null && isset($declaredFunctions[$shadow]))) {
            return null;
        }

        $closure = $call->getArgs()[0]->value ?? null;

        return ($closure instanceof Expr\Closure || $closure instanceof Expr\ArrowFunction) && !$closure->static
            ? $closure
            : null;
    }

    /** @psalm-mutation-free */
    private static function rebindsThis(Node $node): bool
    {
        return $node instanceof Stmt\ClassLike
            || $node instanceof Stmt\Function_
            || (($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) && $node->static);
    }

    /** @psalm-mutation-free */
    private static function widen(Union $type): Union
    {
        $atomics = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            $atomics[] = match (true) {
                $atomic instanceof TLiteralInt => new TInt(),
                $atomic instanceof TLiteralString => new TString(),
                $atomic instanceof TLiteralFloat => new TFloat(),
                $atomic instanceof TTrue, $atomic instanceof TFalse => new TBool(),
                default => $atomic,
            };
        }

        return new Union($atomics);
    }
}
