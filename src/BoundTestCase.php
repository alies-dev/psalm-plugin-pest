<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * The scanned TestCase class Pest binds a test file's closures to, for handlers outside a bound
 * closure. `null` means unknown or not scanned: callers keep Pest's own types (Psalm can bind or
 * extend only a class it has storage for).
 */
final class BoundTestCase
{
    /** `$this` in the file's Pest closures: the bound TestCase intersected with the traits bound next to it. */
    public static function thisType(Codebase $codebase, string $filePath): ?Union
    {
        $binding = self::binding($codebase, $filePath);

        return $binding === null ? null : self::thisTypeOf($codebase, [$binding['class'], ...$binding['traits']]);
    }

    /**
     * One named object of the first scanned class, the scanned traits as intersection types; null
     * when none of the names is a scanned class.
     *
     * @param list<string> $classesAndTraits
     *
     * @psalm-mutation-free
     */
    public static function thisTypeOf(Codebase $codebase, array $classesAndTraits): ?Union
    {
        $class = null;
        $traits = [];
        foreach ($classesAndTraits as $name) {
            $storage = self::storage($codebase, $name);
            if (!$storage instanceof ClassLikeStorage) {
                continue;
            }

            if ($storage->is_trait) {
                $traits[$storage->name] = new TNamedObject($storage->name);
            } elseif ($class === null && !$storage->is_interface && !$storage->is_enum) {
                $class = $storage->name;
            }
        }

        return $class === null ? null : new Union([new TNamedObject($class, extra_types: $traits)]);
    }

    /** @return list<string> the traits bound next to the file's TestCase */
    public static function traits(Codebase $codebase, string $filePath): array
    {
        return self::binding($codebase, $filePath)['traits'] ?? [];
    }

    /**
     * The bound traits' instance properties, which Psalm cannot fetch through a trait in an intersection.
     *
     * @return array<string, Union>
     */
    public static function traitProperties(Codebase $codebase, string $filePath): array
    {
        $properties = [];
        foreach (self::traits($codebase, $filePath) as $trait) {
            foreach (self::storage($codebase, $trait)?->declaring_property_ids ?? [] as $name => $declaring) {
                $property = self::storage($codebase, $declaring)?->properties[$name] ?? null;
                if ($property !== null && !$property->is_static) {
                    $properties[$name] = $property->type ?? Type::getMixed();
                }
            }
        }

        return $properties;
    }

    /**
     * The properties the `Pest.php` hooks declare for the file.
     *
     * @return array<string, Union>
     */
    public static function properties(Codebase $codebase, string $filePath): array
    {
        $properties = [];
        foreach (self::binding($codebase, $filePath)['properties'] ?? [] as $name => $types) {
            $properties[$name] = Type::combineUnionTypeArray(\array_map(Type::parseString(...), $types), $codebase);
        }

        return $properties;
    }

    /** @return array{class: string, traits: list<string>, properties: array<string, non-empty-list<string>>}|null */
    private static function binding(Codebase $codebase, string $filePath): ?array
    {
        return TestCaseResolver::resolve(
            \rtrim($codebase->config->base_dir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . 'tests',
            $filePath,
            $codebase->file_provider->getContents($filePath),
            static function (string $class) use ($codebase): ?bool {
                $storage = self::storage($codebase, $class);

                return $storage instanceof \Psalm\Storage\ClassLikeStorage ? !$storage->is_trait && !$storage->is_interface && !$storage->is_enum : null;
            },
        );
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
}
