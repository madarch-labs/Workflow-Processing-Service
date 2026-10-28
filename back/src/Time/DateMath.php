<?php

declare(strict_types=1);

namespace Wps\Time;

use Wps\Exceptions\NonRecoverableException;
use Wps\Types\TypeCaster;

/**
 * Date arithmetic without expressions (md/tasks_list/done/5.2.2-expr-dates.md §4): used by the date functions of
 * the expression engine, the date_shift change type, relative dates in conditions and delays.
 *
 * Values are canonical strings: a date is "Y-m-d" (a calendar day without a time zone), a datetime is
 * "Y-m-d H:i:s" in UTC. A datetime without an offset is read as UTC (that is what the CRM sends); a date-only value
 * that needs a moment is midnight in the business time zone (WPS_TIMEZONE, "local"). Calendar operations on a
 * datetime (days, months, period boundaries, parts) are done in local time, so 09:00 stays 09:00 across DST changes.
 * Working days are out of scope: add a unit / function here to support them.
 */
final class DateMath
{
    public const DIFF_UNITS = ['minutes', 'hours', 'days', 'weeks', 'months', 'years'];
    public const PERIODS = ['day', 'week', 'month', 'quarter', 'year'];
    public const DATE_FORMAT = 'Y-m-d';
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /** Upper bound for counts, keeps results inside years 0..9999 */
    private const MAX_COUNT = 10_000_000;

    private const EMPTY_VALUES = ['', '0000-00-00', '0000-00-00 00:00:00', '0000-00-00 00:00'];

    private readonly \DateTimeZone $utc;

    public function __construct(private readonly \DateTimeZone $local = new \DateTimeZone('UTC'))
    {
        $this->utc = new \DateTimeZone('UTC');
    }

    public function local(): \DateTimeZone
    {
        return $this->local;
    }

    /**
     * Time zone argument: null or "local" — WPS_TIMEZONE, otherwise "UTC" or an IANA name.
     */
    public function zone(?string $name, string $fn): \DateTimeZone
    {
        if ($name === null || strtolower($name) === 'local') {
            return $this->local;
        }
        $error = self::zoneError($name);
        if ($error !== null) {
            throw new NonRecoverableException("{$error} in {$fn}()");
        }
        return new \DateTimeZone($name);
    }

    public static function zoneError(string $name): ?string
    {
        if (strtolower($name) === 'local') {
            return null;
        }
        try {
            new \DateTimeZone($name);
            return null;
        } catch (\Exception) {
            return "Unknown timezone \"{$name}\"";
        }
    }

