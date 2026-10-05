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
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\IsNotType;
use Psalm\Storage\Assertion\IsType;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
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
 * cannot hold (`expect($string)->toBeInt()`) narrows nothing: the test fails at runtime. Only plain variables
 * are narrowed.
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
    ];

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();
        $source = $event->getStatementsSource();
        if (!$stmt instanceof Expression || !$source instanceof StatementsAnalyzer) {
            return null;
        }

        // `expect($x)->a->b()` => the `expect($x)` call and the members applied to it, in call order.
        $steps = [];
        $root = $stmt->expr;
        while ($root instanceof MethodCall || $root instanceof PropertyFetch) {
            $steps[] = $root;
            $root = $root->var;
        }

        if (!$root instanceof FuncCall || $steps === [] || self::writesVariable($stmt->expr)) {
            return null;
        }

        $subject = self::expectSubject($root, $source, $event->getCodebase());
        if ($subject !== null) {
            $context = $event->getContext();
            $assertions = \array_intersect_key(self::collect($subject, \array_reverse($steps), $source), $context->vars_in_scope);
            [$context->vars_in_scope, $context->references_in_scope] = self::reconcile(
                $assertions,
                $context->vars_in_scope,
                $context->references_in_scope,
                $context->inside_loop,
                $source,
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
     * The `$var` handed to Pest's global `expect()`, as its id in scope (`$name`). The call must reach the global
     * function the way Psalm resolves a name (`\expect()`, `use function expect as e`, an unqualified call in a
     * namespace without its own `expect()`) and return Pest's `Expectation`.
     */
    private static function expectSubject(FuncCall $call, StatementsAnalyzer $source, Codebase $codebase): ?string
    {
        $name = $call->name;
        if (!$name instanceof Name) {
            return null;
        }

        $functions = $codebase->functions;
        $id = \strtolower($functions->getFullyQualifiedFunctionNameFromString($name->toCodeString(), $source));
        if ($id !== 'expect' && ($name->toLowerString() !== 'expect' || $functions->functionExists($source, $id))) {
            return null;
        }

        $returned = self::atomic($source->getNodeTypeProvider()->getType($call));
        if (!$returned instanceof TNamedObject || \strcasecmp($returned->value, PestApi::EXPECTATION) !== 0) {
            return null;
        }

        return self::variableId($call->args[0] ?? null);
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

    /** `$type` once the assertion passed (or, `$negated`, failed). */
    public static function narrow(Union $type, Atomic $asserted, bool $negated, StatementsAnalyzer $source): Union
    {
        $assertion = $negated ? new IsNotType($asserted) : new IsType($asserted);

        return self::reconcile(['$v' => [[$assertion]]], ['$v' => $type], [], false, $source)[0]['$v'];
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
    ): array {
        $changedVarIds = [];
        [$reconciled, $references] = Reconciler::reconcileKeyedTypes(
            $assertions,
            [],
            $vars,
            $references,
            $changedVarIds,
            [],
            $source,
            [],
            $insideLoop,
        );

        foreach (\array_keys($assertions) as $varId) {
            $narrowed = $reconciled[$varId] ?? null;

            // An impossible assertion keeps the type the code had: `never` would only breed noise.
            $reconciled[$varId] = $narrowed === null || $narrowed->isNever() || $narrowed->failed_reconciliation
                ? $vars[$varId]
                : $narrowed->setFromDocblock(true);
        }

        return [$reconciled, $references];
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
