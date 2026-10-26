<?php

declare(strict_types=1);

namespace Wps\Time;

/**
 * A parsed date value. A date-only value is a calendar day kept as midnight UTC (naive, no time zone semantics);
 * a datetime is a moment in UTC.
 */
final class ParsedDate
{
    public function __construct(
        public readonly \DateTimeImmutable $value,
        public readonly bool $isDateOnly,
    ) {
    }
}
