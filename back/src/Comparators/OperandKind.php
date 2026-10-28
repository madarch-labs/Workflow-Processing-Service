<?php

declare(strict_types=1);

namespace Wps\Comparators;

/**
 * What an operator compares against (the shape of the right-hand side of a condition row).
 */
enum OperandKind: string
{
    /** one value: equals, contains, greater_than … */
    case Value = 'value';
    /** no right-hand side, the field must be present: is_empty, is_not_empty */
    case None = 'none';
    /** presence of data, also evaluated for a missing field: is_null, is_not_null */
    case Presence = 'presence';
    /** a list of values: in, not_in */
    case List = 'list';
    /** a pair [from, to]: between, not_between */
    case Range = 'range';
    /** change of the field in this save, no right-hand side: changed, not_changed */
    case Change = 'change';
    /** change of the field + a value: changed_to, changed_from */
    case ChangeValue = 'change_value';
}
