<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * A present field has a value; a missing field gives false (customer answer #10).
 * No right-hand value and no cast (md/tasks_list/done/3.2-is-empty.md); $actual is a PresenceValue.
 */
final class IsNotEmptyComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'is_not_empty';
    }

    public function label(): string
    {
        return 'Не пусто';
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
            throw new \LogicException('is_not_empty expects a PresenceValue');
        }
        return $actual->found && !TypeCaster::isEmpty($actual->value);
    }
}
