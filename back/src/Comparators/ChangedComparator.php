<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;

/**
 * "Изменилось": the field of the event object changed in this CRM save (md/tasks_list/done/3.3-changed-operators.md);
 * false when the event does not say which fields changed (old plugin, delete).
 */
final class ChangedComparator extends AbstractChangeComparator
{
    public function name(): string
    {
        return 'changed';
    }

    public function label(): string
    {
        return 'Изменилось';
    }

    public function operandKind(): OperandKind
    {
        return OperandKind::Change;
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        $c = self::change($actual);
        return $c->known && $c->changed;
    }
}
