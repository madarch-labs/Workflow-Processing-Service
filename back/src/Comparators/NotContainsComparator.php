<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

final class NotContainsComparator extends AbstractComparator
{
    public function name(): string
    {
        return 'not_contains';
    }

    public function label(): string
    {
        return 'Не включает';
    }

    public function supportedCasts(): array
    {
        return [CastType::String, CastType::List];
    }

    public function expectedCast(CastOptions $options): CastOptions
    {
        return $options->type === CastType::List ? $options->element() : $options;
    }

    public function compare(mixed $actual, mixed $expected, CastOptions $options): bool
    {
        return !ContainsComparator::contains($actual, $expected, $options);
    }
}
