<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * A present field is empty (null, blank string, empty list). A missing field is neither empty nor filled (customer answer #10).
 * No right-hand value and no cast (md/tasks_list/done/3.2-is-empty.md); $actual is a PresenceValue.
 */
final class IsEmptyComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'is_empty';
    }

    public function label(): string
    {
        return 'Пусто';
    }

    public function supportedCasts(): array
    {
        return CastType::cases();
    }

    public function operandKind(): OperandKind
    {
        return OperandKind::None;
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        if (!$actual instanceof PresenceValue) {
            throw new \LogicException('is_empty expects a PresenceValue');
        }
        return $actual->found && TypeCaster::isEmpty($actual->value);
    }
}
