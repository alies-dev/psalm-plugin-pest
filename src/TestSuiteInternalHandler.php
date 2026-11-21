<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Codebase;
use Psalm\Issue\InternalClass;
use Psalm\Issue\InternalMethod;
use Psalm\Issue\InternalProperty;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;

/**
 * Lets Pest test files use the classes of the test suite itself.
 *
 * psalm/plugin-phpunit marks every class under the `phpunit.xml` test suites `@internal` to the root namespaces of
 * the test classes, so the users of a library cannot depend on its tests. A Pest test file usually has no namespace,
 * so each use of a test helper from it is "called from root namespace" and reported `InternalClass`.
 *
 * The issue is dropped instead of the marking undone because the marking is neither ours nor ordered against us:
 * plugin-phpunit's `afterCodebasePopulated` overwrites `$storage->internal`, and which plugin Psalm initialises first
 * is not something to depend on. Dropping the issue also works whatever list plugin-phpunit chose, and covers
 * methods and properties (marked too when a population pass runs again, as in a language server).
 *
 * Only a use from a file of the test suites of a class of the test suites is dropped. A class of `src/` that carries
 * `@internal` is still reported, and so is a test class used from `src/`. An `@internal` written on a test-suite class
 * is indistinguishable from plugin-phpunit's marking and is not enforced for the tests.
 */
final class TestSuiteInternalHandler implements BeforeAddIssueInterface
{
    /** @var array<string, list<string>> real paths of the test suites by Psalm's base directory */
    private static array $suitePaths = [];

    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        $issue = $event->getIssue();

        $class = match (true) {
            $issue instanceof InternalClass => $issue->fq_classlike_name,
            // "class::method" in lowercase, of the declaring class
            $issue instanceof InternalMethod => \strstr($issue->method_id, '::', true),
            // "Class::$property"
            $issue instanceof InternalProperty => \strstr($issue->property_id, '::', true),
            default => null,
        };
        if ($class === null || $class === false) {
            return null;
        }

        $codebase = $event->getCodebase();
        $paths = self::suitePaths($codebase);
        if ($paths === [] || !self::isInSuites($issue->getFilePath(), $paths)) {
            return null;
        }

        $storage = BoundTestCase::storage($codebase, $class);
        if ($storage?->location === null || !self::isInSuites($storage->location->file_path, $paths)) {
            return null;
        }

        return false;
    }

    public static function reset(): void
    {
        self::$suitePaths = [];
    }

    /**
     * The directories and files of the test suites in the PHPUnit configuration PHPUnit would use, as psalm/plugin-phpunit
     * reads them.
     *
     * @return list<string>
     */
    private static function suitePaths(Codebase $codebase): array
    {
        $baseDir = $codebase->config->base_dir;

        return self::$suitePaths[$baseDir] ??= self::readSuitePaths($baseDir);
    }

    /** @return list<string> */
    private static function readSuitePaths(string $baseDir): array
    {
        $config = false;
        foreach (['phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist'] as $fileName) {
            $configPath = $baseDir . \DIRECTORY_SEPARATOR . $fileName;
            if (\is_file($configPath)) {
                $config = @\simplexml_load_file($configPath);
                break;
            }
        }

        if ($config === false) {
            return [];
        }

        $nodes = $config->xpath('/phpunit/testsuites/testsuite/directory|/phpunit/testsuites/testsuite/file');
        if (!\is_array($nodes)) {
            return [];
        }

        $paths = [];
        foreach ($nodes as $node) {
            $path = \trim((string) $node);
            if ($path === '') {
                continue;
            }

            if ($path[0] !== '/' && \preg_match('~^[A-Za-z]:[\\\\/]~', $path) !== 1) {
                $path = $baseDir . \DIRECTORY_SEPARATOR . $path;
            }

            // PHPUnit expands wildcards in the paths of test suites
            $matches = \glob($path, \GLOB_NOSORT);
            foreach ($matches === false ? [] : $matches as $match) {
                $realPath = \realpath($match);
                if ($realPath !== false) {
                    $paths[] = $realPath;
                }
            }
        }

        return $paths;
    }

    /** @param list<string> $paths */
    private static function isInSuites(string $filePath, array $paths): bool
    {
        $realPath = \realpath($filePath);
        if ($realPath !== false) {
            $filePath = $realPath;
        }

        foreach ($paths as $path) {
            if ($filePath === $path || \str_starts_with($filePath, $path . \DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
