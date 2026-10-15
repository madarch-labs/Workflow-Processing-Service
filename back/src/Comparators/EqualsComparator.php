<?php

declare(strict_types=1);

namespace Wps\Comparators;

final class EqualsComparator implements ComparatorInterface
{
    public function name(): string
    {
        return 'equals';
    }

    public function label(): string
    {
        return 'Равен';
    }

    public function compare(string $actual, string $expected): bool
    {
        return $actual === $expected;
    }
}
