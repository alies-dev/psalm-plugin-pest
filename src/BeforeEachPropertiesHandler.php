<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClosureAnalyzer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeStatementAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Atomic\TTypeVariable;
use Psalm\Type\Union;

/**
 * Declares, for one test file, the properties it assigns as `$this->name = ...` (or as a target of a
 * destructuring, `[$this->a, 'k' => $this->b] = ...`) in `beforeEach()`
 * closures on the TestCase `$this` is bound to ({@see ClosureThisHandler}). Declaring them on the
 * shared TestCase would leak one file's fixtures into every other file bound to it, so the property
 * existence / type / visibility providers answer from the state of the file being analysed and
 * decline (null) for everything else, declared properties included.
 *
 * {@see self::beforeAnalyzeFile()} reads that state off Psalm's own name-resolved statements, so it
 * is order-independent (a test may precede its `beforeEach()`). The type is the union of what Psalm
 * inferred for the assigned expressions, recorded while the closures are analysed, with scalar
 * literals widened and empty arrays / `never` generics (`[]`, `collect()`) opened up; a read analysed
 * before any assignment is `mixed`, as is a property only ever assigned `null`. A read in `afterEach()` is also `null`, as `beforeEach()`
 * may have thrown first, but without `PossiblyNull*` issues: only guards see it. Psalm hands that flag on to
 * members (`$this->x->y`), aliases and merged branches, so in `afterEach()` a fixture's nullable member, or a
 * null it later picks up, is not reported either. Psalm reconciles an `isset()` that is the left operand of `&&`
 * against a read made without a source, so the statement being analysed says whether that read is in `afterEach()`.
 * Hooks in `Pest.php` (`pest()->beforeEach(...)->in('Feature')`) seed the types for the files they target ({@see UsesParser}).
 *
 * Instance properties only: Psalm resolves `self::$name` through the same existence provider and,
 * once it answers true, reads a static property record the TestCase does not have (an undefined-key
 * crash). The event carries neither the access kind nor a reliable node, so the file's static
 * accesses to the TestCase are recorded and the providers decline those names.
 */
final class BeforeEachPropertiesHandler implements AfterExpressionAnalysisInterface, AfterFileAnalysisInterface, BeforeFileAnalysisInterface, BeforeStatementAnalysisInterface
{
    /** @var array<int, list<string>> start offset of each `$this->name = ...` (or write through an element of it) in a `beforeEach()` closure => name; a destructuring has one per property it targets */
    private static array $assignments = [];

    /** @var array<string, string> `start:end` offsets of the right operand of each `$this->name ??= ...` in a `beforeEach()` closure => name */
    private static array $coalesced = [];

    /** @var array<string, bool> properties only written through elements, `true` until their first write replaces the `array<array-key, mixed>` placeholder they start as */
    private static array $seeded = [];

    /** @var array<string, true> ids of the `afterEach()` closures, which may run after a `beforeEach()` that threw */
    private static array $afterEach = [];

    /** @var bool whether the statement being analysed is in an `afterEach()` closure: Psalm's reconciler reads properties without a source */
    private static bool $teardown = false;

    /** @var array<string, true> static accesses as `self::name`, `*::name` (dynamic class) or `lowercase\fq\class::name` */
    private static array $statics = [];

    /** @var array<string, Union> */
    private static array $types = [];

    /** @var array<string, Union> the bound traits' declared properties: writes keep their type */
    private static array $declared = [];

    /** @var list<array{array<int, list<string>>, array<string, true>, array<string, true>, array<string, Union>, array<string, Union>, array<string, list<string>>, array<string, string>, array<string, bool>}> the including files' state */
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

                if (!$event->isReadMode()) {
                    return self::$declared[$name] ?? Type::getMixed();
                }

                $type = self::$types[$name] ?? Type::getMixed();
                if (isset(self::$declared[$name])) {
                    return $type;
                }

                // `null` alone is only the initial state of a property the file assigns elsewhere.
                if ($type->isNull()) {
                    return Type::getMixed();
                }

