<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * Base of changed / not_changed / changed_to / changed_from: the left side is a FieldChange, every cast is allowed.
 */
abstract class AbstractChangeComparator extends AbstractComparator
{
    public function supportedCasts(): array
    {
        return CastType::cases();
    }

    /**
     * Equality where two empty values are equal and an empty value differs from a non-empty one.
     */
    protected function same(mixed $a, bool $aEmpty, mixed $b, bool $bEmpty, CastOptions $options): bool
    {
        if ($aEmpty || $bEmpty) {
            return $aEmpty && $bEmpty;
        }
        return $this->caster->equals($a, $b, $options);
    }

    protected static function change(mixed $actual): FieldChange
    {
        return $actual instanceof FieldChange ? $actual : FieldChange::unknown();
    }
}
