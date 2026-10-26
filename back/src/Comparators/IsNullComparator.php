<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * No data: the variable was not found, the field is missing or null (customer decision 2026-10-16).
 * No right-hand value and no cast (md/tasks_list/done/3.2-is-empty.md); $actual is a PresenceValue.
 */
final class IsNullComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'is_null';
    }

    public function label(): string
    {
        return 'Данные отсутствуют';
    }

    public function supportedCasts(): array
    {
        return CastType::cases();
    }

    public function operandKind(): OperandKind
    {
        return OperandKind::Presence;
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        if (!$actual instanceof PresenceValue) {
            throw new \LogicException('is_null expects a PresenceValue');
        }
        return !$actual->found || $actual->value === null;
    }
}
