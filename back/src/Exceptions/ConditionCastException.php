<?php

declare(strict_types=1);

namespace Wps\Exceptions;

/**
 * A condition row with "on_cast_error": "error" could not cast its value (md/tasks_list/done/3.0-type-cast.md §4.7).
 * getDetails() is the trace row of the condition.
 */
final class ConditionCastException extends NonRecoverableException
{
}
