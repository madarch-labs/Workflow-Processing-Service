<?php

declare(strict_types=1);

namespace Wps\Types;

use Wps\Exceptions\ConfigurationException;
use Wps\Time\DateMath;
use Wps\Time\ParsedDate;

/**
 * Relative dates in static values of date / datetime conditions (md/tasks_list/done/3.7-relative-dates.md):
 *
 *   relative := anchor ( sign amount unit )*      today+30d, now-24h, start_of_month-1mo, end_of_quarter
 *   anchor   := now | today | yesterday | tomorrow | start_of_day | end_of_day | start_of_week | end_of_week
 *             | start_of_month | end_of_month | start_of_quarter | end_of_quarter | start_of_year | end_of_year
 *   unit     := min | h | d | w | mo | y              (calendar units only: working days are out of scope)
 *
 * Anchors are computed in the business time zone (WPS_TIMEZONE); end_of_* is the last second of the period; months
 * and years stick to the end of the month (31.01 + 1mo = 28.02). A concrete date starts with a digit, so it never
 * looks relative. Values read from records are never interpreted.
 */
final class RelativeDate
{
    public const ANCHORS = [
        'now' => 'Сейчас', 'today' => 'Сегодня', 'yesterday' => 'Вчера', 'tomorrow' => 'Завтра',
        'start_of_day' => 'Начало дня', 'end_of_day' => 'Конец дня',
        'start_of_week' => 'Начало недели', 'end_of_week' => 'Конец недели',
        'start_of_month' => 'Начало месяца', 'end_of_month' => 'Конец месяца',
        'start_of_quarter' => 'Начало квартала', 'end_of_quarter' => 'Конец квартала',
        'start_of_year' => 'Начало года', 'end_of_year' => 'Конец года',
    ];
    public const UNITS = ['min' => 'минут', 'h' => 'часов', 'd' => 'дней', 'w' => 'недель', 'mo' => 'месяцев', 'y' => 'лет'];
    public const MAX_SHIFTS = 5;

    /** @var array<string, array{anchor: string, shifts: list<array{int, string}>}> */
    private static array $cache = [];

    /**
     * The value starts with an anchor (or a word like one) followed by the end, a space, "+" or "-".
     */
    public static function looksRelative(mixed $value): bool
    {
        return is_string($value) && (bool)preg_match('/^\s*(now|today|yesterday|tomorrow|start_of_[a-z]+|end_of_[a-z]+)\s*([+\-]|$)/i', $value);
    }

    /**
     * @return array{anchor: string, shifts: list<array{int, string}>} shifts: [signed amount, unit]
     * @throws ConfigurationException with the position of the error
     */
    public static function parse(string $value): array
    {
        $key = strtolower(preg_replace('/\s+/', '', $value) ?? '');
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        if (!preg_match('/^([a-z_]+)/', $key, $m)) {
            throw new ConfigurationException("Invalid relative date \"{$value}\"");
        }
        $anchor = $m[1];
        if (!isset(self::ANCHORS[$anchor])) {
            throw new ConfigurationException("Unknown relative date anchor \"{$anchor}\"");
        }
        $shifts = [];
        $pos = strlen($anchor);
        while ($pos < strlen($key)) {
            if (!preg_match('/\G([+\-])(\d{1,5})([a-z]*)/', $key, $s, 0, $pos)) {
                throw new ConfigurationException(sprintf('Invalid relative date "%s": expected +N or -N with a unit at position %d', $value, $pos));
            }
            if (!isset(self::UNITS[$s[3]])) {
                throw new ConfigurationException(sprintf('Invalid relative date "%s": unknown unit "%s" at position %d (use %s)',
                    $value, $s[3], $pos + strlen($s[1]) + strlen($s[2]), implode(', ', array_keys(self::UNITS))));
            }
            $shifts[] = [($s[1] === '-' ? -1 : 1) * (int)$s[2], $s[3]];
            $pos += strlen($s[0]);
        }
        if (count($shifts) > self::MAX_SHIFTS) {
            throw new ConfigurationException('Too many shifts in relative date (max ' . self::MAX_SHIFTS . ')');
        }
        if (count(self::$cache) > 500) {
            self::$cache = [];
        }
        return self::$cache[$key] = ['anchor' => $anchor, 'shifts' => $shifts];
    }

    public static function error(string $value): ?string
    {
        try {
            self::parse($value);
            return null;
        } catch (ConfigurationException $e) {
            return $e->getMessage();
        }
    }

    /**
     * The moment the expression stands for (in the business time zone of $math).
     */
    public static function resolve(string $value, \DateTimeImmutable $now, DateMath $math): \DateTimeImmutable
    {
        $spec = self::parse($value);
        $date = new ParsedDate($now->setTimezone(new \DateTimeZone('UTC')), false); // hours and minutes are exact, calendar units are local
        $date = match ($spec['anchor']) {
            'now' => $date,
            'today', 'start_of_day' => $math->startOf($date, 'day'),
            'yesterday' => $math->addDays($math->startOf($date, 'day'), -1),
            'tomorrow' => $math->addDays($math->startOf($date, 'day'), 1),
            'end_of_day' => $math->endOf($date, 'day'),
            default => str_starts_with($spec['anchor'], 'start_of_')
                ? $math->startOf($date, substr($spec['anchor'], 9))
                : $math->endOf($date, substr($spec['anchor'], 7)),
        };
        foreach ($spec['shifts'] as [$amount, $unit]) {
            $date = match ($unit) {
                'min' => $math->addMinutes($date, $amount),
                'h' => $math->addMinutes($date, $amount * 60),
                'd' => $math->addDays($date, $amount),
                'w' => $math->addDays($date, $amount * 7),
                'mo' => $math->addMonths($date, $amount),
                default => $math->addMonths($date, $amount * 12),
            };
        }
        return $date->value;
    }
}
