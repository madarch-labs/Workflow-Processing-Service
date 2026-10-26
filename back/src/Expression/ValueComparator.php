<?php

declare(strict_types=1);

namespace Wps\Expression;

use Wps\Exceptions\NonRecoverableException;
use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * Comparison operators of expressions (md/tasks_list/done/5.2.1-expr-conditionals.md §4.3), checked in this order:
 *   1. null and '' are equal;  2. "< <= > >=" with an empty operand → false;
 *   3. a bool operand: the other one is cast to bool;  4. both strictly numeric → decimal comparison (bcmath);
 *   5. lists: == / != element by element, ordering is an error;  6. otherwise strings (byte-wise, case-sensitive).
 * No automatic date detection: dates in a canonical format compare correctly as strings.
 */
final class ValueComparator
{
    private const SCALE = 10;

    public function __construct(private readonly TypeCaster $caster)
    {
    }

    public function compare(string $op, mixed $a, mixed $b): bool
    {
        $ordering = !in_array($op, ['==', '!='], true);
        $emptyA = $a === null || $a === '';
        $emptyB = $b === null || $b === '';
        if ($emptyA || $emptyB) {
            if ($ordering) {
                return false;
            }
            return ($op === '==') === ($emptyA && $emptyB);
        }
        if (is_bool($a) || is_bool($b)) {
            return $this->compareBool($op, $a, $b);
        }
        if (self::isStrictNumber($a) && self::isStrictNumber($b)) {
            $options = new CastOptions(type: CastType::Decimal, scale: self::SCALE);
            $cmp = bccomp((string)$this->caster->cast($a, $options)->value, (string)$this->caster->cast($b, $options)->value, self::SCALE);
            return self::accept($op, $cmp);
        }
        if (is_array($a) || is_array($b)) {
            if ($ordering) {
                throw new NonRecoverableException("Lists cannot be compared with \"{$op}\"");
            }
            $equal = is_array($a) && is_array($b) && count($a) === count($b);
            if ($equal) {
                foreach (array_values($a) as $i => $item) {
                    if (!$this->compare('==', $item, array_values($b)[$i])) {
                        $equal = false;
                        break;
                    }
                }
            }
            return ($op === '==') === $equal;
        }
        return self::accept($op, strcmp(TypeCaster::stringify($a), TypeCaster::stringify($b)) <=> 0);
    }

    /**
     * true/1/yes/on/да, false/0/no/off/нет/''/null; anything else is an error (no silent truthiness).
     */
    public function truthy(mixed $value): bool
    {
        $cast = $this->caster->cast($value, new CastOptions(type: CastType::Bool));
        if (!$cast->isOk()) {
            throw new NonRecoverableException(sprintf('Condition must be boolean, got "%s"', mb_substr(TypeCaster::stringify($value), 0, 100)));
        }
        return (bool)$cast->value;
    }

    /**
     * int / float, or a numeric string without thousands separators and decimal commas.
     */
    public static function isStrictNumber(mixed $value): bool
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return true;
        }
        return is_string($value) && (bool)preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', trim($value));
    }

    private function compareBool(string $op, mixed $a, mixed $b): bool
    {
        $options = new CastOptions(type: CastType::Bool);
        $castA = is_bool($a) ? $a : $this->caster->cast($a, $options);
        $castB = is_bool($b) ? $b : $this->caster->cast($b, $options);
        foreach ([$castA, $castB] as $side) {
            if (!is_bool($side) && !$side->isOk()) {
                if (in_array($op, ['==', '!='], true)) {
                    return $op === '!=';
                }
                throw new NonRecoverableException('Cannot compare boolean with "' . TypeCaster::stringify(is_bool($a) ? $b : $a) . '"');
            }
        }
        $boolA = is_bool($castA) ? $castA : (bool)$castA->value;
        $boolB = is_bool($castB) ? $castB : (bool)$castB->value;
        return self::accept($op, $boolA <=> $boolB);
    }

    private static function accept(string $op, int $cmp): bool
    {
        return match ($op) {
            '==' => $cmp === 0,
            '!=' => $cmp !== 0,
            '<' => $cmp < 0,
            '<=' => $cmp <= 0,
            '>' => $cmp > 0,
            '>=' => $cmp >= 0,
            default => throw new NonRecoverableException("Unknown comparison operator {$op}"),
        };
    }
}
