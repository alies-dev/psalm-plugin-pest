<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

/**
 * Every Pest class the plugin keys on, in one place: review this list on every Pest major.
 * Each name is the class's case-preserved FQCN as Psalm stores it.
 *
 * @psalm-pure
 */
final class PestApi
{
    /** `expect()`'s return type; declares `@mixin Mixins\Expectation<TValue>` and the `$not` / `$each` pseudo properties. */
    public const EXPECTATION = 'Pest\Expectation';

    /** Holds the `toX()` assertions; `Pest\Expectation::__call()` runs them and returns the outer expectation. */
    public const MIXIN_EXPECTATION = 'Pest\Mixins\Expectation';

    /** `->not`: every assertion through it returns the original `Expectation<TValue>`. */
    public const OPPOSITE_EXPECTATION = 'Pest\Expectations\OppositeExpectation';

    /** `->each`: every assertion through it returns the same `EachExpectation<TValue>`. */
    public const EACH_EXPECTATION = 'Pest\Expectations\EachExpectation';

    /** `->someProperty` / `->someMethod()` on an expectation of an object or array. */
    public const HIGHER_ORDER_EXPECTATION = 'Pest\Expectations\HigherOrderExpectation';

    /** What `@param-closure-this` names on `test()` / `it()` / `beforeEach()` / `afterEach()`. */
    public const TEST_CALL = 'Pest\PendingCalls\TestCall';

    /** What `uses()` / `pest()->extend()` return; its `beforeEach()` / `afterEach()` hook closures run on the TestCase. */
    public const USES_CALL = 'Pest\PendingCalls\UsesCall';

    /** `->expect()` / `->and()` on a `TestCall`: Pest replays them through this class's own `expect()`. */
    public const HIGHER_ORDER_CALLABLES = 'Pest\Support\HigherOrderCallables';

    /** `test()` without arguments inside a running test: forwards to the bound TestCase. */
    public const HIGHER_ORDER_TAP_PROXY = 'Pest\Support\HigherOrderTapProxy';
}
