<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use AliesDev\PsalmPluginPest\Issue\PestHookInDescribe;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterEveryFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterEveryFunctionCallAnalysisEvent;

/**
 * Reports `beforeAll()` / `afterAll()` run while a `describe()` closure loads: Pest throws there.
 *
 * Psalm has resolved each call's function by the time it analyses a `describe()` closure, so the hook
 * calls tag themselves when they are Pest's, and the `describe()` call then reports the tagged calls
 * its closure runs at load time: nested in `if` / `foreach`, but not inside a nested closure (a test
 * body, or a nested `describe()`, which gets its own report).
 */
final class DescribeHookHandler implements AfterEveryFunctionCallAnalysisInterface
{
    public const HOOKS = ['beforeall' => ['beforeAll', 'beforeEach'], 'afterall' => ['afterAll', 'afterEach']];

    public const TAG = 'pest-describe-hook';

    #[\Override]
    public static function afterEveryFunctionCallAnalysis(AfterEveryFunctionCallAnalysisEvent $event): void
    {
        $id = $event->getFunctionId();
        $call = $event->getExpr();
        $functions = $event->getCodebase()->functions;
        if (($id !== 'describe' && !isset(self::HOOKS[$id])) || !$functions->hasStubbedFunction($id)) {
            return;
        }

        // Pest's global functions share a file; a project function named like one does not.
        $describe = $functions->getStorage(null, 'describe');
        /** @psalm-suppress ArgumentTypeCoercion the function id is already lowercase */
        $declaring = $functions->getStorage(null, $id);
        if (!isset($describe->signature_return_type?->getAtomicTypes()[PestApi::DESCRIBE_CALL])
            || $declaring->location?->file_path !== $describe->location?->file_path
        ) {
            return;
        }

        if ($id !== 'describe') {
            $call->setAttribute(self::TAG, $id);

            return;
        }

        $source = $event->getStatementsSource();
        foreach ($call->getArgs() as $arg) {
            if (!$arg->value instanceof Node\FunctionLike) {
                continue;
            }

            foreach (self::tagged($arg->value) as [$hook, $id]) {
                [$name, $instead] = self::HOOKS[$id];
                IssueBuffer::maybeAdd(
                    new PestHookInDescribe(
                        "{$name}() inside describe() makes Pest throw while loading the file; use {$instead}() instead.",
                        new CodeLocation($source, $hook),
                    ),
                    $source->getSuppressedIssues(),
                );
            }
        }
    }

    /**
     * The hook calls that run when the closure does: nested closures are skipped.
     *
     * @return list<array{FuncCall, key-of<self::HOOKS>}>
     */
    private static function tagged(Node\FunctionLike $closure): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<array{FuncCall, key-of<self::HOOKS>}> */
            public array $found = [];

            #[\Override]
            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\FunctionLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                /** @psalm-suppress MixedAssignment */
                $id = $node instanceof FuncCall ? $node->getAttribute(DescribeHookHandler::TAG) : null;
                if ($node instanceof FuncCall && \is_string($id) && isset(DescribeHookHandler::HOOKS[$id])) {
                    $this->found[] = [$node, $id];
                }

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse($closure->getStmts() ?? []);

        return $visitor->found;
    }
}
