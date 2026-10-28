<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * No element of the right side is in the field; an empty list in the field matches (md/tasks_list/done/3.9-list-operators.md): sets of normalized list elements — the field (multienum "^a^,^b^",
 * a JSON array, a separated string) and the right side, both parsed by the list cast.
 */
final class ContainsNoneComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'contains_none';
    }

    public function label(): string
    {
        return 'Не содержит ни одного из';
    }

    public function supportedCasts(): array
    {
        return [CastType::List];
    }

    public function expectedCast(CastOptions $options): CastOptions
    {
        return $options->element();
    }

    public function operandKind(): OperandKind
    {
        return OperandKind::List;
    }

    /**
     * @param list<string> $actual
     * @param list<string> $expected non-empty
     */
    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        $common = array_intersect_key(array_flip((array)$expected), array_flip((array)$actual));
        return $common === [];
    }
}
