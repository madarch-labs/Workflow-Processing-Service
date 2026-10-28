<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Types;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Crm\FilterTranslator;
use Wps\Services\ConditionEngineService;
use Wps\Time\DateMath;
use Wps\Time\FrozenClock;
use Wps\Types\RelativeDate;
use Wps\Types\TypeCaster;

final class RelativeDateTest extends TestCase
{
    private static function at(string $value, string $now, string $zone = 'Europe/Moscow', string $format = 'Y-m-d H:i:s'): string
    {
        $tz = new \DateTimeZone($zone);
        return RelativeDate::resolve($value, new \DateTimeImmutable($now), new DateMath($tz))->setTimezone($tz)->format($format);
    }

    public function testParse(): void
    {
        self::assertTrue(RelativeDate::looksRelative('Today + 7D'));
        self::assertTrue(RelativeDate::looksRelative('start_of_decade'));
        self::assertFalse(RelativeDate::looksRelative('2026-10-16'));
        self::assertFalse(RelativeDate::looksRelative('todayish'));
        self::assertSame(['anchor' => 'now', 'shifts' => [[-2, 'h']]], RelativeDate::parse('NOW - 2H'));
        self::assertSame(['anchor' => 'start_of_month', 'shifts' => [[-1, 'mo']]], RelativeDate::parse('start_of_month-1mo'));
        self::assertSame('Invalid relative date "today+7days": unknown unit "days" at position 7 (use min, h, d, w, mo, y)', RelativeDate::error('today+7days'));
        self::assertSame('Invalid relative date "today+10wd": unknown unit "wd" at position 8 (use min, h, d, w, mo, y)', RelativeDate::error('today+10wd'));
        self::assertStringContainsString('expected +N or -N', (string)RelativeDate::error('today+'));
        self::assertStringContainsString('expected +N or -N', (string)RelativeDate::error('tomorrow++1d'));
        self::assertSame('Unknown relative date anchor "start_of_decade"', RelativeDate::error('start_of_decade'));
        self::assertSame('Too many shifts in relative date (max 5)', RelativeDate::error('today+1d+1d+1d+1d+1d+1d'));
    }

    public function testResolve(): void
    {
        $now = '2026-10-16T22:30:00Z'; // 01:30 on 17.10 in Moscow
        self::assertSame('2026-10-17 00:00:00', self::at('today', $now));
        self::assertSame('2026-10-16 00:00:00', self::at('yesterday', $now));
        self::assertSame('2026-10-17 01:30:00', self::at('now', $now));
        self::assertSame('2026-10-16 23:30:00', self::at('now-2h', $now));
        self::assertSame('2026-11-16 00:00:00', self::at('today+30d', $now));
        self::assertSame('2026-02-28 23:59:59', self::at('end_of_month', '2026-02-10T09:00:00Z'));
        self::assertSame('2026-02-28 00:00:00', self::at('today+1mo', '2026-01-31T09:00:00Z'));
        self::assertSame('2026-10-12 00:00:00', self::at('start_of_week', '2026-10-18T09:00:00Z'), 'Sunday → Monday of the same ISO week');
        self::assertSame('2026-10-25 00:00:00', self::at('today+1w', '2026-10-18T09:00:00Z'));
        self::assertSame('2026-09-01 00:00:00', self::at('start_of_month-1mo', $now));
        self::assertSame('2026-12-31 23:59:59', self::at('end_of_quarter', $now));
        // DST in Berlin on 29.03.2026: a calendar day is 23 hours
        self::assertSame('2026-03-29 12:00:00', self::at('now+1d', '2026-03-28T11:00:00Z', 'Europe/Berlin'));
        self::assertSame('2026-03-29 13:00:00', self::at('now+24h', '2026-03-28T11:00:00Z', 'Europe/Berlin'));
    }

    public function testConditions(): void
    {
        $caster = new TypeCaster(new \DateTimeZone('Europe/Moscow'));
        $engine = new ConditionEngineService(ComparatorRegistry::default($caster), $caster, null, null, new FrozenClock('2026-10-16T09:00:00Z'));
        $context = ExecutionContext::fromEvent('e', ['id' => '1', 'date_end' => '2026-11-10', 'date_entered' => '2026-10-15 12:00:00', 'date_closed' => '2026-12-31', 'txt' => 'today'], 'Contracts');
        $result = $engine->evaluate([
            ['field' => 'object.date_end', 'operator' => 'less_than', 'cast' => 'date', 'value' => 'today+30d'],
            ['field' => 'object.date_entered', 'operator' => 'greater_or_equal', 'cast' => 'datetime', 'value' => 'now-24h'],
            ['field' => 'object.date_closed', 'operator' => 'between', 'cast' => 'date', 'value' => ['start_of_quarter', 'end_of_quarter']],
        ], $context);
        self::assertTrue($result->isMet(), json_encode($result->trace));
        self::assertSame('2026-11-15', $result->trace[0]['expected_resolved']);
        self::assertSame('Europe/Moscow', $result->trace[0]['timezone']);
        self::assertSame(['2026-10-01', '2026-12-31'], $result->trace[2]['expected_resolved']);
        $fromField = $engine->evaluate([['field' => 'object.date_end', 'operator' => 'greater_than', 'cast' => 'date', 'value_type' => 'from_object_field', 'value' => 'object.txt']], $context);
        self::assertFalse($fromField->isMet(), 'a value from a record is never interpreted');
        self::assertArrayHasKey('cast_error', $fromField->trace[0]);

        $filter = (new FilterTranslator($caster, new FrozenClock('2026-10-16T09:00:00Z')))->translate([['field' => 'date_due', 'operator' => 'less_than', 'cast' => 'datetime', 'value' => 'today']], $context);
        self::assertSame('2026-10-15 21:00:00', $filter->conditions()[0]['value'], 'midnight in Moscow as UTC storage format');
    }
}
