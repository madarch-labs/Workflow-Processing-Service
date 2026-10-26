<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

final class NotEqualsComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'not_equals';
    }

    public function label(): string
    {
        return 'Не равен';
    }

    public function supportedCasts(): array
    {
        return CastType::cases();
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        return !$this->caster->equals($actual, $expected, $options);
    }
}