    /**
     * Parses a date or datetime; empty values ('' / null / "0000-00-00") give null.
     *
     * @param \DateTimeZone|null $sourceZone zone of a datetime written without an offset (UTC by default)
     * @param string|null $format PHP DateTime::createFromFormat format for non-standard input
     */
    public function parse(mixed $value, string $fn, ?\DateTimeZone $sourceZone = null, ?string $format = null): ?ParsedDate
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return new ParsedDate(\DateTimeImmutable::createFromInterface($value)->setTimezone($this->utc), false);
        }
        if (!is_string($value)) {
            throw new NonRecoverableException(sprintf('Cannot parse date "%s" in %s()', is_scalar($value) ? var_export($value, true) : get_debug_type($value), $fn));
        }
        $string = trim($value);
        if (in_array($string, self::EMPTY_VALUES, true)) {
            return null;
        }
        $zone = $sourceZone ?? $this->utc;
        foreach ($format !== null ? [$format] : TypeCaster::DEFAULT_DATE_FORMATS as $candidate) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $candidate, $string, $zone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }
            if (!preg_match('/(?<!\\\\)[GgHhisuvUaA]/', $candidate)) {
                return $this->date($parsed->format(self::DATE_FORMAT));
            }
            return new ParsedDate($parsed->setTimezone($this->utc), false);
        }
        throw new NonRecoverableException("Cannot parse date \"{$string}\" in {$fn}()");
    }

    public function toCanonical(ParsedDate $date): string
    {
        return $date->value->format($date->isDateOnly ? self::DATE_FORMAT : self::DATETIME_FORMAT);
    }

    /**
     * Integer count argument ("3", 3, 3.0); fractions and text are errors.
     */
    public static function count(mixed $value, string $fn): int
    {
        $int = match (true) {
            is_int($value) => $value,
            is_float($value) && is_finite($value) && floor($value) === $value => (int)$value,
            is_string($value) && preg_match('/^\s*[+-]?\d{1,18}\s*$/', $value) === 1 => (int)trim($value),
            default => null,
        };
        if ($int === null || abs($int) > self::MAX_COUNT) {
            $shown = is_scalar($value) || $value === null ? (string)var_export($value, true) : get_debug_type($value);
            throw new NonRecoverableException(sprintf('Function "%s": count must be an integer, got %s', $fn, is_string($value) ? "\"{$value}\"" : $shown));
        }
        return $int;
    }

    /**
     * Calendar day of the value in the given zone (local by default).
     */
    public function toDate(ParsedDate $date, ?\DateTimeZone $zone = null): ParsedDate
    {
        return $date->isDateOnly ? $date : $this->date($date->value->setTimezone($zone ?? $this->local)->format(self::DATE_FORMAT));
    }

    /**
     * A moment in UTC; a date-only value becomes midnight in the given zone (local by default).
     */
    public function toDateTime(ParsedDate $date, ?\DateTimeZone $zone = null): ParsedDate
    {
        return $date->isDateOnly ? new ParsedDate($this->wallClock($date, $zone ?? $this->local)->setTimezone($this->utc), false) : $date;
    }

    public function addMinutes(ParsedDate $date, int $minutes): ParsedDate
    {
        return new ParsedDate($this->toDateTime($date)->value->modify(sprintf('%+d minutes', $minutes)), false);
    }

    public function addDays(ParsedDate $date, int $days): ParsedDate
    {
        return $this->inCalendar($date, static fn (\DateTimeImmutable $d) => $d->modify(sprintf('%+d days', $days)));
    }

    /**
     * Adds months clipping the day to the end of the month: 2026-01-31 + 1 month = 2026-02-28.
     */
    public function addMonths(ParsedDate $date, int $months): ParsedDate
    {
        return $this->inCalendar($date, static function (\DateTimeImmutable $d) use ($months): \DateTimeImmutable {
            $total = (int)$d->format('Y') * 12 + (int)$d->format('n') - 1 + $months;
            $year = intdiv($total, 12) - ($total % 12 < 0 ? 1 : 0);
            $month = $total - $year * 12 + 1;
            $last = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
            return $d->setDate($year, $month, min((int)$d->format('j'), $last));
        });
    }

    /**
     * Sets the local wall-clock time (WPS_TIMEZONE); the result is a datetime. A time skipped by a DST change moves
     * to the next existing one.
     */
    public function setTime(ParsedDate $date, int $hour, int $minute): ParsedDate
    {
        return new ParsedDate($this->wallClock($date, $this->local)->setTime($hour, $minute, 0)->setTimezone($this->utc), false);
    }

    /**
     * to − from in whole units, truncated toward zero. "days" counts calendar days (a datetime is taken in local
     * time), months / years count full months.
     */
    public function diff(ParsedDate $from, ParsedDate $to, string $unit): int
    {
        switch ($unit) {
            case 'minutes':
            case 'hours':
                $seconds = $this->toDateTime($to)->value->getTimestamp() - $this->toDateTime($from)->value->getTimestamp();
                return intdiv($seconds, $unit === 'minutes' ? 60 : 3600);
            case 'days':
            case 'weeks':
                $days = intdiv($this->toDate($to)->value->getTimestamp() - $this->toDate($from)->value->getTimestamp(), 86400);
                return $unit === 'days' ? $days : intdiv($days, 7);
            case 'months':
            case 'years':
                $interval = $this->naive($from)->diff($this->naive($to));
                $months = ($interval->y * 12 + $interval->m) * ($interval->invert === 1 ? -1 : 1);
                return $unit === 'months' ? $months : intdiv($months, 12);
        }
        throw new NonRecoverableException(sprintf('Function "date_diff": unit must be one of %s, got "%s"', implode(', ', self::DIFF_UNITS), $unit));
    }

    /**
     * First moment of the period (week starts on Monday). The kind of value is kept.
     */
    public function startOf(ParsedDate $date, string $period): ParsedDate
    {
        return $this->inCalendar($date, fn (\DateTimeImmutable $d) => $this->periodStart($d, $period));
    }

    /**
     * Last second of the period (23:59:59 of its last day). The kind of value is kept.
     */
    public function endOf(ParsedDate $date, string $period): ParsedDate
    {
        return $this->inCalendar($date, function (\DateTimeImmutable $d) use ($period): \DateTimeImmutable {
            $start = $this->periodStart($d, $period);
            $last = match ($period) {
                'day' => $start,
                'week' => $start->modify('+6 days'),
                'month' => $start->setDate((int)$start->format('Y'), (int)$start->format('n'), (int)$start->format('t')),
                'quarter' => $start->modify('+2 months')->modify('last day of this month'),
                default => $start->setDate((int)$start->format('Y'), 12, 31),
            };
            return $last->setTime(23, 59, 59);
        });
    }

    /**
     * Part of the date in the given zone (local by default): year, month, day, hour, minute, weekday (ISO, 1 =
     * Monday), week (ISO), quarter.
     */
    public function part(ParsedDate $date, string $part, ?\DateTimeZone $zone = null): int
    {
        $d = $this->wallClock($date, $zone ?? $this->local);
        return match ($part) {
            'year' => (int)$d->format('Y'),
            'month' => (int)$d->format('n'),
            'day' => (int)$d->format('j'),
            'hour' => (int)$d->format('G'),
            'minute' => (int)$d->format('i'),
            'weekday' => (int)$d->format('N'),
            'week' => (int)$d->format('W'),
            'quarter' => intdiv((int)$d->format('n') - 1, 3) + 1,
            default => throw new \InvalidArgumentException("Unknown date part \"{$part}\""),
        };
    }

    public function isWeekend(ParsedDate $date, ?\DateTimeZone $zone = null): bool
    {
        return $this->part($date, 'weekday', $zone) >= 6;
    }

    /**
     * PHP DateTimeInterface::format tokens; a datetime is shown in the given zone (local by default).
     */
    public function format(ParsedDate $date, string $format, ?\DateTimeZone $zone = null): string
    {
        return $this->wallClock($date, $zone ?? $this->local)->format($format);
    }

    /**
     * The value as wall-clock time in a zone; a date-only value is midnight there.
     */
    public function wallClock(ParsedDate $date, \DateTimeZone $zone): \DateTimeImmutable
    {
        return $date->isDateOnly
            ? new \DateTimeImmutable($date->value->format(self::DATE_FORMAT) . ' 00:00:00', $zone)
            : $date->value->setTimezone($zone);
    }

    private function date(string $day): ParsedDate
    {
        return new ParsedDate(new \DateTimeImmutable($day . ' 00:00:00', $this->utc), true);
    }

    /**
     * Applies a calendar operation: to the naive day of a date, to the local wall-clock time of a datetime.
     *
     * @param \Closure(\DateTimeImmutable): \DateTimeImmutable $operation
     */
    private function inCalendar(ParsedDate $date, \Closure $operation): ParsedDate
    {
        if ($date->isDateOnly) {
            $result = $operation($date->value);
            return $this->date($result->format(self::DATE_FORMAT));
        }
        return new ParsedDate($operation($date->value->setTimezone($this->local))->setTimezone($this->utc), false);
    }

    private function periodStart(\DateTimeImmutable $d, string $period): \DateTimeImmutable
    {
        $year = (int)$d->format('Y');
        $start = match ($period) {
            'day' => $d,
            'week' => $d->modify(sprintf('-%d days', (int)$d->format('N') - 1)),
            'month' => $d->setDate($year, (int)$d->format('n'), 1),
            'quarter' => $d->setDate($year, intdiv((int)$d->format('n') - 1, 3) * 3 + 1, 1),
            'year' => $d->setDate($year, 1, 1),
            default => throw new \InvalidArgumentException("Unknown period \"{$period}\""),
        };
        return $start->setTime(0, 0, 0);
    }

    /**
     * Local wall-clock time as a UTC value, so DateInterval is not distorted by DST.
     */
    private function naive(ParsedDate $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->wallClock($date, $this->local)->format(self::DATETIME_FORMAT), $this->utc);
    }
}
