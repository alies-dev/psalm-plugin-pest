<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Resolves Pest's higher-order tests, `it('x')->actingAsAdmin()->group('g')`: `TestCall::__call()`
 * records the call and returns the `TestCall`; Pest later replays the recorded calls on the file's
 * bound TestCase (or one of its bound traits), each on the previous call's non-null result.
 * `->expect()` / `->and()` start an `Expectation` of `mixed`. Other names keep Psalm's
 * `UndefinedMagicMethod`.
 */
final class HigherOrderTestHandler implements AfterCodebasePopulatedInterface
{
    private const EXPECTATION_METHODS = ['expect', 'and'];

    /** @var array<lowercase-string, ?string> `Class::method` the latest call of each name forwards to; the return provider runs right before the params one. */
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

                // HigherOrderCallables::expect() is templated, which Psalm cannot bind here.
                return \in_array($name, self::EXPECTATION_METHODS, true)
                    ? [new FunctionLikeParameter('value', false, Type::getMixed(), Type::getMixed())]
                    : $source->getCodebase()->getMethodParams($method);
            },
        );
        $codebase->methods->return_type_provider->registerClosure(
            PestApi::TEST_CALL,
            static function (MethodReturnTypeProviderEvent $event): ?Union {
                $name = $event->getMethodNameLowercase();
                $source = $event->getSource();
                $codebase = $source->getCodebase();
                $stmt = $event->getStmt();
                $target = self::replayTarget($codebase, $source->getFilePath(), $stmt instanceof MethodCall ? $stmt->var : null);

                self::$forwarded[$name] = self::forwardedMethod($codebase, $name, $target);
                if (self::$forwarded[$name] === null) {
                    return null;
                }

                return \in_array($name, self::EXPECTATION_METHODS, true)
                    ? new Union([new TGenericObject(PestApi::EXPECTATION, [Type::getMixed()])])
                    : new Union([new TNamedObject(PestApi::TEST_CALL)]);
            },
        );
    }

    /** The object Pest replays the next call on: the TestCase, or the last object a forwarded call of the `$receiver` chain returned. */
    private static function replayTarget(Codebase $codebase, string $filePath, ?Expr $receiver): ?Union
    {
        $names = [];
        for (; $receiver instanceof MethodCall && $receiver->name instanceof Identifier; $receiver = $receiver->var) {
            \array_unshift($names, $receiver->name->toLowerString());
        }

        $target = BoundTestCase::thisType($codebase, $filePath);
        foreach ($names as $name) {
            $method = self::forwardedMethod($codebase, $name, $target);
            $returned = $method === null ? null : $codebase->getMethodReturnType($method, $selfClass)?->getAtomicTypes();
            $objects = [];
            foreach ($returned ?? [] as $atomic) {
                // `static` is the object the call ran on.
                if ($atomic instanceof TNamedObject) {
                    $objects += $atomic->is_static ? $target?->getAtomicTypes() ?? [] : [$atomic->getKey() => $atomic];
                }
            }

            $target = $objects === [] ? $target : new Union($objects);
        }

        return $target;
    }

    /**
     * The `Class::method` id `TestCall::__call()` ends up running for `$name` (a non-private method
     * of any part of the `$target` object), or null when `TestCall` declares `$name` itself or
     * nothing it forwards to has it.
     *
     * @psalm-mutation-free
     */
    private static function forwardedMethod(Codebase $codebase, string $name, ?Union $target): ?string
    {
        if (isset(BoundTestCase::storage($codebase, PestApi::TEST_CALL)?->declaring_method_ids[$name])) {
            return null;
        }

        if (\in_array($name, self::EXPECTATION_METHODS, true)) {
            return PestApi::HIGHER_ORDER_CALLABLES . '::expect';
        }

        foreach ($target?->getAtomicTypes() ?? [] as $type) {
            foreach ($type instanceof TNamedObject ? [$type->value, ...\array_keys($type->extra_types)] : [] as $part) {
                $declaring = BoundTestCase::storage($codebase, $part)?->declaring_method_ids[$name] ?? null;
                $visibility = $declaring === null ? null : BoundTestCase::storage($codebase, $declaring->fq_class_name)?->methods[$name]->visibility ?? null;
                if ($declaring !== null && $visibility !== null && $visibility !== ClassLikeAnalyzer::VISIBILITY_PRIVATE) {
                    return $declaring->fq_class_name . '::' . $name;
                }
            }
        }

        return null;
    }
}
