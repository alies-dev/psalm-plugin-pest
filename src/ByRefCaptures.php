<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Psalm\Internal\Analyzer\ClosureAnalyzer;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\StatementsSource;

/**
 * The variables a closure (a Pest test, hook or `describe()` body) has bound by reference, or has a closure
 * directly inside it bind by reference: `Bus::on(function () use (&$seen) { ... })`. Such a variable changes behind
 * the back of the code that follows, so a type learned from `expect($seen)->toBeNull()` stops holding at the
 * next call. Psalm core keeps the stale type for PHPUnit's asserts too, but there it only makes later code
 * look more certain than it is; here {@see ExpectNarrowingHandler} would turn the stale `null` into an
 * error-level issue (`PestImpossibleExpectation`, `NullPropertyFetch`), so it asks {@see self::isCaptured()}
 * and leaves those variables alone.
 *
 * Only closure scopes are indexed; any other scope reports no captures.
 */
final class ByRefCaptures implements BeforeFileAnalysisInterface, AfterFileAnalysisInterface
{
    /**
     * Closure id (as {@see ClosureAnalyzer::getClosureId()}) => the `$name` ids bound by reference in it.
     *
     * @var array<string, array<string, true>>
     */
    private static array $captured = [];

    /**
     * The enclosing files' indexes: an `include` in a test analyses the included file in the middle of this one.
     *
     * @var list<array<string, array<string, true>>>
     */
    private static array $outer = [];

    /** Whether the closure `$source` is analysing binds `$varId` (`$name`) by reference. */
    public static function isCaptured(string $varId, StatementsSource $source): bool
    {
        $closure = $source->getSource();

        return $closure instanceof ClosureAnalyzer && isset(self::$captured[$closure->getClosureId()][$varId]);
    }

    /** Rebuilt for every analysis, so a reused codebase (language server, watch mode) starts clean. */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        self::$outer[] = self::$captured;

        $visitor = new class (\strtolower($event->getFileStorage()->file_path)) extends NodeVisitorAbstract {
            /** @var array<string, array<string, true>> */
            public array $captured = [];

            /**
             * Closure ids from the outermost, `null` for a named function, method or arrow function: none of them
             * binds a variable of the closure around it by reference.
             *
             * @var list<string|null>
             */
            private array $frames = [];

            public function __construct(private readonly string $file) {}

            #[\Override]
            public function enterNode(Node $node): null
            {
                if ($node instanceof Closure) {
                    $this->frames[] = $this->file . ':' . $node->getLine() . ':' . $node->getStartFilePos() . ':-:closure';
                } elseif ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof ArrowFunction) {
                    $this->frames[] = null;
                } elseif ($node instanceof ClosureUse && $node->byRef && \is_string($node->var->name)) {
                    // The closure holding the clause and the one around it, whose variable it is. A closure further
                    // out may have a different `$name` of its own: it is marked only by an own clause up the chain.
                    $id = '$' . $node->var->name;
                    $count = \count($this->frames);
                    foreach ([$this->frames[$count - 1] ?? null, $this->frames[$count - 2] ?? null] as $frame) {
                        if ($frame === null) {
                            break;
                        }

                        $this->captured[$frame][$id] = true;
                    }
                }

                return null;
            }

            #[\Override]
            public function leaveNode(Node $node): null
            {
                if ($node instanceof Closure || $node instanceof Function_ || $node instanceof ClassMethod || $node instanceof ArrowFunction) {
                    \array_pop($this->frames);
                }

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse($event->getStmts());
        self::$captured = $visitor->captured;
    }

    #[\Override]
    public static function afterAnalyzeFile(AfterFileAnalysisEvent $event): void
    {
        self::$captured = \array_pop(self::$outer) ?? [];
    }
}
