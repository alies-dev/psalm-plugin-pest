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
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $codebase = $event->getCodebase();
        // Gated like the other handlers: only a project that scans Pest's own functions.
        if (!$codebase->functions->hasStubbedFunction('test')) {
            return;
        }

        $storage = $event->getFileStorage();
        foreach (TestCaseResolver::bootFilesOf(BoundTestCase::testsDir($codebase), $storage->file_path) as $bootFile) {
            // A boot file that was not scanned (outside `projectFiles`) has nothing to offer.
            if (!$codebase->file_storage_provider->has($bootFile)) {
                continue;
            }

            // The file's own declarations win, and `+` keeps the first of two boot files declaring one function.
            $storage->declaring_function_ids += $codebase->file_storage_provider->get($bootFile)->declaring_function_ids;
        }
    }
}
