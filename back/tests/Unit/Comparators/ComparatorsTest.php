<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Comparators;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Exceptions\ConfigurationException;
use Wps\Types\CastOptions;
use Wps\Types\TypeCaster;

/**
 * Regression of the four original operators: both sides are compared as strings, case-sensitive.
 */
final class ComparatorsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'equals: same text' => ['equals', 'Active', 'Active', true];
        yield 'equals: case matters' => ['equals', 'Active', 'active', false];
        yield 'equals: numbers compared as text' => ['equals', '100', '100.00', false];
        yield 'equals: trailing space matters' => ['equals', 'Active ', 'Active', false];
        yield 'not_equals: different text' => ['not_equals', 'Active', 'Closed', true];
        yield 'not_equals: same text' => ['not_equals', 'Active', 'Active', false];
        yield 'contains: substring' => ['contains', 'a@b.c', '@', true];
        yield 'contains: empty needle' => ['contains', 'x', '', true];
        yield 'contains: missing substring' => ['contains', 'abc', 'z', false];
        yield 'not_contains: missing substring' => ['not_contains', 'abc', 'z', true];
        yield 'not_contains: substring present' => ['not_contains', 'test name', 'test', false];
    }

    #[DataProvider('cases')]
    public function testCompare(string $operator, string $actual, string $expected, bool $result): void
    {
        // without "cast" both sides are strings (old behaviour)
        $options = new CastOptions();
        $caster = new TypeCaster();
        $comparator = ComparatorRegistry::default()->get($operator);
        $a = $caster->cast($actual, $options)->value;
        $e = $caster->cast($expected, $comparator->expectedCast($options))->value;
        self::assertSame($result, $comparator->compare($a, $e, $options));
    }

    public function testDictionaryDescribesTheOriginalOperators(): void
    {
        $dictionary = array_column(ComparatorRegistry::default()->dictionary(), null, 'value');
        foreach (['equals', 'not_equals', 'contains', 'not_contains'] as $operator) {
            self::assertArrayHasKey($operator, $dictionary);
        }
        self::assertSame(['string', 'int', 'decimal', 'date', 'datetime', 'bool', 'list'], $dictionary['equals']['casts']);
        self::assertSame(['string', 'list'], $dictionary['contains']['casts']);
        self::assertSame('value', $dictionary['contains']['operands']);
    }

    public function testListContains(): void
    {
        $caster = new TypeCaster();
        $options = CastOptions::fromDefinition('list', ['wrapper' => '^', 'case_sensitive' => false]);
        $contains = ComparatorRegistry::default()->get('contains');
        $tags = $caster->cast('^VIP^,^partner^', $options)->value;
        self::assertTrue($contains->compare($tags, $caster->cast(' vip ', $contains->expectedCast($options))->value, $options));
        self::assertFalse($contains->compare($tags, $caster->cast('par', $contains->expectedCast($options))->value, $options), 'an element, not a substring');
        self::assertTrue(ComparatorRegistry::default()->get('not_contains')->compare($tags, 'gold', $options));
    }

    public function testUnknownOperatorIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationException::class);
        ComparatorRegistry::default()->get('greater_than_or_maybe');
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function stringifyCases(): iterable
    {
        yield 'null' => [null, ''];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'int' => [42, '42'];
        yield 'float' => [0.5, '0.5'];
        yield 'float without fraction' => [100.0, '100'];
        yield 'string' => ['abc', 'abc'];
        yield 'array as JSON, unicode and slashes unescaped' => [['a' => 'б/в'], '{"a":"б/в"}'];
    }

    #[DataProvider('stringifyCases')]
    public function testStringify(mixed $value, string $expected): void
    {
        self::assertSame($expected, ComparatorRegistry::stringify($value));
    }
}
