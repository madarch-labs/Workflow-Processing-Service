<?php

declare(strict_types=1);

namespace Wps\Comparators;

use Wps\Types\CastOptions;
use Wps\Types\TypeCaster;

/**
 * Base for operators: a single right-hand value cast the same way as the field.
 */
abstract class AbstractComparator implements ComparatorInterface
{
    public function __construct(protected readonly TypeCaster $caster)
    {
    }

    public function operandKind(): OperandKind
    {
        return OperandKind::Value;
    }

    public function expectedCast(CastOptions $options): CastOptions
    {
        return $options;
    }
}
