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
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\TraitAnalyzer;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\IsNotType;
use Psalm\Storage\Assertion\IsType;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;

/**
 * Narrows the subject of an `expect($var)->...` chain as PHPUnit's `assertNotNull()` does through `@psalm-assert`:
 * after `expect($ad)->not->toBeNull()` `$ad` is no longer nullable, also for the arguments of the later calls of
 * the same chain (`->and($ad->slug)`). Pest's assertions are dynamic (`Expectation::__call()`), so the handler runs
 * on every assertion call and reads the chain below it, down to Pest's `expect()`. Psalm analyses a call's receiver
 * before its arguments, so the narrowed context is the one the following steps are analysed in.
 *
 *  - the root must be Pest's global `expect()`: another function returning an `Expectation` may assert on
 *    something else than its argument (`assertThat($x)` wrapping `expect($x ?? 'fallback')`);
 *  - the subject is the plain variable of the nearest `->and($var)` below the assertion, else the argument of
 *    `expect()`; any other subject (`expect(1)`, `->and($a->b)`) narrows nothing;
 *  - `->not` / `->not()` directly before an assertion negates it (Pest hands back the original expectation);
 *  - an assertion in {@see self::ASSERTIONS} (or `toBeInstanceOf()`) narrows the subject, any other `to*` returns
 *    the same expectation;
 *  - every other member (`each`, `json()`, higher-order access) or a first-class callable (`->toBeNull(...)`,
 *    which only builds a Closure) below the assertion ends the chain: what it asserts on is no longer the variable.
 *
 * Members are compared lowercased, as PHP dispatches them case-insensitively. An assertion whose chain writes a
 * variable anywhere (`->and($value = ...)`) is left alone: the assertion ran on a value the analysis cannot
 * attribute to the variable any more.
 *
 * The narrowing is Psalm's reconciler with `IsType` / `IsNotType`, so it intersects with the known type as
 * `is_string()` would. It gets no code location (tests routinely assert what the type already says) and is marked
 * as coming from a docblock, so a later contradicting `if` is a DocblockTypeContradiction. An assertion that
 * cannot hold (`expect($string)->toBeInt()`) narrows nothing: the test fails at runtime ({@see ExpectationHandler}
 * reports it). Only plain variables are narrowed, and not those a closure of the enclosing test binds by
 * reference ({@see ByRefCaptures}): they change behind the narrowing's back.
 */
