<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestUnknownCoversTarget;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Codebase\InternalCallMapHandler;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;

/**
 * Reports a string-literal target of `TestCall::covers()` / `coversClass()` / `coversTrait()` /
 * `coversFunction()` that PHPUnit or Pest rejects: an unknown name, a leading backslash, or the wrong kind
 * of symbol. `covers()` makes Pest throw on unknown names; the other three only add a PHPUnit attribute,
 * which PHPUnit validates on coverage runs and reports as a warning, so without coverage a bad target goes
 * unnoticed. `X::class` targets are left to Psalm's `UndefinedClass`.
 */
final class CoversTargetHandler implements AfterMethodCallAnalysisInterface
{
    private const CONSEQUENCE = 'PHPUnit reports it as an invalid code coverage target on coverage runs';

    /** Lowercase method name => [noun phrase for an unknown name, symbol kinds PHPUnit accepts]. */
    private const METHODS = [
        // Pest routes interfaces to coversClass(), which PHPUnit then rejects.
        'covers' => ['class, trait or function', ['class', 'enum', 'trait', 'function']],
        'coversclass' => ['class or enum', ['class', 'enum']],
        'coverstrait' => ['trait', ['trait']],
        'coversfunction' => ['function', ['function']],
    ];

    /** Symbol kind => [noun with article, fix]. */
    private const KINDS = [
        'class' => ['a class', 'use coversClass()'],
        'enum' => ['an enum', 'use coversClass()'],
        'interface' => ['an interface', 'cover the classes that implement it instead'],
        'trait' => ['a trait', 'use coversTrait()'],
        'function' => ['a function', 'use coversFunction()'],
    ];

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $declaring = \strtolower($event->getDeclaringMethodId());
        $prefix = \strtolower(PestApi::TEST_CALL) . '::';
        $method = \str_starts_with($declaring, $prefix) ? \substr($declaring, \strlen($prefix)) : '';
        $analyzer = $event->getStatementsSource();
        if (!isset(self::METHODS[$method]) || !$analyzer instanceof StatementsAnalyzer) {
            return;
        }

        foreach ($event->getExpr()->getArgs() as $arg) {
            // Arrays (accepted by covers() at runtime) are skipped: Psalm already reports InvalidArgument for them.
            $value = $arg->value;
            if (!$value instanceof String_) {
                continue;
            }

            $message = self::problem($event->getCodebase(), $analyzer, $method, $value->value);
            if ($message !== null) {
                IssueBuffer::maybeAdd(
                    new PestUnknownCoversTarget($message, new CodeLocation($analyzer, $value)),
                    $event->getStatementsSource()->getSuppressedIssues(),
                );
            }
        }
    }

    private static function problem(Codebase $codebase, StatementsAnalyzer $analyzer, string $method, string $target): ?string
    {
        [$noun, $accepted] = self::METHODS[$method];
        $name = \ltrim($target, '\\');
        $kind = $name === '' ? null : self::kindOf($codebase, $analyzer, $name, $method === 'coversfunction');

        if ($kind === null || ($kind === 'classlike' && $method === 'coversfunction')) {
            return $method === 'covers'
                ? \sprintf('No %s named "%s" exists; Pest throws "No class, trait or method named [%s] has been found" while loading the file.', $noun, $target, $target)
                : \sprintf('No %s named "%s" exists; %s.', $noun, $target, self::CONSEQUENCE);
        }

        if ($name !== $target) {
            return \sprintf('PHPUnit does not strip the leading backslash in "%s" and reports it as an invalid code coverage target on coverage runs; drop the backslash.', $target);
        }

        if ($kind === 'classlike' || \in_array($kind, $accepted, true)) {
            return null;
        }

        [$article, $fix] = self::KINDS[$kind];

        return \sprintf('"%s" is %s, so %s; %s.', $target, $article, self::CONSEQUENCE, $fix);
    }

    /**
     * Kind of the symbol `$name` names, `'classlike'` when only Composer knows it, or null when nothing does.
     * Pest's covers() prefers a classlike over a function of the same name; coversFunction() wants the function.
     */
    private static function kindOf(Codebase $codebase, StatementsAnalyzer $analyzer, string $name, bool $preferFunction): ?string
    {
        $function = self::functionExists($codebase, $analyzer, $name);
        if ($preferFunction && $function) {
            return 'function';
        }

        return self::classLikeKind($codebase, $name) ?? ($function ? 'function' : null);
    }

    /** A string names a class nothing else referenced, so Psalm may not have scanned it: ask Composer as the scanner would. */
    private static function classLikeKind(Codebase $codebase, string $name): ?string
    {
        if ($codebase->classOrInterfaceOrEnumExists($name) || $codebase->classlikes->traitExists($name)) {
            $storage = $codebase->classlike_storage_provider->has(\strtolower($name))
                ? $codebase->classlike_storage_provider->get($name)
                : null;

            return match (true) {
                $storage === null => 'classlike',
                $storage->is_interface => 'interface',
                $storage->is_trait => 'trait',
                $storage->is_enum => 'enum',
                default => 'class',
            };
        }

        // Composer matches the PSR-4 prefix case-sensitively and the file system decides the rest, so a
        // miscased name nothing else references may be reported on a case-sensitive file system only.
        $file = $codebase->config->getComposerFilePathForClassLike($name);

        return \is_string($file) && \file_exists($file) ? 'classlike' : null;
    }

    /** Like Psalm's call analysis: a function only the callmap or a stub knows (extension not loaded) still exists. */
    private static function functionExists(Codebase $codebase, StatementsAnalyzer $analyzer, string $name): bool
    {
        // The callmap also lists `Class::method`; neither Pest nor PHPUnit accepts those as function targets.
        return !\str_contains($name, '::') && (
            $codebase->functions->functionExists($analyzer, \strtolower($name))
            || InternalCallMapHandler::inCallMap($name)
            || $codebase->functions->hasStubbedFunction($name)
        );
    }
}
