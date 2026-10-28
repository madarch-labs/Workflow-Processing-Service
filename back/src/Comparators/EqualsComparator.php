<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

final class EqualsComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'equals';
    }

    public function label(): string
    {
        return 'Равен';
    }

    public function supportedCasts(): array
    {
        return CastType::cases();
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        return $this->caster->equals($actual, $expected, $options);
    }
}
