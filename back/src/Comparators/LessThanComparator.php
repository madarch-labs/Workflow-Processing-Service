<?php

declare(strict_types=1);

namespace Wps\Comparators;

final class LessThanComparator extends AbstractOrderComparator
{
    public function name(): string
    {
        return 'less_than';
    }

    public function label(): string
    {
        return 'Меньше';
    }

    protected function accept(int $cmp): bool
    {
        return $cmp < 0;
    }
}
