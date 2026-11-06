<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Any;
use Psalm\Storage\Assertion\IsNotType;
use Psalm\Storage\Assertion\IsType;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TCallableObject;
use Psalm\Type\Atomic\TCallableString;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;

/**
 * Narrows the subject of a statement-level `expect($var)->...` chain for the code after it, as PHPUnit's
 * `assertNotNull()` does through `@psalm-assert`: after `expect($ad)->not->toBeNull();` `$ad` is no longer
 * nullable. Pest's assertions are dynamic (`Expectation::__call()`), so a handler walks the chain from `expect()`:
 *
 *  - the root must be Pest's global `expect()`: another function returning an `Expectation` may assert on
 *    something else than its argument (`assertThat($x)` wrapping `expect($x ?? 'fallback')`);
 *  - `->not` / `->not()` negates the next assertion only (Pest hands back the original expectation);
 *  - an assertion in {@see self::ASSERTIONS} (or `toBeInstanceOf()`) narrows the current subject, any other `to*`
 *    returns the same expectation; `->and($var)` makes `$var` the subject;
 *  - every other member (`each`, `json()`, higher-order access) or a first-class callable (`->toBeNull(...)`,
 *    which only builds a Closure) ends the walk: what they assert on is no longer the variable.
 *
 * Members are compared lowercased, as PHP dispatches them case-insensitively. A statement that writes a variable
 * anywhere in the chain (`->and($value = ...)`) is left alone: the assertion ran on a value the analysis cannot
 * attribute to the variable any more.
 *
 * The narrowing is Psalm's reconciler with `IsType` / `IsNotType`, so it intersects with the known type as
 * `is_string()` would. It gets no code location (tests routinely assert what the type already says) and is marked
 * as coming from a docblock, so a later contradicting `if` is a DocblockTypeContradiction. An assertion that
 * cannot hold (`expect($string)->toBeInt()`) narrows nothing: the test fails at runtime ({@see ExpectationHandler}
 * reports it).
 *
 * The subject is a plain variable or a property path (`$a->b`, `$this->prop`), both of which Psalm tracks in scope.
 * A property can change behind the analysis' back (a service, a method writing through a private helper, a free
 * function, a closure, an alias, a magic `@property`), and Psalm keeps a property path across calls by default
 * (`rememberPropertyAssignmentsAfterCall`). A narrowed property path therefore lives only until the next statement
 * that may run code or write a property: any call or `new`, `clone`, `include`, `eval`, `yield`, a property write,
 * a reference assignment or `unset()`. Then the path gets back the type it had before the assertion (its declared
 * type once Psalm has rewritten the narrowed one). A plain variable keeps its narrowing: a call cannot reassign it.
 * Consecutive `expect()` statements keep it, unless one of them runs code itself: an argument or dynamic member name
 * that does, a callable argument (Pest may invoke it), `toThrow()` (invokes the subject), or a member Pest does not
 * declare (a custom expectation or a higher-order call on the value). Such a chain narrows no property path either:
 * it runs after Pest captured the subject. Not covered: a property read or string conversion running `__get()` /
 * `__toString()`, an array offset running `offsetGet()`, a write through a reference taken earlier.
 */
final class ExpectNarrowingHandler implements AfterStatementAnalysisInterface
{
    /** Lowercased Pest assertion => Psalm type of the subject once it passes. */
    private const ASSERTIONS = [
        'tobenull' => 'null',
        'tobestring' => 'string',
        'tobeint' => 'int',
        'tobefloat' => 'float',
        'tobebool' => 'bool',
        'tobearray' => 'array',
        'tobetrue' => 'true',
        'tobefalse' => 'false',
        'tobeobject' => 'object',
        'tobecallable' => 'callable',
        'tobeiterable' => 'iterable',
        'tobelist' => 'list<mixed>',
        'tobenumeric' => 'numeric',
        'tobescalar' => 'scalar',
        'toberesource' => 'resource',
    ];

