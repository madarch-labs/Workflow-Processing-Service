<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * The value lies outside the range (md/tasks_list/done/3.6-between.md): the value is a pair [from, to] cast like the field, the bounds are
 * included; an inverted range is rejected by ConditionEngineService before the comparison.
 */
final class NotBetweenComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'not_between';
    }

    public function label(): string
    {
        return 'Вне диапазона';
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
        return $this->caster->compare($actual, $from, $options) < 0 || $this->caster->compare($actual, $to, $options) > 0;
    }
}
