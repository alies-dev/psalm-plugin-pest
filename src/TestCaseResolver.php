<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

/**
 * Which TestCase class Pest binds a test file's closures to, from the file's own `uses()` /
 * `pest()->extend()` calls plus every file `Pest\Bootstrappers\BootFiles::boot()` loads, following
 * `Pest\Repositories\TestRepository::make()`: a file target matches by equality, a directory target
 * by path prefix, traits never decide the class, and two classes for one file are a runtime
 * `TestCaseAlreadyInUse`. The `beforeEach()` properties of every matching entry are merged.
 *
 * `null` means "unknown": the handler keeps Pest's own `@param-closure-this TestCall`. PHPUnit's
 * default is only claimed inside the test directory (a monorepo package may have its own `Pest.php`).
 *
 * @psalm-import-type PestUsesEntry from UsesParser
 * @psalm-type TestCaseBinding = array{class: string, traits: list<string>, properties: array<string, non-empty-list<string>>}
 */
final class TestCaseResolver
{
    public const DEFAULT_TEST_CASE = 'PHPUnit\Framework\TestCase';

    /** `Pest\Bootstrappers\BootFiles::STRUCTURE` (pest v4.7.0): files, or directories loaded recursively. */
    private const BOOT_FILES = ['Expectations', 'Expectations.php', 'Helpers', 'Helpers.php', 'Pest.php'];

    /** `Pest\Support\DatasetInfo`: files with this name, and files under a directory with this name. */
    private const DATASETS = 'Datasets';

    /** @var array<string, array{0: list<PestUsesEntry>|null}> boot files per test directory; wrapped so a null is cached too */
    private static array $configs = [];

    /** @var array<string, array{0: ?TestCaseBinding}> */
    private static array $resolved = [];

    /**
     * @param \Closure(string): ?bool $isClass true for a class, false for a trait or interface, null when unknown
     * @return TestCaseBinding|null
     */
    public static function resolve(string $testsDir, string $testFile, string $testContents, \Closure $isClass): ?array
    {
        return (self::$resolved[$testFile] ??= [self::doResolve($testsDir, $testFile, $testContents, $isClass)])[0];
    }

    /**
     * @param \Closure(string): ?bool $isClass
     * @return TestCaseBinding|null
     */
    private static function doResolve(string $testsDir, string $testFile, string $testContents, \Closure $isClass): ?array
    {
        $inFile = UsesParser::parse($testFile, $testContents, bootFile: false);
        if ($inFile === null) {
            return null;
        }

        $config = (self::$configs[$testsDir] ??= [self::loadConfig($testsDir)])[0];
        $realTestFile = UsesParser::realpath($testFile);

        $candidates = [];
        $traits = [];
        $properties = [];
        foreach ([...$inFile, ...$config ?? []] as $entry) {
            if (!self::targets($entry['targets'], $realTestFile)) {
                continue;
            }

            $properties = \array_merge_recursive($properties, $entry['properties']);
            foreach ($entry['classes'] as $class) {
                $kind = $isClass($class);
                if ($kind === null) {
                    return null;
                }

                if ($kind) {
                    $candidates[\strtolower($class)] = $class;
                } else {
                    $traits[\strtolower($class)] = $class;
                }
            }
        }

        // Without readable boot files a directory-wide TestCase may exist that was not seen.
        $class = $candidates === []
            ? ($config !== null && self::targets([UsesParser::realpath($testsDir)], $realTestFile) ? self::DEFAULT_TEST_CASE : null)
            : (\count($candidates) === 1 ? \reset($candidates) : null);

        return $class === null ? null : ['class' => $class, 'traits' => \array_values($traits), 'properties' => $properties];
    }

    /** @return list<PestUsesEntry>|null */
    private static function loadConfig(string $testsDir): ?array
    {
        $realTestsDir = \realpath($testsDir);
        $files = $realTestsDir !== false && \is_file($realTestsDir . \DIRECTORY_SEPARATOR . 'Pest.php') ? self::bootFiles($realTestsDir) : null;
        if ($files === null) {
            return null;
        }

        $entries = [];
        foreach ($files as $file) {
            // PHP runs a symlinked file from its target, so `__DIR__`, `in()` and Pest's Pest.php
            // detection all resolve elsewhere: not modeled.
            $contents = \realpath($file) === $file ? \file_get_contents($file) : false;
            $parsed = $contents === false ? null : UsesParser::parse($file, $contents);
            if ($parsed === null) {
                return null;
            }

            \array_push($entries, ...$parsed);
        }

        return $entries;
    }

    /** @return list<string>|null null when the tree cannot be walked */
    private static function bootFiles(string $testsDir): ?array
    {
        $files = [];

        try {
            foreach (self::BOOT_FILES as $name) {
                $path = $testsDir . \DIRECTORY_SEPARATOR . $name;
                if (\is_file($path) || \is_link($path)) {
                    $files[] = $path;
                } elseif (\is_dir($path)) {
                    // BootFiles::boot(): RecursiveDirectoryIterator without FOLLOW_SYMLINKS.
                    \array_push($files, ...self::phpFiles(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)));
                }
            }

            // BootFiles::bootDatasets(): PHPUnit's file iterator (symlinks followed, files in hidden
            // directories skipped), keeping every `Datasets.php` and every file below `Datasets/`.
            $datasets = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::FOLLOW_SYMLINKS | \FilesystemIterator::SKIP_DOTS),
                static fn(mixed $entry): bool => !$entry instanceof \SplFileInfo
                    || !$entry->isDir()
                    || !\str_starts_with($entry->getFilename(), '.'),
            );
            foreach (self::phpFiles($datasets) as $file) {
                $relativeDirs = \explode(\DIRECTORY_SEPARATOR, \dirname(\substr($file, \strlen($testsDir) + 1)));
                if (\basename($file) === self::DATASETS . '.php' || \in_array(self::DATASETS, $relativeDirs, true)) {
                    $files[] = $file;
                }
            }
        } catch (\UnexpectedValueException) {
            // Unreadable directory or a symlink cycle.
            return null;
        }

        return $files;
    }

    /**
     * @param \RecursiveIterator<array-key, \SplFileInfo|string> $directory
     * @return list<string>
     */
    private static function phpFiles(\RecursiveIterator $directory): array
    {
        $files = [];
        /** @psalm-var \SplFileInfo|string $file */
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (\str_ends_with((string) $file, '.php')) {
                $files[] = (string) $file;
            }
        }

        return $files;
    }

    /**
     * @param list<string> $targets
     *
     * @psalm-pure
     */
    private static function targets(array $targets, string $file): bool
    {
        foreach ($targets as $target) {
            if ($target === $file || \str_starts_with($file, \rtrim($target, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    public static function reset(): void
    {
        self::$configs = [];
        self::$resolved = [];
        // The answers rest on realpath() / is_file(); a new run must see the current tree.
        \clearstatcache(true);
    }
}
