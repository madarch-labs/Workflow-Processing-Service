<?php

declare(strict_types=1);

namespace Wps\Crm;

/**
 * The value of a filter row taken from a field is empty or missing (find_records "on_empty_value": empty_result).
 */
final class EmptyFilterValue extends \RuntimeException
{
}
