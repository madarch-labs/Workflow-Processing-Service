<?php

declare(strict_types=1);

namespace Wps\Comparators;

/**
 * Extension point: a comparison operator used in process conditions (TS §13.1).
 * A new operator = a class implementing this interface + registration in ComparatorRegistry::default().
 */
interface ComparatorInterface
{
    /** Operator code stored in the process definition, e.g. "equals". */
    public function name(): string;

    /** Human readable label for the UI. */
    public function label(): string;

    public function compare(string $actual, string $expected): bool;
}
