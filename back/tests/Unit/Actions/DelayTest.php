<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use Wps\Actions\ActionResult;
use Wps\Actions\DelayHandler;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\Functions\DateFunctions;
use Wps\Services\Validation\DefinitionScope;
use Wps\Time\DateMath;
use Wps\Time\FrozenClock;

final class DelayTest extends TestCase
{
    private static function config(int $inline = 5): AppConfig
    {
        $config = (new \ReflectionClass(AppConfig::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AppConfig::class, 'waitInlineMaxSeconds'))->setValue($config, $inline);
        (new \ReflectionProperty(AppConfig::class, 'delayMax'))->setValue($config, 'P400D');
        return $config;
    }

    private static function handler(string $now = '2026-10-16 07:30:00', int $inline = 5): DelayHandler
    {
        $math = new DateMath(new \DateTimeZone('Europe/Moscow'));
        $clock = new FrozenClock($now);
        $expressions = new ExpressionEngine();
        DateFunctions::register($expressions, $math, $clock);
        return new DelayHandler($expressions, $math, $clock, self::config($inline));
    }

    private static function context(): ExecutionContext
    {
        return ExecutionContext::fromEvent('e', ['id' => '1', 'date_end' => '2026-12-01 10:00:00', 'closed' => '2026-10-31', 'empty' => '', 'sla' => '3'], 'Leads');
    }

    public function testShift(): void
    {
        $math = new DateMath();
        $base = new \DateTimeImmutable('2026-01-31 10:00:00', new \DateTimeZone('UTC'));
        $f = static fn (?\DateTimeImmutable $d) => $d?->format('Y-m-d H:i:s');
        self::assertSame('2026-02-03 10:00:00', $f(DelayHandler::shift($base, 'P3D', $math)));
        self::assertSame('2026-01-31 12:30:00', $f(DelayHandler::shift($base, 'PT2H30M', $math)));
        self::assertSame('2026-02-28 10:00:00', $f(DelayHandler::shift($base, 'P1M', $math)), 'months clip to the end of month');
        self::assertSame('2026-01-01 10:00:00', $f(DelayHandler::shift($base, '-P30D', $math)));
        self::assertSame('2026-01-31 10:15:00', $f(DelayHandler::shift($base, '15m', $math)));
        self::assertSame('2026-01-28 10:00:00', $f(DelayHandler::shift($base, '-3d', $math)));
        foreach (['3x', '', 'P', '5', 'PT0S', '-'] as $bad) {
            self::assertNull(DelayHandler::shift($base, $bad, $math), $bad);
        }
    }

    public function testDurationParksOrSleeps(): void
    {
        $result = self::handler()->execute(self::context(), ['mode' => 'duration', 'duration' => 'P3D']);
        self::assertSame(ActionResult::WAITING, $result->status);
        self::assertSame('2026-10-19T07:30:00+00:00', $result->dueAt->format(\DATE_ATOM));
        self::assertSame('delay', $result->waitReason);
        self::assertSame('2026-10-19T10:30:00+03:00', $result->request['due_at_local']);
        $expression = self::handler()->execute(self::context(), ['mode' => 'duration', 'duration_expression' => "concat(object.sla, 'd')"]);
        self::assertSame('2026-10-19T07:30:00+00:00', $expression->dueAt->format(\DATE_ATOM));
        $short = self::handler()->execute(self::context(), ['mode' => 'duration', 'duration' => '1s']);
        self::assertSame(ActionResult::SUCCESS, $short->status, 'a short pause sleeps in the coroutine');
        self::assertTrue($short->result['inline']);
    }

