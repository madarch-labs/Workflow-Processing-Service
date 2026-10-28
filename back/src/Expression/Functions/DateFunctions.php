<?php

declare(strict_types=1);

namespace Wps\Expression\Functions;

use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\FunctionSpec;
use Wps\Time\Clock;
use Wps\Time\DateMath;
use Wps\Time\ParsedDate;

/**
 * Date and time functions (md/tasks_list/done/5.2.2-expr-dates.md §4.5). Results are canonical strings: a date is
 * "Y-m-d", a datetime is "Y-m-d H:i:s" in UTC; an empty argument gives ''. Registered by Container::expressions().
 */
final class DateFunctions
{
    private const PARTS = ['year', 'month', 'day', 'hour', 'minute', 'weekday', 'week', 'quarter'];

    public static function register(ExpressionEngine $engine, DateMath $math, Clock $clock): void
    {
        $parse = static fn (mixed $v, string $fn): ?ParsedDate => $math->parse($v, $fn);
        $out = static fn (?ParsedDate $d): string => $d === null ? '' : $math->toCanonical($d);
        $zone = static fn (mixed $tz, string $fn): \DateTimeZone => $math->zone($tz === null ? null : ExpressionEngine::toString($tz), $fn);
        $spec = static fn (string $name, int $min, ?int $max, string $signature, string $description, string $example, string $returns, ?\Closure $validator = null, bool $pure = true): FunctionSpec
            => new FunctionSpec($name, $min, $max, 'date', $signature, $description, $example, $returns, $pure, $validator);
        $tzAt = static fn (int ...$positions): \Closure => static function (array $args) use ($positions): ?string {
            foreach ($positions as $i) {
                $literal = FunctionSpec::literal($args[$i] ?? null);
                if ($literal !== null && ($error = DateMath::zoneError($literal)) !== null) {
                    return $error;
                }
            }
            return null;
        };

        $engine->registerFunction('now', static fn () => $clock->now()->format(DateMath::DATETIME_FORMAT),
            $spec('now', 0, 0, 'now()', 'Текущий момент, UTC', 'now()', 'datetime', pure: false));
        $engine->registerFunction('today', static fn (mixed $tz = null) => $clock->now()->setTimezone($zone($tz, 'today'))->format(DateMath::DATE_FORMAT),
            $spec('today', 0, 1, 'today([tz])', 'Сегодняшняя дата в поясе tz (по умолчанию WPS_TIMEZONE)', 'today()', 'date', $tzAt(0), false));

        $engine->registerFunction('date', static function (mixed $value, mixed $format = null) use ($math, $out): string {
            $parsed = $math->parse($value, 'date', null, self::format($format, 'date'));
            return $out($parsed === null ? null : $math->toDate($parsed));
        }, $spec('date', 1, 2, 'date(value[, format])', 'Календарная дата; из даты-времени берётся день в WPS_TIMEZONE; format — формат PHP для нестандартного ввода',
            "date('16.10.2026')", 'date', self::nonEmptyLiteral('date', 1, 'format')));
        $engine->registerFunction('datetime', static function (mixed $value, mixed $format = null, mixed $tz = null) use ($math, $out, $zone): string {
            $source = $zone($tz ?? 'UTC', 'datetime');
            $parsed = $math->parse($value, 'datetime', $source, self::format($format, 'datetime'));
            return $out($parsed === null ? null : $math->toDateTime($parsed, $tz === null ? null : $source));
        }, $spec('datetime', 1, 3, 'datetime(value[, format[, tz]])', "Дата-время в UTC; tz — пояс, в котором записан ввод без смещения (по умолчанию UTC, 'local' — WPS_TIMEZONE)",
            "datetime(concat(add_days(today(), 1), ' 10:00'), 'Y-m-d H:i', 'local')", 'datetime',
            self::all(self::nonEmptyLiteral('datetime', 1, 'format'), $tzAt(2))));

        $arithmetic = [
            'add_minutes' => ['Прибавляет минуты (абсолютное время); дата становится датой-временем', 'add_minutes(object.date_start, 30)', static fn (ParsedDate $d, int $n) => $math->addMinutes($d, $n)],
            'add_hours' => ['Прибавляет часы (абсолютное время); дата становится датой-временем', 'add_hours(now(), 2)', static fn (ParsedDate $d, int $n) => $math->addMinutes($d, $n * 60)],
            'add_days' => ['Прибавляет календарные дни; местное время сохраняется', 'add_days(today(), 3)', static fn (ParsedDate $d, int $n) => $math->addDays($d, $n)],
            'add_weeks' => ['Прибавляет недели', 'add_weeks(today(), 2)', static fn (ParsedDate $d, int $n) => $math->addDays($d, $n * 7)],
            'add_months' => ['Прибавляет месяцы с обрезкой конца месяца (31.01 + 1 = 28.02)', 'add_months(object.date_closed, 1)', static fn (ParsedDate $d, int $n) => $math->addMonths($d, $n)],
            'add_years' => ['Прибавляет годы (29.02 + 1 = 28.02)', 'add_years(object.contract_start, 1)', static fn (ParsedDate $d, int $n) => $math->addMonths($d, $n * 12)],
        ];
        foreach ($arithmetic as $name => [$description, $example, $operation]) {
            $engine->registerFunction($name, static function (mixed $value, mixed $count) use ($parse, $out, $operation, $name): string {
                $n = DateMath::count($count, $name);
                $parsed = $parse($value, $name);
                return $out($parsed === null ? null : $operation($parsed, $n));
            }, $spec($name, 2, 2, "{$name}(d, n)", $description, $example, 'date|datetime'));
        }

        $engine->registerFunction('date_diff', static function (mixed $from, mixed $to, mixed $unit) use ($parse, $math): int|string {
            $unit = strtolower(ExpressionEngine::toString($unit));
            if (!in_array($unit, DateMath::DIFF_UNITS, true)) {
                throw new NonRecoverableException(self::unitError($unit));
            }
            $a = $parse($from, 'date_diff');
            $b = $parse($to, 'date_diff');
            return $a === null || $b === null ? '' : $math->diff($a, $b, $unit);
        }, $spec('date_diff', 3, 3, 'date_diff(from, to, unit)', 'to − from целым числом единиц: minutes, hours, days (календарные дни), weeks, months, years (полные)',
            "date_diff(object.date_modified, now(), 'days')", 'number', static function (array $args): ?string {
                $literal = FunctionSpec::literal($args[2] ?? null);
                return $literal !== null && !in_array(strtolower($literal), DateMath::DIFF_UNITS, true) ? self::unitError($literal) : null;
            }));

        foreach (self::PARTS as $part) {
            $engine->registerFunction($part, static function (mixed $value, mixed $tz = null) use ($parse, $math, $zone, $part): int|string {
                $parsed = $parse($value, $part);
                return $parsed === null ? '' : $math->part($parsed, $part, $zone($tz, $part));
            }, $spec($part, 1, 2, "{$part}(d[, tz])", self::PART_TEXTS[$part], "{$part}(object.date_closed)", 'number', $tzAt(1)));
        }

        foreach (DateMath::PERIODS as $period) {
            foreach (['start' => 'Начало', 'end' => 'Конец'] as $edge => $text) {
                $name = "{$edge}_of_{$period}";
                $engine->registerFunction($name, static function (mixed $value) use ($parse, $out, $math, $edge, $period, $name): string {
                    $parsed = $parse($value, $name);
                    return $out($parsed === null ? null : ($edge === 'start' ? $math->startOf($parsed, $period) : $math->endOf($parsed, $period)));
                }, $spec($name, 1, 1, "{$name}(d)", $text . ' ' . self::PERIOD_TEXTS[$period] . ' (для даты-времени — в WPS_TIMEZONE, результат в UTC)', "{$name}(today())", 'date|datetime'));
            }
        }

        $engine->registerFunction('is_weekend', static function (mixed $value, mixed $tz = null) use ($parse, $math, $zone): bool|string {
            $parsed = $parse($value, 'is_weekend');
            return $parsed === null ? '' : $math->isWeekend($parsed, $zone($tz, 'is_weekend'));
        }, $spec('is_weekend', 1, 2, 'is_weekend(d[, tz])', 'Суббота или воскресенье (производственный календарь не учитывается)', 'is_weekend(today())', 'bool', $tzAt(1)));

        $engine->registerFunction('format_date', static function (mixed $value, mixed $format, mixed $tz = null) use ($parse, $math, $zone): string {
            $format = self::format($format, 'format_date') ?? throw new NonRecoverableException('Function "format_date": format must not be empty');
            $parsed = $parse($value, 'format_date');
            return $parsed === null ? '' : $math->format($parsed, $format, $zone($tz, 'format_date'));
        }, $spec('format_date', 2, 3, 'format_date(d, format[, tz])', 'Форматирует дату токенами PHP (d.m.Y H:i); дата-время показывается в поясе tz (по умолчанию WPS_TIMEZONE)',
            "format_date(object.date_closed, 'd.m.Y')", 'string', self::all(self::nonEmptyLiteral('format_date', 1, 'format'), $tzAt(2))));

        if (class_exists(\IntlDateFormatter::class)) {
            $engine->registerFunction('format_date_locale', static function (mixed $value, mixed $pattern, mixed $locale, mixed $tz = null) use ($parse, $math, $zone): string {
                $pattern = self::format($pattern, 'format_date_locale') ?? throw new NonRecoverableException('Function "format_date_locale": pattern must not be empty');
                $parsed = $parse($value, 'format_date_locale');
                if ($parsed === null) {
                    return '';
                }
                $moment = $math->wallClock($parsed, $parsed->isDateOnly ? new \DateTimeZone('UTC') : $zone($tz, 'format_date_locale'));
                $formatter = new \IntlDateFormatter(ExpressionEngine::toString($locale), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $moment->getTimezone(), null, $pattern);
                $result = $formatter->format($moment);
                if ($result === false) {
                    throw new NonRecoverableException('Function "format_date_locale": ' . $formatter->getErrorMessage());
                }
                return $result;
            }, $spec('format_date_locale', 3, 4, 'format_date_locale(d, pattern, locale[, tz])', 'Форматирует дату шаблоном ICU с названиями месяцев и дней на языке locale',
                "format_date_locale(object.date_closed, 'd MMMM y', 'ru')", 'string', self::all(self::nonEmptyLiteral('format_date_locale', 1, 'pattern'), $tzAt(3))));
        }
    }

