<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodVisibilityProviderEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Types `test()` called without arguments inside a test as the TestCase the test runs on.
 *
 * Pest returns `HigherOrderTapProxy|TestCall` there; the proxy forwards calls to the TestCase but
 * declares no `@mixin`, so Psalm reports `UndefinedMagicMethod`. {@see ClosureThisHandler} already
 * makes the closure's scope the TestCase (nested closures inherit it), so the call's context
 * `self` is the answer; in a named helper function the file's bound TestCase is. Pest forwards
 * `test()->method()` through reflection, so protected and private methods are callable there: the
 * typed TestCase answers the visibility check `true` when the receiver is a `test()` call.
 * Registered only when the scanned `test()` has the proxy in its native return type, so a
 * project's own global `test()` is never touched.
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
                // Null keeps Pest's type: a description was given, or the call is not in a test or helper function.
                if ($event->getCallArgs() !== []) {
                    return null;
                }

                $context = $event->getContext();
                $codebase = $event->getStatementsSource()->getCodebase();
                $self = $context->self;
                if ($self !== null && ($self === TestCaseResolver::DEFAULT_TEST_CASE || $codebase->classExtends($self, TestCaseResolver::DEFAULT_TEST_CASE))) {
                    return self::exposeNonPublicMethods($codebase, new Union([new TNamedObject($self)]));
                }

                // A named function (and any closure in it) runs while a test runs; top level and methods do not.
                $bound = $context->calling_function_id !== null
                    ? BoundTestCase::thisType($codebase, $event->getStatementsSource()->getFilePath())
                    : null;

                return $bound instanceof \Psalm\Type\Union ? self::exposeNonPublicMethods($codebase, $bound) : null;
            },
        );
    }

    /** Psalm keys visibility providers by exact class name, so each TestCase is registered when `test()` first resolves to it. */
    private static function exposeNonPublicMethods(Codebase $codebase, Union $testCase): Union
    {
        $class = $testCase->getSingleAtomic()->getId();
        $visibility = $codebase->methods->visibility_provider;
        if (!$visibility->has($class)) {
            $visibility->registerClosure(
                $class,
                static function (MethodVisibilityProviderEvent $event): ?bool {
                    $location = $event->getCodeLocation();
                    if (!$location instanceof CodeLocation) {
                        return null;
                    }

                    $source = $event->getSource();
                    $before = \substr($source->getCodebase()->file_provider->getContents($source->getFilePath()), 0, $location->raw_file_start);

                    return \preg_match('/(?<![\w$>:])\\\\?test\s*\(\s*\)\s*\??->\s*$/', $before) === 1 ? true : null;
                },
            );
        }

        return $testCase;
    }
}
