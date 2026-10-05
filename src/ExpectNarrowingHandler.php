<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
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
 * Narrows the subject of a statement-level `expect($var)->...` chain for the code after it, the way
 * `PHPUnit`'s `assertNotNull()` does through `@psalm-assert`: after `expect($ad)->not->toBeNull();`
 * `$ad` is no longer nullable, so `return $ad;` stops reporting `NullableReturnStatement`.
 *
 * Pest's assertions are dynamic (`Expectation::__call()` forwards to `Mixins\Expectation`), so no
 * docblock can carry the assertion; and what it applies to depends on the chain, so a handler walks it
 * from `expect()` outward:
 *
 *  - the root must be Pest's global `expect()`: any other function that happens to return an
 *    `Expectation` may assert on something else than its argument (`assertThat($x)` wrapping
 *    `expect($x ?? 'fallback')`);
 *  - `->not` / `->not()` negates the next assertion only (Pest's `OppositeExpectation` hands back the
 *    original expectation afterwards);
 *  - an assertion in {@see self::ASSERTIONS} (or `toBeInstanceOf()`) narrows the current subject;
 *  - any other `to*` assertion returns the same expectation, so the walk goes on;
 *  - `->and($var)` makes `$var` the subject, `and()` of anything else ends the walk, and so does every
 *    other member (`each`, `json()`, `sequence()`, higher-order access): the value they assert on is
 *    no longer the variable;
 *  - a first-class callable (`->toBeNull(...)`) only builds a Closure and asserts nothing, so it ends
 *    the walk too.
 *
 * PHP dispatches method names case-insensitively, and Pest's `__get()` resolves `->NOT` through
 * `method_exists()`, so members are compared lowercased. A statement that writes to a variable anywhere
 * in the chain (`->and($value = ...)`) is left alone: the assertion ran on a value the analysis cannot
 * attribute to the variable any more.
 *
 * Narrowing goes through Psalm's own reconciler with `IsType` / `IsNotType` assertions, so it
 * intersects with the known type exactly as `is_string()` would, and it is given no code location:
 * tests routinely assert what the type already says, which must stay silent. As with Psalm's
 * own assertion application, the result is marked as coming from a docblock, so a later contradicting
 * `if` is a DocblockTypeContradiction rather than a claim about the code. An assertion that cannot
 * hold (`expect($string)->toBeInt()`) narrows nothing: the test fails at runtime, there is no type to
 * continue with.
 *
 * Only plain variables are narrowed: that is what the reconciler handles identically on every
 * Psalm major, and what the tests overwhelmingly pass to `expect()`.
 */
final class ExpectNarrowingHandler implements AfterStatementAnalysisInterface
{
    /**
     * Lowercased Pest assertion => Psalm type the subject has when it passes. One line per assertion; each
     * mirrors the PHP predicate Pest runs (`toBeTrue()` is `=== true`, `toBeFloat()` is `is_float()`).
     */
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

    /** Takes its type from the argument, so it cannot live in {@see self::ASSERTIONS}. */
    private const INSTANCE_OF = 'tobeinstanceof';

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();
        $source = $event->getStatementsSource();
        if (!$stmt instanceof Expression || !$source instanceof StatementsAnalyzer) {
            return null;
        }

        $chain = self::chain($stmt->expr);
        if ($chain === null || self::writesVariable($stmt->expr)) {
            return null;
        }

        [$root, $steps] = $chain;

        $codebase = $event->getCodebase();
        $subject = self::expectSubject($root, $source, $codebase);
        if ($subject === null) {
            return null;
        }

        self::apply(self::collect($subject, $steps, $source), $event->getContext(), $source);

