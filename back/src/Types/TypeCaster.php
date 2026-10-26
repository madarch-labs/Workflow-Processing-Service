<?php

declare(strict_types=1);

namespace Wps\Types;

/**
 * Casts values to a CastType and compares cast values (md/tasks_list/done/3.0-type-cast.md §4.3–4.4).
 * One implementation for conditions, expressions and templates. Never throws on bad input — see CastResult.
 *
 *   string    stringify() → trim? → lower case? (no options = exactly the old string comparison)
 *   int       "1 200", "+5", "100.00" (zero fraction only)
 *   decimal   canonical string ("-1234.5"), compared with bcmath; "1 234,56" accepted, "1,234.56" is ambiguous
 *   date      DateTimeImmutable at 00:00 in the business time zone (values with a time are moved into it first)
 *   datetime  DateTimeImmutable in UTC
 *   bool      true/1/yes/on/да, false/0/no/off/нет/empty
 *   list      list<string> from an array, a JSON array or a separated string (multienum: wrapper "^")
 */
final class TypeCaster
{
    /** Formats tried for date / datetime values when "cast_options.format" is not given. */
    public const DEFAULT_DATE_FORMATS = [
        'Y-m-d',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d\TH:i:s\Z',
        'Y-m-d\TH:i:s.u\Z',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:s',
        'd.m.Y',
        'd.m.Y H:i:s',
        'd.m.Y H:i',
    ];

    private const TRUE_VALUES = ['true', '1', 't', 'yes', 'y', 'on', 'да'];
    private const FALSE_VALUES = ['false', '0', 'f', 'no', 'n', 'off', 'нет'];

    private readonly \DateTimeZone $utc;

    public function __construct(private readonly \DateTimeZone $businessTimezone = new \DateTimeZone('UTC'))
    {
        $this->utc = new \DateTimeZone('UTC');
    }

    public function businessTimezone(): \DateTimeZone
    {
        return $this->businessTimezone;
    }

    public function cast(mixed $value, CastOptions $options): CastResult
    {
        if ($options->type === CastType::String) {
            return CastResult::ok($this->castString($value, $options));
        }
        if (self::isEmpty($value)) {
            return $options->type === CastType::Bool ? CastResult::ok(false) : CastResult::empty();
        }
        return match ($options->type) {
            CastType::Int => $this->castInt($value),
            CastType::Decimal => $this->castDecimal($value, $options->scale),
            CastType::Date => $this->castDate($value, $options, dateOnly: true),
            CastType::DateTime => $this->castDate($value, $options, dateOnly: false),
            CastType::Bool => $this->castBool($value),
            CastType::List => $this->castList($value, $options),
        };
    }

