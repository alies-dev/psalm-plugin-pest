<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TVoid;
use Psalm\Type\Union;

/**
 * Resolves Pest's higher-order tests, `it('x')->actingAsAdmin()->group('g')`: `TestCall::__call()`
 * records the call and returns the `TestCall`; Pest later replays the recorded calls on the file's
 * bound TestCase (or its bound traits, exposed on it), each on the previous call's non-null result.
 * `->expect()` / `->and()` start an `Expectation` of `mixed`, and bind a closure value to the TestCase
 * ({@see ClosureThisHandler}). A name no TestCase method has but a global function does runs that function
 * (`Reflection::call()` falls back to `is_callable($name)`), with the chain's arguments. Other names keep
 * Psalm's `UndefinedMagicMethod`.
 */
final class HigherOrderTestHandler implements AfterCodebasePopulatedInterface
{
    private const EXPECTATION_METHODS = ['expect', 'and'];

    /** @var array<lowercase-string, ?non-empty-string> `Class::method`, or the id of a global function, the latest call of each name forwards to; the return provider runs right before the params one. */
    private static array $forwarded = [];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        if (!BoundTestCase::storage($codebase, PestApi::TEST_CALL) instanceof \Psalm\Storage\ClassLikeStorage) {
            return;
        }

        $codebase->methods->params_provider->registerClosure(
            PestApi::TEST_CALL,
            static function (MethodParamsProviderEvent $event): ?array {
                $source = $event->getStatementsSource();
                $name = $event->getMethodNameLowercase();
                $method = self::$forwarded[$name] ?? null;
                if ($method === null || !$source instanceof StatementsSource) {
                    return null;
                }

                if (!\in_array($name, self::EXPECTATION_METHODS, true)) {
                    return self::paramsOf($source, $method);
                }

                // HigherOrderCallables::expect() is templated, which Psalm cannot bind here. It binds a closure
                // value to the TestCase (`Reflection::bindCallableWithData()`), but only when the call is spelled
                // exactly `expect` / `and`: Pest matches the name case-sensitively.
                $codebase = $source->getCodebase();
                $file = $source->getFilePath();
                $params = [new FunctionLikeParameter('value', false, Type::getMixed(), Type::getMixed())];

                return ClosureThisHandler::calledAs(($event->getCallArgs() ?? [])[0] ?? null, $name)
                    ? ClosureThisHandler::bind($codebase, $params, 0, BoundTestCase::thisType($codebase, $file), BoundTestCase::traits($codebase, $file))
                    : $params;
            },
        );
        $codebase->methods->return_type_provider->registerClosure(
            PestApi::TEST_CALL,
            static function (MethodReturnTypeProviderEvent $event): ?Union {
                $name = $event->getMethodNameLowercase();
                $source = $event->getSource();
                $stmt = $event->getStmt();
                $target = self::replayTarget($source, $stmt instanceof MethodCall ? $stmt->var : null);

                self::$forwarded[$name] = self::forwardedMethod($source, $name, $target);
                if (self::$forwarded[$name] === null) {
                    return null;
                }

                return \in_array($name, self::EXPECTATION_METHODS, true)
                    ? new Union([new TGenericObject(PestApi::EXPECTATION, [Type::getMixed()])])
                    : new Union([new TNamedObject(PestApi::TEST_CALL)]);
            },
        );
    }

    /**
     * The object Pest replays the next call on: the TestCase, or the last object a forwarded call of the `$receiver`
     * chain returned. A global function returning a non-object (`sleep()`) leaves `never`: Pest replays the next
     * call on that value, so nothing after it resolves.
     */
    private static function replayTarget(StatementsSource $source, ?Expr $receiver): ?Union
    {
        $codebase = $source->getCodebase();
        $names = [];
        for (; $receiver instanceof MethodCall && $receiver->name instanceof Identifier; $receiver = $receiver->var) {
            \array_unshift($names, $receiver->name->toLowerString());
        }

        $target = BoundTestCase::thisType($codebase, $source->getFilePath());
        foreach ($names as $name) {
            $method = self::forwardedMethod($source, $name, $target);
            $returned = $method === null ? null : self::returnTypeOf($source, $method)?->getAtomicTypes();
            $objects = [];
            $nonObject = false;
            foreach ($returned ?? [] as $atomic) {
                // `static` is the object the call ran on.
                if ($atomic instanceof TNamedObject) {
                    $objects += $atomic->is_static ? $target?->getAtomicTypes() ?? [] : [$atomic->getKey() => $atomic];
                } elseif (!$atomic instanceof TNull && !$atomic instanceof TVoid) {
                    $nonObject = true;
                }
            }

            // Only functions: a TestCase method of an unknown or non-object return type keeps the target.
            $target = match (true) {
                $method !== null && $nonObject && !\str_contains($method, '::') => Type::getNever(),
                $objects === [] => $target,
                default => new Union($objects),
            };
        }

        return $target;
    }

    /**
     * The `Class::method` id `TestCall::__call()` ends up running for `$name` (a non-private method
     * of the `$target` object), else the id of the global function of that name (Pest's last resort),
     * or null when `TestCall` declares `$name` itself or nothing it forwards to has it.
     *
     * @param lowercase-string $name
     * @return ?non-empty-string
     */
    private static function forwardedMethod(StatementsSource $source, string $name, ?Union $target): ?string
    {
        $codebase = $source->getCodebase();
        if (isset(BoundTestCase::storage($codebase, PestApi::TEST_CALL)?->declaring_method_ids[$name])) {
            return null;
        }

        // The previous call returned a non-object: there is no object to replay this one on.
        if (isset($target?->getAtomicTypes()['never'])) {
            return null;
        }

        if (\in_array($name, self::EXPECTATION_METHODS, true)) {
            return PestApi::HIGHER_ORDER_CALLABLES . '::expect';
        }

        foreach ($target?->getAtomicTypes() ?? [] as $type) {
            $declaring = $type instanceof TNamedObject ? BoundTestCase::storage($codebase, $type->value)?->declaring_method_ids[$name] ?? null : null;
            $visibility = $declaring === null ? null : (BoundTestCase::storage($codebase, $declaring->fq_class_name)?->methods[$declaring->method_name] ?? null)?->visibility;
            if ($declaring !== null && $visibility !== null && $visibility !== ClassLikeAnalyzer::VISIBILITY_PRIVATE) {
                return $declaring->fq_class_name . '::' . $name;
            }
        }

        // The emptiness check comes last: Psalm 6 drops `lowercase-string` from a variable once it is narrowed.
        return $source instanceof StatementsAnalyzer && $codebase->functions->functionExists($source, $name) && $name !== '' ? $name : null;
    }

    /**
     * @param non-empty-string $forwarded a {@see self::forwardedMethod()} result
     * @return ?array<int, FunctionLikeParameter>
     */
    private static function paramsOf(StatementsSource $source, string $forwarded): ?array
    {
        $codebase = $source->getCodebase();
        if (\str_contains($forwarded, '::')) {
            return $codebase->getMethodParams($forwarded);
        }

        return $source instanceof StatementsAnalyzer ? $codebase->functions->getStorage($source, \strtolower($forwarded))->params : null;
    }

    /** @param non-empty-string $forwarded a {@see self::forwardedMethod()} result */
    private static function returnTypeOf(StatementsSource $source, string $forwarded): ?Union
    {
        $codebase = $source->getCodebase();
        if (\str_contains($forwarded, '::')) {
            return $codebase->getMethodReturnType($forwarded, $selfClass);
        }

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $function = $codebase->functions->getStorage($source, \strtolower($forwarded));

        return $function->return_type ?? $function->signature_return_type;
    }
}
