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

/**
 * Narrows the subject of a statement-level `expect($var)->...` chain for the code after it, the way
 * PHPUnit's `assertNotNull()` does through `@psalm-assert`: after `expect($ad)->not->toBeNull();` `$ad`
 * is no longer nullable. Pest's assertions are dynamic (`Expectation::__call()`), so no docblock can say
 * so; a handler walks the chain from `expect()` outward instead:
 *
 *  - the root must be Pest's global `expect()`: any other function returning an `Expectation` may assert
 *    on something else than its argument (`assertThat($x)` wrapping `expect($x ?? 'fallback')`);
 *  - `->not` / `->not()` negates the next assertion only (Pest hands back the original expectation);
 *  - an assertion in {@see self::ASSERTIONS} (or `toBeInstanceOf()`) narrows the current subject, any
 *    other `to*` assertion returns the same expectation, so the walk goes on;
 *  - `->and($var)` makes `$var` the subject; every other member (`each`, `json()`, `and()` of anything
 *    else, higher-order access) ends the walk, as does a first-class callable (`->toBeNull(...)`, which
 *    only builds a Closure): what they assert on is no longer the variable.
 *
 * Members are compared lowercased, as PHP dispatches them case-insensitively. A statement that writes a
 * variable anywhere in the chain (`->and($value = ...)`) is left alone: the assertion ran on a value the
 * analysis cannot attribute to the variable any more.
 *
 * The narrowing is Psalm's own reconciler with `IsType` / `IsNotType`, so it intersects with the known
 * type exactly as `is_string()` would. It gets no code location, because tests routinely assert what the
 * type already says, and the result is marked as coming from a docblock, so a later contradicting `if`
 * is a DocblockTypeContradiction. An assertion that cannot hold (`expect($string)->toBeInt()`) narrows
 * nothing: the test fails at runtime, there is no type to continue with. Only plain variables are
 * narrowed, which is what tests overwhelmingly pass to `expect()`.
 */
final class ExpectNarrowingHandler implements AfterStatementAnalysisInterface
{
    /**
     * Lowercased Pest assertion => Psalm type the subject has when it passes. Each mirrors the PHP
     * predicate Pest runs (`toBeTrue()` is `=== true`, `toBeFloat()` is `is_float()`).
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
            self::apply(self::collect($subject, \array_reverse($steps), $source), $event->getContext(), $source);
        }

        return null;
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
            static fn(Node $node): bool => $node instanceof Expr\Assign
                || $node instanceof Expr\AssignOp
                || $node instanceof Expr\AssignRef
                || $node instanceof Expr\PreInc
                || $node instanceof Expr\PostInc
                || $node instanceof Expr\PreDec
                || $node instanceof Expr\PostDec,
        ) instanceof Node;
    }

    /**
     * The `$var` handed to Pest's global `expect()`, as its id in scope (`$name`).
     *
     * The call must reach the global function `expect` the way Psalm resolves a function name (`\expect()`,
     * `use function expect as e`, an unqualified call in a namespace that declares no `expect()` of its
     * own) and return Pest's `Expectation`: a helper returning `expect(...)` has the same type.
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

        $type = $source->getNodeTypeProvider()->getType($call);
        $returned = $type?->isSingle() ? $type->getSingleAtomic() : null;
        if (!$returned instanceof TNamedObject || \strcasecmp($returned->value, PestApi::EXPECTATION) !== 0) {
            return null;
        }

        return self::variableId($call->args[0] ?? null);
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
            if (!$step->name instanceof Identifier) {
                break;
            }

            $member = $step->name->toLowerString();

            if ($member === 'not' && (!$step instanceof MethodCall || $step->args === [])) {
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

    private static function assertedType(string $assertion, MethodCall $call, StatementsAnalyzer $source): ?Atomic
    {
        if ($assertion === 'tobeinstanceof') {
            return self::instanceOfType($call, $source);
        }

        $type = self::ASSERTIONS[$assertion] ?? null;

        return $type === null ? null : Type::parseString($type)->getSingleAtomic();
    }

    /**
     * `toBeInstanceOf(Foo::class)` or `toBeInstanceOf(Foo::class, 'message')`: the class comes from the
     * first argument's inferred literal class-string, so imports, aliases and `\Foo::class` need no name
     * resolution here. A dynamic class name narrows nothing, and neither do spread arguments (they could
     * fill any position) or a named `class:` (declined for simplicity).
     */
    private static function instanceOfType(MethodCall $call, StatementsAnalyzer $source): ?Atomic
    {
        // Pest's signature is `toBeInstanceOf(string $class, string $message = '')`.
        $args = $call->getArgs();
        foreach ($args as $i => $arg) {
            $namedOtherThanMessage = $arg->name !== null && ($i === 0 || $arg->name->toLowerString() !== 'message');
            if ($arg->unpack || $i > 1 || $namedOtherThanMessage) {
                return null;
            }
        }

        $type = isset($args[0]) ? $source->getNodeTypeProvider()->getType($args[0]->value) : null;
        $class = $type?->isSingle() ? $type->getSingleAtomic() : null;

        return $class instanceof TLiteralClassString ? new TNamedObject($class->value) : null;
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
}
