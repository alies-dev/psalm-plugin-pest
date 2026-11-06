<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestInvalidRepeatCount;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Reports a count that is below 1 on every path passed to `repeat()` or `flaky()` on a test or a
 * `describe()` (Pest replays it on each test inside): Pest throws `InvalidArgumentException` for it while
 * loading the file. `int` and `positive-int` stay silent.
 *
 * Keyed on the receiver's type rather than the method hook: `describe()->repeat()` is a magic call, which
 * Psalm does not report to the method hooks, and a hook fires once per receiver alternative, so a
 * `TestCall|Other` receiver would report a count that `Other::repeat()` may accept. Every alternative
 * must be Pest's.
 */
final class RepeatCountHandler implements AfterExpressionAnalysisInterface
{
    /** Method id => parameter name. */
    private const METHODS = ['repeat' => 'times', 'flaky' => 'tries'];

    private const RECEIVERS = [PestApi::TEST_CALL, PestApi::DESCRIBE_CALL];

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        if (!$call instanceof MethodCall || !$call->name instanceof Identifier || $call->isFirstClassCallable()) {
            return null;
        }

        $name = \strtolower($call->name->name);
        $param = self::METHODS[$name] ?? null;
        $source = $event->getStatementsSource();
        $nodeTypes = $source->getNodeTypeProvider();
        $receiver = $param === null ? null : $nodeTypes->getType($call->var);
        if (!$receiver instanceof \Psalm\Type\Union || !self::isPestReceiver($receiver)) {
            return null;
        }

        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack || ($arg->name === null ? $index !== 0 : $arg->name->name !== $param)) {
                continue;
            }

            $type = $nodeTypes->getType($arg->value);
            if (!$type instanceof \Psalm\Type\Union || !self::isBelowOne($type)) {
                return null;
            }

            IssueBuffer::maybeAdd(
                new PestInvalidRepeatCount(
                    \sprintf('Pest throws while loading the file when %s() receives %s: the count must be greater than 0.', $name, $type->getId()),
                    new CodeLocation($source, $arg->value),
                ),
                $source->getSuppressedIssues(),
            );

            return null;
        }

        return null;
    }

    private static function isPestReceiver(\Psalm\Type\Union $receiver): bool
    {
        foreach ($receiver->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject || !\in_array($atomic->value, self::RECEIVERS, true)) {
                return false;
            }
        }

        return true;
    }

    private static function isBelowOne(\Psalm\Type\Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            $isBelowOne = match (true) {
                $atomic instanceof TLiteralInt => $atomic->value < 1,
                $atomic instanceof TIntRange => $atomic->max_bound !== null && $atomic->max_bound < 1,
                default => false,
            };
            if (!$isBelowOne) {
                return false;
            }
        }

        return true;
    }
}
