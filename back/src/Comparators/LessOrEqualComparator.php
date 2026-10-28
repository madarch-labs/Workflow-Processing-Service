<?php

declare(strict_types=1);

namespace Wps\Comparators;

final class LessOrEqualComparator extends AbstractOrderComparator
{
    public function name(): string
    {
        return 'less_or_equal';
    }

    public function label(): string
    {
        return 'Меньше или равно';
    }

    protected function accept(int $cmp): bool
    {
        return $cmp <= 0;
    }
}
