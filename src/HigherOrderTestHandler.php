<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

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
 * replays the call on the file's bound TestCase (or one of its bound traits) and returns the
 * `TestCall`, which keeps chaining. `->expect()` / `->and()` start an `Expectation` of `mixed`.
 * Other names keep Psalm's `UndefinedMagicMethod`.
 */
final class HigherOrderTestHandler implements AfterCodebasePopulatedInterface
{
    private const EXPECTATION_METHODS = ['expect', 'and'];

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
                $method = self::forwardedMethod($source, $name);
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
                if (self::forwardedMethod($event->getSource(), $name) === null) {
                    return null;
                }

                return \in_array($name, self::EXPECTATION_METHODS, true)
                    ? new Union([new TGenericObject(PestApi::EXPECTATION, [Type::getMixed()])])
                    : new Union([new TNamedObject(PestApi::TEST_CALL)]);
            },
        );
    }

    /**
     * The `Class::method` id `TestCall::__call()` ends up running for `$name` (a non-private method
     * of any part of the file's `$this` type), or null when `TestCall` declares `$name` itself or
     * nothing it forwards to has it.
     */
    private static function forwardedMethod(?StatementsSource $source, string $name): ?string
    {
        if (!$source instanceof StatementsSource) {
            return null;
        }

        $codebase = $source->getCodebase();
        if (isset(BoundTestCase::storage($codebase, PestApi::TEST_CALL)?->declaring_method_ids[$name])) {
            return null;
        }

        if (\in_array($name, self::EXPECTATION_METHODS, true)) {
            return PestApi::HIGHER_ORDER_CALLABLES . '::expect';
        }

        $type = BoundTestCase::thisType($codebase, $source->getFilePath())?->getSingleAtomic();
        foreach ($type instanceof TNamedObject ? [$type->value, ...\array_keys($type->extra_types)] : [] as $part) {
            $declaring = BoundTestCase::storage($codebase, $part)?->declaring_method_ids[$name] ?? null;
            $visibility = $declaring === null ? null : BoundTestCase::storage($codebase, $declaring->fq_class_name)?->methods[$name]->visibility ?? null;
            if ($declaring !== null && $visibility !== null && $visibility !== ClassLikeAnalyzer::VISIBILITY_PRIVATE) {
                return $declaring->fq_class_name . '::' . $name;
            }
        }

        return null;
    }
}
