<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestInvalidRepeatCount;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TLiteralInt;

/**
 * Reports a count that is below 1 on every path passed to `TestCall::repeat()` or `::flaky()`: Pest throws
 * `InvalidArgumentException` for it while loading the file. `int` and `positive-int` stay silent.
 */
final class RepeatCountHandler implements AfterMethodCallAnalysisInterface
{
    /** Declaring method id (lowercase) => [method as written, parameter name]. */
    private const METHODS = [
        'pest\pendingcalls\testcall::repeat' => ['repeat', 'times'],
        'pest\pendingcalls\testcall::flaky' => ['flaky', 'tries'],
    ];

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $method = self::METHODS[\strtolower($event->getDeclaringMethodId())] ?? null;
        if ($method === null) {
            return;
        }

        [$name, $param] = $method;
        $source = $event->getStatementsSource();
        foreach ($event->getExpr()->getArgs() as $index => $arg) {
            if ($arg->unpack || ($arg->name === null ? $index !== 0 : $arg->name->name !== $param)) {
                continue;
            }

            $type = $source->getNodeTypeProvider()->getType($arg->value);
            if (!$type instanceof \Psalm\Type\Union) {
                return;
            }

            foreach ($type->getAtomicTypes() as $atomic) {
                $isBelowOne = match (true) {
                    $atomic instanceof TLiteralInt => $atomic->value < 1,
                    $atomic instanceof TIntRange => $atomic->max_bound !== null && $atomic->max_bound < 1,
                    default => false,
                };
                if (!$isBelowOne) {
                    return;
                }
            }

            IssueBuffer::maybeAdd(
                new PestInvalidRepeatCount(
                    \sprintf('Pest throws while loading the file when %s() receives %s: the count must be greater than 0.', $name, $type->getId()),
                    new CodeLocation($source, $arg->value),
                ),
                $source->getSuppressedIssues(),
            );

            return;
        }
    }
}
