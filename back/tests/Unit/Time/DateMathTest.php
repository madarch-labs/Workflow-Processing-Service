<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Time;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\NonRecoverableException;
use Wps\Time\DateMath;
use Wps\Time\FrozenClock;
use Wps\Time\SystemClock;

final class DateMathTest extends TestCase
{
    private DateMath $math;

    protected function setUp(): void
    {
        $this->math = new DateMath(new \DateTimeZone('Europe/Moscow'));
    }

    /**
     * @return iterable<string, array{mixed, string, bool}>
     */
    public static function formats(): iterable
    {
        yield 'Y-m-d' => ['2026-10-16', '2026-10-16', true];
        yield 'Y-m-d H:i:s' => ['2026-10-16 07:30:00', '2026-10-16 07:30:00', false];
        yield 'Y-m-d H:i' => ['2026-10-16 07:30', '2026-10-16 07:30:00', false];
        yield 'ISO Z' => ['2026-10-16T07:30:00Z', '2026-10-16 07:30:00', false];
        yield 'ISO Z with fraction' => ['2026-10-16T07:30:00.123Z', '2026-10-16 07:30:00', false];
        yield 'ISO offset' => ['2026-10-16T10:30:00+03:00', '2026-10-16 07:30:00', false];
        yield 'ISO offset with fraction' => ['2026-10-16T10:30:00.5+03:00', '2026-10-16 07:30:00', false];
        yield 'ISO without zone is UTC' => ['2026-10-16T07:30:00', '2026-10-16 07:30:00', false];
        yield 'd.m.Y' => ['16.10.2026', '2026-10-16', true];
        yield 'd.m.Y H:i:s' => ['16.10.2026 07:30:00', '2026-10-16 07:30:00', false];
        yield 'd.m.Y H:i' => ['16.10.2026 07:30', '2026-10-16 07:30:00', false];
        yield 'trimmed' => ['  2026-10-16 ', '2026-10-16', true];
        yield 'DateTime object' => [new \DateTimeImmutable('2026-10-16 10:30:00', new \DateTimeZone('Europe/Moscow')), '2026-10-16 07:30:00', false];
    }

    #[DataProvider('formats')]
    public function testParse(mixed $input, string $canonical, bool $dateOnly): void
    {
        $parsed = $this->math->parse($input, 'test');
        self::assertNotNull($parsed);
        self::assertSame($canonical, $this->math->toCanonical($parsed));
        self::assertSame($dateOnly, $parsed->isDateOnly);
    }

    public function testEmptyValues(): void
    {
        foreach ([null, '', '  ', '0000-00-00', '0000-00-00 00:00:00'] as $value) {
            self::assertNull($this->math->parse($value, 'test'));
        }
    }

    public function testSourceZone(): void
    {
        $parsed = $this->math->parse('2026-10-16 10:30', 'test', new \DateTimeZone('Europe/Moscow'));
        self::assertNotNull($parsed);
        self::assertSame('2026-10-16 07:30:00', $this->math->toCanonical($parsed));
        $offset = $this->math->parse('2026-10-16T10:30:00+03:00', 'test', new \DateTimeZone('America/New_York'));
        self::assertNotNull($offset);
        self::assertSame('2026-10-16 07:30:00', $this->math->toCanonical($offset), 'an explicit offset wins');
    }

    public function testParseErrors(): void
    {
        foreach (['abc', '2026-13-01', '31.02.2026', true, ['2026-10-16']] as $value) {
            try {
                $this->math->parse($value, 'f');
                self::fail('expected an error for ' . json_encode($value));
            } catch (NonRecoverableException $e) {
                self::assertStringContainsString('Cannot parse date', $e->getMessage());
                self::assertStringContainsString('in f()', $e->getMessage());
            }
        }
    }

    public function testCount(): void
    {
        self::assertSame(3, DateMath::count(3, 'f'));
        self::assertSame(-3, DateMath::count('-3', 'f'));
        self::assertSame(2, DateMath::count(2.0, 'f'));
        foreach (['x', 1.5, '', null, '1e3', 99_000_000] as $bad) {
            try {
                DateMath::count($bad, 'f');
                self::fail('expected an error for ' . json_encode($bad));
            } catch (NonRecoverableException $e) {
                self::assertStringStartsWith('Function "f": count must be an integer', $e->getMessage());
            }
        }
    }

    public function testZone(): void
    {
        self::assertSame('Europe/Moscow', $this->math->zone(null, 'f')->getName());
        self::assertSame('Europe/Moscow', $this->math->zone('LOCAL', 'f')->getName());
        self::assertSame('UTC', $this->math->zone('UTC', 'f')->getName());
        self::assertNull(DateMath::zoneError('Asia/Tokyo'));
        self::assertSame('Unknown timezone "Mars/Base"', DateMath::zoneError('Mars/Base'));
        $this->expectExceptionMessage('Unknown timezone "Mars/Base" in f()');
        $this->math->zone('Mars/Base', 'f');
    }

    public function testDiffRejectsUnknownUnit(): void
    {
        $d = $this->math->parse('2026-10-16', 'f');
        self::assertNotNull($d);
        $this->expectException(NonRecoverableException::class);
        $this->math->diff($d, $d, 'decades');
    }

    public function testAddMonthsOverManyMonthEnds(): void
    {
        $expected = ['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31', '2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31'];
        $start = $this->math->parse('2026-01-31', 'f');
        self::assertNotNull($start);
        foreach ($expected as $i => $day) {
            self::assertSame($day, $this->math->toCanonical($this->math->addMonths($start, $i + 1)));
        }
        self::assertSame('2025-11-30', $this->math->toCanonical($this->math->addMonths($start, -2)));
    }

    public function testClocks(): void
    {
        $clock = new FrozenClock('2026-10-16T10:30:00+03:00');
        self::assertSame('2026-10-16 07:30:00 UTC', $clock->now()->format('Y-m-d H:i:s e'));
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }
}
