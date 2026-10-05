<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Storage\ClassLikeStorage;

/**
 * The scanned TestCase class Pest binds a test file's closures to, for any handler that needs it
 * outside a bound closure (where the call's context `self` already answers).
 *
 * `null` means "unknown or not scanned": callers keep Pest's own types. Psalm can bind or extend
 * only a class it has storage for, so an unscanned class is never returned.
 */
final class BoundTestCase
{
    public static function forFile(Codebase $codebase, string $filePath): ?ClassLikeStorage
    {
        $testCase = TestCaseResolver::resolve(
            \rtrim($codebase->config->base_dir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . 'tests',
            $filePath,
            $codebase->file_provider->getContents($filePath),
            static fn(string $class): ?bool => self::isClass($codebase, $class),
        );

        return $testCase === null ? null : self::storage($codebase, $testCase);
    }

    /** @psalm-mutation-free */
    public static function storage(Codebase $codebase, string $class): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get($class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }
    }

    /** @psalm-mutation-free */
    private static function isClass(Codebase $codebase, string $class): ?bool
    {
        $storage = self::storage($codebase, $class);
        if (!$storage instanceof ClassLikeStorage) {
            return null;
        }

        return !$storage->is_trait && !$storage->is_interface && !$storage->is_enum;
    }
}
