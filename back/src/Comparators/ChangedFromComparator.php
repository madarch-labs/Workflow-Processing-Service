<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;

/**
 * "Изменилось с": the field changed in this save and its old value equals the expected one; $expected is
 * [cast value, empty]. On create there was no old value.
 */
final class ChangedFromComparator extends AbstractChangeComparator
{
    public function name(): string
    {
        return 'changed_from';
    }

    public function label(): string
    {
        return 'Изменилось с';
    }

    public function operandKind(): OperandKind
    {
        return OperandKind::ChangeValue;
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        $c = self::change($actual);
        [$value, $empty] = $expected;
        return $c->changed && $this->same($c->before, $c->beforeEmpty, $value, $empty, $options);
    }
}