    public function testUntil(): void
    {
        $h = self::handler();
        $field = $h->execute(self::context(), ['mode' => 'until', 'until' => 'object.date_end', 'offset' => '-P30D']);
        self::assertSame('2026-11-01T10:00:00+00:00', $field->dueAt->format(\DATE_ATOM), 'CRM values are UTC');
        $literal = $h->execute(self::context(), ['mode' => 'until', 'until' => '2026-12-01 10:00']);
        self::assertSame('2026-12-01T07:00:00+00:00', $literal->dueAt->format(\DATE_ATOM), 'literals are in WPS_TIMEZONE');
        $tz = $h->execute(self::context(), ['mode' => 'until', 'until' => '2026-12-01 10:00', 'timezone' => 'UTC']);
        self::assertSame('2026-12-01T10:00:00+00:00', $tz->dueAt->format(\DATE_ATOM));
        $date = $h->execute(self::context(), ['mode' => 'until', 'until' => 'object.closed', 'time' => '09:00']);
        self::assertSame('2026-10-31T06:00:00+00:00', $date->dueAt->format(\DATE_ATOM), 'a date with a local time of day');
        $expr = $h->execute(self::context(), ['mode' => 'until', 'until_expression' => 'add_days(today(), 2)']);
        self::assertSame('2026-10-17T21:00:00+00:00', $expr->dueAt->format(\DATE_ATOM), 'a date from an expression: local midnight');
        $past = $h->execute(self::context(), ['mode' => 'until', 'until' => '2026-01-01']);
        self::assertSame(['waited' => false, 'past_due' => true], $past->result);
    }

    public function testErrors(): void
    {
        $h = self::handler();
        self::assertSame(ActionResult::SKIPPED, $h->execute(self::context(), ['mode' => 'until', 'until' => 'object.empty', 'on_invalid' => 'continue'])->status);
        foreach ([
            [['mode' => 'until', 'until' => 'object.empty'], 'delay: cannot resolve a date from object.empty (value "")'],
            [['mode' => 'until', 'until' => '2026-01-01', 'if_past' => 'fail'], 'has already passed'],
            [['mode' => 'duration', 'duration' => 'P500D'], 'delay: wait of 500 days exceeds the limit P400D'],
            [['mode' => 'duration', 'duration' => 'P10D', 'max_wait' => 'P7D'], 'exceeds the limit P7D'],
            [['mode' => 'duration', 'duration_expression' => "'soon'"], 'delay: invalid duration "soon"'],
        ] as [$config, $message]) {
            try {
                $h->execute(self::context(), $config);
                self::fail("expected: {$message}");
            } catch (NonRecoverableException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testResume(): void
    {
        $result = self::handler()->resume(self::context(), ['mode' => 'duration'], ['due_at' => gmdate(\DATE_ATOM, time() - 2)]);
        self::assertSame(ActionResult::SUCCESS, $result->status);
        self::assertGreaterThanOrEqual(1000, $result->result['lateness_ms']);
    }

    public function testValidation(): void
    {
        $scope = new DefinitionScope();
        $h = self::handler();
        $h->validate(['mode' => 'sleep'], $scope, 'a');
        $h->validate(['mode' => 'duration'], $scope, 'b');
        $h->validate(['mode' => 'duration', 'duration' => '3x', 'duration_expression' => 'x'], $scope, 'c');
        $h->validate(['mode' => 'duration', 'duration' => 'P500D'], $scope, 'd');
        $h->validate(['mode' => 'until', 'until' => 'v9.date', 'offset' => '-3x', 'time' => '25:00', 'if_past' => 'later', 'timezone' => 'Mars/Base'], $scope, 'e');
        $h->validate(['mode' => 'until', 'until' => '31.02.2026', 'max_wait' => 'P900D'], $scope, 'f');
        $h->validate(['mode' => 'until', 'until_expression' => 'add_days(v8.x, 1)'], $scope, 'g');
        $h->validate(['mode' => 'until', 'until' => 'object.date_end', 'offset' => '-P30D', 'time' => '09:00', 'cast' => 'date'], $scope, 'ok');
        self::assertSame([
            'a.mode: Delay mode must be one of: duration, until',
            'b.duration: Duration is required',
            'c.duration: Use either duration or duration_expression',
            'c.duration: Invalid duration "3x" (use ISO 8601 like P3D or 3d, 2h, 30m)',
            'c.duration_expression: Unknown identifier "x" (use object.<field> or <variable>.<field>) at position 0 in "x"',
            'd.duration: Delay exceeds the limit P400D',
            'e.until: Variable "v9" is not defined at this point',
            'e.offset: Invalid offset "-3x"',
            'e.time: Time must be HH:MM',
            'e.timezone: Unknown timezone "Mars/Base"',
            'e.if_past: Must be "continue" or "fail"',
            'f.max_wait: Must not exceed P400D',
            'f.until: Invalid date "31.02.2026"',
            'g.until_expression: Variable "v8" is not defined at this point',
        ], array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors()));
    }
}
