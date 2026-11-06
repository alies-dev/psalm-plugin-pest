<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * The closures Pest binds to the running test in a dataset: a row, or a value of an array row
 * (`Testable::__resolveTestArguments()` binds every `Closure` argument it gets). A dataset is an
 * array literal (`->with([...])`, `dataset('x', [...])`) or a lazy closure, whose rows are the
 * arrays it returns and the values it yields at its own top level (Pest calls the closure itself
 * unbound, from its static data provider).
 *
 * Pest leaves a closure unbound when the test parameter it fills is typed `Closure` or `callable`
 * (or `mixed` when it gets several arguments). That is not followed: every closure in a row
 * position is bound, so a closure Pest passes as is is never reported for `$this`.
 */
final class DatasetRows
{
    /**
     * @param list<Expr> $datasets
     * @return list<Closure|ArrowFunction>
     */
    public static function closures(array $datasets): array
    {
        $closures = [];
        foreach ($datasets as $dataset) {
            foreach (self::rows($dataset) as $row) {
                foreach ($row instanceof Array_ ? self::values($row) : [$row] as $value) {
                    if ($value instanceof Closure || $value instanceof ArrowFunction) {
                        $closures[] = $value;
                    }
                }
            }
        }

        return $closures;
    }

    /** @return list<Expr> */
    private static function rows(Expr $dataset): array
    {
        if ($dataset instanceof Array_) {
            return self::values($dataset);
        }

        if (!$dataset instanceof Closure && !$dataset instanceof ArrowFunction) {
            return [];
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Expr> */
            public array $rows = [];

            #[\Override]
            public function enterNode(Node $node): ?int
            {
                // A nested function or class produces values of its own.
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if (($node instanceof Return_ || $node instanceof YieldFrom) && $node->expr instanceof Array_) {
                    \array_push($this->rows, ...DatasetRows::values($node->expr));
                } elseif ($node instanceof Yield_ && $node->value instanceof Expr) {
                    $this->rows[] = $node->value;
                }

                return null;
            }
        };
        // An arrow function's statements are the `return` of its body.
        (new NodeTraverser($visitor))->traverse($dataset->getStmts());

        return $visitor->rows;
    }

    /**
     * The values of an array literal, with those of an unpacked array literal.
     *
     * @return list<Expr>
     */
    public static function values(Array_ $array): array
    {
        $values = [];
        foreach ($array->items as $item) {
            if (!$item->unpack) {
                $values[] = $item->value;
            } elseif ($item->value instanceof Array_) {
                \array_push($values, ...self::values($item->value));
            }
        }

        return $values;
    }
}
