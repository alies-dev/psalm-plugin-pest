<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\Internal\Codebase\Functions;
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
 * `afterEach()`, `TestCall::with()` datasets and `after()` closures, the hooks of a `uses()` / `pest()` chain and the
 * `->and()` of a higher-order `it('x')->expect(1)->and(fn () => $this->x)` chain.
 *
 * Pest tags them `@param-closure-this TestCall`, whose `@mixin` union Psalm does not honor; the
 * `with()` and `UsesCall` closures carry no tag. A params provider answers per call site (no shared
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
        PestApi::TEST_CALL => ['with', 'after'],
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

        self::bindDatasetFunction($functions);
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
        $arg = ($event->getCallArgs() ?? [])[0] ?? null;
        if (!\in_array($method, self::METHODS[$class] ?? [], true) || !$source instanceof \Psalm\StatementsSource || $arg === null) {
            return null;
        }

        $codebase = $source->getCodebase();
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

        $bound = self::bind($codebase, $params, 0, $type, $traits);
        if ($class === PestApi::TEST_CALL && $method === 'with' && $type instanceof Union) {
            self::bindRows($type, \array_map(static fn(Arg $arg): Expr => $arg->value, $event->getCallArgs() ?? []));
        }

        return $bound;
    }

    /**
     * `dataset('x', [...])` carries no closure tag, so its rows are bound by this call-site provider alone.
     */
    private static function bindDatasetFunction(Functions $functions): void
    {
        if (!$functions->hasStubbedFunction('dataset')) {
            return;
        }

        $storage = $functions->getStorage(null, 'dataset');
        $params = $storage->params;
        $declaredIn = \str_replace('\\', '/', $storage->location->file_path ?? '');
        if (!\str_ends_with($declaredIn, '/src/Functions.php')
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

                if ($type instanceof Union && $dataset instanceof Arg) {
                    // Only for the side effects of binding; the closure parameter itself stays unbound.
                    self::bind($codebase, $params, 1, $type, BoundTestCase::traits($codebase, $file));
                    self::bindRows($type, [$dataset->value]);
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
     */
    private static function bindRows(Union $type, array $datasets): void
    {
        foreach (DatasetRows::closures($datasets) as $closure) {
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
        while ($link instanceof MethodCall || $link instanceof NullsafeMethodCall || $link instanceof PropertyFetch || $link instanceof NullsafePropertyFetch) {
            $link = $link->var;
        }

        return $link instanceof FuncCall
            && $link->name instanceof Name
            && $link->args !== []
            && \in_array(\strtolower(NameResolution::resolved($link->name) ?? $link->name->toString()), ['it', 'test'], true);
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
     * Binds the closure parameter at `$offset` to `$type`, lets that TestCase's file declare its
     * `beforeEach()` properties and exposes the bound `$traits`' methods on it. Returns the params unchanged, never null (a function provider's null
     * would skip argument checking), when the TestCase is unknown.
     *
     * @param array<int, FunctionLikeParameter> $params
     * @param list<string> $traits
     * @return array<int, FunctionLikeParameter>
     */
    public static function bind(Codebase $codebase, array $params, int $offset, ?Union $type, array $traits): array
    {
        $class = $type?->getSingleAtomic();
        if (!$class instanceof TNamedObject) {
            return $params;
        }

        BeforeEachPropertiesHandler::register($codebase, $class->value);
        BoundTestCase::expose($codebase, $class->value, $traits);

        $params[$offset] = clone $params[$offset];
        $params[$offset]->closure_this_type = $type;

        return $params;
    }
}
