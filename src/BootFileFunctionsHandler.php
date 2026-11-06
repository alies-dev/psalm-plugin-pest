<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;

/**
 * Makes the functions declared in Pest's boot files (`tests/Pest.php`, `Helpers.php`, `Helpers/`,
 * `Expectations*`, datasets, and the files they require) callable from every file in the tests
 * directory: Pest `include_once`s them all before any test runs, but Psalm resolves a function from
 * the analysed file's own `declaring_function_ids`, which only lists what the file itself
 * includes. The ids point at the declaring file, so the declared return types come along; a file
 * outside the tests directory keeps reporting `UndefinedFunction`.
 *
 * Registered before {@see BeforeEachPropertiesHandler}, which asks the same map whether a file
 * shadows Pest's `beforeEach()`.
 */
final class BootFileFunctionsHandler implements BeforeFileAnalysisInterface
{
    /**
     * @var array<string, array<string, string>> function id => declaring file the handler injected, per analysed file.
     * A file's storage outlives an edit of Pest.php when a language server or `--watch` reanalyses
     * in one process, so the previous injection is withdrawn before the current one is made.
     */
    private static array $injected = [];

    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $codebase = $event->getCodebase();
        // Gated like the other handlers: only a project that scans Pest's own functions.
        if (!$codebase->functions->hasStubbedFunction('test')) {
            return;
        }

        $storage = $event->getFileStorage();
        foreach (self::$injected[$storage->file_path] ?? [] as $functionId => $declaringFile) {
            // The file may declare the function itself since; only the entry this handler added goes.
            if (($storage->declaring_function_ids[$functionId] ?? null) === $declaringFile) {
                unset($storage->declaring_function_ids[$functionId]);
            }
        }

        unset(self::$injected[$storage->file_path]);

        foreach (TestCaseResolver::bootFilesOf(BoundTestCase::testsDir($codebase), $storage->file_path) as $bootFile) {
            // A boot file that was not scanned (outside `projectFiles`) has nothing to offer.
            if (!$codebase->file_storage_provider->has($bootFile)) {
                continue;
            }

            // The file's own declarations win, and the first of two boot files declaring one function.
            foreach ($codebase->file_storage_provider->get($bootFile)->declaring_function_ids as $functionId => $declaringFile) {
                // What this handler injected into the boot file is not its own: copying it on would
                // outlive the sibling's removal. Neither is an id that Psalm's Populator copied into a
                // retained file (a class the boot file references) before a rename: the declaring
                // file must still declare the function.
                if ((self::$injected[$bootFile][$functionId] ?? null) === $declaringFile
                    || !$codebase->file_storage_provider->has($declaringFile)
                    || !isset($codebase->file_storage_provider->get($declaringFile)->functions[$functionId])
                ) {
                    continue;
                }

                if (!isset($storage->declaring_function_ids[$functionId])) {
                    $storage->declaring_function_ids[$functionId] = $declaringFile;
                    self::$injected[$storage->file_path][$functionId] = $declaringFile;
                }
            }
        }
    }

    public static function reset(): void
    {
        self::$injected = [];
    }
}
