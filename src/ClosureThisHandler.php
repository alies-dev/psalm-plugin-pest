<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\FunctionParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Binds `$this` in the closures Pest runs on the TestCase: `test()` / `it()` / `beforeEach()` /
 * `afterEach()`, `TestCall::after()` closures, the hooks of a `uses()` / `pest()` chain, the
 * `->and()` of a higher-order `it('x')->expect(1)->and(fn () => $this->x)` chain, and the closure
 * rows of a `with([...])` / `describe()->with([...])` / `dataset('x', [...])` array literal
 * ({@see DatasetRows}). A closure that is a whole dataset (`with(fn () => [...])`) is not bound:
 * Pest calls it from its static data provider.
 *
 * Pest tags them `@param-closure-this TestCall`, whose `@mixin` union Psalm does not honor; the
 * dataset and `UsesCall` closures carry no tag. A params provider answers per call site (no shared
 * state), and functions are registered only when the scanned one carries Pest's tag. It sees the
 * arguments but not the receiver, so a `UsesCall` hook is bound to the classes its own chain names
 * (`uses(A::class)->in('Feature')->beforeEach(...)`), and `Expectation::and()` only when its chain
 * starts at `it()` / `test()` (elsewhere its closure is just a value): {@see self::beforeExpressionAnalysis()}
 * stashes the receiver on the first argument.
 */
final class ClosureThisHandler implements AfterCodebasePopulatedInterface, BeforeExpressionAnalysisInterface, MethodParamsProviderInterface
{
    private const FUNCTIONS = ['test', 'it', 'beforeeach', 'aftereach'];

    /** Pest methods whose first parameter is a closure that runs on the TestCase. */
    private const METHODS = [
        PestApi::TEST_CALL => ['after'],
        PestApi::BEFORE_EACH_CALL => ['after'],
        PestApi::USES_CALL => ['beforeeach', 'aftereach'],
        PestApi::EXPECTATION => ['and'],
    ];

    private const RECEIVER = 'pest-receiver';

    /** The method name as written at the call site (the providers only see it lowercased). */
    private const WRITTEN = 'pest-written-method';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $functions = $event->getCodebase()->functions;
        foreach (self::FUNCTIONS as $functionId) {
            if (!$functions->hasStubbedFunction($functionId)) {
                continue;
            }

            $params = $functions->getStorage(null, $functionId)->params;
            foreach ($params as $offset => $param) {
                if ($param->closure_this_type?->getId() === PestApi::TEST_CALL) {
                    $functions->params_provider->registerClosure(
                        $functionId,
                        static function (FunctionParamsProviderEvent $event) use ($params, $offset): array {
                            $source = $event->getStatementsSource();
                            $codebase = $source->getCodebase();

                            $file = $source->getFilePath();

                            return self::bind($codebase, $params, $offset, BoundTestCase::thisType($codebase, $file), BoundTestCase::traits($codebase, $file));
                        },
                    );
                    break;
                }
            }
        }

