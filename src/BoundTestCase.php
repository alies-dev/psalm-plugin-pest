<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
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
    /** @var array<string, array{ClassLikeStorage, lowercase-string, ?MethodIdentifier, ?MethodIdentifier}> what {@see self::reinstate()} replaced, by `Class::method` */
    private static array $exposed = [];

    /** @var array<string, list<string>> the traits exposed on each TestCase now */
    private static array $bound = [];

    /** `$this` in the file's Pest closures: the bound TestCase, never an intersection (Psalm reports a trait in one as an undefined class). */
    public static function thisType(Codebase $codebase, string $filePath): ?Union
    {
        $binding = self::binding($codebase, $filePath);

        return $binding === null ? null : self::thisTypeOf($codebase, [$binding['class']]);
    }

    /**
     * One named object of the first scanned class among the names; null when none is.
     *
     * @param list<string> $classesAndTraits
     *
     * @psalm-mutation-free
     */
    public static function thisTypeOf(Codebase $codebase, array $classesAndTraits): ?Union
    {
        foreach ($classesAndTraits as $name) {
            $storage = self::storage($codebase, $name);
            if ($storage instanceof ClassLikeStorage && !$storage->is_trait && !$storage->is_interface && !$storage->is_enum) {
                return new Union([new TNamedObject($storage->name)]);
            }
        }

        return null;
    }

    /**
     * The scanned traits among the names.
     *
     * @param list<string> $classesAndTraits
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    public static function traitsOf(Codebase $codebase, array $classesAndTraits): array
    {
        $traits = [];
        foreach ($classesAndTraits as $name) {
            $storage = self::storage($codebase, $name);
            if ($storage instanceof ClassLikeStorage && $storage->is_trait) {
                $traits[] = $storage->name;
            }
        }

        return $traits;
    }

    /**
     * Makes the traits' methods members of `$class`, as in the TestCase Pest generates (a trait wins
     * over an inherited method, not over an abstract one). Psalm resolves a call from the class's
     * storage alone: a method provider cannot add a method to a class without `__call`, and a trait
     * in an intersection type is reported as an undefined class. The traits replace the ones
     * exposed on `$class` before; {@see self::reinstate()} undoes them when the file ends.
     *
     * @param list<string> $traits
     */
    public static function expose(Codebase $codebase, string $class, array $traits): void
    {
        self::reinstate($codebase, [$class => $traits] + self::$bound);
    }

    /** @return array<string, list<string>> the traits exposed on each TestCase now */
    public static function bound(): array
    {
        return self::$bound;
    }

    /**
     * Exposes exactly `$bound` (an earlier {@see self::bound()}, or none to undo everything).
     *
     * @param array<string, list<string>> $bound
     */
    public static function reinstate(Codebase $codebase, array $bound): void
    {
        if ($bound === self::$bound) {
            return;
        }

        foreach (self::$exposed as [$storage, $name, $declaring, $appearing]) {
            unset($storage->declaring_method_ids[$name], $storage->appearing_method_ids[$name]);
            if ($declaring !== null && $appearing !== null) {
                $storage->declaring_method_ids[$name] = $declaring;
                $storage->appearing_method_ids[$name] = $appearing;
            }
        }

        self::$exposed = [];
        self::$bound = $bound;
        foreach ($bound as $class => $traits) {
            $storage = self::storage($codebase, $class);
            if (!$storage instanceof \Psalm\Storage\ClassLikeStorage) {
                continue;
            }

            foreach ($traits as $trait) {
                foreach (self::storage($codebase, $trait)?->declaring_method_ids ?? [] as $name => $declaring) {
                    // `$name` can be a trait alias (`use Inner { original as renamed; }`): the method is stored under its own name.
                    $method = self::storage($codebase, $declaring->fq_class_name)?->methods[$declaring->method_name] ?? null;
                    if ($method?->abstract !== false) {
                        continue;
                    }

                    self::$exposed[$storage->name . '::' . $name] ??= [$storage, $name, $storage->declaring_method_ids[$name] ?? null, $storage->appearing_method_ids[$name] ?? null];
                    $storage->declaring_method_ids[$name] = $declaring;
                    $storage->appearing_method_ids[$name] = new MethodIdentifier($storage->name, $name);
                }
            }
        }
    }

    /** @return list<string> the traits bound next to the file's TestCase */
    public static function traits(Codebase $codebase, string $filePath): array
    {
        return self::binding($codebase, $filePath)['traits'] ?? [];
    }

    /**
     * The bound traits' instance properties, which the property providers answer for.
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
