<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestInvalidGroupName;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;

/**
 * Reports `group()` on a test, `uses()`, `pest()` or `describe()` with no name or an empty or
 * whitespace-only string literal: PHPUnit's `Group` attribute, which Pest builds from each name, takes
 * a `non-empty-string`. Names that are not string literals are never inspected.
 *
 * Keyed on the receiver's type rather than the method hook: `describe()->group()` is a magic call,
 * which Psalm does not report to the method hooks.
 */
final class InvalidGroupNameHandler implements AfterExpressionAnalysisInterface
{
    private const RECEIVERS = [PestApi::TEST_CALL, PestApi::USES_CALL, PestApi::CONFIGURATION, PestApi::DESCRIBE_CALL];

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        if (!$call instanceof MethodCall || !$call->name instanceof Identifier || \strtolower($call->name->name) !== 'group' || $call->isFirstClassCallable()) {
            return null;
        }

        $source = $event->getStatementsSource();
        $receiver = $source->getNodeTypeProvider()->getType($call->var);
        if (!$receiver instanceof \Psalm\Type\Union || \array_intersect(self::RECEIVERS, \array_keys($receiver->getAtomicTypes())) === []) {
            return null;
        }

        if ($call->getArgs() === []) {
            self::report($source, new CodeLocation($source, $call), 'group() needs at least one group name.');
        }

        foreach ($call->getArgs() as $arg) {
            if (!$arg->unpack && $arg->value instanceof String_ && \trim($arg->value->value) === '') {
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
