<?php

declare(strict_types=1);

namespace Wps\Comparators;

final class GreaterOrEqualComparator extends AbstractOrderComparator
{
    public function name(): string
    {
        return 'greater_or_equal';
    }

    public function label(): string
    {
        return 'Больше или равно';
    }

    protected function accept(int $cmp): bool
    {
        return $cmp >= 0;
    }
}
