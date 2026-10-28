<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;

/**
 * "Не изменилось": the event says which fields changed and this field of the event object is not among them (or its value
 * is the same after the cast) (md/tasks_list/done/3.3-changed-operators.md);
 * false when the event does not say which fields changed (old plugin, delete).
 */
final class NotChangedComparator extends AbstractChangeComparator
{
    public function name(): string
    {
        return 'not_changed';
    }

    public function label(): string
    {
        return 'Не изменилось';
    }

    public function operandKind(): OperandKind
    {
        return OperandKind::Change;
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        $c = self::change($actual);
        return $c->known && !$c->changed;
    }
}
