<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\FunctionParamsProviderEvent;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Rebinds `$this` in Pest's `test()` / `it()` / `beforeEach()` / `afterEach()` closures to the
 * TestCase Pest actually binds them to at runtime.
 *
 * Pest annotates those closures `@param-closure-this TestCall` (src/Functions.php), which PHPStan
 * resolves through `TestCall`'s `@mixin HigherOrderCallables|TestCase|Testable`. Psalm honors the
 * tag but not that union mixin, so every `$this->...` in a test turns into
 * `UndefinedThisPropertyFetch` / `UndefinedMethod` on `TestCall` plus a Mixed* cascade. At runtime
 * `$this` is a generated subclass of the TestCase configured via `uses()` / `pest()->extend()`
 * ({@see TestCaseResolver}).
 *
 * A handler, not a stub: the class differs per test file. It is a params provider (not a storage
 * swap) so each call site gets its own answer with no shared state to restore; Psalm reads
 * `closure_this_type` from the resolved params list (`ArgumentsAnalyzer::applyParamClosureThisHint()`).
 * Registered at AfterCodebasePopulated only when the scanned function carries Pest's own
 * `TestCall` tag, so a project's unrelated global `test()` is never touched.
 */
final class ClosureThisHandler implements AfterCodebasePopulatedInterface
{
    private const FUNCTIONS = ['test', 'it', 'beforeeach', 'aftereach'];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $functions = $event->getCodebase()->functions;
        foreach (self::FUNCTIONS as $functionId) {
            if ($functions->hasStubbedFunction($functionId)
                && self::pestClosureOffset($functions->getStorage(null, $functionId)->params) !== null
            ) {
                $functions->params_provider->registerClosure(
                    $functionId,
                    static fn(FunctionParamsProviderEvent $event): array => self::getFunctionParams($event, $functionId),
                );
            }
        }
    }

    /**
     * Declining returns the unchanged storage params, never null: Psalm assigns a provider's null
     * straight to the call's params and would skip argument checking entirely.
     *
     * @param non-empty-lowercase-string $functionId
     * @return array<int, FunctionLikeParameter>
     */
    public static function getFunctionParams(FunctionParamsProviderEvent $event, string $functionId): array
    {
        $source = $event->getStatementsSource();
        $codebase = $source->getCodebase();
        $params = $codebase->functions->getStorage(null, $functionId)->params;

        $offset = self::pestClosureOffset($params);
        if ($offset === null) {
            return $params;
        }

        $storage = BoundTestCase::forFile($codebase, $source->getFilePath());
        if (!$storage instanceof \Psalm\Storage\ClassLikeStorage) {
            return $params;
        }

        // The class `$this` is bound to now exists for this codebase: let the file's beforeEach()
        // assignments declare their properties on it (see BeforeEachPropertiesHandler).
        BeforeEachPropertiesHandler::register($codebase, $storage->name);

        $param = clone $params[$offset];
        $param->closure_this_type = new Union([new TNamedObject($storage->name)]);
        $params[$offset] = $param;

        return $params;
    }

    /**
     * @param array<int, FunctionLikeParameter> $params
     *
     * @psalm-mutation-free
     */
    private static function pestClosureOffset(array $params): ?int
    {
        foreach ($params as $offset => $param) {
            if ($param->closure_this_type?->getId() === PestApi::TEST_CALL) {
                return $offset;
            }
        }

        return null;
    }
}
