<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * The field is present and not null (an empty string is data too).
 * No right-hand value and no cast (md/tasks_list/done/3.2-is-empty.md); $actual is a PresenceValue.
 */
final class IsNotNullComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'is_not_null';
    }

    public function label(): string
    {
        return 'Данные есть';
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
            throw new \LogicException('is_not_null expects a PresenceValue');
        }
        return $actual->found && $actual->value !== null;
    }
}
