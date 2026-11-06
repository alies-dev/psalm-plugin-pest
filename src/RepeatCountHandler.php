<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestInvalidRepeatCount;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Reports a count that is below 1 on every path passed to `repeat()` or `flaky()` on a test, a
 * `describe()` or a `beforeEach()`: Pest rejects it with `InvalidArgumentException`, on the test itself or
 * on every test the block or hook is replayed on. `int` and `positive-int` stay silent.
 *
 * Keyed on the receiver's type rather than the method hook: `describe()->repeat()` and
 * `beforeEach()->repeat()` are magic calls, which Psalm does not report to the method hooks, and a hook
 * fires once per receiver alternative, so a `TestCall|Other` receiver would report a count that
 * `Other::repeat()` may accept. Every alternative must be Pest's.
 */
final class RepeatCountHandler implements AfterExpressionAnalysisInterface
{
    private const METHODS = ['repeat', 'flaky'];

    private const RECEIVERS = [PestApi::TEST_CALL, PestApi::DESCRIBE_CALL, PestApi::BEFORE_EACH_CALL];

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        if (!$call instanceof MethodCall || !$call->name instanceof Identifier || $call->isFirstClassCallable()) {
            return null;
        }

        $name = \strtolower($call->name->name);
        if (!\in_array($name, self::METHODS, true)) {
            return null;
        }

        $source = $event->getStatementsSource();
        $nodeTypes = $source->getNodeTypeProvider();
        $receiver = $nodeTypes->getType($call->var);
        if (!$receiver instanceof Union || !self::isPestReceiver($receiver)) {
            return null;
        }

        // Older Pest 4 releases have no flaky(): Psalm reports the missing method itself.
        $param = self::countParam($event->getCodebase(), $name);
        if ($param === null) {
            return null;
        }

        $count = self::countArg($call, $param);
        $type = $count === null ? null : $nodeTypes->getType($count->value);
        if ($count === null || !$type instanceof Union || !self::isBelowOne($type)) {
            return null;
        }

        IssueBuffer::maybeAdd(
            new PestInvalidRepeatCount(
                \sprintf('Pest rejects a count below 1, but %s() receives %s.', $name, $type->getId()),
                new CodeLocation($source, $count->value),
            ),
            $source->getSuppressedIssues(),
        );

        return null;
    }

    /**
     * Name of `TestCall`'s count parameter, read from the stubs so a rename in Pest is picked up; null when
     * the installed Pest has no such method.
     *
     * @param lowercase-string $name
     */
    private static function countParam(Codebase $codebase, string $name): ?string
    {
        return BoundTestCase::storage($codebase, PestApi::TEST_CALL)?->methods[$name]?->params[0]?->name;
    }

    /** The argument bound to the count: the first positional one, or the one named after the parameter. */
    private static function countArg(MethodCall $call, string $param): ?Arg
    {
        foreach ($call->getArgs() as $index => $arg) {
            if (!$arg->unpack && ($arg->name === null ? $index === 0 : $arg->name->name === $param)) {
                return $arg;
            }
        }

        return null;
    }

    private static function isPestReceiver(Union $receiver): bool
    {
        foreach ($receiver->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject || !\in_array($atomic->value, self::RECEIVERS, true)) {
                return false;
            }
        }

        return true;
    }

    private static function isBelowOne(Union $type): bool
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
