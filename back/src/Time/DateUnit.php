<?php

declare(strict_types=1);

namespace Wps\Time;

/**
 * Calendar units of a date shift (date_shift, md/tasks_list/done/5.1.2-date-shift.md). Working days are out of
 * scope: a new case here plus a DateMath method is the extension point.
 */
enum DateUnit: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Minute => 'Минуты',
            self::Hour => 'Часы',
            self::Day => 'Дни',
            self::Week => 'Недели',
            self::Month => 'Месяцы',
            self::Year => 'Годы',
        };
    }

    /**
     * "day", "days", "Day" → Day.
     */
    public static function parse(string $name): ?self
    {
        $name = strtolower(trim($name));
        return self::tryFrom($name) ?? (str_ends_with($name, 's') ? self::tryFrom(substr($name, 0, -1)) : null);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $u) => $u->value, self::cases());
    }

    public function apply(DateMath $math, ParsedDate $date, int $amount): ParsedDate
    {
        return match ($this) {
            self::Minute => $math->addMinutes($date, $amount),
            self::Hour => $math->addMinutes($date, $amount * 60),
            self::Day => $math->addDays($date, $amount),
            self::Week => $math->addDays($date, $amount * 7),
            self::Month => $math->addMonths($date, $amount),
            self::Year => $math->addMonths($date, $amount * 12),
        };
    }
}