        self::bindDatasetFunction($event->getCodebase());
    }

    /** @psalm-pure */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return \array_keys(self::METHODS);
    }

    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $class = $event->getFqClasslikeName();
        $method = $event->getMethodNameLowercase();
        $source = $event->getStatementsSource();
        $args = $event->getCallArgs() ?? [];
        $arg = $args[0] ?? null;
        if (!$source instanceof \Psalm\StatementsSource || $arg === null) {
            return null;
        }

        $codebase = $source->getCodebase();
        if ($class === PestApi::TEST_CALL && $method === 'with') {
            $file = $source->getFilePath();
            $type = self::prepare($codebase, BoundTestCase::thisType($codebase, $file), BoundTestCase::traits($codebase, $file));
            if ($type instanceof Union) {
                self::bindRows($type, self::values($args), self::testParameterTypes($arg->getAttribute(self::RECEIVER)));
            }

            return null;
        }

        if (!\in_array($method, self::METHODS[$class] ?? [], true)) {
            return null;
        }

        $params = BoundTestCase::storage($codebase, $class)?->methods[$method]->params ?? null;
        if ($params === null) {
            return null;
        }

        if ($class === PestApi::EXPECTATION && (!self::calledAs($arg, 'and') || !self::startsAtTest($arg->getAttribute(self::RECEIVER)))) {
            return null;
        }

        $chain = $class === PestApi::USES_CALL ? self::chainClasses($arg->getAttribute(self::RECEIVER)) : [];

        $type = BoundTestCase::thisTypeOf($codebase, $chain);
        $file = $source->getFilePath();
        $traits = BoundTestCase::traitsOf($codebase, $chain);
        if (!$type instanceof Union) {
            $type = BoundTestCase::thisType($codebase, $file);
            $traits = BoundTestCase::traits($codebase, $file);
        }

        return self::bind($codebase, $params, 0, $type, $traits);
    }

    /**
     * `describe(...)->with([...])` replays `with()` on the `TestCall` of every test in the block. Only
     * the rows are bound: the params come back unchanged, as a closure that is the whole dataset is
     * called unbound. The tests of the block are not followed, so every closure row is bound.
     *
     * @param array<int, FunctionLikeParameter> $params the params of `TestCall::with()`
     * @param list<Arg> $args
     * @return array<int, FunctionLikeParameter>
     */
    public static function bindWith(Codebase $codebase, string $file, array $params, array $args): array
    {
        $type = self::prepare($codebase, BoundTestCase::thisType($codebase, $file), BoundTestCase::traits($codebase, $file));
        if ($type instanceof Union) {
            self::bindRows($type, self::values($args));
        }

        return $params;
    }

    /**
     * @param list<Arg> $args
     * @return list<Expr>
     */
    private static function values(array $args): array
    {
        return \array_map(static fn(Arg $arg): Expr => $arg->value, $args);
    }

    /**
     * `dataset('x', [...])` carries no closure tag, so its rows are bound by this call-site provider alone.
     */
    private static function bindDatasetFunction(Codebase $codebase): void
    {
        $functions = $codebase->functions;
        if (!$functions->hasStubbedFunction('dataset')) {
            return;
        }

        $storage = $functions->getStorage(null, 'dataset');
        $params = $storage->params;
        // Pest's `src/Functions.php` sits next to `src/PendingCalls/`; another autoloaded `dataset()` is not Pest's.
        $testCall = BoundTestCase::storage($codebase, PestApi::TEST_CALL)?->location?->file_path;
        if ($testCall === null
            || $storage->location?->file_path !== \dirname($testCall, 2) . \DIRECTORY_SEPARATOR . 'Functions.php'
            || \array_map(static fn(FunctionLikeParameter $param): string => $param->name, $params) !== ['name', 'dataset']
        ) {
            return;
        }

        $functions->params_provider->registerClosure(
            'dataset',
            static function (FunctionParamsProviderEvent $event) use ($params): array {
                $source = $event->getStatementsSource();
                $codebase = $source->getCodebase();
                $file = $source->getFilePath();
                $type = BoundTestCase::thisType($codebase, $file);
                $dataset = null;
                foreach ($event->getCallArgs() as $position => $arg) {
                    if ($arg->name?->name === 'dataset' || ($arg->name === null && $position === 1)) {
                        $dataset = $arg;
                    }
                }

                // Pest binds a shared dataset (`Datasets.php`, `Datasets/`, `Pest.php`) to the test that consumes it, not to its own file's fallback TestCase.
                $shared = TestCaseResolver::declaresSharedDatasets(BoundTestCase::testsDir($codebase), $file);
                if ($type instanceof Union && (!$shared || $type->getId() !== TestCaseResolver::DEFAULT_TEST_CASE) && $dataset instanceof Arg) {
                    $type = self::prepare($codebase, $type, BoundTestCase::traits($codebase, $file));
                    if ($type instanceof Union) {
                        self::bindRows($type, [$dataset->value]);
                    }
                }

                return $params;
            },
        );
    }

    /**
     * Psalm reads a closure's bound `$this` from this node attribute, which it sets only for a closure
     * that is itself an argument. Rows inside an array literal are never arguments, so they get it here.
     *
     * @param list<Expr> $datasets
     * @param array<string, string>|null $parameterTypes see {@see DatasetRows::closures()}
     */
    private static function bindRows(Union $type, array $datasets, ?array $parameterTypes = null): void
    {
        foreach (DatasetRows::closures($datasets, $parameterTypes) as $closure) {
            $closure->setAttribute('psalm-closure-this-type', $type);
        }
    }

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        if ($call instanceof MethodCall && isset($call->args[0])) {
            $call->args[0]->setAttribute(self::RECEIVER, $call->var);
            $call->args[0]->setAttribute(self::WRITTEN, $call->name instanceof Identifier ? $call->name->name : null);
        }

        return null;
    }

    /**
     * Whether the call took `$arg` as its first argument under exactly the method name `$name`. Psalm
     * lowercases the name it hands to providers, but Pest looks a higher-order `expect()` / `and()` up
     * case-sensitively (`HigherOrderMessage::hasHigherOrderCallable()`), so `And()` binds nothing.
     */
    public static function calledAs(?Arg $arg, string $name): bool
    {
        return $arg?->getAttribute(self::WRITTEN) === $name;
    }

    /**
     * Whether a chain starts at Pest's `it('x')` / `test('x')`: Pest replays the rest of that chain
     * (method calls and property fetches such as `->not`) through `HigherOrderCallables`, which binds
     * the closure of `expect()` / `and()` to the TestCase. Any other `Expectation::and()` takes the
     * closure as a plain value. A qualified or imported function of another namespace
     * (`\Other\test()`) is told apart by its resolved name.
     */
    private static function startsAtTest(mixed $link): bool
    {
        return self::testCall($link) instanceof FuncCall;
    }

    /** The `it('x')` / `test('x')` call a chain starts at, if any. */
    private static function testCall(mixed $link): ?FuncCall
    {
        while ($link instanceof MethodCall || $link instanceof NullsafeMethodCall || $link instanceof PropertyFetch || $link instanceof NullsafePropertyFetch) {
            $link = $link->var;
        }

        return $link instanceof FuncCall
            && $link->name instanceof Name
            && $link->args !== []
            && \in_array(\strtolower(NameResolution::resolved($link->name) ?? $link->name->toString()), ['it', 'test'], true)
            ? $link
            : null;
    }

    /**
     * The parameter types of the test closure of a `with()` chain, as `Reflection::getFunctionArguments()`
     * spells them, or null when the closure is not in sight or `repeat()` adds an argument to every row.
     *
     * @return array<string, string>|null
     */
    private static function testParameterTypes(mixed $receiver): ?array
    {
        if (!$receiver instanceof Expr) {
            return null;
        }

        $link = $receiver;
        while ($link instanceof MethodCall) {
            if ($link->name instanceof Identifier && $link->name->toLowerString() === 'repeat') {
                return null;
            }

            $link = $link->var;
        }

        $closure = null;
        foreach (self::testCall($receiver)?->args ?? [] as $position => $arg) {
            $name = $arg instanceof Arg ? $arg->name?->name : '';
            if ($arg instanceof Arg && ($name === 'closure' || ($name === null && $position === 1))) {
                $closure = $arg->value;
            }
        }

        if (!$closure instanceof Closure && !$closure instanceof ArrowFunction) {
            return null;
        }

        $types = [];
        foreach ($closure->params as $param) {
            if (!$param->var instanceof Expr\Variable || !\is_string($param->var->name)) {
                return null;
            }

            $types[$param->var->name] = self::typeName($param->type);
        }

        return $types;
    }

    /** `ReflectionNamedType::getName()`, or the names of a union joined by `|`; an untyped parameter is `mixed`. */
    private static function typeName(mixed $type): string
    {
        if ($type instanceof \PhpParser\Node\NullableType) {
            return self::typeName($type->type);
        }

        if ($type instanceof \PhpParser\Node\UnionType) {
            $names = \array_map(self::typeName(...), $type->types);
            $nonNull = \array_values(\array_diff($names, ['null']));

            // PHP reflects `T|null` as the named type `?T`.
            return \count($nonNull) === 1 ? $nonNull[0] : \implode('|', $names);
        }

        return match (true) {
            $type === null => 'mixed',
            $type instanceof Identifier => $type->toLowerString(),
            $type instanceof Name => \ltrim(NameResolution::resolved($type) ?? $type->toString(), '\\'),
            default => '',
        };
    }

    /**
     * The class names a hook's chain passes literally (`uses(A::class, T::class)`, `pest()->extend(A::class)`).
     *
     * @return list<string>
     */
    private static function chainClasses(mixed $link): array
    {
        $classes = [];
        for (; $link instanceof MethodCall || $link instanceof FuncCall; $link = $link instanceof MethodCall ? $link->var : null) {
            foreach ($link->args as $arg) {
                $value = $arg instanceof Arg ? $arg->value : null;
                if ($value instanceof ClassConstFetch
                    && $value->class instanceof Name
                    && $value->name instanceof Identifier
                    && $value->name->toLowerString() === 'class'
                ) {
                    $classes[] = NameResolution::resolved($value->class) ?? $value->class->toString();
                }
            }
        }

        return $classes;
    }

    /**
     * Binds the closure parameter at `$offset` to `$type`, after {@see self::prepare()}. Returns the
     * params unchanged, never null (a function provider's null would skip argument checking), when
     * the TestCase is unknown.
     *
     * @param array<int, FunctionLikeParameter> $params
     * @param list<string> $traits
     * @return array<int, FunctionLikeParameter>
     */
    public static function bind(Codebase $codebase, array $params, int $offset, ?Union $type, array $traits): array
    {
        $type = self::prepare($codebase, $type, $traits);
        if (!$type instanceof Union) {
            return $params;
        }

        $params[$offset] = clone $params[$offset];
        $params[$offset]->closure_this_type = $type;

        return $params;
    }

    /**
     * Lets the TestCase `$type` names declare its file's `beforeEach()` properties and exposes the
     * bound `$traits`' methods on it, before a closure is bound to it. Null when it names no single class.
     *
     * @param list<string> $traits
     */
    private static function prepare(Codebase $codebase, ?Union $type, array $traits): ?Union
    {
        $class = $type?->getSingleAtomic();
        if (!$class instanceof TNamedObject) {
            return null;
        }

        BeforeEachPropertiesHandler::register($codebase, $class->value);
        BoundTestCase::expose($codebase, $class->value, $traits);

        return $type;
    }
}
