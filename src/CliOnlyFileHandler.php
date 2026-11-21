<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;

/**
 * Marks the files of Pest's tests directory CLI-only, as psalm/plugin-phpunit does for a TestCase class.
 * With the TestCase it marks `cli_only` typing `$this` in a test closure, a call such as
 * `$this->blade()` would otherwise be reported as `CliOnlyCall`: the closure is plain top-level code
 * of a file Psalm takes to run anywhere. Pest only runs from the command line. The file's functions
 * and classes are not affected, only its top-level code and the closures in it.
 *
 * Gated like the other handlers: only a project that scans Pest's own functions, and only the tests
 * directory, so `src/` keeps its `CliOnlyCall` reports.
 */
final class CliOnlyFileHandler implements BeforeFileAnalysisInterface
{
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $codebase = $event->getCodebase();
        if (!$codebase->functions->hasStubbedFunction('test')) {
            return;
        }

        if (TestCaseResolver::inTestsDir(BoundTestCase::testsDir($codebase), $event->getFileStorage()->file_path)) {
            $event->markFileAsCliOnly();
        }
    }
}
