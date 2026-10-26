<?php

declare(strict_types=1);

namespace Wps\Comparators;

/**
 * Left side of the change operators (md/tasks_list/done/3.3-changed-operators.md): how a field of the event object
 * changed in this CRM save. Values are cast with the options of the condition; null with *Empty = true is "empty".
 */
final class FieldChange
{
    public function __construct(
        /** the event says which fields changed (format 2.0, not a delete) */
        public readonly bool $known,
        public readonly bool $changed,
        public readonly mixed $before = null,
        public readonly bool $beforeEmpty = true,
        public readonly mixed $after = null,
        public readonly bool $afterEmpty = true,
    ) {
    }

    public static function unknown(): self
    {
        return new self(false, false);
    }
}
