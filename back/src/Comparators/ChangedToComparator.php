<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;

/**
 * "Изменилось на": the field changed in this save and its new value equals the expected one; $expected is
 * [cast value, empty].
 */
final class ChangedToComparator extends AbstractChangeComparator
{
    public function name(): string
    {
        return 'changed_to';
    }

    public function label(): string
    {
        return 'Изменилось на';
    }

    public function operandKind(): OperandKind
    {
        return OperandKind::ChangeValue;
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        $c = self::change($actual);
        [$value, $empty] = $expected;
        return $c->changed && $this->same($c->after, $c->afterEmpty, $value, $empty, $options);
    }
}