    /**
     * "Empty" for every type except string and bool: null, a string blank after trim, an empty array.
     */
    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }

    /**
     * Equality of two values cast with the same options.
     */
    public function equals(mixed $a, mixed $b, CastOptions $options): bool
    {
        return match ($options->type) {
            CastType::Decimal => bccomp((string)$a, (string)$b, $options->scale) === 0,
            CastType::Date => self::dateKey($a) === self::dateKey($b),
            CastType::DateTime => self::momentKey($a) === self::momentKey($b),
            CastType::List => self::asSet((array)$a) === self::asSet((array)$b),
            default => $a === $b,
        };
    }

    /**
     * Three-way comparison (-1 / 0 / 1) of two values of an orderable type.
     */
    public function compare(mixed $a, mixed $b, CastOptions $options): int
    {
        return match ($options->type) {
            CastType::Int => $a <=> $b,
            CastType::Decimal => bccomp((string)$a, (string)$b, $options->scale),
            CastType::Date => strcmp(self::dateKey($a), self::dateKey($b)) <=> 0,
            CastType::DateTime => bccomp(self::momentKey($a), self::momentKey($b), 6),
            default => throw new \LogicException("Cast \"{$options->type->value}\" is not orderable"),
        };
    }

    /**
     * Normalizes any value to the string form used by string comparisons (former ComparatorRegistry::stringify()).
     */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            is_scalar($value) => (string)$value,
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            default => (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * Readable form of a cast value for traces and the audit.
     */
    public static function export(mixed $value, CastType $type): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $type === CastType::Date ? $value->format('Y-m-d') : $value->format('Y-m-d\TH:i:sP');
        }
        return $value;
    }

    private function castString(mixed $value, CastOptions $options): string
    {
        $string = self::stringify($value);
        if ($options->trim) {
            $string = self::trimUnicode($string);
        }
        return $options->caseSensitive ? $string : self::fold($string);
    }

    /**
     * Case folding for comparisons (md/tasks_list/done/3.8-case-insensitive.md): simple folding, the same on both sides;
     * for Cyrillic it equals lower case. "ё" and "е" stay different, ß is not expanded to ss.
     */
    public static function fold(string $value): string
    {
        return mb_convert_case($value, MB_CASE_FOLD_SIMPLE, 'UTF-8');
    }

    /** trim() that also removes the no-break space and the byte order mark (imports, copy-paste). */
    public static function trimUnicode(string $value): string
    {
        return (string)preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $value);
    }

    private function castInt(mixed $value): CastResult
    {
        if (is_int($value)) {
            return CastResult::ok($value);
        }
        if (is_float($value)) {
            if (!is_finite($value) || floor($value) !== $value) {
                return CastResult::error(sprintf('Value "%s" is not an integer', self::stringify($value)));
            }
            if ($value > PHP_INT_MAX || $value < PHP_INT_MIN) {
                return CastResult::error(sprintf('Value "%s" is out of integer range, use cast "decimal"', self::stringify($value)));
            }
            return CastResult::ok((int)$value);
        }
        if (!is_string($value)) {
            return CastResult::error(sprintf('Value "%s" is not an integer', self::stringify($value)));
        }
        $normalized = self::normalizeNumberString($value);
        if (!preg_match('/^(-?\d+)(?:[.,]0+)?$/', $normalized, $m)) {
            return CastResult::error("Value \"{$value}\" is not an integer");
        }
        $digits = $m[1];
        if (bccomp($digits, (string)PHP_INT_MAX) > 0 || bccomp($digits, (string)PHP_INT_MIN) < 0) {
            return CastResult::error("Value \"{$value}\" is out of integer range, use cast \"decimal\"");
        }
        return CastResult::ok((int)$digits);
    }

    private function castDecimal(mixed $value, int $scale): CastResult
    {
        if (is_int($value)) {
            return CastResult::ok((string)$value);
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                return CastResult::error('Value is not a finite number');
            }
            $string = (string)$value;
            if (stripos($string, 'e') !== false) {
                $string = sprintf('%.' . $scale . 'F', $value);
            }
            return CastResult::ok(self::canonicalDecimal($string, $scale));
        }
        if (!is_string($value)) {
            return CastResult::error(sprintf('Value "%s" is not a valid decimal', self::stringify($value)));
        }
        $normalized = self::normalizeNumberString($value);
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            return CastResult::error("Ambiguous decimal separators in \"{$value}\"");
        }
        $normalized = str_replace(',', '.', $normalized);
        if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $normalized)) {
            return CastResult::error("Value \"{$value}\" is not a valid decimal");
        }
        return CastResult::ok(self::canonicalDecimal($normalized, $scale));
    }

    private function castDate(mixed $value, CastOptions $options, bool $dateOnly): CastResult
    {
        if ($value instanceof \DateTimeInterface) {
            $moment = \DateTimeImmutable::createFromInterface($value);
            return CastResult::ok($dateOnly ? $this->startOfBusinessDay($moment, true) : $moment->setTimezone($this->utc));
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return CastResult::error(sprintf('Value "%s" is not a date', self::stringify($value)));
        }
        $string = trim((string)$value);
        $formats = $options->formats !== [] ? $options->formats : self::DEFAULT_DATE_FORMATS;
        try {
            $sourceZone = new \DateTimeZone($options->timezone ?? 'UTC');
        } catch (\Exception) {
            return CastResult::error("Unknown timezone \"{$options->timezone}\"");
        }

        foreach ($formats as $format) {
            $zone = str_ends_with($format, '\Z') ? $this->utc : $sourceZone;
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $string, $zone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }
            $hasTime = (bool)preg_match('/(?<!\\\\)[GgHhisuvUaA]/', $format);
            if ($dateOnly) {
                return CastResult::ok($this->startOfBusinessDay($parsed, $hasTime));
            }
            return CastResult::ok($parsed->setTimezone($this->utc));
        }
        return CastResult::error(sprintf('Value "%s" is not a %s (expected %s)', $string, $dateOnly ? 'date' : 'date and time', $options->formats !== [] ? implode(' or ', $options->formats) : 'Y-m-d, Y-m-d H:i:s, ISO 8601 or d.m.Y'));
    }

    /**
     * A moment with a time is moved into the business time zone first; a pure date keeps its calendar day.
     */
    private function startOfBusinessDay(\DateTimeImmutable $moment, bool $hasTime): \DateTimeImmutable
    {
        $day = $hasTime ? $moment->setTimezone($this->businessTimezone)->format('Y-m-d') : $moment->format('Y-m-d');
        return new \DateTimeImmutable($day . ' 00:00:00', $this->businessTimezone);
    }

    private function castBool(mixed $value): CastResult
    {
        if (is_bool($value)) {
            return CastResult::ok($value);
        }
        if ((is_int($value) || is_float($value)) && ($value == 0 || $value == 1)) {
            return CastResult::ok($value == 1);
        }
        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));
            if (in_array($normalized, self::TRUE_VALUES, true)) {
                return CastResult::ok(true);
            }
            if (in_array($normalized, self::FALSE_VALUES, true)) {
                return CastResult::ok(false);
            }
        }
        return CastResult::error(sprintf('Value "%s" is not a boolean', self::stringify($value)));
    }

    private function castList(mixed $value, CastOptions $options): CastResult
    {
        if (is_array($value)) {
            $items = array_values($value);
        } elseif (is_string($value)) {
            $trimmed = trim($value);
            $decoded = str_starts_with($trimmed, '[') ? json_decode($trimmed, true) : null;
            $items = is_array($decoded) && array_is_list($decoded) ? $decoded : self::splitList($trimmed, $options);
        } else {
            $items = [$value];
        }

        $result = [];
        foreach ($items as $item) {
            $string = self::trimUnicode(self::stringify($item));
            if ($string === '') {
                continue;
            }
            $result[] = $options->caseSensitive ? $string : self::fold($string);
        }
        return $result === [] ? CastResult::empty() : CastResult::ok($result);
    }

    /**
     * Without a wrapper: plain explode. With a wrapper (SuiteCRM multienum "^a^,^b^"): the outer wrappers are removed
     * and the string is split by wrapper.separator.wrapper, so "^a,b^" stays one element (unencodeMultienum()).
     *
     * @return list<string>
     */
    private static function splitList(string $value, CastOptions $options): array
    {
        if ($options->wrapper === null) {
            return explode($options->separator, $value);
        }
        $wrapper = $options->wrapper;
        if (str_starts_with($value, $wrapper)) {
            $value = substr($value, strlen($wrapper));
        }
        if (str_ends_with($value, $wrapper)) {
            $value = substr($value, 0, -strlen($wrapper));
        }
        return explode($wrapper . $options->separator . $wrapper, $value);
    }

    /**
     * Removes spaces used as thousands separators (regular, no-break U+00A0, narrow no-break U+202F) and a leading "+".
     */
    private static function normalizeNumberString(string $value): string
    {
        $normalized = str_replace([' ', "\u{00A0}", "\u{202F}", "\t"], '', trim($value));
        return str_starts_with($normalized, '+') ? substr($normalized, 1) : $normalized;
    }

    /**
     * "+0012.3400" → "12.34", "-0.0" → "0", "5." → "5"; more fraction digits than $scale are rounded half away from zero.
     */
    private static function canonicalDecimal(string $number, int $scale): string
    {
        $number = ltrim($number, '+');
        $negative = str_starts_with($number, '-');
        $number = ltrim($number, '-');
        [$int, $frac] = array_pad(explode('.', $number, 2), 2, '');
        $int = ltrim($int, '0');
        $int = $int === '' ? '0' : $int;
        if (strlen($frac) > $scale) {
            $half = '0.' . str_repeat('0', $scale) . '5'; // 0.5 units of the last kept digit
            $rounded = bcadd($int . '.' . $frac, $half, $scale);
            [$int, $frac] = array_pad(explode('.', $rounded, 2), 2, '');
        }
        $frac = rtrim($frac, '0');
        $result = $int . ($frac !== '' ? '.' . $frac : '');
        return $negative && $result !== '0' ? '-' . $result : $result;
    }

    private static function dateKey(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string)$value;
    }

    private static function momentKey(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('U.u') : (string)$value;
    }

    /**
     * @param array<mixed> $items
     * @return list<string>
     */
    private static function asSet(array $items): array
    {
        $set = array_values(array_unique(array_map(static fn ($v) => (string)$v, $items)));
        sort($set, SORT_STRING);
        return $set;
    }
}
