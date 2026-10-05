<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Types `test()` called without arguments inside a test as the TestCase the test runs on.
 *
 * Pest returns `HigherOrderTapProxy|TestCall` there; the proxy forwards calls to the TestCase but
 * declares no `@mixin`, so Psalm reports `UndefinedMagicMethod`. {@see ClosureThisHandler} already
 * makes the closure's scope the TestCase (nested closures inherit it), so the call's context
 * `self` is the answer. Registered only when the scanned `test()` has the proxy in its native
 * return type, so a project's own global `test()` is never touched.
 */
final class CurrentTestHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $functions = $event->getCodebase()->functions;
        if (!$functions->hasStubbedFunction('test')
            || !isset($functions->getStorage(null, 'test')->signature_return_type?->getAtomicTypes()[PestApi::HIGHER_ORDER_TAP_PROXY])
        ) {
            return;
        }

        $functions->return_type_provider->registerClosure(
            'test',
            static function (FunctionReturnTypeProviderEvent $event): ?Union {
                $self = $event->getContext()->self;
                $codebase = $event->getStatementsSource()->getCodebase();

                // Null keeps Pest's type: a description was given, or the call is outside a TestCase-bound closure.
                return $event->getCallArgs() === [] && $self !== null
                    && ($self === TestCaseResolver::DEFAULT_TEST_CASE || $codebase->classExtends($self, TestCaseResolver::DEFAULT_TEST_CASE))
                    ? new Union([new TNamedObject($self)])
                    : null;
            },
        );
    }
}
