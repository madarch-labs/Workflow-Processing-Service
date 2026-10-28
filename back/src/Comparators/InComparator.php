<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * The value equals one of the list items (md/tasks_list/done/3.4-in-not-in.md);
 * $expected is the list of items cast like the field.
 */
final class InComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'in';
    }

    public function label(): string
    {
        return 'Входит в';
    }

    public function supportedCasts(): array
    {
        return [CastType::String, CastType::Int, CastType::Decimal, CastType::Date];
    }

    public function operandKind(): OperandKind
    {
        return OperandKind::List;
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        foreach ((array)$expected as $item) {
            if ($this->caster->equals($actual, $item, $options)) {
                return true;
            }
        }
        return false;
    }
}
