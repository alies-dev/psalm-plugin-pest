<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Types `test()` called without arguments inside a test as the TestCase that test runs on.
 *
 * Pest declares `test()` as `($description is string ? TestCall : HigherOrderTapProxy|TestCall)`.
 * The `HigherOrderTapProxy` wraps the running TestCase and forwards method calls and property
 * reads/writes to it (`test()->get('/')->assertOk()`), but it declares no `@mixin`, so Psalm
 * reports `UndefinedMagicMethod` for every forwarded call.
 *
 * A handler, not a stub: the class differs per test file. The binding is already known at the call
 * site: {@see ClosureThisHandler} makes the closure's scope the TestCase, and nested closures and
 * arrow functions inherit it, so the call's context `self` is the answer. Outside such a closure
 * `self` is not a TestCase and the call keeps Pest's own return type.
 *
 * Registered at AfterCodebasePopulated only when the scanned `test()` is Pest's, recognised by the
 * proxy in its native return type, so a project's unrelated global `test()` is never touched.
 * Without `@param-closure-this` support in the installed Psalm no closure is bound, `self` stays
 * unset, and the handler declines everywhere.
 */
final class CurrentTestHandler implements AfterCodebasePopulatedInterface
{
    private const FUNCTION = 'test';

    private const PHPUNIT_TEST_CASE = 'PHPUnit\Framework\TestCase';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $functions = $event->getCodebase()->functions;
        if (!$functions->hasStubbedFunction(self::FUNCTION)
            || !self::returnsTapProxy($functions->getStorage(null, self::FUNCTION)->signature_return_type)
        ) {
            return;
        }

        $functions->return_type_provider->registerClosure(
            self::FUNCTION,
            static fn(FunctionReturnTypeProviderEvent $event): ?Union => self::getFunctionReturnType($event),
        );
    }

    /**
     * Null keeps Pest's declared return type: a description was given (a `TestCall`), or the call
     * is not inside a closure bound to a TestCase.
     */
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getCallArgs() !== []) {
            return null;
        }

        $self = $event->getContext()->self;
        if ($self === null || !self::isTestCase($event->getStatementsSource()->getCodebase(), $self)) {
            return null;
        }

        return new Union([new TNamedObject($self)]);
    }

    /** @psalm-pure */
    private static function returnsTapProxy(?Union $returnType): bool
    {
        if (!$returnType instanceof Union) {
            return false;
        }

        foreach ($returnType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && $atomic->value === PestApi::HIGHER_ORDER_TAP_PROXY) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads the populated parent list rather than asking the Codebase, whose class lookups throw
     * for a class without storage.
     *
     * @psalm-mutation-free
     */
    private static function isTestCase(Codebase $codebase, string $class): bool
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($class);
        } catch (\InvalidArgumentException|\Psalm\Exception\UnpopulatedClasslikeException) {
            return false;
        }

        return \strtolower($storage->name) === \strtolower(self::PHPUNIT_TEST_CASE)
            || isset($storage->parent_classes[\strtolower(self::PHPUNIT_TEST_CASE)]);
    }
}