        return null;
    }

    /**
     * Splits `expect($x)->a->b()` into the `expect($x)` call and the members applied to it, in call order.
     *
     * @return array{FuncCall, list<MethodCall|PropertyFetch>}|null null when the expression is not a
     *                                                              member chain on a function call
     *
     * @psalm-mutation-free
     */
    private static function chain(Expr $expr): ?array
    {
        $steps = [];
        while ($expr instanceof MethodCall || $expr instanceof PropertyFetch) {
            $steps[] = $expr;
            $expr = $expr->var;
        }

        if (!$expr instanceof FuncCall || $steps === []) {
            return null;
        }

        return [$expr, \array_reverse($steps)];
    }

    /**
     * Whether the expression assigns or increments anything, anywhere (arguments, nested closures and
     * the root call included). Deliberately coarse: it also covers writes to variables the chain never
     * names, which cost nothing to skip.
     */
    private static function writesVariable(Expr $expr): bool
    {
        return (new NodeFinder())->findFirst(
            $expr,
            static fn(\PhpParser\Node $node): bool => $node instanceof Assign
                || $node instanceof AssignOp
                || $node instanceof AssignRef
                || $node instanceof PreInc
                || $node instanceof PostInc
                || $node instanceof PreDec
                || $node instanceof PostDec,
        ) instanceof \PhpParser\Node;
    }

    /**
     * The `$var` handed to Pest's global `expect()`.
     *
     * The call must resolve to the global function `expect` the way Psalm resolves it (`\expect()`,
     * `use function expect as e`, an unqualified call in a namespace that defines no `expect()` of its
     * own), and that function must be the one that returns Pest's `Expectation`. The inferred type of
     * the call is not enough: a helper returning `expect(...)` has the same type.
     *
     * @return string|null the variable's id in scope (`$name`)
     */
    private static function expectSubject(FuncCall $call, StatementsAnalyzer $source, Codebase $codebase): ?string
    {
        $name = $call->name;
        if ($call->isFirstClassCallable() || !$name instanceof Name) {
            return null;
        }

        // Fully qualified or imported names resolve to themselves (`expect`, but not `Foo\expect` or
        // `use function Foo\expect`).
        [$function, $shadow] = NameResolution::functionName($name);
        if ($function !== 'expect') {
            return null;
        }

        // Psalm throws for a function it never scanned.
        try {
            // Unqualified in a namespace: Psalm prefers the namespaced function when there is one.
            if ($shadow !== null) {
                $codebase->functions->getStorage($source, $shadow);

                return null;
            }
        } catch (\UnexpectedValueException) {
            // No such function in the namespace: the global one is reached.
        }

        try {
            $storage = $codebase->functions->getStorage($source, 'expect');
        } catch (\UnexpectedValueException) {
            return null;
        }

        if (!self::isExpectation($storage->return_type)) {
            return null;
        }

        return self::variableId($call->args[0] ?? null);
    }

    /** @psalm-mutation-free */
    private static function isExpectation(?Union $type): bool
    {
        if (!$type instanceof Union || !$type->isSingle()) {
            return false;
        }

        $atomic = $type->getSingleAtomic();

        return $atomic instanceof TNamedObject && \strcasecmp($atomic->value, PestApi::EXPECTATION) === 0;
    }

    /**
     * Walks the chain from `expect()` outward and gathers, per variable, the reconciler assertions
     * its assertions imply.
     *
     * @param list<MethodCall|PropertyFetch> $steps
     *
     * @return array<string, list<list<Assertion>>>
     */
    private static function collect(string $subject, array $steps, StatementsAnalyzer $source): array
    {
        $assertions = [];
        $negated = false;

        foreach ($steps as $step) {
            // `->toBeNull(...)` builds a Closure and runs nothing; its name and arguments mean nothing.
            if (!$step->name instanceof Identifier || ($step instanceof MethodCall && $step->isFirstClassCallable())) {
                break;
            }

            $member = $step->name->toLowerString();

            if ($member === 'not') {
                if ($step instanceof MethodCall && $step->args !== []) {
                    break;
                }

                $negated = !$negated;

                continue;
            }

            if (!$step instanceof MethodCall) {
                break;
            }

            if ($member === 'and') {
                $next = \count($step->args) === 1 ? self::variableId($step->args[0]) : null;
                if ($next === null) {
                    break;
                }

                $subject = $next;
                $negated = false;

                continue;
            }

            if (!\str_starts_with($member, 'to')) {
                break;
            }

            $type = self::assertedType($member, $step, $source);
            if ($type instanceof \Psalm\Type\Atomic) {
                $assertions[$subject][] = [$negated ? new IsNotType($type) : new IsType($type)];
            }

            $negated = false;
        }

        return $assertions;
    }

    private static function assertedType(string $assertion, MethodCall $call, StatementsAnalyzer $source): ?Atomic
    {
        if ($assertion === self::INSTANCE_OF) {
            return self::instanceOfType($call, $source);
        }

        $type = self::ASSERTIONS[$assertion] ?? null;

        return $type === null ? null : self::singleAtomic(Type::parseString($type));
    }

    /**
     * `toBeInstanceOf(Foo::class)` or `toBeInstanceOf(Foo::class, 'message')`: the class comes from the
     * first argument's inferred literal class-string, so imports, aliases and `\Foo::class` need no name
     * resolution here. A dynamic class name narrows nothing, and neither do spread or named arguments:
     * the positions they fill are unknown (named `class:` is declined for simplicity).
     */
    private static function instanceOfType(MethodCall $call, StatementsAnalyzer $source): ?Atomic
    {
        $arg = $call->args[0] ?? null;
        if (!$arg instanceof Arg || $arg->unpack || $arg->name instanceof Identifier) {
            return null;
        }

        // Pest's signature is `toBeInstanceOf(string $class, string $message = '')`.
        $message = $call->args[1] ?? null;
        if (\count($call->args) > 2 || ($message !== null && !self::isMessageArgument($message))) {
            return null;
        }

        $type = $source->getNodeTypeProvider()->getType($arg->value);
        $class = $type instanceof \Psalm\Type\Union ? self::singleAtomic($type) : null;

        return $class instanceof TLiteralClassString ? new TNamedObject($class->value) : null;
    }

    /** Positional, or named `message:`; a spread could fill any position. */
    private static function isMessageArgument(mixed $arg): bool
    {
        return $arg instanceof Arg
            && !$arg->unpack
            && (!$arg->name instanceof Identifier || $arg->name->toLowerString() === 'message');
    }

    /**
     * @param array<string, list<list<Assertion>>> $assertions
     */
    private static function apply(array $assertions, Context $context, StatementsAnalyzer $source): void
    {
        // The reconciler would conjure a type for an unknown variable; only narrow what is in scope.
        $assertions = \array_intersect_key($assertions, $context->vars_in_scope);
        if ($assertions === []) {
            return;
        }

        $changedVarIds = [];
        [$reconciled, $references] = Reconciler::reconcileKeyedTypes(
            $assertions,
            [],
            $context->vars_in_scope,
            $context->references_in_scope,
            $changedVarIds,
            [],
            $source,
            [],
            $context->inside_loop,
        );

        foreach (\array_keys($assertions) as $varId) {
            $narrowed = $reconciled[$varId] ?? null;

            // An impossible assertion keeps the type the code had: `never` would only breed noise.
            $reconciled[$varId] = $narrowed === null || $narrowed->isNever() || $narrowed->failed_reconciliation
                ? $context->vars_in_scope[$varId]
                : $narrowed->setFromDocblock(true);
        }

        $context->vars_in_scope = $reconciled;
        $context->references_in_scope = $references;
    }

    /**
     * @return string|null `$name` for a plain `$name` argument; null for anything else, including
     *                     spread, named and by-expression arguments
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

    /** @psalm-pure */
    private static function singleAtomic(Union $type): ?Atomic
    {
        return $type->isSingle() ? $type->getSingleAtomic() : null;
    }
}
