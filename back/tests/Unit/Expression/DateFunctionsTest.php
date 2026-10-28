<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\Functions\DateFunctions;
use Wps\Time\DateMath;
use Wps\Time\FrozenClock;

final class DateFunctionsTest extends TestCase
{
    private static function engine(string $timezone = 'Europe/Moscow', string $now = '2026-10-16 07:30:00'): ExpressionEngine
    {
        $engine = new ExpressionEngine();
        DateFunctions::register($engine, new DateMath(new \DateTimeZone($timezone)), new FrozenClock($now));
        return $engine;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function eval(string $expression, array $vars = [], string $timezone = 'Europe/Moscow'): mixed
    {
        return self::engine($timezone)->evaluate($expression, static fn (string $path) => $vars[$path] ?? null);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function moscowCases(): iterable
    {
        // current moment and construction (WPS_TIMEZONE = Europe/Moscow, now 2026-10-16 07:30 UTC)
        yield 'now' => ['now()', '2026-10-16 07:30:00'];
        yield 'today' => ['today()', '2026-10-16'];
        yield 'today in tz' => ["today('America/New_York')", '2026-10-16'];
        yield 'date from d.m.Y' => ["date('16.10.2026')", '2026-10-16'];
        yield 'date from datetime takes the local day' => ["date('2026-10-15 22:30:00')", '2026-10-16'];
        yield 'date with format' => ["date('16/10/2026', 'd/m/Y')", '2026-10-16'];
        yield 'datetime local input' => ["datetime('2026-10-17 10:00', 'Y-m-d H:i', 'local')", '2026-10-17 07:00:00'];
        yield 'datetime iso offset' => ["datetime('2026-10-16T10:00:00+03:00')", '2026-10-16 07:00:00'];
        yield 'datetime iso Z' => ["datetime('2026-10-16T07:00:00Z')", '2026-10-16 07:00:00'];
        yield 'datetime of a date is local midnight' => ["datetime('2026-10-16')", '2026-10-15 21:00:00'];
        yield 'scenario: tomorrow 10:00 local' => ["datetime(concat(add_days(today(), 1), ' 10:00'), 'Y-m-d H:i', 'local')", '2026-10-17 07:00:00'];
        // arithmetic
        yield 'scenario: due in 3 days' => ['add_days(today(), 3)', '2026-10-19'];
        yield 'add_days negative' => ["add_days('2026-03-01', -1)", '2026-02-28'];
        yield 'add_days string count' => ["add_days('2026-10-16', '2')", '2026-10-18'];
        yield 'add_weeks' => ["add_weeks('2026-10-16', 2)", '2026-10-30'];
        yield 'add_hours on a date' => ["add_hours('2026-10-16', 2)", '2026-10-15 23:00:00'];
        yield 'add_minutes' => ["add_minutes('2026-10-16 23:50:00', 15)", '2026-10-17 00:05:00'];
        yield 'add_months clips' => ["add_months('2026-01-31', 1)", '2026-02-28'];
        yield 'add_months leap' => ["add_months('2024-01-31', 1)", '2024-02-29'];
        yield 'add_months back' => ["add_months('2026-03-31', -1)", '2026-02-28'];
        yield 'add_months across years back' => ["add_months('2026-01-15', -13)", '2024-12-15'];
        yield 'add_months 29 Feb + 12' => ["add_months('2024-02-29', 12)", '2025-02-28'];
        yield 'add_years 29 Feb' => ["add_years('2024-02-29', 1)", '2025-02-28'];
        yield 'add_months keeps time' => ["add_months('2026-01-31 09:00:00', 1)", '2026-02-28 09:00:00'];
        // date_diff
        yield 'scenario: days without movement' => ["date_diff('2026-10-01 10:00:00', now(), 'days')", 15];
        yield 'diff days negative' => ["date_diff('2026-10-16', '2026-10-15', 'days')", -1];
        yield 'diff days are calendar days in local' => ["date_diff('2026-10-15 20:00:00', '2026-10-16 10:00:00', 'days')", 1];
        yield 'diff hours' => ["date_diff('2026-10-15 20:00:00', '2026-10-16 10:00:00', 'hours')", 14];
        yield 'diff minutes truncates' => ["date_diff('2026-10-16 10:00:00', '2026-10-16 10:01:59', 'minutes')", 1];
        yield 'diff minutes truncates toward zero' => ["date_diff('2026-10-16 10:01:59', '2026-10-16 10:00:00', 'minutes')", -1];
        yield 'diff weeks' => ["date_diff('2026-10-01', '2026-10-16', 'weeks')", 2];
        yield 'diff months not full' => ["date_diff('2026-01-31', '2026-02-28', 'months')", 0];
        yield 'diff months' => ["date_diff('2026-01-15', '2026-03-15', 'months')", 2];
        yield 'diff months negative' => ["date_diff('2026-03-15', '2026-01-15', 'months')", -2];
        yield 'diff years' => ["date_diff('2020-05-01', '2026-04-30', 'years')", 5];
        yield 'diff unit case-insensitive' => ["date_diff('2026-10-15', '2026-10-16', 'DAYS')", 1];
        // parts
        yield 'weekday' => ["weekday('2026-10-16')", 5];
        yield 'week' => ["week('2026-10-16')", 42];
        yield 'quarter' => ["quarter('2026-10-16')", 4];
        yield 'year' => ["year('2026-10-16')", 2026];
        yield 'month' => ["month('2026-10-16')", 10];
        yield 'day of datetime in local' => ["day('2026-10-15 22:30:00')", 16];
        yield 'hour in local' => ["hour('2026-10-16 07:30:00')", 10];
        yield 'hour in UTC' => ["hour('2026-10-16 07:30:00', 'UTC')", 7];
        yield 'minute' => ["minute('2026-10-16 07:31:00')", 31];
        // boundaries
        yield 'start_of_week' => ["start_of_week('2026-10-16')", '2026-10-12'];
        yield 'end_of_week' => ["end_of_week('2026-10-16')", '2026-10-18'];
        yield 'start_of_day of a date' => ["start_of_day('2026-10-16')", '2026-10-16'];
        yield 'end_of_month february' => ["end_of_month('2026-02-10')", '2026-02-28'];
        yield 'start_of_quarter' => ["start_of_quarter('2026-08-20')", '2026-07-01'];
        yield 'scenario: end of quarter' => ['end_of_quarter(today())', '2026-12-31'];
        yield 'start_of_year' => ["start_of_year('2026-10-16')", '2026-01-01'];
        yield 'end_of_year' => ["end_of_year('2026-10-16')", '2026-12-31'];
        yield 'end_of_day datetime' => ["end_of_day('2026-10-16 07:30:00')", '2026-10-16 20:59:59'];
        yield 'start_of_month datetime in local' => ["start_of_month('2026-10-01 01:00:00')", '2026-09-30 21:00:00'];
        yield 'end_of_quarter datetime' => ["end_of_quarter('2026-11-15 12:00:00')", '2026-12-31 20:59:59'];
        // checks and formatting
        yield 'is_weekend saturday' => ["is_weekend('2026-10-17')", true];
        yield 'is_weekend friday' => ["is_weekend('2026-10-16')", false];
        yield 'is_weekend local night' => ["is_weekend('2026-10-16 22:00:00')", true];
        yield 'is_weekend explicit UTC' => ["is_weekend('2026-10-16 22:00:00', 'UTC')", false];
        yield 'scenario: format for a letter' => ["format_date('2026-10-16', 'd.m.Y')", '16.10.2026'];
        yield 'format datetime in local' => ["format_date('2026-10-16 07:30:00', 'd.m.Y H:i')", '16.10.2026 10:30'];
        yield 'format datetime in tz' => ["format_date('2026-10-16 07:30:00', 'H:i', 'UTC')", '07:30'];
        // empty values flow through
        yield 'empty string' => ["add_days('', 3)", ''];
        yield 'missing path' => ['add_days(object.none, 3)', ''];
        yield 'zero date' => ["format_date('0000-00-00', 'd.m.Y')", ''];
        yield 'zero datetime' => ["date_diff('0000-00-00 00:00:00', now(), 'days')", ''];
        yield 'empty part' => ["year('')", ''];
        // comparison of canonical strings
        yield 'compare dates' => ["add_days(today(), 3) > '2026-10-18'", true];
    }

    #[DataProvider('moscowCases')]
    public function testMoscow(string $expression, mixed $expected): void
    {
        self::assertSame($expected, self::eval($expression));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function berlinDstCases(): iterable
    {
        // spring forward 2026-03-29 02:00 CET → 03:00 CEST; fall back 2026-10-25 03:00 CEST → 02:00 CET
        yield 'add_days keeps local time in spring' => ["add_days('2026-03-28 06:00:00', 1)", '2026-03-29 05:00:00'];
        yield 'add_days keeps local time in autumn' => ["add_days('2026-10-24 07:00:00', 1)", '2026-10-25 08:00:00'];
        yield 'add_hours is absolute' => ["add_hours('2026-03-29 00:30:00', 1)", '2026-03-29 01:30:00'];
        yield 'add_hours over the gap' => ["format_date(add_hours('2026-03-29 00:30:00', 1), 'H:i')", '03:30'];
        yield 'start_of_day on DST day' => ["start_of_day('2026-03-29 12:00:00')", '2026-03-28 23:00:00'];
        yield 'end_of_day on DST day' => ["end_of_day('2026-10-25 12:00:00')", '2026-10-25 22:59:59'];
        yield 'date_diff hours over spring' => ["date_diff(datetime('2026-03-29'), datetime('2026-03-30'), 'hours')", 23];
        yield 'date_diff hours over autumn' => ["date_diff(datetime('2026-10-25'), datetime('2026-10-26'), 'hours')", 25];
        yield 'date_diff days over spring' => ["date_diff('2026-03-28 12:00:00', '2026-03-29 12:00:00', 'days')", 1];
        yield 'date_diff months over DST' => ["date_diff('2026-03-01 10:00:00', '2026-04-01 09:00:00', 'months')", 1];
    }

    #[DataProvider('berlinDstCases')]
    public function testBerlinDst(string $expression, mixed $expected): void
    {
        self::assertSame($expected, self::eval($expression, [], 'Europe/Berlin'));
    }

    public function testFormatDateLocale(): void
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            self::assertFalse(self::engine()->hasFunction('format_date_locale'));
            return;
        }
        self::assertSame('16 октября 2026', self::eval("format_date_locale('2026-10-16', 'd MMMM y', 'ru')"));
        self::assertSame('17 October', self::eval("format_date_locale('2026-10-16 22:30:00', 'd MMMM', 'en')"));
        self::assertSame('16 October', self::eval("format_date_locale('2026-10-16 22:30:00', 'd MMMM', 'en', 'UTC')"));
        self::assertSame('', self::eval("format_date_locale('', 'd MMMM', 'en')"));
    }

    public function testUtcDefault(): void
    {
        self::assertSame('2026-10-15', self::eval("date('2026-10-15 22:30:00')", [], 'UTC'));
        self::assertSame('2026-10-16', self::engine('UTC', '2026-10-16 23:59:59')->evaluate('today()', static fn () => null));
    }

    public function testFieldValues(): void
    {
        $vars = ['object.date_modified' => '2026-10-10 12:00:00', 'object.date_closed' => '2026-10-31'];
        self::assertSame('2026-10-13', self::eval('add_days(date(object.date_modified), 3)', $vars));
        self::assertSame('31.10.2026', self::eval("format_date(object.date_closed, 'd.m.Y')", $vars));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runtimeErrors(): iterable
    {
        yield 'unparseable' => ["add_days('abc', 3)", 'Cannot parse date "abc" in add_days()'];
        yield 'text count' => ["add_days('2026-10-16', 'x')", 'Function "add_days": count must be an integer, got "x"'];
        yield 'fractional count' => ["add_days('2026-10-16', 1.5)", 'Function "add_days": count must be an integer, got 1.5'];
        yield 'number as date' => ['add_days(20261016, 1)', 'Cannot parse date "20261016" in add_days()'];
        yield 'invalid calendar date' => ["date('2026-02-30')", 'Cannot parse date "2026-02-30" in date()'];
        yield 'computed timezone' => ['today(object.tz)', 'Unknown timezone "Mars/Base" in today()'];
        yield 'computed unit' => ["date_diff('2026-10-16', '2026-10-17', object.unit)", 'Function "date_diff": unit must be one of minutes, hours, days, weeks, months, years, got "decades"'];
        yield 'format mismatch' => ["date('2026-10-16', 'd.m.Y')", 'Cannot parse date "2026-10-16" in date()'];
    }

    #[DataProvider('runtimeErrors')]
    public function testRuntimeErrors(string $expression, string $message): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage($message);
        self::eval($expression, ['object.tz' => 'Mars/Base', 'object.unit' => 'decades']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function saveErrors(): iterable
    {
        yield 'working days are out of scope' => ['add_workdays(today(), 1)', 'Unknown function "add_workdays"'];
        yield 'literal unit' => ["date_diff(object.a, now(), 'decades')", 'Function "date_diff": unit must be one of minutes, hours, days, weeks, months, years, got "decades"'];
        yield 'literal timezone' => ["today('Mars/Base')", 'Unknown timezone "Mars/Base"'];
        yield 'literal timezone in format_date' => ["format_date(now(), 'H:i', 'Moon')", 'Unknown timezone "Moon"'];
        yield 'empty format' => ["format_date(now(), '')", 'Function "format_date": format must not be empty'];
        yield 'arity' => ['add_days(today())', 'Function "add_days" expects 2 arguments, got 1'];
        yield 'now takes nothing' => ['now(1)', 'Function "now" expects 0 arguments, got 1'];
    }

    #[DataProvider('saveErrors')]
    public function testSaveErrors(string $expression, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        self::engine()->parse($expression);
    }

    public function testValidLiteralsAreAccepted(): void
    {
        $engine = self::engine();
        foreach (["today('local')", "today('UTC')", "hour(now(), 'Asia/Tokyo')", "date_diff(object.a, object.b, 'months')", 'today(object.tz)'] as $expression) {
            self::assertIsArray($engine->parse($expression));
        }
    }

    public function testDictionary(): void
    {
        $specs = [];
        foreach (self::engine()->functionSpecs() as $spec) {
            $specs[$spec->name] = $spec;
        }
        self::assertSame('date', $specs['add_days']->category);
        self::assertFalse($specs['now']->pure);
        self::assertFalse($specs['today']->pure);
        self::assertTrue($specs['add_days']->pure);
        foreach (['start_of_quarter', 'end_of_year', 'weekday', 'format_date', 'is_weekend', 'date_diff', 'datetime'] as $name) {
            self::assertArrayHasKey($name, $specs);
        }
        self::assertArrayNotHasKey('add_workdays', $specs);
    }

    public function testNowFollowsTheClock(): void
    {
        $clock = new FrozenClock('2026-10-16 07:30:00');
        $engine = new ExpressionEngine();
        DateFunctions::register($engine, new DateMath(new \DateTimeZone('Europe/Moscow')), $clock);
        self::assertSame('2026-10-16 07:30:00', $engine->evaluate('now()', static fn () => null));
        $clock->set('2026-10-16 21:30:00');
        self::assertSame('2026-10-17 00:30:00', $engine->evaluate("format_date(now(), 'Y-m-d H:i:s')", static fn () => null));
        self::assertSame('2026-10-17', $engine->evaluate('today()', static fn () => null));
    }
}
