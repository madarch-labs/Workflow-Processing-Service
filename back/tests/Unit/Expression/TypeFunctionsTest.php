<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Expression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\Functions\DateFunctions;
use Wps\Expression\Functions\TypeFunctions;
use Wps\Time\DateMath;
use Wps\Time\FrozenClock;
use Wps\Types\TypeCaster;

final class TypeFunctionsTest extends TestCase
{
    private static function engine(string $timezone = 'Europe/Moscow'): ExpressionEngine
    {
        $engine = new ExpressionEngine();
        $dates = new DateMath(new \DateTimeZone($timezone));
        DateFunctions::register($engine, $dates, new FrozenClock('2026-10-16 07:30:00'));
        TypeFunctions::register($engine, $engine->caster(), $dates);
        return $engine;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function eval(string $expression, array $vars = []): mixed
    {
        return self::engine()->evaluate($expression, static fn (string $path) => $vars[$path] ?? null);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function checkCases(): iterable
    {
        yield 'is_empty null' => ['is_empty(object.x)', true];
        yield 'is_empty empty string' => ["is_empty('')", true];
        yield 'is_empty spaces' => ["is_empty('   ')", true];
        yield 'is_empty empty list' => ['is_empty([])', true];
        yield 'is_empty zero' => ['is_empty(0)', false];
        yield 'is_empty zero string' => ["is_empty('0')", false];
        yield 'is_empty text' => ["is_empty('x')", false];
        yield 'is_empty non-empty list' => ['is_empty([0])', false];
        yield 'is_not_empty' => ["is_not_empty('x')", true];
        yield 'is_not_empty empty' => ["is_not_empty('')", false];
        yield 'is_number int' => ['is_number(12)', true];
        yield 'is_number float' => ['is_number(1.5)', true];
        yield 'is_number text' => ["is_number(' 12.5 ')", true];
        yield 'is_number negative' => ["is_number('-0.5')", true];
        yield 'is_number human text' => ["is_number('1 234,56')", false];
        yield 'is_number exponent' => ["is_number('1e3')", false];
        yield 'is_number empty' => ["is_number('')", false];
        yield 'is_number text value' => ["is_number('abc')", false];
        yield 'is_numeric_text human' => ["is_numeric_text('1 234,56')", true];
        yield 'is_numeric_text dot' => ["is_numeric_text('1234.56')", true];
        yield 'is_numeric_text int' => ['is_numeric_text(42)', true];
        yield 'is_numeric_text text' => ["is_numeric_text('abc')", false];
        yield 'is_numeric_text empty' => ["is_numeric_text('')", false];
        yield 'is_int' => ['is_int(42)', true];
        yield 'is_int text' => ["is_int('42')", true];
        yield 'is_int integral text' => ["is_int('42.0')", true];
        yield 'is_int fraction' => ['is_int(1.5)', false];
        yield 'is_int fraction text' => ["is_int('1.5')", false];
        yield 'is_date' => ["is_date('2026-10-16')", true];
        yield 'is_date datetime' => ["is_date('2026-10-16 07:30:00')", true];
        yield 'is_date local' => ["is_date('16.10.2026')", true];
        yield 'is_date text' => ["is_date('abc')", false];
        yield 'is_date empty' => ["is_date('')", false];
        yield 'is_date number' => ['is_date(20261016)', false];
        yield 'is_bool true' => ['is_bool(true)', true];
        yield 'is_bool one' => ["is_bool('1')", true];
        yield 'is_bool yes' => ["is_bool('yes')", true];
        yield 'is_bool da' => ["is_bool('да')", true];
        yield 'is_bool false text' => ["is_bool('false')", true];
        yield 'is_bool text' => ["is_bool('abc')", false];
        yield 'is_bool empty' => ["is_bool('')", false];
        yield 'is_list' => ['is_list([1, 2])', true];
        yield 'is_list empty' => ['is_list([])', true];
        yield 'is_list record' => ['is_list(object.body)', false];
        yield 'is_object' => ['is_object(object.body)', true];
        yield 'is_object list' => ['is_object([1])', false];
        yield 'is_object json text' => ["is_object('{\"a\":1}')", false];
    }

    /**
     * @param array<string, mixed> $vars
     */
    #[DataProvider('checkCases')]
    public function testChecks(string $expression, bool $expected, array $vars = []): void
    {
        self::assertSame($expected, self::eval($expression, $vars === [] ? ['object.x' => null, 'object.body' => ['a' => 1]] : $vars));
    }

    public function testIsEmptyMatchesTheConditionOperator(): void
    {
        foreach ([null, '', '   ', [], 0, '0', 'x', [0], ['a' => 1]] as $value) {
            self::assertSame(
                TypeCaster::isEmpty($value),
                self::eval('is_empty(object.v)', ['object.v' => $value]),
                'value: ' . var_export($value, true),
            );
        }
    }

    public function testToBool(): void
    {
        self::assertTrue(self::eval("to_bool('1')"));
        self::assertTrue(self::eval("to_bool('yes')"));
        self::assertTrue(self::eval("to_bool('да')"));
        self::assertFalse(self::eval("to_bool('0')"));
        self::assertFalse(self::eval("to_bool('no')"));
        self::assertFalse(self::eval("to_bool('')"));
        self::assertFalse(self::eval('to_bool(object.missing)'));
    }

    public function testToBoolError(): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('Cannot convert "abc" to boolean');
        self::eval("to_bool('abc')");
    }

    public function testToString(): void
    {
        self::assertSame('42', self::eval('to_string(42)'));
        self::assertSame('true', self::eval('to_string(true)'));
        self::assertSame('[1,2]', self::eval('to_string([1, 2])'));
        self::assertSame('{"a":1}', self::eval('to_string(object.body)', ['object.body' => ['a' => 1]]));
        self::assertSame('', self::eval('to_string(object.missing)'));
    }

    public function testToDate(): void
    {
        self::assertSame('2026-10-16', self::eval("to_date('16.10.2026')"));
        self::assertSame('2026-10-16', self::eval("to_date('2026-10-16 07:30:00')"));
        self::assertSame('', self::eval('to_date(object.missing)'));
    }

    public function testToDatetime(): void
    {
        self::assertSame('2026-10-16 07:00:00', self::eval("to_datetime('16.10.2026 10:00', 'd.m.Y H:i', 'Europe/Moscow')"));
        self::assertSame('2026-10-16 07:30:00', self::eval("to_datetime('2026-10-16 07:30:00')"));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function jsonCases(): iterable
    {
        yield 'json_encode cyrillic' => ["json_encode(object.body)", '{"name":"Иван"}', ['object.body' => ['name' => 'Иван']]];
        yield 'json_encode slash' => ["json_encode('a/b')", '"a/b"'];
        yield 'json_encode numeric string' => ["json_encode('42')", '"42"'];
        yield 'json_decode object' => ["json_decode('{\"a\":1}')", ['a' => 1]];
        yield 'json_decode list' => ["json_decode('[1, 2]')", [1, 2]];
        yield 'json_decode empty' => ["json_decode('')", ''];
        yield 'json_get string' => ["json_get('{\"data\":{\"customer\":{\"id\":42}}}', '$.data.customer.id')", 42];
        yield 'json_get array' => ["json_get(object.body, '$.items[0].id')", 10, ['object.body' => ['items' => [['id' => 10], ['id' => 20]]]]];
        yield 'json_get missing' => ["json_get('{\"a\":1}', '$.missing')", ''];
        yield 'json_get default' => ["json_get('{\"a\":1}', '$.missing', 'x')", 'x'];
        yield 'json_get empty document' => ["json_get('', '$.a')", ''];
        yield 'json_get scalar document' => ["json_get(42, '$.a')", ''];
        yield 'json_get_all' => ["json_get_all('{\"items\":[{\"id\":1},{\"id\":2}]}', '$.items[*].id')", [1, 2]];
        yield 'json_get_all missing' => ["json_get_all('{\"a\":1}', '$.items[*].id')", []];
    }

    /**
     * @param array<string, mixed> $vars
     */
    #[DataProvider('jsonCases')]
    public function testJson(string $expression, mixed $expected, array $vars = []): void
    {
        self::assertSame($expected, self::eval($expression, $vars));
    }

    public function testJsonDecodeError(): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('json_decode(): Syntax error');
        self::eval('json_decode(object.bad)', ['object.bad' => 'not json']);
    }

    public function testJsonDecodeDepthLimit(): void
    {
        $deep = str_repeat('[', 70) . str_repeat(']', 70);
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('json_decode():');
        self::eval('json_decode(object.deep)', ['object.deep' => $deep]);
    }

    public function testJsonDecodeLengthLimit(): void
    {
        $long = '"' . str_repeat('a', TypeFunctions::MAX_JSON_BYTES) . '"';
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('json_decode(): JSON is too long (max 1048576 bytes)');
        self::eval('json_decode(object.long)', ['object.long' => $long]);
    }

    public function testJsonGetInvalidJson(): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('json_get(): invalid JSON');
        self::eval("json_get('not json', '$.a')");
    }

    public function testJsonLiteralPathIsCheckedOnSave(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('json_get(): JSONPath must start with "$"');
        self::engine()->parse("json_get('{\"a\":1}', 'a.b')");
    }

    public function testJsonMalformedLiteralPathIsCheckedOnSave(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('json_get_all(): Invalid JSONPath:');
        self::engine()->parse("json_get_all('{}', '$.items[?(@.x')");
    }

    public function testArityIsChecked(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Function "is_empty" expects 1 argument, got 0');
        self::engine()->parse('is_empty()');
    }

    public function testDictionaryCategory(): void
    {
        $engine = self::engine();
        self::assertSame('type', $engine->spec('is_empty')?->category);
        self::assertSame('boolean', $engine->spec('is_number')?->returns);
        self::assertSame('type', $engine->spec('json_get')?->category);
        self::assertSame('list', $engine->spec('json_get_all')?->returns);
    }
}
