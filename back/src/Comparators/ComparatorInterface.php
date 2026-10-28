<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * Extension point: a comparison operator used in process conditions (TS §13.1).
 * A new operator = a class implementing this interface (usually extending AbstractComparator)
 * + registration in ComparatorRegistry::default().
 *
 * Both sides are cast by ConditionEngineService before compare() is called (md/tasks_list/done/3.0-type-cast.md):
 * a missing field, an empty value and a cast error never reach the comparator.
 */
interface ComparatorInterface
{
    /** Operator code stored in the process definition, e.g. "equals". */
    public function name(): string;

    /** Human readable label for the UI. */
    public function label(): string;

    /**
     * Casts the operator accepts ("cast" of the condition row).
     *
     * @return list<CastType>
     */
    public function supportedCasts(): array;

    public function operandKind(): OperandKind;

    /**
     * How the right-hand side is cast; by default the same way as the field.
     */
    public function expectedCast(CastOptions $options): CastOptions;

    /**
     * @param mixed $actual   the field, already cast with $options
     * @param mixed $expected the right-hand side, already cast with expectedCast($options)
     */
    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool;
}