                // Psalm's reconciler reads without a source (the left operand of `&&`), so the statement decides.
                $source = $event->getSource();
                $teardown = $source instanceof StatementsSource ? self::inAfterEach($source) : self::$teardown;
                if ($type->isNullable() || $type->isMixed() || !$teardown) {
                    return $type;
                }

                // The `null` keeps guards (`?->`, `isset()`, `!== null`) from being redundant, while
                // teardown that uses the value as is does not report `PossiblyNull*`.
                return Type::combineUnionTypes($type, Type::getNull())->setProperties(['ignore_nullable_issues' => true]);
            },
        );
    }

    /**
     * Records the type of each `$this->name = <expr>` that sits directly in a `beforeEach()` closure,
     * of the right operand of each `$this->name ??= <expr>`, and of each write through an element of
     * the property (`$this->name['a'] = ...`, `$this->name[] = ...`), which holds an array, and of each
     * `$this->name` a destructuring assigns (`[$this->a, $this->b] = ...`).
     */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        // Psalm analyses `??=` as an assignment of a coalesce, whose left side is what the property
        // held so far: the operand is what the property gets, and is typed once analysed.
        $coalesced = self::$coalesced === [] ? null : self::$coalesced[$expr->getStartFilePos() . ':' . $expr->getEndFilePos()] ?? null;
        // A property already typed (by an earlier write) only takes the operand when it can still be null:
        // otherwise `??=` never assigns, and the operand would widen what the property holds.
        if ($coalesced !== null && (!isset(self::$types[$coalesced]) || self::$types[$coalesced]->isNullable() || self::$types[$coalesced]->possibly_undefined)) {
            $operand = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr);
            if ($operand instanceof Union) {
                self::record($coalesced, self::widen($operand, $event->getCodebase()), $event->getCodebase());
            }
        }

        $names = $expr instanceof Assign ? self::$assignments[$expr->getStartFilePos()] ?? null : null;
        if (!$expr instanceof Assign || $names === null) {
            return null;
        }

        if (UsesParser::isDestructuring($expr)) {
            // Each target holds what the destructuring left on it, as in the plain assignment below.
            foreach ($names as $target) {
                self::record($target, self::widen($event->getContext()->vars_in_scope['$this->' . $target] ?? Type::getMixed(), $event->getCodebase()), $event->getCodebase());
            }

            return null;
        }

        $name = $names[0];

        if ($expr->var instanceof ArrayDimFetch) {
            if (isset(self::$seeded[$name])) {
                // A property only written through elements has no whole value to start from: it holds an array
                // per dimension, around the type of the value written (the context holds the shape of that one write).
                $value = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->expr);
                $assigned = self::arrayWrittenThrough($expr->var, $value instanceof Union ? self::widen($value, $event->getCodebase()) : Type::getMixed());
            } elseif (isset(self::$types[$name])) {
                // A property that is also assigned as a whole keeps what Psalm infers for it after the write
                // (its shape, a string, an ArrayAccess object): the write must not invent another container.
                $assigned = self::widen($event->getContext()->vars_in_scope['$this->' . $name] ?? Type::getMixed(), $event->getCodebase());
            } else {
                // Analysed before any whole assignment, the property is still `mixed`: that assignment types it.
                return null;
            }
        } else {
            // The context holds what the assignment left on the property, a `@var` on it included.
            $assigned = self::widen($event->getContext()->vars_in_scope['$this->' . $name] ?? Type::getMixed(), $event->getCodebase());
        }

        self::record($name, $assigned, $event->getCodebase());

        return null;
    }

    /** The first write replaces the placeholder the property was seeded with, whose mixed leaf would absorb its type. */
    private static function record(string $name, Union $type, Codebase $codebase): void
    {
        self::$types[$name] = isset(self::$types[$name]) && !(self::$seeded[$name] ?? false)
            ? Type::combineUnionTypes(self::$types[$name], $type, $codebase)
            : $type;
        if (isset(self::$seeded[$name])) {
            self::$seeded[$name] = false;
        }
    }

    /** An array per dimension of `$element` around `$leaf`: `$this->p['a']['b'] = ...` finds `array<array-key, array<array-key, T>>`. */
    private static function arrayWrittenThrough(ArrayDimFetch $element, ?Union $leaf = null): Union
    {
        $type = $leaf ?? Type::getMixed();
        for ($fetch = $element; $fetch instanceof ArrayDimFetch; $fetch = $fetch->var) {
            $type = new Union([new TArray([Type::getArrayKey(), $type])]);
        }

        return $type;
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
        self::$outer[] = [self::$assignments, self::$afterEach, self::$statics, self::$types, self::$declared, BoundTestCase::bound(), self::$coalesced, self::$seeded];

        // Pest.php hooks that target this file seed the types, so they union with its own assignments.
        self::$declared = BoundTestCase::traitProperties($codebase, $file);
        self::$types = \array_replace(self::$declared, BoundTestCase::properties($codebase, $file));
        self::$assignments = [];
        self::$coalesced = [];
        self::$seeded = [];
        self::$afterEach = [];
        self::$teardown = false;
        self::$statics = [];

        // Names written as a whole (`$this->name = ...`, `??=`) and names written only through an element.
        $plain = [];
        $elements = [];

        $finder = new NodeFinder();
        $stmts = $event->getStmts();
        $declaredFunctions = $event->getFileStorage()->declaring_function_ids;
        foreach ([...$finder->findInstanceOf($stmts, FuncCall::class), ...$finder->findInstanceOf($stmts, MethodCall::class)] as $call) {
            $teardown = self::hookClosure($call, $declaredFunctions, 'aftereach');
            if ($teardown !== null) {
                self::$afterEach[\strtolower($file) . ':' . $teardown->getLine() . ':' . $teardown->getStartFilePos() . ':-:closure'] = true;
            }

            $closure = self::hookClosure($call, $declaredFunctions, 'beforeeach');
            if ($closure === null) {
                continue;
            }

            foreach (UsesParser::thisAssignments($closure) as $name => [$assign]) {
                if ($assign instanceof AssignOp\Coalesce && $assign->var instanceof PropertyFetch) {
                    self::$coalesced[$assign->expr->getStartFilePos() . ':' . $assign->expr->getEndFilePos()] = $name;
                    $plain[$name] = true;
                } else {
                    self::$assignments[$assign->getStartFilePos()][] = $name;
                    if ($assign->var instanceof ArrayDimFetch) {
                        $elements[$name] = isset($elements[$name])
                            ? Type::combineUnionTypes($elements[$name], self::arrayWrittenThrough($assign->var), $codebase)
                            : self::arrayWrittenThrough($assign->var);
                    } else {
                        $plain[$name] = true;
                    }
                }
            }
        }

        // A property only ever written through its elements holds an array, whichever write is analysed first.
        // Until then it may still be unset (`if (!isset($this->p)) { $this->p['a'] = 1; }`), so the seed is
        // nullable, with the null not reported on the dimensions of a write.
        foreach (\array_diff_key($elements, $plain) as $name => $type) {
            if (!isset(self::$types[$name])) {
                self::$types[$name] = Type::combineUnionTypes($type, Type::getNull(), $codebase)->setProperties(['ignore_nullable_issues' => true]);
                self::$seeded[$name] = true;
            }
        }

        if (self::$assignments === [] && self::$coalesced === [] && self::$types === []) {
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
        [self::$assignments, self::$afterEach, self::$statics, self::$types, self::$declared, $bound, self::$coalesced, self::$seeded] = \array_pop(self::$outer) ?? [[], [], [], [], [], [], [], []];
        BoundTestCase::reinstate($event->getCodebase(), $bound);
        self::$teardown = false;
    }

    #[\Override]
    public static function beforeStatementAnalysis(BeforeStatementAnalysisEvent $event): ?bool
    {
        if (self::$afterEach !== []) {
            self::$teardown = self::inAfterEach($event->getStatementsSource());
        }

        return null;
    }

    /**
     * Whether the file's `beforeEach()` declares `$name` on the TestCase. A property the TestCase
     * declares itself keeps resolving through its own storage.
     */
    private static function declares(Codebase $codebase, string $testCase, string $name): bool
    {
        return (isset(self::$types[$name]) || \in_array($name, \array_merge([], ...self::$assignments), true) || \in_array($name, self::$coalesced, true))
            && !isset(self::$statics['self::' . $name])
            && !isset(self::$statics['*::' . $name])
            && !isset(self::$statics[\strtolower($testCase) . '::' . $name])
            && !isset($codebase->classlike_storage_provider->get($testCase)->appearing_property_ids[$name]);
    }

    /** Whether `$source` is, or sits in a closure nested in, an `afterEach()` closure. */
    private static function inAfterEach(?StatementsSource $source): bool
    {
        while ($source instanceof \Psalm\StatementsSource) {
            if ($source instanceof ClosureAnalyzer && isset(self::$afterEach[$source->getClosureId()])) {
                return true;
            }

            $parent = $source->getSource();
            $source = $parent === $source ? null : $parent;
        }

        return false;
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
     * The closure handed to Pest's `beforeEach(...)` / `afterEach(...)` call, or to the same method of a
     * `pest()` / `uses()` chain, that keeps the bound `$this`. An unqualified call inside a namespace
     * falls back to the global function only when the namespace declares none, so a function
     * declared in this file wins.
     *
     * @param array<string, string> $declaredFunctions the file's function ids
     * @param 'beforeeach'|'aftereach' $hook
     */
    private static function hookClosure(FuncCall|MethodCall $call, array $declaredFunctions, string $hook): Expr\Closure|Expr\ArrowFunction|null
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        if ($call instanceof MethodCall) {
            $isHook = $call->name instanceof Identifier && \strtolower($call->name->name) === $hook && self::isPestChain($call->var);
        } elseif ($call->name instanceof Name) {
            $shadow = NameResolution::resolved($call->name, 'namespacedName');
            $isHook = \strtolower(NameResolution::resolved($call->name) ?? $call->name->toString()) === $hook
                && !isset($declaredFunctions[\strtolower($shadow ?? '')]);
        } else {
            $isHook = false;
        }

        $closure = $isHook ? $call->getArgs()[0]->value ?? null : null;

        return ($closure instanceof Expr\Closure || $closure instanceof Expr\ArrowFunction) && !$closure->static
            ? $closure
            : null;
    }

    private static function widen(Union $type, Codebase $codebase): Union
    {
        $atomics = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            $atomics[] = match (true) {
                $atomic instanceof TLiteralInt => new TInt(),
                $atomic instanceof TLiteralString => new TString(),
                $atomic instanceof TLiteralFloat => new TFloat(),
                $atomic instanceof TTrue, $atomic instanceof TFalse => new TBool(),
                // A fixture starting as `[]` / `collect()` is filled later, so `never` is only its initial state.
                $atomic instanceof TArray && $atomic->isEmptyArray() => new TArray([Type::getArrayKey(), Type::getMixed()]),
                $atomic instanceof TGenericObject => $atomic->setTypeParams(\array_map(
                    static fn(Union $param): Union => self::openTemplateParam($param, $codebase),
                    $atomic->type_params,
                )),
                default => $atomic,
            };
        }

        return new Union($atomics);
    }

    /**
     * A generic fixture's template argument, with `never` (the template of an empty `new Bag()`)
     * opened up to `mixed`. Psalm 7 carries an argument no constructor argument pinned as a type
     * variable (`Bag<`_0, `_1>`) tied to the function-like that minted it, so it is resolved to
     * the type it was inferred as before the check; a variable nothing bound resolves to `mixed`.
     *
     * @param array<string, true> $unrolling the type variables being resolved further up the stack
     */
    private static function openTemplateParam(Union $param, Codebase $codebase, array $unrolling = []): Union
    {
        $opened = Type::getNever();
        foreach ($param->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TTypeVariable) {
                $resolved = !isset($unrolling[$atomic->name]) ? $atomic->getResolvedType($codebase, false) : null;
                $part = $resolved instanceof Union
                    ? self::openTemplateParam($resolved, $codebase, [...$unrolling, $atomic->name => true])
                    : Type::getMixed();
            } else {
                $part = new Union([$atomic]);
            }

            $opened = Type::combineUnionTypes($opened, $part, $codebase);
        }

        return $opened->isNever() ? Type::getMixed() : $opened;
    }
}
