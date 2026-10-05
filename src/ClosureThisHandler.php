<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\FunctionParamsProviderEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Rebinds `$this` in Pest's `test()` / `it()` / `beforeEach()` / `afterEach()` closures to the
 * TestCase Pest actually binds them to at runtime.
 *
 * Pest v4 annotates those closures `@param-closure-this TestCall` (src/Functions.php), which PHPStan
 * resolves through `TestCall`'s `@mixin HigherOrderCallables|TestCase|Testable`. Psalm honors the
 * tag since 6.19 but not that union mixin, so every `$this->...` in a test turns into
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

    /**
     * `FunctionLikeParameter::$closure_this_type` exists on Psalm 6.19+ and on vimeo/psalm master,
     * but not on Psalm 7.0.0-beta22, so it is reached by name (reflection for the write): the
     * handler compiles against both and stays dormant until the installed Psalm carries it.
     */
    private const CLOSURE_THIS_TYPE = 'closure_this_type';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        // No @param-closure-this support in this Psalm: nothing to correct.
        if (!\property_exists(FunctionLikeParameter::class, self::CLOSURE_THIS_TYPE)) {
            return;
        }

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

        $testFile = $source->getFilePath();
        $testCase = TestCaseResolver::resolve(
            \rtrim($codebase->config->base_dir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . 'tests',
            $testFile,
            $codebase->file_provider->getContents($testFile),
            static fn(string $class): ?bool => self::isClass($codebase, $class),
        );

        // ClosureAnalyzer binds only a class it has storage for; otherwise `$this` would be unbound.
        $storage = $testCase === null ? null : self::storage($codebase, $testCase);
        if (!$storage instanceof \Psalm\Storage\ClassLikeStorage) {
            return $params;
        }

        // The class `$this` is bound to now exists for this codebase: let the file's beforeEach()
        // assignments declare their properties on it (see BeforeEachPropertiesHandler).
        BeforeEachPropertiesHandler::register($codebase, $storage->name);

        $param = clone $params[$offset];
        (new \ReflectionProperty(FunctionLikeParameter::class, self::CLOSURE_THIS_TYPE))
            ->setValue($param, new Union([new TNamedObject($storage->name)]));
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
            if (self::isPestTestCall($param->{self::CLOSURE_THIS_TYPE} ?? null)) {
                return $offset;
            }
        }

        return null;
    }

    /** @psalm-pure */
    private static function isPestTestCall(mixed $bound): bool
    {
        return $bound instanceof Union
            && $bound->isSingle()
            && $bound->getSingleAtomic() instanceof TNamedObject
            && $bound->getSingleAtomic()->value === PestApi::TEST_CALL;
    }

    /** @psalm-mutation-free */
    private static function isClass(Codebase $codebase, string $class): ?bool
    {
        $storage = self::storage($codebase, $class);
        if (!$storage instanceof \Psalm\Storage\ClassLikeStorage) {
            return null;
        }

        return !$storage->is_trait && !$storage->is_interface && !$storage->is_enum;
    }

    /** @psalm-mutation-free */
    private static function storage(Codebase $codebase, string $class): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get($class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }
    }
}
