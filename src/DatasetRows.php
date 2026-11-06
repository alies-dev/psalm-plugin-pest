<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

/**
 * The closures Pest runs on the test inside a dataset array literal: `->with([...])` and
 * `dataset('x', [...])`. Each item of the literal is a row, either a closure or an array of
 * values, and Pest binds a closure that is a row or a value of an array row
 * (`Testable::__resolveTestArguments()`); a closure nested deeper is passed as is.
 *
 * Pest also passes a closure as is when the test parameter it fills is typed `Closure` or
 * `callable`, or `mixed` (or untyped) when the row gives the test more than one argument. That is
 * followed only when the test closure is in sight ({@see self::closures()}).
 */
final class DatasetRows
{
    private const UNBOUND_SINGLE = ['Closure', 'callable'];

    private const UNBOUND_MANY = ['Closure', 'callable', 'mixed'];

    /**
     * @param list<Expr> $datasets the arguments of one `with()` / `dataset()` call
     * @param array<string, string>|null $parameterTypes the test closure's parameter types by name
     *     (`Reflection::getFunctionArguments()`), or null when unknown: then every closure row is bound
     * @return list<Closure|ArrowFunction>
     */
    public static function closures(array $datasets, ?array $parameterTypes = null): array
    {
        $rows = \array_map(self::rows(...), $datasets);
        // The sizes a row of each dataset can have, null when not known.
        $sizes = \array_map(
            static fn(?array $rows): ?array => $rows === null || \in_array(false, \array_column($rows, 'known'), true)
                ? null
                : \array_values(\array_unique(\array_map(static fn(array $row): int => \count($row['values']), $rows))),
            $rows,
        );

        $closures = [];
        foreach ($rows as $index => $datasetRows) {
            foreach ($datasetRows ?? [] as $row) {
                foreach ($row['values'] as $key => $value) {
                    if (($value instanceof Closure || $value instanceof ArrowFunction)
                        && ($parameterTypes === null || !$row['known'] || self::bound($parameterTypes, $sizes, $index, $key, \count($row['values'])))
                    ) {
                        $closures[] = $value;
                    }
                }
            }
        }

        return $closures;
    }

    /**
     * Whether Pest binds the value at `$key` of a row of `$rowSize` values in dataset `$index`, in
     * any combination with the rows of the other datasets (Pest merges one row of each into the
     * test's arguments). True when a combination cannot be told.
     *
     * @param array<string, string> $parameterTypes
     * @param list<list<int>|null> $sizes
     */
    private static function bound(array $parameterTypes, array $sizes, int $index, int|string $key, int $rowSize): bool
    {
        $before = self::sums(\array_slice($sizes, 0, $index));
        $after = self::sums(\array_slice($sizes, $index + 1));
        if ($before === null || $after === null) {
            return true;
        }

        $types = \array_values($parameterTypes);
        foreach ($before as $offset) {
            foreach ($after as $rest) {
                if ($offset + $rowSize + $rest === 1) {
                    // A lone argument is resolved only at position 0: a named one is passed as is.
                    if (\is_int($key) && !\in_array($types[0] ?? null, self::UNBOUND_SINGLE, true)) {
                        return true;
                    }

                    continue;
                }

                $type = \is_string($key) ? ($parameterTypes[$key] ?? null) : ($types[$offset + $key] ?? null);
                if (!\in_array($type, self::UNBOUND_MANY, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every total of one size per dataset, or null when a size is not known.
     *
     * @param list<list<int>|null> $sizes
     * @return list<int>|null
     */
    private static function sums(array $sizes): ?array
    {
        $sums = [0];
        foreach ($sizes as $options) {
            if ($options === null) {
                return null;
            }

            $next = [];
            foreach ($sums as $sum) {
                foreach ($options as $option) {
                    $next[] = $sum + $option;
                }
            }

            $sums = \array_values(\array_unique($next));
        }

        return $sums;
    }

    /**
     * The rows of a dataset array literal, null when the dataset is not one. A row holds the
     * arguments it gives the test, keyed as `array_merge()` leaves them; it is not `known` when an
     * unpacked item or a computed key hides some of them, nor is a row of a dataset with an unpacked item.
     *
     * @return list<array{values: array<int|string, Expr>, known: bool}>|null
     */
    private static function rows(Expr $dataset): ?array
    {
        $items = self::items($dataset);
        if ($items === null) {
            return null;
        }

        $known = !\in_array(true, \array_map(static fn(ArrayItem $item): bool => $item->unpack, $items), true);
        $rows = [];
        foreach ($items as $item) {
            if ($item->unpack) {
                continue;
            }

            $row = $item->value instanceof Expr\Array_ ? self::values($item->value) : ['values' => [$item->value], 'known' => true];
            $rows[] = ['values' => $row['values'], 'known' => $known && $row['known']];
        }

        return $rows;
    }

    /** @return array{values: array<int|string, Expr>, known: bool} */
    private static function values(Expr\Array_ $row): array
    {
        $values = [];
        $known = true;
        foreach (self::items($row) ?? [] as $item) {
            if ($item->unpack) {
                $known = false;
            } elseif ($item->key instanceof String_ && \preg_match('/^(0|-?[1-9]\d*)$/', $item->key->value) !== 1) {
                $values[$item->key->value] = $item->value;
            } else {
                $known = $known && ($item->key === null || $item->key instanceof Int_ || $item->key instanceof String_);
                $values[] = $item->value;
            }
        }

        return ['values' => $values, 'known' => $known];
    }

    /** @return list<ArrayItem>|null */
    private static function items(Expr $array): ?array
    {
        if (!$array instanceof Expr\Array_) {
            return null;
        }

        /** @var list<ArrayItem> $items */
        $items = $array->items;

        return $items;
    }
}
