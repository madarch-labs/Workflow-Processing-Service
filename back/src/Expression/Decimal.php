<?php

declare(strict_types=1);

namespace Wps\Expression;

use Wps\Exceptions\NonRecoverableException;

/**
 * Exact decimal arithmetic over bcmath strings (md/tasks_list/done/5.2.3-expr-numbers.md §4.1). Values stay plain
 * scalars: operations take and return canonical strings ("-123.45", no trailing zeros), results are rounded HALF_UP
 * to SCALE digits. toNative() turns a result back into int / float when that is lossless, so ordinary numbers keep
 * their former PHP types and only very large or very precise numbers stay strings.
 */
final class Decimal
{
    public const SCALE = 10;
    public const MAX_DIGITS = 100;

    /** Floats print with 15 significant digits: 0.1 stays "0.1", not "0.1000000000000000055…" */
    private const FLOAT_DIGITS = 15;

    /**
     * int, finite float or a strict numeric string ("12", "-0.5", " 3 "); "1 234,56" and "1e3" are not numbers.
     */
    public static function isNumeric(mixed $value): bool
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return true;
        }
        return is_string($value) && (bool)preg_match('/^\s*[+-]?(\d+(\.\d*)?|\.\d+)\s*$/', $value);
    }

    /**
     * Canonical decimal string of an int / float / strict numeric string.
     */
    public static function of(mixed $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new NonRecoverableException('Expression result is not a finite number');
            }
            return self::fromFloat($value);
        }
        if (!self::isNumeric($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a number', is_scalar($value) ? (string)$value : get_debug_type($value)));
        }
        return self::normalize(trim((string)$value));
    }

    public static function add(string $a, string $b): string
    {
        return self::normalize(bcadd($a, $b, self::SCALE));
    }

    public static function sub(string $a, string $b): string
    {
        return self::normalize(bcsub($a, $b, self::SCALE));
    }

    public static function mul(string $a, string $b): string
    {
        return self::round(bcmul($a, $b, self::SCALE * 2 + 1), self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        if (bccomp($b, '0', self::SCALE * 2) === 0) {
            throw new NonRecoverableException('Division by zero in expression');
        }
        return self::round(bcdiv($a, $b, self::SCALE + 1), self::SCALE);
    }

    /**
     * Remainder with the sign of the dividend (like fmod).
     */
    public static function mod(string $a, string $b): string
    {
        if (bccomp($b, '0', self::SCALE * 2) === 0) {
            throw new NonRecoverableException('Modulo by zero in expression');
        }
        return self::normalize(bcmod($a, $b, self::SCALE));
    }

    public static function neg(string $a): string
    {
        return self::normalize(bcmul($a, '-1', self::SCALE));
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function sign(string $a): int
    {
        return self::cmp($a, '0');
    }

    /**
     * HALF_UP (away from zero): 2.5 → 3, -2.5 → -3, 1.005 → 1.01; a negative precision rounds to tens, hundreds …
     */
    public static function round(string $a, int $precision = 0): string
    {
        if ($precision < 0) {
            $factor = bcpow('10', (string)-$precision);
            return self::normalize(bcmul(self::round(bcdiv($a, $factor, self::SCALE * 2), 0), $factor));
        }
        $half = '0.' . str_repeat('0', $precision) . '5';
        $shifted = str_starts_with(ltrim($a), '-') ? bcsub($a, $half, $precision + 1) : bcadd($a, $half, $precision + 1);
        return self::normalize(bcadd($shifted, '0', $precision));
    }

    /**
     * Toward zero.
     */
    public static function trunc(string $a, int $precision = 0): string
    {
        return self::withPrecision($a, $precision, static fn (string $x) => self::normalize(bcadd($x, '0', 0)));
    }

    /**
     * Toward −∞.
     */
    public static function floor(string $a, int $precision = 0): string
    {
        return self::withPrecision($a, $precision, static function (string $x): string {
            $t = bcadd($x, '0', 0);
            return self::normalize(bccomp($x, $t, self::SCALE * 2) < 0 ? bcsub($t, '1', 0) : $t);
        });
    }

    /**
     * Toward +∞.
     */
    public static function ceil(string $a, int $precision = 0): string
    {
        return self::withPrecision($a, $precision, static function (string $x): string {
            $t = bcadd($x, '0', 0);
            return self::normalize(bccomp($x, $t, self::SCALE * 2) > 0 ? bcadd($t, '1', 0) : $t);
        });
    }

    public static function abs(string $a): string
    {
        return ltrim($a, '-');
    }

    public static function sqrt(string $a): string
    {
        if (self::sign($a) < 0) {
            throw new NonRecoverableException('sqrt() of a negative number');
        }
        return self::round(bcsqrt($a, self::SCALE + 1), self::SCALE);
    }

    /**
     * Integer power |n| ≤ 100 is exact; other exponents go through float pow().
     */
    public static function pow(string $a, string $n): string
    {
        if (preg_match('/^-?\d+$/', $n) && abs((int)$n) <= 100) {
            if ((int)$n < 0 && self::sign($a) === 0) {
                throw new NonRecoverableException('Division by zero in expression');
            }
            return self::round(bcpow($a, $n, self::SCALE + 1), self::SCALE);
        }
        $result = pow((float)$a, (float)$n);
        if (!is_finite($result)) {
            throw new NonRecoverableException('Expression result is not a finite number');
        }
        return self::fromFloat($result);
    }

    /**
     * "450.000" → "450", "-0.50" → "-0.5", "-0" → "0"; more than MAX_DIGITS integer digits is an error.
     */
    public static function normalize(string $a): string
    {
        $negative = str_starts_with($a, '-');
        $a = ltrim($a, '+-');
        [$int, $frac] = array_pad(explode('.', $a, 2), 2, '');
        $int = ltrim($int, '0');
        $frac = rtrim($frac, '0');
        if (strlen($int) > self::MAX_DIGITS) {
            throw new NonRecoverableException('Number is too large');
        }
        $result = ($int === '' ? '0' : $int) . ($frac === '' ? '' : '.' . $frac);
        return $negative && $result !== '0' ? '-' . $result : $result;
    }

    /**
     * int for integers below 10^15, float for values exact in 15 significant digits, the string otherwise.
     */
    public static function toNative(string $a): int|float|string
    {
        $a = self::normalize($a);
        $digits = ltrim(str_replace(['-', '.'], '', $a), '0');
        if (!str_contains($a, '.')) {
            return strlen($digits) < 16 ? (int)$a : $a;
        }
        return strlen($digits) <= self::FLOAT_DIGITS && self::fromFloat((float)$a) === $a ? (float)$a : $a;
    }

    /**
     * Text of a number for concatenation and messages: 2.50 → "2.5", 90.0 → "90".
     */
    public static function toText(int|float|string $value): string
    {
        return is_string($value) ? $value : self::of($value);
    }

    private static function fromFloat(float $value): string
    {
        $text = sprintf('%.' . self::FLOAT_DIGITS . 'G', $value);
        if (stripos($text, 'E') !== false) {
            [$mantissa, $exponent] = explode('E', strtoupper($text));
            $exponent = (int)$exponent;
            $text = $exponent >= 0
                ? bcmul($mantissa, bcpow('10', (string)$exponent), self::SCALE)
                : bcdiv($mantissa, bcpow('10', (string)-$exponent), self::SCALE);
        }
        return self::normalize($text);
    }

    /**
     * @param \Closure(string): string $operation rounding to an integer
     */
    private static function withPrecision(string $a, int $precision, \Closure $operation): string
    {
        $factor = bcpow('10', (string)abs($precision));
        if ($precision >= 0) {
            return self::normalize(bcdiv($operation(bcmul($a, $factor, self::SCALE * 2)), $factor, max($precision, 0)));
        }
        return self::normalize(bcmul($operation(bcdiv($a, $factor, self::SCALE * 2)), $factor));
    }
}
