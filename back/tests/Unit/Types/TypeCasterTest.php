<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Types\CastOptions;
use Wps\Types\CastResult;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

final class TypeCasterTest extends TestCase
{
    private static function caster(string $timezone = 'UTC'): TypeCaster
    {
        return new TypeCaster(new \DateTimeZone($timezone));
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cast(mixed $value, string $type, array $options = [], string $timezone = 'UTC'): CastResult
    {
        return self::caster($timezone)->cast($value, CastOptions::fromDefinition($type, $options));
    }

    /**
     * @return iterable<string, array{mixed, string, array<string, mixed>, mixed}>
     */
    public static function okCases(): iterable
    {
        // string — without options exactly the old stringify()
        yield 'string null' => [null, 'string', [], ''];
        yield 'string bool' => [true, 'string', [], 'true'];
        yield 'string float' => [100.0, 'string', [], '100'];
        yield 'string array keeps JSON' => [[], 'string', [], '[]'];
        yield 'string case-insensitive' => ['Active', 'string', ['case_sensitive' => false], 'active'];
        yield 'string case-insensitive unicode' => ['ЁЛКА', 'string', ['case_sensitive' => false], 'ёлка'];
        yield 'string trim' => ['  x ', 'string', ['trim' => true], 'x'];
        yield 'string no trim by default' => ['  x ', 'string', [], '  x '];
        // int
        yield 'int int' => [5, 'int', [], 5];
        yield 'int spaces' => ['1 200', 'int', [], 1200];
        yield 'int no-break space' => ["1\u{00A0}200", 'int', [], 1200];
        yield 'int narrow no-break space' => ["1\u{202F}200", 'int', [], 1200];
        yield 'int plus' => ['+5', 'int', [], 5];
        yield 'int negative' => ['-42', 'int', [], -42];
        yield 'int zero fraction' => ['100.00', 'int', [], 100];
        yield 'int zero fraction comma' => ['100,0', 'int', [], 100];
        yield 'int float without fraction' => [7.0, 'int', [], 7];
        // decimal
        yield 'decimal comma' => ['1 234,56', 'decimal', [], '1234.56'];
        yield 'decimal trailing zeros' => ['100.00', 'decimal', [], '100'];
        yield 'decimal int' => [100, 'decimal', [], '100'];
        yield 'decimal negative zero' => ['-0.0', 'decimal', [], '0'];
        yield 'decimal leading point' => ['.5', 'decimal', [], '0.5'];
        yield 'decimal leading zeros' => ['+0012.3400', 'decimal', [], '12.34'];
        yield 'decimal float' => [0.1, 'decimal', [], '0.1'];
        yield 'decimal big float' => [1e25, 'decimal', [], '10000000000000000905969664'];
        yield 'decimal rounding half up' => ['1.005', 'decimal', ['scale' => 2], '1.01'];
        yield 'decimal rounding negative' => ['-1.005', 'decimal', ['scale' => 2], '-1.01'];
        yield 'decimal rounding to integer' => ['2.5', 'decimal', ['scale' => 0], '3'];
        yield 'decimal negative' => ['-0.50', 'decimal', [], '-0.5'];
        // bool
        yield 'bool true' => [true, 'bool', [], true];
        yield 'bool 1' => ['1', 'bool', [], true];
        yield 'bool yes' => ['Yes', 'bool', [], true];
        yield 'bool on' => [' ON ', 'bool', [], true];
        yield 'bool да' => ['Да', 'bool', [], true];
        yield 'bool int 0' => [0, 'bool', [], false];
        yield 'bool off' => ['off', 'bool', [], false];
        yield 'bool нет' => ['нет', 'bool', [], false];
        yield 'bool null' => [null, 'bool', [], false];
        yield 'bool empty' => ['', 'bool', [], false];
        // list
        yield 'list array' => [['a', 'b'], 'list', [], ['a', 'b']];
        yield 'list assoc array' => [['x' => 'a', 'y' => 2], 'list', [], ['a', '2']];
        yield 'list JSON' => ['["a","b"]', 'list', [], ['a', 'b']];
        yield 'list separated' => ['a, b,,c', 'list', [], ['a', 'b', 'c']];
        yield 'list custom separator' => ['a;b', 'list', ['separator' => ';'], ['a', 'b']];
        yield 'list multienum' => ['^vip^,^partner^', 'list', ['wrapper' => '^'], ['vip', 'partner']];
        yield 'list multienum comma inside' => ['^a,b^,^c^', 'list', ['wrapper' => '^'], ['a,b', 'c']];
        yield 'list case-insensitive' => ['VIP,Partner', 'list', ['case_sensitive' => false], ['vip', 'partner']];
        yield 'list scalar' => [5, 'list', [], ['5']];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('okCases')]
    public function testCastOk(mixed $value, string $type, array $options, mixed $expected): void
    {
        $result = self::cast($value, $type, $options);
        self::assertTrue($result->isOk(), (string)$result->error);
        self::assertSame($expected, $result->value);
    }

    /**
     * @return iterable<string, array{mixed, string, array<string, mixed>, string}>
     */
    public static function errorCases(): iterable
    {
        yield 'int fraction' => ['12.5', 'int', [], 'Value "12.5" is not an integer'];
        yield 'int out of range' => ['9999999999999999999', 'int', [], 'out of integer range'];
        yield 'int bool' => [true, 'int', [], 'is not an integer'];
        yield 'int text' => ['abc', 'int', [], 'is not an integer'];
        yield 'int float fraction' => [1.5, 'int', [], 'is not an integer'];
        yield 'decimal ambiguous' => ['1,234.56', 'decimal', [], 'Ambiguous decimal separators'];
        yield 'decimal exponent string' => ['1e3', 'decimal', [], 'is not a valid decimal'];
        yield 'decimal NAN' => [NAN, 'decimal', [], 'not a finite number'];
        yield 'decimal text' => ['abc', 'decimal', [], 'is not a valid decimal'];
        yield 'date impossible' => ['2026-02-30', 'date', [], 'is not a date'];
        yield 'date american without format' => ['10/16/2026', 'date', [], 'is not a date'];
        yield 'date digits only' => ['20261016', 'date', [], 'is not a date'];
        yield 'date text' => ['tomorrow', 'date', [], 'is not a date'];
        yield 'datetime text' => ['soon', 'datetime', [], 'is not a date and time'];
        yield 'bool maybe' => ['maybe', 'bool', [], 'Value "maybe" is not a boolean'];
        yield 'bool other number' => [2, 'bool', [], 'is not a boolean'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('errorCases')]
    public function testCastError(mixed $value, string $type, array $options, string $message): void
    {
        $result = self::cast($value, $type, $options);
        self::assertTrue($result->isError(), 'expected an error');
        self::assertStringContainsString($message, (string)$result->error);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function emptyCases(): iterable
    {
        foreach (['int', 'decimal', 'date', 'datetime', 'list'] as $type) {
            yield "{$type} null" => [null, $type];
            yield "{$type} blank" => ['  ', $type];
            yield "{$type} empty array" => [[], $type];
        }
        yield 'list only separators' => [',,', 'list'];
    }

    #[DataProvider('emptyCases')]
    public function testEmpty(mixed $value, string $type): void
    {
        self::assertTrue(self::cast($value, $type)->isEmpty());
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>, string}>
     */
    public static function dateCases(): iterable
    {
        yield 'Y-m-d' => ['2026-10-16', 'UTC', [], '2026-10-16'];
        yield 'd.m.Y' => ['16.10.2026', 'UTC', [], '2026-10-16'];
        yield 'ISO Z' => ['2026-10-16T10:00:00Z', 'UTC', [], '2026-10-16'];
        yield 'explicit format' => ['10/16/2026', 'UTC', ['format' => 'm/d/Y'], '2026-10-16'];
        yield 'format list' => ['16/10/2026', 'UTC', ['format' => ['m/d/Y', 'd/m/Y']], '2026-10-16'];
        yield 'unix timestamp only with format U' => ['1760572800', 'UTC', ['format' => 'U'], '2025-10-16'];
        // a value with a time is moved into the business time zone, a pure date is not
        yield 'moved to business zone' => ['2026-10-16 22:30:00', 'Europe/Moscow', [], '2026-10-17'];
        yield 'pure date not moved' => ['2026-10-16', 'Europe/Moscow', [], '2026-10-16'];
        yield 'value in its own zone' => ['2026-10-16 22:30:00', 'Europe/Moscow', ['timezone' => 'Europe/Moscow'], '2026-10-16'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('dateCases')]
    public function testDate(string $value, string $businessZone, array $options, string $expected): void
    {
        $result = self::cast($value, 'date', $options, $businessZone);
        self::assertTrue($result->isOk(), (string)$result->error);
        self::assertSame($expected, TypeCaster::export($result->value, CastType::Date));
        self::assertSame('00:00:00', $result->value->format('H:i:s'));
        self::assertSame($businessZone, $result->value->getTimezone()->getName());
    }

    public function testDateTimeIsCompared(): void
    {
        $caster = self::caster();
        $options = CastOptions::fromDefinition('datetime');
        $utc = $caster->cast('2026-10-16 10:00:00', $options)->value;
        $offset = $caster->cast('2026-10-16T13:00:00+03:00', $options)->value;
        self::assertTrue($caster->equals($utc, $offset, $options));
        self::assertSame('2026-10-16T10:00:00+00:00', TypeCaster::export($offset, CastType::DateTime));

        $later = $caster->cast('2026-10-16T10:00:00.500000Z', $options)->value;
        self::assertFalse($caster->equals($utc, $later, $options), 'microseconds matter');
        self::assertSame(-1, $caster->compare($utc, $later, $options));
        $dateOnly = $caster->cast('2026-10-16', $options)->value;
        self::assertSame('2026-10-16T00:00:00+00:00', TypeCaster::export($dateOnly, CastType::DateTime));
    }

    public function testEqualsAndCompare(): void
    {
        $caster = self::caster();
        $decimal = CastOptions::fromDefinition('decimal');
        self::assertTrue($caster->equals('100', '100.0', $decimal));
        self::assertSame(1, $caster->compare('150000', '100000', $decimal));
        self::assertSame(-1, $caster->compare('-5', '0.1', $decimal));

        $int = CastOptions::fromDefinition('int');
        self::assertSame(0, $caster->compare(5, 5, $int));

        $date = CastOptions::fromDefinition('date');
        $a = $caster->cast('2026-10-16', $date)->value;
        $b = $caster->cast('16.10.2026', $date)->value;
        $c = $caster->cast('2026-10-17', $date)->value;
        self::assertTrue($caster->equals($a, $b, $date));
        self::assertSame(-1, $caster->compare($a, $c, $date));

        $list = CastOptions::fromDefinition('list');
        self::assertTrue($caster->equals(['a', 'b', 'a'], ['b', 'a'], $list), 'lists are compared as sets');
        self::assertFalse($caster->equals(['a'], ['a', 'c'], $list));

        $string = new CastOptions();
        self::assertTrue($caster->equals('Active', 'Active', $string));
        self::assertFalse($caster->equals('Active', 'active', $string));
    }

    public function testCompareRejectsUnorderedTypes(): void
    {
        $this->expectException(\LogicException::class);
        self::caster()->compare('a', 'b', new CastOptions());
    }

    public function testOptionErrors(): void
    {
        self::assertSame([], CastOptions::errors(null, null));
        self::assertSame([], CastOptions::errors('decimal', ['scale' => 2]));
        self::assertSame('cast_options.scale', CastOptions::errors('string', ['scale' => 2])[0]['field']);
        self::assertStringContainsString('Unknown cast "money"', CastOptions::errors('money', null)[0]['message']);
        self::assertSame('Must be an integer from 0 to 20', CastOptions::errors('decimal', ['scale' => 30])[0]['message']);
        self::assertSame('Unknown timezone "Moscow"', CastOptions::errors('date', ['timezone' => 'Moscow'])[0]['message']);
        self::assertSame('Must be an object', CastOptions::errors('list', ['a'])[0]['message']);
        self::assertSame('Must be true or false', CastOptions::errors('string', ['trim' => 'yes'])[0]['message']);
        self::assertSame('Must be a non-empty string or a list of strings', CastOptions::errors('date', ['format' => ''])[0]['message']);
    }

    public function testFromDefinitionThrowsOnBadOptions(): void
    {
        $this->expectException(ConfigurationException::class);
        CastOptions::fromDefinition('string', ['scale' => 2]);
    }

    public function testStringify(): void
    {
        self::assertSame('0.5', TypeCaster::stringify(0.5));
        self::assertSame('{"a":"б/в"}', TypeCaster::stringify(['a' => 'б/в']));
    }
}