    /**
     * Each property path type this handler wrote => the type it replaced (false: the path was not in scope). Keyed by
     * identity: a Union is immutable, so it is the same object in every Context cloned from the one it was written to.
     *
     * @var \WeakMap<Union, Union|false>|null
     */
    private static ?\WeakMap $narrowedTypes = null;

    /**
     * Per function body (its StatementsAnalyzer), each narrowed property path => the ids of the types it was narrowed
     * to. Psalm writes an equal Union in place of the narrowed one when it records a read for the data flow, and when
     * branches merge; such a path is recognised by its type and goes back to its declared type. A write to the path
     * (or its base) ends that: an equal type is Psalm's own then.
     *
     * @var \WeakMap<StatementsAnalyzer, array<string, array<string, true>>>|null
     */
    private static ?\WeakMap $narrowedIds = null;

    private static ?NodeFinder $finder = null;

    /** @return \WeakMap<Union, Union|false> */
    private static function narrowedTypes(): \WeakMap
    {
        if (!self::$narrowedTypes instanceof \WeakMap) {
            /** @var \WeakMap<Union, Union|false> $map */
            $map = new \WeakMap();
            self::$narrowedTypes = $map;
        }

        return self::$narrowedTypes;
    }

    /** @return \WeakMap<StatementsAnalyzer, array<string, array<string, true>>> */
    private static function narrowedIds(): \WeakMap
    {
        if (!self::$narrowedIds instanceof \WeakMap) {
            /** @var \WeakMap<StatementsAnalyzer, array<string, array<string, true>>> $map */
            $map = new \WeakMap();
            self::$narrowedIds = $map;
        }

        return self::$narrowedIds;
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();
        $source = $event->getStatementsSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $context = $event->getContext();
        $codebase = $event->getCodebase();

        // `expect($x)->a->b()` => the `expect($x)` call and the members applied to it, in call order.
        $steps = [];
        $root = $stmt instanceof Expression ? $stmt->expr : null;
        while ($root instanceof MethodCall || $root instanceof PropertyFetch) {
            $steps[] = $root;
            $root = $root->var;
        }

        $isExpect = $root instanceof FuncCall && $steps !== [] && self::isPestExpect($root, $source, $codebase);
        $runsCode = $isExpect && self::chainRunsCode($root, $steps, $source, $codebase);
        if ((self::narrowedIds()[$source] ?? []) !== []) {
            $written = self::writtenPaths($stmt);
            if ($isExpect ? $runsCode : self::runsCode($stmt)) {
                self::forgetNarrowedPaths($context, $source, $written);
            }

            self::forgetWrittenIds($source, $written);
        }

        if (!$isExpect || !$root instanceof FuncCall || self::writesVariable($stmt)) {
            return null;
        }

        $subject = self::variableId($root->args[0] ?? null);
        if ($subject === null) {
            return null;
        }

        $assertions = [];
        foreach (self::collect($subject, \array_reverse($steps), $source) as $id => $idAssertions) {
            // A path Psalm forgot (`rememberPropertyAssignmentsAfterCall` off) is rebuilt from its declared type.
            $isPath = \str_contains($id, '->');
            if ($isPath ? !$runsCode : isset($context->vars_in_scope[$id])) {
                $assertions[$id] = $idAssertions;
            }
        }

        $before = $context->vars_in_scope;
        [$context->vars_in_scope, $context->references_in_scope] = self::reconcile(
            $assertions,
            $before,
            $context->references_in_scope,
            $context->inside_loop,
            $source,
            $codebase,
        );
        self::rememberNarrowedPaths($before, $context->vars_in_scope, $source);

        return null;
    }

