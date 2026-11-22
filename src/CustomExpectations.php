<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Psalm\Codebase;
use Psalm\Internal\Provider\FileProvider;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Storage\FunctionStorage;

/**
 * The expectations a project registers with `expect()->extend('toBePrice', function (...) { ... })`, which a
 * scan of Pest's own classes cannot know. Pest keeps one table for the whole run and fills it while the boot files
 * load, so a name is visible from every file in the tests directory. The analysed file's own registrations count
 * too, after the boot files' ones.
 *
 * The Composer `files` autoloads of the installed packages that require Pest (listed in `<vendor-dir>/composer/installed.json`) count as well: a Pest plugin such as
 * `spatie/pest-plugin-snapshots` registers its expectations there, and they run before any boot file, so a boot
 * file's registration of the same name wins. They are read once per run.
 *
 * Known limit: Pest requires every test file before it runs a test, so on a full run a registration at the top of
 * one test file reaches the others; it is seen only in the file that makes it.
 *
 * Read statically, never executed: a name that is not a string literal is not seen. A registration anywhere in
 * the file counts (inside an `if`, say), since over-accepting a name only silences a report. The closure's own
 * storage, which the scan made, gives the parameters (`null` when the closure is not a literal one).
 */
final class CustomExpectations implements BeforeFileAnalysisInterface
{
    /** @var array<string, array{0: string, 1: array<string, string|null>}> content hash and closure id per name, per file */
    private static array $parsed = [];

    /** @var array<string, list<array{0: string, 1: array<string, string|null>}>> path and names of each Composer `files` autoload that registers expectations, per vendor directory */
    private static array $vendor = [];

    /**
     * Computed once per analysed file: Psalm asks the existence provider on every method lookup on `->not` and `->each`.
     *
     * @var array<string, array{0: array<string, FunctionStorage|null>, 1: array<lowercase-string, true>}> per analysed file
     */
    private static array $visible = [];

