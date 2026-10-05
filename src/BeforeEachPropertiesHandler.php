<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;
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
 * Declares, for one test file, the properties it assigns as `$this->name = ...` in `beforeEach()`
 * closures on the TestCase `$this` is bound to ({@see ClosureThisHandler}). Declaring them on the
 * shared TestCase would leak one file's fixtures into every other file bound to it, so the property
 * existence / type / visibility providers answer from the state of the file being analysed and
 * decline (null) for everything else, declared properties included.
 *
 * {@see self::beforeAnalyzeFile()} reads that state off Psalm's own name-resolved statements, so it
 * is order-independent (a test may precede its `beforeEach()`). The type is the union of what Psalm
 * inferred for the assigned expressions, recorded while the closures are analysed, with scalar
 * literals widened; a read analysed before any assignment is `mixed`. Hooks in `Pest.php`
 * (`pest()->beforeEach(...)->in('Feature')`) seed the types for the files they target ({@see UsesParser}).
 *
 * Instance properties only: Psalm resolves `self::$name` through the same existence provider and,
 * once it answers true, reads a static property record the TestCase does not have (an undefined-key
 * crash). The event carries neither the access kind nor a reliable node, so the file's static
 * accesses to the TestCase are recorded and the providers decline those names.
 */
final class BeforeEachPropertiesHandler implements AfterExpressionAnalysisInterface, AfterFileAnalysisInterface, BeforeFileAnalysisInterface
{
    /** @var array<int, string> start offset of each `$this->name = ...` in a `beforeEach()` closure => name */
    private static array $assignments = [];

    /** @var array<string, true> static accesses as `self::name`, `*::name` (dynamic class) or `lowercase\fq\class::name` */
    private static array $statics = [];

    /** @var array<string, Union> */
    private static array $types = [];

    /** @var array<string, Union> the bound traits' declared properties: writes keep their type */
    private static array $declared = [];

    /** @var list<array{array<int, string>, array<string, true>, array<string, Union>, array<string, Union>}> the including files' state */
    private static array $outer = [];

    /** Asks for the providers on one bound TestCase; Psalm keeps them per class name. */
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

                return ($event->isReadMode() ? self::$types[$name] ?? null : self::$declared[$name] ?? null) ?? Type::getMixed();
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

        // The context holds what the assignment left on the property, a `@var` on it included.
        $assigned = self::widen($event->getContext()->vars_in_scope['$this->' . $name] ?? Type::getMixed());
        self::$types[$name] = isset(self::$types[$name])
            ? Type::combineUnionTypes(self::$types[$name], $assigned, $event->getCodebase())
            : $assigned;

        return null;
    }

    /**
     * Rebuilds the file's state; the types are a union across assignments, so a reused codebase
     * (language server, watch mode) must not add a new analysis to the previous one's. An `include`
     * in a test analyses the included file in the middle of this one, so the state is stacked.
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $codebase = $event->getCodebase();
        $file = $event->getFileStorage()->file_path;
        self::$outer[] = [self::$assignments, self::$statics, self::$types, self::$declared];

        // Pest.php hooks that target this file seed the types, so they union with its own assignments.
        self::$declared = BoundTestCase::traitProperties($codebase, $file);
        self::$types = \array_replace(self::$declared, BoundTestCase::properties($codebase, $file));
        self::$assignments = [];
        self::$statics = [];

        $finder = new NodeFinder();
        $stmts = $event->getStmts();
        $declaredFunctions = $event->getFileStorage()->declaring_function_ids;
        foreach ([...$finder->findInstanceOf($stmts, FuncCall::class), ...$finder->findInstanceOf($stmts, MethodCall::class)] as $call) {
            $closure = self::beforeEachClosure($call, $declaredFunctions);
            if ($closure === null) {
                continue;
            }

            foreach (UsesParser::thisAssignments($closure) as $name => [$assign]) {
                self::$assignments[$assign->getStartFilePos()] = $name;
            }
        }

        if (self::$assignments === [] && self::$types === []) {
            return;
        }

        foreach ($finder->findInstanceOf($stmts, StaticPropertyFetch::class) as $fetch) {
            $class = $fetch->class;
            $class = $class instanceof Name
                ? \strtolower(NameResolution::resolved($class) ?? $class->toString())
                : ($class instanceof Variable && $class->name === 'this' ? 'self' : '*');
            if ($fetch->name instanceof Identifier) {
                self::$statics[($class === 'static' ? 'self' : $class) . '::' . $fetch->name->name] = true;
            }
        }
    }

    #[\Override]
    public static function afterAnalyzeFile(AfterFileAnalysisEvent $event): void
    {
        [self::$assignments, self::$statics, self::$types, self::$declared] = \array_pop(self::$outer) ?? [[], [], [], []];
        if (self::$outer === []) {
            BoundTestCase::restore();
        }
    }

    /**
     * Whether the file's `beforeEach()` declares `$name` on the TestCase. A property the TestCase
     * declares itself keeps resolving through its own storage.
     */
    private static function declares(Codebase $codebase, string $testCase, string $name): bool
    {
        return (isset(self::$types[$name]) || \in_array($name, self::$assignments, true))
            && !isset(self::$statics['self::' . $name])
            && !isset(self::$statics['*::' . $name])
            && !isset(self::$statics[\strtolower($testCase) . '::' . $name])
            && !isset($codebase->classlike_storage_provider->get($testCase)->appearing_property_ids[$name]);
    }

    /** Whether `$link` is a chain of method calls rooted at Pest's `uses()` / `pest()`. */
    private static function isPestChain(Expr $link): bool
    {
        while ($link instanceof MethodCall) {
            $link = $link->var;
        }

        return $link instanceof FuncCall
            && $link->name instanceof Name
            && \in_array(\strtolower(NameResolution::resolved($link->name) ?? $link->name->toString()), ['uses', 'pest'], true);
    }

    /**
     * The closure handed to Pest's `beforeEach(...)` call, or to the `->beforeEach(...)` of a
     * `pest()` / `uses()` chain, that keeps the bound `$this`. An unqualified call inside a namespace
     * falls back to the global function only when the namespace declares none, so a function
     * declared in this file wins.
     *
     * @param array<string, string> $declaredFunctions the file's function ids
     */
    private static function beforeEachClosure(FuncCall|MethodCall $call, array $declaredFunctions): Expr\Closure|Expr\ArrowFunction|null
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        if ($call instanceof MethodCall) {
            $isHook = $call->name instanceof Identifier && \strtolower($call->name->name) === 'beforeeach' && self::isPestChain($call->var);
        } elseif ($call->name instanceof Name) {
            $shadow = NameResolution::resolved($call->name, 'namespacedName');
            $isHook = \strtolower(NameResolution::resolved($call->name) ?? $call->name->toString()) === 'beforeeach'
                && !isset($declaredFunctions[\strtolower($shadow ?? '')]);
        } else {
            $isHook = false;
        }

        $closure = $isHook ? $call->getArgs()[0]->value ?? null : null;

        return ($closure instanceof Expr\Closure || $closure instanceof Expr\ArrowFunction) && !$closure->static
            ? $closure
            : null;
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