    private const PART_TEXTS = [
        'year' => 'Год', 'month' => 'Месяц (1–12)', 'day' => 'День месяца', 'hour' => 'Час (0–23) в поясе tz',
        'minute' => 'Минута', 'weekday' => 'День недели по ISO: 1 — понедельник … 7 — воскресенье',
        'week' => 'Номер недели по ISO', 'quarter' => 'Квартал (1–4)',
    ];

    private const PERIOD_TEXTS = ['day' => 'дня', 'week' => 'недели (неделя с понедельника по воскресенье)', 'month' => 'месяца', 'quarter' => 'квартала', 'year' => 'года'];

    private static function format(mixed $format, string $fn): ?string
    {
        if ($format === null) {
            return null;
        }
        $string = ExpressionEngine::toString($format);
        if ($string === '') {
            throw new NonRecoverableException("Function \"{$fn}\": format must not be empty");
        }
        return $string;
    }

    private static function unitError(string $unit): string
    {
        return sprintf('Function "date_diff": unit must be one of %s, got "%s"', implode(', ', DateMath::DIFF_UNITS), $unit);
    }

    private static function nonEmptyLiteral(string $fn, int $position, string $what): \Closure
    {
        return static function (array $args) use ($fn, $position, $what): ?string {
            return FunctionSpec::literal($args[$position] ?? null) === '' ? "Function \"{$fn}\": {$what} must not be empty" : null;
        };
    }

    private static function all(\Closure ...$validators): \Closure
    {
        return static function (array $args) use ($validators): ?string {
            foreach ($validators as $validator) {
                if (($error = $validator($args)) !== null) {
                    return $error;
                }
            }
            return null;
        };
    }
}