    /**
     * @param array<string, Union> $before
     * @param array<string, Union> $after
     */
    private static function rememberNarrowedPaths(array $before, array $after, StatementsAnalyzer $scope): void
    {
        $types = self::narrowedTypes();
        $ids = self::narrowedIds()[$scope] ?? [];
        foreach ($after as $id => $type) {
            $previous = $before[$id] ?? false;
            if ($type === $previous || !\str_contains($id, '->')) {
                continue;
            }

            // Narrowing a narrowed path again: the type to go back to is still the one before the first assertion.
            $first = $previous instanceof Union ? $types[$previous] ?? null : null;
            if ($first !== null) {
                $previous = $first;
            } elseif ($previous instanceof Union && self::isNarrowedCopy($previous, $ids[$id] ?? [])) {
                $previous = false;
            }

            $types[$type] = $previous;
            $ids[$id][$type->getId()] = true;
        }

        self::narrowedIds()[$scope] = $ids;
    }

    /**
     * Puts back the type each property path narrowed by this handler had before (its declared type when Psalm has
     * rewritten it since): the assertion checked a value the property may no longer hold. A path the statement wrote
     * holds what Psalm inferred for the write.
     *
     * @param array<string, true> $written
     */
    private static function forgetNarrowedPaths(Context $context, StatementsAnalyzer $scope, array $written): void
    {
        $types = self::narrowedTypes();
        $ids = self::narrowedIds()[$scope] ?? [];
        foreach ($context->vars_in_scope as $id => $type) {
            $previous = $types[$type] ?? null;
            if ($previous instanceof Union) {
                $context->vars_in_scope[$id] = $previous;
            } elseif ($previous === false || !isset($written[$id]) && self::isNarrowedCopy($type, $ids[$id] ?? [])) {
                $context->remove($id, false);
            }
        }
    }

    /**
     * A write gives a path (and the paths under it) a value of Psalm's: an equal type is no longer the narrowing's.
     *
     * @param array<string, true> $written
     */
    private static function forgetWrittenIds(StatementsAnalyzer $scope, array $written): void
    {
        if ($written === []) {
            return;
        }

        $ids = self::narrowedIds()[$scope] ?? [];
        foreach (\array_keys($ids) as $id) {
            foreach (\array_keys($written) as $writtenId) {
                if ($id === $writtenId || \str_starts_with($id, $writtenId . '->')) {
                    unset($ids[$id]);
                }
            }
        }

        self::narrowedIds()[$scope] = $ids;
    }

    /**
     * Whether `$type` is what Psalm made of a narrowing under another identity: when it records a read for the data
     * flow, or merges branches.
     *
     * @param array<string, true> $narrowedTo
     *
     * @psalm-mutation-free
     */
    private static function isNarrowedCopy(Union $type, array $narrowedTo): bool
    {
        return isset($narrowedTo[$type->getId()]);
    }

    /**
     * The variables and property paths a write in `$node` targets, outside the closures and declarations in it: they
     * write another scope.
     *
     * @return array<string, true>
     */
    private static function writtenPaths(Node $node): array
    {
        $finder = self::finder();
        $elsewhere = [];
        $scopes = $finder->find(
            $node,
            static fn(Node $node): bool => $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction
                || $node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_,
        );
        foreach ($finder->find($scopes, self::isWrite(...)) as $write) {
            $elsewhere[\spl_object_id($write)] = true;
        }

        $paths = [];
        foreach ($finder->find($node, self::isWrite(...)) as $write) {
            if (isset($elsewhere[\spl_object_id($write)]) || !self::isWrite($write)) {
                continue;
            }

            foreach ($finder->find($write->var, static fn(Node $node): bool => $node instanceof Expr) as $target) {
                $id = $target instanceof Expr ? self::pathId($target) : null;
                if ($id !== null) {
                    $paths[$id] = true;
                }
            }
        }

        return $paths;
    }

    /**
     * @psalm-assert-if-true Expr\Assign|Expr\AssignOp|Expr\AssignRef|Expr\PreInc|Expr\PostInc|Expr\PreDec|Expr\PostDec $node
     *
     * @psalm-pure
     */
    private static function isWrite(Node $node): bool
    {
        return $node instanceof Expr\Assign || $node instanceof Expr\AssignOp || $node instanceof Expr\AssignRef
            || $node instanceof Expr\PreInc || $node instanceof Expr\PostInc || $node instanceof Expr\PreDec
            || $node instanceof Expr\PostDec;
    }

