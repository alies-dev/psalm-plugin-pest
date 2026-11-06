<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;

/**
 * The closures Pest runs on the test inside a dataset array literal: `->with([...])` and
 * `dataset('x', [...])`. A row is a closure or an array of values, and Pest binds every closure
 * among them (`Testable::__resolveTestArguments()`), one level deep.
 */
final class DatasetRows
{
    /**
     * @param list<Expr> $datasets the array arguments of one `with()` / `dataset()` call
     * @return list<Closure|ArrowFunction>
     */
    public static function closures(array $datasets): array
    {
        $closures = [];
        foreach ($datasets as $dataset) {
            foreach (self::values($dataset) as $row) {
                if ($row instanceof Closure || $row instanceof ArrowFunction) {
                    $closures[] = $row;
                    continue;
                }

                foreach (self::values($row) as $value) {
                    if ($value instanceof Closure || $value instanceof ArrowFunction) {
                        $closures[] = $value;
                    }
                }
            }
        }

        return $closures;
    }

    /** @return list<Expr> */
    private static function values(Expr $array): array
    {
        $values = [];
        // `list()` is accepted only so that Psalm 6 and 7 agree that an item may be null.
        if ($array instanceof Expr\Array_ || $array instanceof Expr\List_) {
            foreach ($array->items as $item) {
                if ($item !== null) {
                    $values[] = $item->value;
                }
            }
        }

        return $values;
    }
}