final class ExpectNarrowingHandler implements AfterExpressionAnalysisInterface
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

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        $source = $event->getStatementsSource();
        if (!$call instanceof MethodCall || !$source instanceof StatementsAnalyzer || $call->isFirstClassCallable()) {
            return null;
        }

        $context = $event->getContext();
        $assertions = \array_intersect_key(self::collect($call, $source, $event->getCodebase()), $context->vars_in_scope);
        $assertions = \array_filter($assertions, static fn(string $id): bool => !ByRefCaptures::isCaptured($id, $source), \ARRAY_FILTER_USE_KEY);
        if ($assertions !== []) {
            [$context->vars_in_scope, $context->references_in_scope] = self::reconcile(
                $assertions,
                $context->vars_in_scope,
                $context->references_in_scope,
                $context->inside_loop,
                $source,
                $event->getCodebase(),
                $context,
            );
        }

        return null;
    }

    /** Deliberately coarse: any assignment or increment anywhere (arguments, closures, the root call). */
    private static function writesVariable(Expr $expr): bool
    {
        return (new NodeFinder())->findFirst(
            $expr,
            static fn(Node $node): bool => $node instanceof Expr\Assign || $node instanceof Expr\AssignOp
                || $node instanceof Expr\AssignRef || $node instanceof Expr\PreInc || $node instanceof Expr\PostInc
                || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec,
        ) instanceof Node;
    }

    /**
     * Whether `$call` is Pest's global `expect()`: it must reach the global function the way Psalm resolves a name
     * (`\expect()`, `use function expect as e`, an unqualified call in a namespace without its own `expect()`) and
     * return Pest's `Expectation`.
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
     * Walks the chain below `$call` down to `expect()`: per variable, the reconciler assertions `$call` implies.
     *
     * @return array<string, list<list<Assertion>>>
     */
    private static function collect(MethodCall $call, StatementsAnalyzer $source, Codebase $codebase): array
    {
        $type = $call->name instanceof Identifier ? self::assertedType($call->name->toLowerString(), $call, $source) : null;
        if (!$type instanceof Atomic) {
            return [];
        }

        $subject = null;
        $subjectKnown = false;
        $negated = false;
        $negating = true;
        $node = $call->var;

        while ($node instanceof MethodCall || $node instanceof PropertyFetch) {
            $member = $node->name instanceof Identifier ? $node->name->toLowerString() : '';

            if ($member === 'not' && (!$node instanceof MethodCall || $node->args === [])) {
                $negated = $negating ? !$negated : $negated;
                $node = $node->var;

                continue;
            }

            $negating = false;
            if (!$node instanceof MethodCall || $node->isFirstClassCallable()) {
                return [];
            }

            if ($member === 'and') {
                if (!$subjectKnown) {
                    $subjectKnown = true;
                    $subject = \count($node->args) === 1 ? self::variableId($node->args[0]) : null;
                }
            } elseif (!\str_starts_with($member, 'to')) {
                return [];
            }

            $node = $node->var;
        }

        if (!$node instanceof FuncCall || !self::isPestExpect($node, $source, $codebase) || self::writesVariable($call)) {
            return [];
        }

        $subject = $subjectKnown ? $subject : self::variableId($node->args[0] ?? null);

        return $subject === null ? [] : [$subject => [[$negated ? new IsNotType($type) : new IsType($type)]]];
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
        Context $context,
    ): array {
        $changedVarIds = [];
        [$reconciled, $references] = self::run($assertions, $vars, $references, $insideLoop, $source, $changedVarIds);

        foreach (\array_keys($assertions) as $varId) {
            $narrowed = $reconciled[$varId] ?? null;

            // An impossible assertion keeps the type the code had: `never` would only breed noise.
            if (self::impossible($narrowed, $vars[$varId], $codebase)) {
                $reconciled[$varId] = $vars[$varId];

                continue;
            }

            $reconciled[$varId] = $narrowed->setFromDocblock(true);
            if (isset($changedVarIds[$varId]) && $vars[$varId]->hasMixed()) {
                self::forgetMixedAssignment($varId, $source, $codebase, $context);
            }
        }

        return [$reconciled, $references];
    }

    /**
     * A mixed variable the assertion gave a type is no longer an untyped assignment, as after `@psalm-assert`
     * (Psalm's `CallAnalyzer::applyAssertionsToContext()`): the MixedAssignment of its first appearance goes away.
     */
    private static function forgetMixedAssignment(string $varId, StatementsAnalyzer $source, Codebase $codebase, Context $context): void
    {
        $firstAppearance = $source->getFirstAppearance($varId);
        if (!$firstAppearance instanceof CodeLocation) {
            return;
        }

        $parent = $source->getSource();
        if (
            !$context->collect_initializations && !$context->collect_mutations
            && $source->getFilePath() === $source->getRootFilePath()
            && (!$parent instanceof FunctionLikeAnalyzer || !$parent->getSource() instanceof TraitAnalyzer)
        ) {
            $codebase->analyzer->decrementMixedCount($source->getFilePath());
        }

        IssueBuffer::remove($source->getFilePath(), 'MixedAssignment', $firstAppearance->raw_file_start);
    }

    /**
     * @param array<string, list<list<Assertion>>> $assertions
     * @param array<string, Union> $vars
     * @param array<string, string> $references
     * @param array<string, bool> $changedVarIds
     *
     * @return array{array<string, Union>, array<string, string>}
     */
    private static function run(array $assertions, array $vars, array $references, bool $insideLoop, StatementsAnalyzer $source, array &$changedVarIds = []): array
    {
        return Reconciler::reconcileKeyedTypes($assertions, [], $vars, $references, $changedVarIds, [], $source, [], $insideLoop);
    }

    /**
     * @return string|null `$name` for a plain `$name` argument; null for anything else (spread, named, expression)
     *
     * @psalm-mutation-free
     */
    private static function variableId(mixed $arg): ?string
    {
        if (!$arg instanceof Arg || $arg->unpack || $arg->name instanceof \PhpParser\Node\Identifier) {
            return null;
        }

        $value = $arg->value;

        return $value instanceof Variable && \is_string($value->name) ? '$' . $value->name : null;
    }
}
