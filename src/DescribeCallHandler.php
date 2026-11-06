<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Resolves `describe('x', fn () => ...)->group('g')->skip()`: `DescribeCall::__call()` hands the call to a
 * `BeforeEachCall`, which replays it on the `TestCall` of every test in the block, and returns the
 * `DescribeCall`. So the public methods of `TestCall` exist on `DescribeCall` with the same params.
 * Other names keep Psalm's `UndefinedMagicMethod`: Pest also forwards them to the bound TestCase
 * ({@see HigherOrderTestHandler} does that for `TestCall`), which is not modelled here.
 *
 * Psalm asks the providers for magic calls only, so methods `DescribeCall` declares itself are not affected.
 */
final class DescribeCallHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        if (!BoundTestCase::storage($codebase, PestApi::TEST_CALL) instanceof \Psalm\Storage\ClassLikeStorage
            || !BoundTestCase::storage($codebase, PestApi::DESCRIBE_CALL) instanceof \Psalm\Storage\ClassLikeStorage
        ) {
            return;
        }

        $codebase->methods->params_provider->registerClosure(
            PestApi::DESCRIBE_CALL,
            static function (MethodParamsProviderEvent $event): ?array {
                $source = $event->getStatementsSource();
                $name = $event->getMethodNameLowercase();
                if (!$source instanceof StatementsSource || !self::forwardsToTestCall($source->getCodebase(), $name)) {
                    return null;
                }

                $params = $source->getCodebase()->getMethodParams(PestApi::TEST_CALL . '::' . $name);

                return $name === 'with'
                    ? ClosureThisHandler::bindWith($source->getCodebase(), $source->getFilePath(), $params, $event->getCallArgs() ?? [])
                    : $params;
            },
        );
        $codebase->methods->return_type_provider->registerClosure(
            PestApi::DESCRIBE_CALL,
            static fn(MethodReturnTypeProviderEvent $event): ?Union
                => self::forwardsToTestCall($event->getSource()->getCodebase(), $event->getMethodNameLowercase())
                    ? new Union([new TNamedObject(PestApi::DESCRIBE_CALL)])
                    : null,
        );
    }

    /**
     * `BeforeEachCall::__call()` checks `method_exists(TestCall::class, $name)`; the magic methods are not
     * part of the chainable API.
     *
     * @psalm-mutation-free
     */
    private static function forwardsToTestCall(Codebase $codebase, string $name): bool
    {
        if (\str_starts_with($name, '__')) {
            return false;
        }

        $method = BoundTestCase::storage($codebase, PestApi::TEST_CALL)?->methods[$name] ?? null;

        return $method !== null && $method->visibility === ClassLikeAnalyzer::VISIBILITY_PUBLIC;
    }
}
