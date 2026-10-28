<?php

declare(strict_types=1);

namespace Wps\Comparators;

final class GreaterThanComparator extends AbstractOrderComparator
{
    public function name(): string
    {
        return 'greater_than';
    }

    public function label(): string
    {
        return 'Больше';
    }

    protected function accept(int $cmp): bool
    {
        return $cmp > 0;
    }
}
