<?php

declare(strict_types=1);

namespace Wps\Comparators;

/**
 * Left side of a condition for operators without a right-hand value (is_empty, is_null …): whether the field was
 * found at all and its raw value (not cast). A variable that was not found in the CRM has no fields (found = false).
 */
final class PresenceValue
{
    public function __construct(
        public readonly bool $found,
        public readonly mixed $value,
    ) {
    }
}
