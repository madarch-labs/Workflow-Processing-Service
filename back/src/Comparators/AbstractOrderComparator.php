<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * "Greater / less than" for orderable casts (md/tasks_list/done/3.1-order-operators.md): int, decimal, date, datetime.
 * Comparison is TypeCaster::compare() — the same as in expressions.
 */
abstract class AbstractOrderComparator extends AbstractComparator
{
    public function supportedCasts(): array
    {
        return CastType::orderable();
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        return $this->accept($this->caster->compare($actual, $expected, $options));
    }

    /**
     * @param int $cmp -1 / 0 / 1 (actual vs expected)
     */
    abstract protected function accept(int $cmp): bool;
}
