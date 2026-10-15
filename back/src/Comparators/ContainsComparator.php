<?php

declare(strict_types=1);

namespace Wps\Comparators;

/**
 * Substring search (customer answer #11: "это поиск подстроки в строке").
 */
final class ContainsComparator implements ComparatorInterface
{
    public function name(): string
    {
        return 'contains';
    }

    public function label(): string
    {
        return 'Включает';
    }

    public function compare(string $actual, string $expected): bool
    {
        return str_contains($actual, $expected);
    }
}
