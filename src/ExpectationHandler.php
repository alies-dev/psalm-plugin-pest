<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Makes `expect($x)` chains type the way Pest runs them: `->not->toBeNull()`, `->each->toBeInt()`,
 * `->toBeArray()->not->toBeEmpty()` and `->formatId->toBe('meta')` all resolve.
 *
 * Pest hides its assertions one `@mixin` deeper than Psalm looks: `Expectation` mixes in
 * `Mixins\Expectation<TValue>`, which holds every `toX()`, while the proxies behind `->not`,
 * `->each` and `->someProperty` (`OppositeExpectation`, `EachExpectation`, `HigherOrderExpectation`)
 * only mix in `Expectation`. Psalm follows a mixin one hop and not through a generic one
 * (`Populator::resolveTransitiveMixins()` skips `TGenericObject`), and Pest's `self<TValue>` returns
 * and `@property` tags without template arguments break the chain a second time. All of it is storage
 * data, so this patches storage once population is done, using what Psalm scanned: a new Pest
 * assertion is picked up with no change here. Four patches:
 *
 * 1. Assertions return `Expectation<TValue>` instead of `Mixins\Expectation<TValue>`. `Expectation::__call()`
 *    runs the mixin and returns the outer `$this`, so `->not` / `->each` stay reachable after any assertion.
 * 2. `Expectation`'s `$not` / `$each` pseudo properties carry the value type (`OppositeExpectation<TValue>`):
 *    without it a proxy has no template arguments for step 3 to resolve.
 * 3. Each proxy gets a pseudo method per assertion, cloned from `Mixins\Expectation` so argument checking
 *    still works (`expect(1)->not->toBe()` is a `TooFewArguments`). The return type follows the proxy's
 *    `__call()`: `OppositeExpectation` hands back `Expectation<TValue>`, `EachExpectation` itself,
 *    `HigherOrderExpectation` itself. Only assertions neither the proxy nor `Expectation` already
 *    reaches are cloned, so what Pest declares on the proxy (the arch assertions) keeps its own signature.
 *    A pseudo method, not a mixin or a method provider: it leaves unknown methods sealed, so
 *    `->not->toBeBananas()` is still reported.
 * 4. `Expectation::__get()` accepts any name: `$expect->formatId` is a `HigherOrderExpectation` over that
 *    member of the value. `@property` tags make Psalm treat magic properties as sealed, so the tag-less
 *    `HigherOrderExpectation` (sealed by `sealAllProperties`) is opened the same way, and for methods too:
 *    its `__call()` forwards an unknown method to the value. `Expectation::__call()` stays sealed, because
 *    there an unknown name on a non-object value is a real mistake.
 *
 * Runs after {@see InternalDslHandler}, so the cloned methods carry no `@internal` marker.
 *
 * Known limits: assertions added at runtime through `expect()->extend()` are invisible to a scan; and
 * `->not` / `->each` read through `EachExpectation` or `HigherOrderExpectation` are still resolved by
 * Psalm from the `Expectation` mixin (which wins over the proxy's own pseudo properties), so the chain
 * stays valid but the value type inside it is not tracked.
 */
final class ExpectationHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Each proxy and what an assertion called through it returns (the class, with the proxy's own
     * template arguments: the `__call()` return types in Pest's src/Expectations).
     */
    private const ASSERTION_RESULTS = [
        PestApi::OPPOSITE_EXPECTATION => PestApi::EXPECTATION,
        PestApi::EACH_EXPECTATION => PestApi::EACH_EXPECTATION,
        PestApi::HIGHER_ORDER_EXPECTATION => PestApi::HIGHER_ORDER_EXPECTATION,
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $storageProvider = $event->getCodebase()->classlike_storage_provider;

        $classes = [PestApi::EXPECTATION, PestApi::MIXIN_EXPECTATION, ...\array_keys(self::ASSERTION_RESULTS)];
        foreach ($classes as $class) {
            // A Pest too old or too new to have one of them is not ours to patch.
            if (!$storageProvider->has($class)) {
                return;
            }
        }

        $expectation = $storageProvider->get(PestApi::EXPECTATION);
        $assertions = $storageProvider->get(PestApi::MIXIN_EXPECTATION);
        $proxies = [];
        foreach (self::ASSERTION_RESULTS as $proxy => $_) {
            $proxies[$proxy] = $storageProvider->get($proxy);
        }

        self::returnOuterExpectation($assertions);
        self::carryValueTypeIntoProxyProperties($expectation, $proxies);

        foreach (self::ASSERTION_RESULTS as $proxy => $result) {
            $proxyStorage = $proxies[$proxy];
            $resultType = self::resultType($proxyStorage, $storageProvider->get($result));
            if (!$resultType instanceof \Psalm\Type\Union) {
                continue;
            }

            self::forwardAssertions($proxyStorage, $resultType, $assertions, $expectation);
        }

        self::acceptAnyHigherOrderMember($expectation, $proxies[PestApi::HIGHER_ORDER_EXPECTATION]);
    }

    /**
     * `self<TValue>` in Mixins\Expectation is `Mixins\Expectation<TValue>`; the template arguments stay
     * as they are, since Psalm resolves them against the mixin class that declares the method.
     */
    private static function returnOuterExpectation(ClassLikeStorage $assertions): void
    {
        foreach ($assertions->methods as $method) {
            $method->return_type = $method->return_type?->replaceClassLike(
                PestApi::MIXIN_EXPECTATION,
                PestApi::EXPECTATION,
            );
            $method->signature_return_type = $method->signature_return_type?->replaceClassLike(
                PestApi::MIXIN_EXPECTATION,
                PestApi::EXPECTATION,
            );
        }
    }

    /**
     * `@property OppositeExpectation $not` names the class bare, so `expect(1)->not` would be
     * `OppositeExpectation<mixed>`. A proxy whose template list matches the expectation's is handed
     * the expectation's own `TValue`; the rest of the tags (`$classes` ...) stay as Pest wrote them.
     *
     * @param array<string, ClassLikeStorage> $proxies
     */
    private static function carryValueTypeIntoProxyProperties(ClassLikeStorage $expectation, array $proxies): void
    {
        $arguments = self::templateArguments($expectation);
        if ($arguments === []) {
            return;
        }

        foreach ($expectation->pseudo_property_get_types as $property => $type) {
            $atomics = [];
            $carried = false;
            foreach ($type->getAtomicTypes() as $atomic) {
                $isBareProxy = $atomic::class === TNamedObject::class
                    && isset($proxies[$atomic->value])
                    && \count(self::templateArguments($proxies[$atomic->value])) === \count($arguments);

                $carried = $carried || $isBareProxy;
                $atomics[] = $isBareProxy ? new TGenericObject($atomic->value, $arguments) : $atomic;
            }

            if ($carried) {
                $expectation->pseudo_property_get_types[$property] = new Union($atomics);
            }
        }
    }

    /**
     * What an assertion returns through `$proxy`: the result class on the proxy's own template
     * arguments. Null when the two do not line up (a Pest that changed their shape).
     *
     * @psalm-mutation-free
     */
    private static function resultType(ClassLikeStorage $proxy, ClassLikeStorage $result): ?Union
    {
        $arguments = self::templateArguments($proxy);
        if ($arguments === [] || \count(self::templateArguments($result)) !== \count($arguments)) {
            return null;
        }

        return new Union([new TGenericObject($result->name, $arguments)]);
    }

    /**
     * Gives `$proxy` a pseudo method for every assertion it does not reach yet. The parameters are
     * shared with the original, which mentions no class template (only `__construct()` does), so
     * they need no remapping; the return type is built from the proxy's own templates.
     */
    private static function forwardAssertions(
        ClassLikeStorage $proxy,
        Union $resultType,
        ClassLikeStorage $assertions,
        ClassLikeStorage $expectation,
    ): void {
        foreach ($assertions->methods as $name => $assertion) {
            if (!self::isAssertion($assertion, $name)
                || isset($proxy->declaring_method_ids[$name])
                || isset($expectation->declaring_method_ids[$name])
            ) {
                continue;
            }

            $forwarded = clone $assertion;
            $forwarded->defining_fqcln = $proxy->name;
            $forwarded->return_type = $resultType;
            $forwarded->signature_return_type = null;

            $proxy->pseudo_methods[$name] = $forwarded;
        }
    }

    /**
     * What `Expectation::__get()` and `HigherOrderExpectation::__get()` / `__call()` do with a name
     * that is not an assertion: a new `HigherOrderExpectation` over the value's member, whose own
     * value is unknown. Pest declares `Expectation::__get()` as a union of everything it can return,
     * of which the assertions are covered by now, so the member case replaces it.
     */
    private static function acceptAnyHigherOrderMember(
        ClassLikeStorage $expectation,
        ClassLikeStorage $higherOrder,
    ): void {
        $expectation->sealed_properties = false;
        $higherOrder->sealed_properties = false;
        $higherOrder->sealed_methods = false;

        $arguments = self::templateArguments($expectation);
        if ($arguments === [] || !isset($expectation->methods['__get'])) {
            return;
        }

        $expectation->methods['__get']->return_type = new Union([
            new TGenericObject($higherOrder->name, [
                new Union([new TGenericObject($expectation->name, $arguments)]),
                Type::getMixed(),
            ]),
        ]);
    }

    /**
     * Public instance methods only. The magic ones are the dispatch itself, and a static one
     * (`Mixins\Expectation` has none today) is not reachable through `->not->`.
     *
     * @psalm-mutation-free
     */
    private static function isAssertion(MethodStorage $method, string $lowercaseName): bool
    {
        return $method->visibility === \ReflectionMethod::IS_PUBLIC
            && !$method->is_static
            && !\str_starts_with($lowercaseName, '__');
    }

    /**
     * A class's own template parameters as type arguments (`Expectation<TValue>` for `Expectation`).
     *
     * @return list<Union>
     *
     * @psalm-mutation-free
     */
    private static function templateArguments(ClassLikeStorage $storage): array
    {
        $arguments = [];
        foreach ($storage->template_types ?? [] as $name => $definedAs) {
            $arguments[] = new Union([
                new TTemplateParam($name, \current($definedAs), $storage->name),
            ]);
        }

        return $arguments;
    }
}
