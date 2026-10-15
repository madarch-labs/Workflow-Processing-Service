<?php

declare(strict_types=1);

namespace Wps\Comparators;

final class NotContainsComparator implements ComparatorInterface
{
    public function name(): string
    {
        return 'not_contains';
    }

    public function label(): string
    {
        return 'Не включает';
    }

    public function compare(string $actual, string $expected): bool
    {
        return !str_contains($actual, $expected);
    }
}