    /** A boot file may have changed since the last analysis of this file (language server, `--watch`). */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        unset(self::$visible[$event->getFileStorage()->file_path]);
    }

    /** @return array<string, FunctionStorage|null> the closure's storage per expectation name visible from `$file` */
    public static function visibleFrom(Codebase $codebase, string $file): array
    {
        return self::compute($codebase, $file)[0];
    }

    /** Whether `$file` sees an expectation of this name in any case (the existence provider only gets it lowercased). */
    public static function isVisibleLowercase(Codebase $codebase, string $file, string $nameLowercase): bool
    {
        return isset(self::compute($codebase, $file)[1][$nameLowercase]);
    }

    /** @return array{0: array<string, FunctionStorage|null>, 1: array<lowercase-string, true>} */
    private static function compute(Codebase $codebase, string $file): array
    {
        if (isset(self::$visible[$file])) {
            return self::$visible[$file];
        }

        $files = TestCaseResolver::bootFilesOf(BoundTestCase::testsDir($codebase), $file);
        // A test file runs after every boot file; a boot file keeps its place in Pest's order.
        if (!\in_array($file, $files, true)) {
            $files[] = $file;
        }

        $visible = [];
        foreach (self::vendorRegistrations($codebase) as [$path, $names]) {
            // A package's file is scanned only when the project's autoloader runs it (Psalm queues what that includes).
            $storage = $codebase->file_storage_provider->has($path) ? $codebase->file_storage_provider->get($path) : null;
            foreach ($names as $name => $closureId) {
                $visible[$name] = $closureId === null || !$storage instanceof \Psalm\Storage\FileStorage ? null : ($storage->functions[$closureId] ?? null);
            }
        }

        foreach ($files as $path) {
            // Boot file paths are real paths: in a project reached through a symlink they miss Psalm's storage (as
            // for BootFileFunctionsHandler), and a closure id built from them names no closure (any arguments pass).
            if (!$codebase->file_storage_provider->has($path)) {
                continue;
            }

            $storage = $codebase->file_storage_provider->get($path);
            foreach (self::declaredIn($path, $codebase->file_provider->getContents($path)) as $name => $closureId) {
                // Pest's table keeps the last registration; the file that runs later wins, and a test file runs after the boot files.
                $visible[$name] = $closureId === null ? null : ($storage->functions[$closureId] ?? null);
            }
        }

        $lowercase = [];
        foreach (\array_keys($visible) as $name) {
            $lowercase[\strtolower($name)] = true;
        }

        return self::$visible[$file] = [$visible, $lowercase];
    }

    /**
     * The Composer `files` autoloads of the project's packages that register expectations. Only the packages
     * that require Pest are looked at, and one that does not call `extend()` costs a read and a substring check, no parse.
     *
     * @return list<array{0: string, 1: array<string, string|null>}> real path and names
     */
    private static function vendorRegistrations(Codebase $codebase): array
    {
        $vendorDir = self::vendorDir($codebase->config->base_dir);
        if (isset(self::$vendor[$vendorDir])) {
            return self::$vendor[$vendorDir];
        }

        $registrations = [];
        foreach (self::pestPackageAutoloadFiles($codebase->file_provider, $vendorDir) as $path) {
            // Psalm keys a scanned file by its real path, so a package linked in by a path repository is found by it.
            $real = \realpath($path);
            // Through Psalm's provider: the path derives from COMPOSER_VENDOR_DIR, which taint analysis would flag on file_get_contents().
            $contents = $real === false || !\is_file($real) ? null : $codebase->file_provider->getContents($real);
            if ($real === false || $contents === null || \stripos($contents, 'expect(') === false) {
                continue;
            }

            $names = self::declaredIn($real, $contents);
            if ($names !== []) {
                $registrations[] = [$real, $names];
            }
        }

        return self::$vendor[$vendorDir] = $registrations;
    }

    /**
     * The `files` autoloads of the installed packages that require Pest (`pestphp/*`), from Composer's `installed.json`:
     * `{"packages": [...]}` in Composer 2, a bare list of packages in Composer 1.
     *
     * @return list<string> paths, not yet resolved
     */
    private static function pestPackageAutoloadFiles(FileProvider $files, string $vendorDir): array
    {
        $installed = $vendorDir . \DIRECTORY_SEPARATOR . 'composer' . \DIRECTORY_SEPARATOR . 'installed.json';
        $decoded = \is_file($installed) ? (array) \json_decode($files->getContents($installed), true) : [];

        $paths = [];
        foreach (\array_filter((array) ($decoded['packages'] ?? $decoded), '\is_array') as $package) {
            $strings = \array_filter($package, '\is_string');
            $name = $strings['name'] ?? null;
            $autoload = (array) ($package['autoload'] ?? null);
            $autoloaded = \array_filter((array) ($autoload['files'] ?? null), '\is_string');
            if ($name === null || $autoloaded === []) {
                continue;
            }

            $requiresPest = false;
            foreach (\array_keys((array) ($package['require'] ?? null)) as $required) {
                $requiresPest = $requiresPest || \str_starts_with((string) $required, 'pestphp/');
            }

            if (!$requiresPest) {
                continue;
            }

            // Composer 2 gives the install path relative to `composer/`; Composer 1 installs into `<vendor-dir>/<name>`.
            $installPath = isset($strings['install-path'])
                ? $vendorDir . \DIRECTORY_SEPARATOR . 'composer' . \DIRECTORY_SEPARATOR . $strings['install-path']
                : $vendorDir . \DIRECTORY_SEPARATOR . $name;
            foreach ($autoloaded as $file) {
                $paths[] = $installPath . \DIRECTORY_SEPARATOR . $file;
            }
        }

        return $paths;
    }

    /** Where Composer installs the packages, as Psalm finds it: COMPOSER_VENDOR_DIR, the `vendor-dir` of the project's composer.json, or `vendor`. */
    private static function vendorDir(string $baseDir): string
    {
        $dir = \getenv('COMPOSER_VENDOR_DIR');
        if (!\is_string($dir) || $dir === '') {
            $dir = 'vendor';
            $composerJson = $baseDir . \DIRECTORY_SEPARATOR . 'composer.json';
            $contents = \is_file($composerJson) ? \file_get_contents($composerJson) : false;
            $config = $contents === false ? [] : (array) \json_decode($contents, true);
            $configured = \array_filter((array) ($config['config'] ?? null), '\is_string')['vendor-dir'] ?? '';
            if ($configured !== '') {
                $dir = $configured;
            }
        }

        $absolute = \str_starts_with($dir, '/') || \str_starts_with($dir, '\\') || \preg_match('~^[a-z]:[\\\\/]~i', $dir) === 1;

        return \rtrim($absolute ? $dir : $baseDir . \DIRECTORY_SEPARATOR . $dir, '/\\');
    }

    /** @return array<string, string|null> closure id (null: not a closure literal) per name */
    private static function declaredIn(string $path, string $contents): array
    {
        $hash = \hash('xxh3', $contents);
        if ((self::$parsed[$path][0] ?? null) === $hash) {
            return self::$parsed[$path][1];
        }

        $names = [];
        if (\stripos($contents, 'extend') !== false) {
            try {
                // Names resolved, so `Other\expect()` is not Pest's.
                $statements = (new NodeTraverser(new NameResolver()))->traverse((new ParserFactory())->createForHostVersion()->parse($contents) ?? []);
            } catch (\PhpParser\Error) {
                $statements = [];
            }

            foreach ((new NodeFinder())->findInstanceOf($statements, MethodCall::class) as $call) {
                $given = [];
                foreach ($call->isFirstClassCallable() ? [] : $call->getArgs() as $position => $arg) {
                    $given[$arg->name->name ?? ($position === 0 ? 'name' : 'extend')] = $arg->value;
                }

                $name = $given['name'] ?? null;
                $closure = $given['extend'] ?? null;
                if ($call->name instanceof Identifier && \strtolower($call->name->name) === 'extend'
                    && $call->var instanceof FuncCall && $call->var->name instanceof Name && \strtolower($call->var->name->toString()) === 'expect'
                    // Only a name a call can spell; a numeric one would also turn into an integer key.
                    && $name instanceof String_ && \preg_match('/^[a-z_\x80-\xff][a-z0-9_\x80-\xff]*$/i', $name->value) === 1
                ) {
                    $names[$name->value] = $closure instanceof Closure || $closure instanceof ArrowFunction
                        ? \strtolower($path) . ':' . $closure->getLine() . ':' . (int) $closure->getAttribute('startFilePos') . ':-:closure'
                        : null;
                }
            }
        }

        self::$parsed[$path] = [$hash, $names];

        return $names;
    }

    public static function reset(): void
    {
        self::$parsed = [];
        self::$visible = [];
        self::$vendor = [];
    }
}
