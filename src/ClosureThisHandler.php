<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
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
 * `afterEach()`, `TestCall::with()` datasets, the hooks of a `uses()` / `pest()` chain and the
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
        PestApi::TEST_CALL => ['with'],
        PestApi::USES_CALL => ['beforeeach', 'aftereach'],
        PestApi::EXPECTATION => ['and'],
    ];

    private const RECEIVER = 'pest-receiver';

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

        if ($class === PestApi::EXPECTATION && !self::startsAtTest($arg->getAttribute(self::RECEIVER))) {
            return null;
        }

        $chain = $class === PestApi::USES_CALL ? self::chainClasses($arg->getAttribute(self::RECEIVER)) : [];

        $type = BoundTestCase::thisTypeOf($codebase, $chain);
        $file = $source->getFilePath();

        return $type instanceof \Psalm\Type\Union
            ? self::bind($codebase, $params, 0, $type, BoundTestCase::traitsOf($codebase, $chain))
            : self::bind($codebase, $params, 0, BoundTestCase::thisType($codebase, $file), BoundTestCase::traits($codebase, $file));
    }

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        if ($call instanceof MethodCall && isset($call->args[0])) {
            $call->args[0]->setAttribute(self::RECEIVER, $call->var);
        }

        return null;
    }

    /**
     * Whether a method chain starts at `it('x')` / `test('x')`: Pest replays the rest of that chain
     * through `HigherOrderCallables`, which binds the closure of `expect()` / `and()` to the TestCase.
     * Any other `Expectation::and()` takes the closure as a plain value.
     */
    private static function startsAtTest(mixed $link): bool
    {
        while ($link instanceof MethodCall) {
            $link = $link->var;
        }

        return $link instanceof FuncCall
            && $link->name instanceof Name
            && \in_array($link->name->getLast(), ['it', 'test'], true)
            && $link->args !== [];
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
