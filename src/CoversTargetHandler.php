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
 * `coversFunction()` that names nothing. `covers()` makes Pest throw; the other three silently add a
 * PHPUnit attribute, so a typo goes unnoticed. `X::class` targets are left to Psalm's `UndefinedClass`.
 */
final class CoversTargetHandler implements AfterMethodCallAnalysisInterface
{
    /** Declaring method id => [classlike allowed, function allowed]. */
    private const METHODS = [
        'covers' => [true, true],
        'coversclass' => [true, false],
        'coverstrait' => [true, false],
        'coversfunction' => [false, true],
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

        [$classlike, $function] = self::METHODS[$method];
        $codebase = $event->getCodebase();
        foreach ($event->getExpr()->getArgs() as $arg) {
            $value = $arg->value;
            if (!$value instanceof String_) {
                continue;
            }

            $name = \ltrim($value->value, '\\');
            if ($name !== ''
                && (($classlike && self::classLikeExists($codebase, $name)) || ($function && self::functionExists($codebase, $analyzer, $name)))
            ) {
                continue;
            }

            IssueBuffer::maybeAdd(
                new PestUnknownCoversTarget(
                    \sprintf('No %s named "%s" exists.', $classlike && $function ? 'class, trait or function' : ($classlike ? 'class or trait' : 'function'), $value->value),
                    new CodeLocation($analyzer, $value),
                ),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }
    }

    /** A string names a class nothing else referenced, so Psalm has not scanned it: ask Composer as the scanner would. */
    private static function classLikeExists(Codebase $codebase, string $name): bool
    {
        if ($codebase->classOrInterfaceOrEnumExists($name) || $codebase->classlikes->traitExists($name)) {
            return true;
        }

        $file = $codebase->config->getComposerFilePathForClassLike($name);

        return \is_string($file) && \file_exists($file);
    }

    /** Like Psalm's call analysis: a function only the callmap or a stub knows (extension not loaded) still exists. */
    private static function functionExists(Codebase $codebase, StatementsAnalyzer $analyzer, string $name): bool
    {
        // The callmap also lists `Class::method`; Pest rejects those as covers targets.
        return !\str_contains($name, '::') && (
            $codebase->functions->functionExists($analyzer, \strtolower($name))
            || InternalCallMapHandler::inCallMap($name)
            || $codebase->functions->hasStubbedFunction($name)
        );
    }
}
