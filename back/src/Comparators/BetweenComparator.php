<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * The value lies in the range (md/tasks_list/done/3.6-between.md): the value is a pair [from, to] cast like the field, the bounds are
 * included; an inverted range is rejected by ConditionEngineService before the comparison.
 */
final class BetweenComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'between';
    }

    public function label(): string
    {
        return 'В диапазоне';
    }

    public function supportedCasts(): array
    {
        return CastType::orderable();
    }

    public function operandKind(): OperandKind
    {
        return OperandKind::Range;
    }

    /**
     * @param array{0: mixed, 1: mixed} $expected
     */
    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        [$from, $to] = $expected;
        return $this->caster->compare($from, $actual, $options) <= 0 && $this->caster->compare($actual, $to, $options) <= 0;
    }
}
