<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Actions\ChangeTypes\DateShiftChangeType;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Services\Validation\DefinitionScope;
use Wps\Time\DateMath;
use Wps\Time\FrozenClock;

final class DateShiftChangeTypeTest extends TestCase
{
    private static function type(string $timezone = 'Europe/Moscow', string $now = '2026-10-16 07:30:00'): DateShiftChangeType
    {
        return new DateShiftChangeType(new DateMath(new \DateTimeZone($timezone)), new FrozenClock($now));
    }

    private static function context(): ExecutionContext
    {
        return new ExecutionContext('e1', [], [
            'object' => [
                'date_closed' => '2026-01-31', 'contract_end' => '2026-12-01', 'date_modified' => '2026-10-10 12:00:00',
                'zero' => '0000-00-00', 'empty' => '', 'bad' => 'abc', 'days' => '5', 'fraction' => '1.5', 'ru' => '16.10.2026 10:00',
                'berlin' => '2026-03-28 09:00:00', 'leap' => '2024-02-29',
            ],
        ]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, mixed, string}>
     */
    public static function cases(): iterable
    {
        // now = 2026-10-16 07:30 UTC = 10:30 in Moscow
        yield 'task due: now + 2 days at 18:00' => [['base' => 'now', 'shift' => [['amount' => 2, 'unit' => 'day']], 'set_time' => '18:00'], '2026-10-18 15:00:00', 'Europe/Moscow'];
        yield 'call tomorrow 10:00' => [['base' => 'today', 'shift' => '+1 day', 'set_time' => '10:00'], '2026-10-17 07:00:00', 'Europe/Moscow'];
        yield 'today + 1 day is a date' => [['base' => 'today', 'shift' => '+1 day'], '2026-10-17', 'Europe/Moscow'];
        yield 'reminder 30 days before' => [['base' => 'object.contract_end', 'shift' => '-30 day'], '2026-11-01', 'Europe/Moscow'];
        yield 'next contact + 1 month clips' => [['base' => 'object.date_closed', 'shift' => '+1 month'], '2026-02-28', 'Europe/Moscow'];
        yield 'leap day + 1 year' => [['base' => 'object.leap', 'shift' => '+1 year'], '2025-02-28', 'Europe/Moscow'];
        yield 'leap year february' => [['base' => 'object.leap', 'shift' => [['amount' => -1, 'unit' => 'month'], ['amount' => 2, 'unit' => 'month']]], '2024-03-29', 'Europe/Moscow'];
        yield 'SLA now + 8 hours' => [['base' => 'now', 'shift' => '+8 hour'], '2026-10-16 15:30:00', 'Europe/Moscow'];
        yield 'default base is now' => [['shift' => '+2 hours'], '2026-10-16 09:30:00', 'Europe/Moscow'];
        yield 'field datetime + 2 day' => [['base' => 'object.date_modified', 'shift' => '+2 day'], '2026-10-12 12:00:00', 'Europe/Moscow'];
        yield 'several shifts in a string' => [['base' => 'object.date_modified', 'shift' => '+1 week -2 day +30 minute'], '2026-10-15 12:30:00', 'Europe/Moscow'];
        yield 'single object shift' => [['base' => 'object.date_closed', 'shift' => ['amount' => '3', 'unit' => 'days']], '2026-02-03', 'Europe/Moscow'];
        yield 'amount from a field' => [['base' => 'object.date_closed', 'shift' => [['amount' => 'object.days', 'unit' => 'day']]], '2026-02-05', 'Europe/Moscow'];
        yield 'result date from now' => [['base' => 'now', 'result' => 'date'], '2026-10-16', 'Europe/Moscow'];
        yield 'result date near midnight is the local day' => [['base' => 'object.date_modified', 'shift' => '+10 hour', 'result' => 'date'], '2026-10-11', 'Europe/Moscow'];
        yield 'result datetime from a date' => [['base' => 'object.date_closed', 'result' => 'datetime'], '2026-01-30 21:00:00', 'Europe/Moscow'];
        yield 'hours on a date become datetime' => [['base' => 'object.date_closed', 'shift' => '+2 hour'], '2026-01-30 23:00:00', 'Europe/Moscow'];
        yield 'base_cast with format and timezone' => [['base' => 'object.ru', 'base_cast' => ['type' => 'datetime', 'format' => 'd.m.Y H:i', 'timezone' => 'Europe/Moscow']], '2026-10-16 07:00:00', 'Europe/Moscow'];
        yield 'base_cast date of a datetime' => [['base' => 'object.date_modified', 'base_cast' => 'date'], '2026-10-10', 'Europe/Moscow'];
        yield 'empty shift' => [['base' => 'object.date_closed'], '2026-01-31', 'Europe/Moscow'];
        // DST in Berlin: 2026-03-29 02:00 CET → 03:00 CEST; 09:00 UTC on 28.03 = 10:00 CET
        yield 'DST: + 1 day keeps 10:00 local' => [['base' => 'object.berlin', 'shift' => '+1 day', 'set_time' => '10:00'], '2026-03-29 08:00:00', 'Europe/Berlin'];
        yield 'DST: + 1 day keeps local time' => [['base' => 'object.berlin', 'shift' => '+1 day'], '2026-03-29 08:00:00', 'Europe/Berlin'];
        yield 'DST: + 24 hour is absolute' => [['base' => 'object.berlin', 'shift' => '+24 hour'], '2026-03-29 09:00:00', 'Europe/Berlin'];
        yield 'DST: set_time in the gap moves forward' => [['base' => 'object.berlin', 'shift' => '+1 day', 'set_time' => '02:30'], '2026-03-29 01:30:00', 'Europe/Berlin'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('cases')]
    public function testCompute(array $config, mixed $expected, string $timezone): void
    {
        self::assertSame($expected, self::type($timezone)->compute(self::context(), $config));
    }

    public function testMissingBase(): void
    {
        $type = self::type();
        [$value, $details] = $type->computeWithDetails(self::context(), ['base' => 'object.zero', 'shift' => '+1 day']);
        self::assertNull($value);
        self::assertTrue($details['missing_base']);
        self::assertNull($type->compute(self::context(), ['base' => 'object.empty']));
        self::assertNull($type->compute(self::context(), ['base' => 'object.none']));
        self::assertSame('2026-10-17 07:30:00', $type->compute(self::context(), ['base' => 'object.none', 'shift' => '+1 day', 'on_missing' => 'now']));
        $this->expectExceptionMessage('date_shift: base "object.none" has no value');
        $type->compute(self::context(), ['base' => 'object.none', 'on_missing' => 'error']);
    }

    public function testDetails(): void
    {
        [, $details] = self::type()->computeWithDetails(self::context(), ['base' => 'object.date_modified', 'shift' => [['amount' => 'object.days', 'unit' => 'day']]]);
        self::assertSame(['missing_base' => false, 'base' => '2026-10-10 12:00:00', 'shifts' => [['amount' => 5, 'unit' => 'day']]], $details);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function runtimeErrors(): iterable
    {
        yield 'unparseable base' => [['base' => 'object.bad'], 'date_shift: cannot parse "abc" as datetime'];
        yield 'fraction amount from a field' => [['base' => 'now', 'shift' => [['amount' => 'object.fraction', 'unit' => 'day']]], 'date_shift: amount must be an integer, got "1.5"'];
        yield 'format mismatch' => [['base' => 'object.date_closed', 'base_cast' => ['type' => 'date', 'format' => 'd.m.Y']], 'date_shift: cannot parse "2026-01-31" as date'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('runtimeErrors')]
    public function testRuntimeErrors(array $config, string $message): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage($message);
        self::type()->compute(self::context(), $config);
    }

    public function testValidation(): void
    {
        $scope = new DefinitionScope();
        $scope->declare('object', 'event');
        $type = self::type();
        $type->validate(['base' => 'yesterday'], $scope, 'a');
        $type->validate(['base' => 'v9.date'], $scope, 'b');
        $type->validate(['shift' => 'soon'], $scope, 'c');
        $type->validate(['shift' => '+1 workday'], $scope, 'd');
        $type->validate(['shift' => [['amount' => 1, 'unit' => 'workday'], ['amount' => 1.5, 'unit' => 'day'], ['amount' => 200000, 'unit' => 'day'], ['amount' => 'v8.n', 'unit' => 'day']]], $scope, 'e');
        $type->validate(['set_time' => '24:00', 'result' => 'time', 'on_missing' => 'skip'], $scope, 'f');
        $type->validate(['base' => 'object.x', 'base_cast' => 'int'], $scope, 'g');
        $type->validate(['base' => 'object.x', 'base_cast' => ['type' => 'date', 'timezone' => 'Mars/Base']], $scope, 'h');
        $type->validate(['base' => 'object.date_closed', 'shift' => '+2 day', 'set_time' => '18:00', 'result' => 'datetime', 'on_missing' => 'now'], $scope, 'ok');
        $errors = array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors());
        self::assertSame([
            'a.base: "yesterday" must point to a field, e.g. yesterday.name',
            'b.base: Variable "v9" is not defined at this point',
            'c.shift: Shift must be a list of {amount, unit} or a string like "+2 day"',
            'd.shift: Unit must be one of: minute, hour, day, week, month, year',
            'e.shift[0].unit: Unit must be one of: minute, hour, day, week, month, year',
            'e.shift[1].amount: Amount must be an integer or a field path',
            'e.shift[2].amount: Amount is out of range',
            'e.shift[3].amount: Variable "v8" is not defined at this point',
            'f.set_time: Time must be HH:MM',
            'f.result: Result must be "date" or "datetime"',
            'f.on_missing: Must be one of: null, error, now',
            'g.base_cast: Base cast must be "date" or "datetime"',
            'h.base_cast.timezone: Unknown timezone "Mars/Base"',
        ], $errors);
    }
}
