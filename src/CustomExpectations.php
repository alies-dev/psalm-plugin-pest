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
use Psalm\Storage\FunctionStorage;

/**
 * The expectations a project registers with `expect()->extend('toBePrice', function (...) { ... })`, which a
 * scan of Pest's own classes cannot know. Pest keeps one table for the whole run and fills it while the boot files
 * load, so a name is visible from every file in the tests directory, and from a test file's own later code.
 *
 * Read statically, never executed: a name that is not a string literal is not seen. A registration anywhere in
 * the file counts (inside an `if`, say), since over-accepting a name only silences a report. The closure's own
 * storage, which the scan made, gives the parameters (`null` when the closure is not a literal one).
 */
final class CustomExpectations
{
    /** @var array<string, array{0: string, 1: array<string, string|null>}> content hash and closure id per name, per file */
    private static array $parsed = [];

    /** @return array<string, FunctionStorage|null> the closure's storage per expectation name visible from `$file` */
    public static function visibleFrom(Codebase $codebase, string $file): array
    {
        $files = [...TestCaseResolver::bootFilesOf(BoundTestCase::testsDir($codebase), $file), $file];
        $visible = [];
        foreach ($files as $path) {
            if (!$codebase->file_storage_provider->has($path)) {
                continue;
            }

            $storage = $codebase->file_storage_provider->get($path);
            foreach (self::declaredIn($path, $codebase->file_provider->getContents($path)) as $name => $closureId) {
                // Pest's table keeps the last registration; the file that runs later wins, and a test file runs after the boot files.
                $visible[$name] = $closureId === null ? null : ($storage->functions[$closureId] ?? null);
            }
        }

        return $visible;
    }

    /** @return array<string, string|null> closure id (null: not a closure literal) per name */
    private static function declaredIn(string $path, string $contents): array
    {
        $hash = \md5($contents);
        if ((self::$parsed[$path][0] ?? null) === $hash) {
            return self::$parsed[$path][1];
        }

        $names = [];
        if (\stripos($contents, 'extend') !== false) {
            try {
                // Names resolved, so `Other\expect()` is not Pest's and `use function Pest\expect` is.
                $statements = (new NodeTraverser(new NameResolver()))->traverse((new ParserFactory())->createForHostVersion()->parse($contents) ?? []);
            } catch (\PhpParser\Error) {
                $statements = [];
            }

            foreach ((new NodeFinder())->findInstanceOf($statements, MethodCall::class) as $call) {
                $args = $call->isFirstClassCallable() ? [] : $call->getArgs();
                $name = ($args[0] ?? null)?->value;
                $closure = ($args[1] ?? null)?->value;
                if ($call->name instanceof Identifier && \strtolower($call->name->name) === 'extend'
                    && $call->var instanceof FuncCall && $call->var->name instanceof Name && \in_array(\strtolower($call->var->name->toString()), ['expect', 'pest\\expect'], true)
                    && $name instanceof String_
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
    }
}
