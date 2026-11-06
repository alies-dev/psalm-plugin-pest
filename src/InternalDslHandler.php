<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Lifts Pest's class-level `@internal` from the classes that ARE its public test-writing DSL, so
 * `expect($x)->toBeTrue()`, `uses(...)->in(...)` and `test(...)->group(...)` stop reporting
 * `InternalMethod`. Pest marks them `@internal` to keep them out of its BC promise, not to forbid
 * the fluent calls its documentation teaches.
 *
 * Psalm prepends a class's `internal` list onto each of its methods and properties at population,
 * once per population pass (`Populator::populateClassLikeStorage()`), and `MethodCallProhibitionAnalyzer`
 * checks only the member's copy, so the class and every member are cleared. Storage cannot tell a
 * member's own `@internal` from the inherited one, so members Pest marks `@internal` itself (pest v4.7.0
 * and v5.3) are listed in `OWN_INTERNAL_MEMBERS` and left alone.
 */
final class InternalDslHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Return types of the functions in Pest's src/Functions.php and of the fluent calls on them.
     * `Pest\Expectation` itself is not internal; its `toX()` assertions live on the `@mixin`.
     */
    private const DSL_CLASSES = [
        PestApi::MIXIN_EXPECTATION,
        PestApi::OPPOSITE_EXPECTATION,
        PestApi::EACH_EXPECTATION,
        PestApi::HIGHER_ORDER_EXPECTATION,
        PestApi::TEST_CALL,
        'Pest\PendingCalls\BeforeEachCall',
        'Pest\PendingCalls\AfterEachCall',
        'Pest\PendingCalls\DescribeCall',
        'Pest\PendingCalls\UsesCall',
        'Pest\Configuration',
        PestApi::HIGHER_ORDER_TAP_PROXY,
        'Pest\Configuration\Printer',
        'Pest\Configuration\Project',
        'Pest\Plugins\Tia\Configuration',
        'Pest\Arch\Contracts\ArchExpectation',
        'Pest\Arch\PendingArchExpectation',
    ];

    /**
     * Class => members whose own `@internal` stays. Method names are lowercase, property names are not.
     */
    private const OWN_INTERNAL_MEMBERS = [
        'Pest\Arch\Contracts\ArchExpectation' => ['mergeexcludecallbacks', 'excludecallbacks'],
        'Pest\Configuration\Project' => ['assignees', 'issues', 'prs'],
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $storageProvider = $event->getCodebase()->classlike_storage_provider;

        foreach (self::DSL_CLASSES as $class) {
            if (!$storageProvider->has($class)) {
                continue;
            }

            $storage = $storageProvider->get($class);
            $classInternal = $storage->internal;
            if ($classInternal === []) {
                continue;
            }

            $storage->internal = [];

            $kept = self::OWN_INTERNAL_MEMBERS[$class] ?? [];

            // Methods and properties are keyed by name and may share one, so no merged loop.
            foreach ($storage->methods as $name => $method) {
                if (!\in_array($name, $kept, true)) {
                    $method->internal = \array_values(\array_diff($method->internal, $classInternal));
                }
            }

            foreach ($storage->properties as $name => $property) {
                if (!\in_array($name, $kept, true)) {
                    $property->internal = \array_values(\array_diff($property->internal, $classInternal));
                }
            }
        }
    }
}
