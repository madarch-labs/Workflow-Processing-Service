<?php

declare(strict_types=1);

namespace Wps\Comparators;

final class NotEqualsComparator implements ComparatorInterface
{
    public function name(): string
    {
        return 'not_equals';
    }

    public function label(): string
    {
        return 'Не равен';
    }

    public function compare(string $actual, string $expected): bool
    {
        return $actual !== $expected;
    }
}
