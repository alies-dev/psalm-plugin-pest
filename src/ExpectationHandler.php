<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
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
 * `Mixins\Expectation<TValue>`, which holds every `toX()`, while the proxies behind `->not`, `->each`
 * and `->someProperty` (`OppositeExpectation`, `EachExpectation`, `HigherOrderExpectation`) only mix in
 * `Expectation`. Psalm follows a mixin one hop and not through a generic one, and Pest's `self<TValue>`
 * returns and template-less `@property` tags break the chain a second time. All of it is storage data,
 * so this patches storage once population is done, from what Psalm scanned: a new Pest assertion needs
 * no change here. Four patches:
 *
 * 1. Assertions return `Expectation<TValue>` instead of `Mixins\Expectation<TValue>`:
 *    `Expectation::__call()` returns the outer `$this`, so `->not` / `->each` stay reachable.
 * 2. `Expectation`'s `$not` / `$each` pseudo properties carry the value type, so a proxy has template
 *    arguments for step 3 to resolve.
 * 3. Each proxy gets a pseudo method per assertion, cloned from `Mixins\Expectation` so argument checking
 *    still works (`expect(1)->not->toBe()` is a `TooFewArguments`). The return type follows the proxy's
 *    `__call()`: `Expectation<TValue>` for `OppositeExpectation`, the proxy itself for the others. Only
 *    assertions neither the proxy nor `Expectation` reaches are cloned, so the proxy's own methods (the
 *    arch assertions) keep their signature. A pseudo method, not a mixin, keeps unknown methods sealed:
 *    `->not->toBeBananas()` is still reported.
 * 4. `Expectation::__get()` accepts any name: `$expect->formatId` is a `HigherOrderExpectation` over that
 *    member of the value. `@property` tags make Psalm treat magic properties as sealed, so the tag-less
 *    `HigherOrderExpectation` is opened the same way, and for methods too: its `__call()` forwards an
 *    unknown method to the value. `Expectation::__call()` stays sealed, because there an unknown name on
 *    a non-object value is a real mistake.
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
     * Each proxy and what an assertion called through it returns: the class its `__call()` hands back,
     * on the proxy's own template arguments.
     */
    private const PROXY_RESULTS = [
        PestApi::OPPOSITE_EXPECTATION => PestApi::EXPECTATION,
        PestApi::EACH_EXPECTATION => PestApi::EACH_EXPECTATION,
        PestApi::HIGHER_ORDER_EXPECTATION => PestApi::HIGHER_ORDER_EXPECTATION,
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $provider = $event->getCodebase()->classlike_storage_provider;

        $storages = [];
        foreach ([PestApi::EXPECTATION, PestApi::MIXIN_EXPECTATION, ...\array_keys(self::PROXY_RESULTS)] as $class) {
            // Without one of them (no Pest, or a Pest of another shape) there is nothing of ours to patch.
            if (!$provider->has($class)) {
                return;
            }

            $storages[$class] = $provider->get($class);
        }

        $expectation = $storages[PestApi::EXPECTATION];
        $assertions = $storages[PestApi::MIXIN_EXPECTATION];

        self::returnOuterExpectation($assertions);
        self::carryValueTypeIntoProxyProperties($expectation);

        foreach (self::PROXY_RESULTS as $proxy => $result) {
            self::forwardAssertions($storages[$proxy], $result, $assertions, $expectation);
        }

        self::acceptAnyHigherOrderMember($expectation, $storages[PestApi::HIGHER_ORDER_EXPECTATION]);
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
     * `OppositeExpectation<mixed>`: give it the expectation's own `TValue`. The other tags
     * (`$classes` ...) stay as Pest wrote them.
     */
    private static function carryValueTypeIntoProxyProperties(ClassLikeStorage $expectation): void
    {
        $arguments = self::templateArguments($expectation);

        foreach ($expectation->pseudo_property_get_types as $property => $type) {
            $atomic = $type->isSingle() ? $type->getSingleAtomic() : null;

            if ($atomic !== null
                && $atomic::class === TNamedObject::class
                && isset(self::PROXY_RESULTS[$atomic->value])
            ) {
                $expectation->pseudo_property_get_types[$property] = new Union([
                    new TGenericObject($atomic->value, $arguments),
                ]);
            }
        }
    }

    /**
     * Gives `$proxy` a pseudo method for every assertion it does not reach yet. The parameters are
     * shared with the original, which mentions no class template (only `__construct()` does), so
     * they need no remapping; the return type is `$result` on the proxy's own templates.
     */
    private static function forwardAssertions(
        ClassLikeStorage $proxy,
        string $result,
        ClassLikeStorage $assertions,
        ClassLikeStorage $expectation,
    ): void {
        $returnType = new Union([new TGenericObject($result, self::templateArguments($proxy))]);

        foreach ($assertions->methods as $name => $assertion) {
            // Public only (`export()` is not an assertion); `__construct()` is declared by every proxy.
            if ($assertion->visibility !== \ReflectionMethod::IS_PUBLIC
                || isset($proxy->declaring_method_ids[$name])
                || isset($expectation->declaring_method_ids[$name])
            ) {
                continue;
            }

            $forwarded = clone $assertion;
            $forwarded->defining_fqcln = $proxy->name;
            $forwarded->return_type = $returnType;
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

        $expectation->methods['__get']->return_type = new Union([
            new TGenericObject($higherOrder->name, [
                new Union([new TGenericObject($expectation->name, self::templateArguments($expectation))]),
                Type::getMixed(),
            ]),
        ]);
    }

    /**
     * A class's own template parameters as type arguments (`Expectation<TValue>` for `Expectation`);
     * every Pest class this plugin patches has at least one.
     *
     * @return non-empty-list<Union>
     *
     * @psalm-mutation-free
     */
    private static function templateArguments(ClassLikeStorage $storage): array
    {
        /** @var non-empty-list<Union> */
        return \array_map(
            static fn(string $name, array $definedAs): Union => new Union([
                new TTemplateParam($name, \current($definedAs), $storage->name),
            ]),
            \array_keys($storage->template_types ?? []),
            \array_values($storage->template_types ?? []),
        );
    }
}
