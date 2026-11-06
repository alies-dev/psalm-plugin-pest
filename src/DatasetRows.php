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
 * values it yields at its own top level or, when it yields nothing, the arrays it returns (Pest
 * calls the closure itself unbound, from its static data provider, and reads a generator with
 * `iterator_to_array()`, which drops its return value).
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
            public array $returned = [];

            /** @var list<Expr> */
            public array $yielded = [];

            public bool $generator = false;

            #[\Override]
            public function enterNode(Node $node): ?int
            {
                // A nested function or class produces values of its own.
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Return_ && $node->expr instanceof Array_) {
                    \array_push($this->returned, ...DatasetRows::values($node->expr));
                } elseif ($node instanceof YieldFrom) {
                    $this->generator = true;
                    if ($node->expr instanceof Array_) {
                        \array_push($this->yielded, ...DatasetRows::values($node->expr));
                    }
                } elseif ($node instanceof Yield_) {
                    $this->generator = true;
                    if ($node->value instanceof Expr) {
                        $this->yielded[] = $node->value;
                    }
                }

                return null;
            }
        };
        // An arrow function's statements are the `return` of its body.
        (new NodeTraverser($visitor))->traverse($dataset->getStmts());

        // A generator's return value is not a row.
        return $visitor->generator ? $visitor->yielded : $visitor->returned;
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
