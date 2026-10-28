<?php

declare(strict_types=1);

namespace Wps\Time;

/**
 * Source of "now" (md/tasks_list/done/5.2.2-expr-dates.md §4.4): SystemClock in production, FrozenClock in tests
 * and dry runs.
 */
interface Clock
{
    /**
     * Current moment in UTC.
     */
    public function now(): \DateTimeImmutable;
}
