<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * Substring search (customer answer #11: "это поиск подстроки в строке");
 * with cast "list" — the list contains the element.
 */
final class ContainsComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'contains';
    }

    public function label(): string
    {
        return 'Включает';
    }

    public function supportedCasts(): array
    {
        return [CastType::String, CastType::List];
    }

    public function expectedCast(CastOptions $options): CastOptions
    {
        return $options->type === CastType::List ? $options->element() : $options;
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        return self::contains($actual, $expected, $options);
    }

    public static function contains(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        if ($options->type === CastType::List) {
            return in_array((string)$expected, (array)$actual, true);
        }
        return str_contains((string)$actual, (string)$expected);
    }
}
