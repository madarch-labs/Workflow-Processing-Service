<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * The string has the value at its end (md/tasks_list/done/3.5-starts-ends-with.md); case and trimming come from the
 * string cast options (case_sensitive, trim), applied by TypeCaster to both sides.
 */
final class EndsWithComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'ends_with';
    }

    public function label(): string
    {
        return 'Заканчивается на';
    }

    public function supportedCasts(): array
    {
        return [CastType::String];
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        return str_ends_with((string)$actual, (string)$expected);
    }
}