    /** Deliberately coarse: any assignment or increment anywhere (arguments, closures, the root call). */
    private static function writesVariable(Node $node): bool
    {
        return self::finder()->findFirst($node, self::isWrite(...)) instanceof Node;
    }

    /**
     * Whether evaluating `$node` may change a property: it runs code (a call, `new`, `clone`, `include`, `eval`, a
     * generator resuming its caller), writes a property (perhaps through an alias of the narrowed object), takes a
     * reference or unsets something. Deliberately coarse: a closure counts by its body, called or not.
     */
    private static function runsCode(Node $node): bool
    {
        return self::finder()->findFirst(
            $node,
            static fn(Node $node): bool => $node instanceof Expr\CallLike || $node instanceof Expr\Clone_
                || $node instanceof Expr\Include_ || $node instanceof Expr\Eval_ || $node instanceof Expr\Yield_
                || $node instanceof Expr\YieldFrom || $node instanceof Expr\AssignRef || $node instanceof Stmt\Unset_
                || self::isWrite($node) && self::finder()->findFirstInstanceOf($node->var, PropertyFetch::class) instanceof Node,
        ) instanceof Node;
    }

    /**
     * {@see self::runsCode()} for an `expect()` chain, whose own calls are Pest's: what its arguments and dynamic
     * member names evaluate, a callable argument (`each()`, `sequence()`, `when()`, `toSatisfy()` call it),
     * `toThrow()` (calls the subject) and a member Pest does not declare (a custom expectation, a higher-order call).
     *
     * @param list<MethodCall|PropertyFetch> $steps
     */
    private static function chainRunsCode(FuncCall $root, array $steps, StatementsAnalyzer $source, Codebase $codebase): bool
    {
        $nodeTypes = $source->getNodeTypeProvider();
        foreach ($root->args as $arg) {
            if (self::runsCode($arg)) {
                return true;
            }
        }

        foreach ($steps as $step) {
            if ($step->name instanceof Expr) {
                return true;
            }

            if (!$step instanceof MethodCall) {
                continue;
            }

            $member = $step->name->toLowerString();
            if ($member === 'tothrow'
                || !$codebase->methodExists(PestApi::EXPECTATION . '::' . $member)
                    && !$codebase->methodExists(PestApi::MIXIN_EXPECTATION . '::' . $member)
            ) {
                return true;
            }

            foreach ($step->args as $arg) {
                if (self::runsCode($arg) || $arg instanceof Arg && self::mayBeCallable($nodeTypes->getType($arg->value))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @psalm-mutation-free */
    private static function mayBeCallable(?Union $type): bool
    {
        foreach ($type?->getAtomicTypes() ?? [] as $atomic) {
            if ($atomic instanceof TCallable || $atomic instanceof TClosure || $atomic instanceof TCallableObject
                || $atomic instanceof TCallableString || $atomic instanceof TObject || $atomic instanceof TMixed
                || $atomic instanceof TNamedObject && \strcasecmp($atomic->value, \Closure::class) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    private static function finder(): NodeFinder
    {
        return self::$finder ??= new NodeFinder();
    }

    /**
     * Whether the call is Pest's global `expect()`, reached the way Psalm resolves a name (`\expect()`, `use function
     * expect as e`, an unqualified call in a namespace without its own `expect()`) and returning Pest's `Expectation`.
     */
    private static function isPestExpect(FuncCall $call, StatementsAnalyzer $source, Codebase $codebase): bool
    {
        $name = $call->name;
        if (!$name instanceof Name) {
            return false;
        }

        $functions = $codebase->functions;
        $id = \strtolower($functions->getFullyQualifiedFunctionNameFromString($name->toCodeString(), $source));
        if ($id !== 'expect' && ($name->toLowerString() !== 'expect' || $functions->functionExists($source, $id))) {
            return false;
        }

        $returned = self::atomic($source->getNodeTypeProvider()->getType($call));

        return $returned instanceof TNamedObject && \strcasecmp($returned->value, PestApi::EXPECTATION) === 0;
    }

    /**
     * Walks the chain from `expect()` outward: per variable, the reconciler assertions it implies.
     *
     * @param list<MethodCall|PropertyFetch> $steps
     *
     * @return array<string, list<list<Assertion>>>
     */
    private static function collect(string $subject, array $steps, StatementsSource $source): array
    {
        $assertions = [];
        $negated = false;

        foreach ($steps as $step) {
            $member = $step->name instanceof Identifier ? $step->name->toLowerString() : '';

            if ($member === 'not' && (!$step instanceof MethodCall || $step->args === [])) {
                $negated = !$negated;

                continue;
            }

            if (!$step instanceof MethodCall) {
                break;
            }

            if ($member === 'and') {
                $subject = \count($step->args) === 1 ? self::variableId($step->args[0]) : null;
                if ($subject === null) {
                    break;
                }

                $negated = false;

                continue;
            }

            if (!\str_starts_with($member, 'to') || $step->isFirstClassCallable()) {
                break;
            }

            $type = self::assertedType($member, $step, $source);
            if ($type instanceof Atomic) {
                $assertions[$subject][] = [$negated ? new IsNotType($type) : new IsType($type)];
            }

            $negated = false;
        }

        return $assertions;
    }

    /**
     * The type the subject has once the (lowercased) assertion passed; null for one this handler does not know.
     * {@see ExpectationHandler} narrows the chain's value with it too.
     */
    public static function assertedType(string $assertion, MethodCall $call, StatementsSource $source): ?Atomic
    {
        if ($assertion !== 'tobeinstanceof') {
            $type = self::ASSERTIONS[$assertion] ?? null;

            return $type === null ? null : Type::parseString($type)->getSingleAtomic();
        }

        // `toBeInstanceOf(Foo::class, 'message')`: the class is the first argument's inferred literal class-string,
        // so imports and aliases need no name resolution. A dynamic class, a spread (it could fill any position)
        // or a named `class:` narrows nothing.
        $args = $call->getArgs();
        foreach ($args as $i => $arg) {
            if ($arg->unpack || $i === 0 && $arg->name !== null) {
                return null;
            }
        }

        $class = isset($args[0]) ? self::atomic($source->getNodeTypeProvider()->getType($args[0]->value)) : null;

        return $class instanceof TLiteralClassString ? new TNamedObject($class->value) : null;
    }

    /** @psalm-pure */
    public static function atomic(?Union $type): ?Atomic
    {
        return $type?->isSingle() ? $type->getSingleAtomic() : null;
    }

    /** `$type` once the assertion passed (or, `$negated`, failed); null when it cannot hold for `$type`. */
    public static function narrow(Union $type, Atomic $asserted, bool $negated, StatementsAnalyzer $source, Codebase $codebase): ?Union
    {
        $assertion = $negated ? new IsNotType($asserted) : new IsType($asserted);
        $narrowed = self::run(['$v' => [[$assertion]]], ['$v' => $type], [], false, $source)[0]['$v'] ?? null;

        return self::impossible($narrowed, $type, $codebase) ? null : $narrowed;
    }

    /**
     * Without a code location the reconciler does not flag an assertion that cannot hold: it answers the asserted
     * type itself, which a possible assertion never does (its answer is always a part of the old type). A float
     * is not an int here: Pest's matchers are strict. A template parameter stands for whatever its bound allows,
     * so the answer is checked against the bounds: `T` (bound `mixed`) can be any class, `T of Foo` only a Foo.
     *
     * The reconciler answers a plain `Foo` for `Foo&static` (a `static`/`$this` return), and a plain class is
     * never contained by its `static` form; `static` only tells which subclass, so it is dropped from the old type.
     *
     * @psalm-assert-if-false Union $narrowed
     */
    private static function impossible(?Union $narrowed, Union $old, Codebase $codebase): bool
    {
        return !$narrowed instanceof \Psalm\Type\Union || $narrowed->isNever() || $narrowed->failed_reconciliation
            || !$codebase->isTypeContainedByType($narrowed, self::withoutStatic(self::bounds($old, $codebase)), false, false, false, false);
    }

    /** `$type` with each template parameter replaced by its bound (itself resolved). */
    private static function bounds(Union $type, Codebase $codebase): Union
    {
        if (!$type->hasTemplate()) {
            return $type;
        }

        $parts = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            $parts[] = $atomic instanceof TTemplateParam ? self::bounds($atomic->as, $codebase) : new Union([$atomic]);
        }

        return Type::combineUnionTypeArray($parts, $codebase);
    }

    private static function withoutStatic(Union $type): Union
    {
        $atomics = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            $atomics[] = $atomic instanceof TNamedObject && $atomic->is_static ? $atomic->setIsStatic(false) : $atomic;
        }

        return new Union($atomics);
    }

    /**
     * @param array<string, list<list<Assertion>>> $assertions
     * @param array<string, Union> $vars
     * @param array<string, string> $references
     *
     * @return array{array<string, Union>, array<string, string>}
     */
    private static function reconcile(
        array $assertions,
        array $vars,
        array $references,
        bool $insideLoop,
        StatementsAnalyzer $source,
        Codebase $codebase,
    ): array {
        // A property path Psalm forgot gets its declared type back first, so the assertion meets the type the code had.
        $forgotten = \array_map(static fn(array $_assertions): array => [[new Any()]], \array_diff_key($assertions, $vars));
        if ($forgotten !== []) {
            $vars += \array_intersect_key(self::run($forgotten, $vars, $references, $insideLoop, $source)[0], $forgotten);
            $assertions = \array_intersect_key($assertions, $vars);
        }

        [$reconciled, $references] = self::run($assertions, $vars, $references, $insideLoop, $source);

        foreach (\array_keys($assertions) as $varId) {
            $narrowed = $reconciled[$varId] ?? null;

            // An impossible assertion keeps the type the code had: `never` would only breed noise.
            $reconciled[$varId] = self::impossible($narrowed, $vars[$varId], $codebase)
                ? $vars[$varId]
                : $narrowed->setFromDocblock(true);
        }

        return [$reconciled, $references];
    }

    /**
     * @param array<string, list<list<Assertion>>> $assertions
     * @param array<string, Union> $vars
     * @param array<string, string> $references
     *
     * @return array{array<string, Union>, array<string, string>}
     */
    private static function run(array $assertions, array $vars, array $references, bool $insideLoop, StatementsAnalyzer $source): array
    {
        $changedVarIds = [];

        return Reconciler::reconcileKeyedTypes($assertions, [], $vars, $references, $changedVarIds, [], $source, [], $insideLoop);
    }

    /**
     * @return string|null the id Psalm keeps the argument under in scope: `$name`, or a property path such as
     *                     `$a->b` / `$this->prop`; null for anything else (spread, named, expression, `$a?->b`)
     *
     * @psalm-mutation-free
     */
    private static function variableId(mixed $arg): ?string
    {
        return $arg instanceof Arg && !$arg->unpack && !$arg->name instanceof Identifier
            ? self::pathId($arg->value)
            : null;
    }

    /** @psalm-mutation-free */
    private static function pathId(Expr $expr): ?string
    {
        if ($expr instanceof Variable) {
            return \is_string($expr->name) ? '$' . $expr->name : null;
        }

        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            $base = self::pathId($expr->var);

            return $base === null ? null : $base . '->' . $expr->name->name;
        }

        return null;
    }
}
