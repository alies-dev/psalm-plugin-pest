<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestInvalidGroupName;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;

/**
 * Reports `group()` on a test, `uses()`, `pest()`, `describe()` or `beforeEach()` with no name, or with
 * a name whose type is a single empty or whitespace-only literal string. Pest turns each name into
 * PHPUnit's `Group` attribute, which declares a `non-empty-string` but does not check it at runtime, so
 * a blank name silently creates a group no `--group` filter can select. With no names the call adds no
 * group at all. Names typed as a plain `string` are never reported.
 *
 * Keyed on the receiver's type rather than the method hook: `describe()->group()` and
 * `beforeEach()->group()` are magic calls, which Psalm does not report to the method hooks.
 */
final class InvalidGroupNameHandler implements AfterExpressionAnalysisInterface
{
    private const RECEIVERS = [PestApi::TEST_CALL, PestApi::USES_CALL, PestApi::CONFIGURATION, PestApi::DESCRIBE_CALL, PestApi::BEFORE_EACH_CALL];

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        if (!$call instanceof MethodCall || !$call->name instanceof Identifier || \strtolower($call->name->name) !== 'group' || $call->isFirstClassCallable()) {
            return null;
        }

        $source = $event->getStatementsSource();
        $types = $source->getNodeTypeProvider();
        $receiver = $types->getType($call->var);
        if (!$receiver instanceof \Psalm\Type\Union || \array_intersect(self::RECEIVERS, \array_keys($receiver->getAtomicTypes())) === []) {
            return null;
        }

        if ($call->getArgs() === []) {
            self::report($source, new CodeLocation($source, $call), 'group() needs at least one group name.');
        }

        foreach ($call->getArgs() as $arg) {
            $name = $types->getType($arg->value);
            if ($name instanceof \Psalm\Type\Union && $name->isSingleStringLiteral() && \trim($name->getSingleStringLiteral()->value) === '') {
                self::report($source, new CodeLocation($source, $arg->value), 'A group name must not be empty or whitespace only.');
            }
        }

        return null;
    }

    private static function report(\Psalm\StatementsSource $source, CodeLocation $location, string $message): void
    {
        IssueBuffer::maybeAdd(new PestInvalidGroupName($message, $location), $source->getSuppressedIssues());
    }
}
