<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * The string has the value at its start (md/tasks_list/done/3.5-starts-ends-with.md); case and trimming come from the
 * string cast options (case_sensitive, trim), applied by TypeCaster to both sides.
 */
final class StartsWithComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'starts_with';
    }

    public function label(): string
    {
        return 'Начинается с';
    }

    public function supportedCasts(): array
    {
        return [CastType::String];
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        return str_starts_with((string)$actual, (string)$expected);
    }
}
