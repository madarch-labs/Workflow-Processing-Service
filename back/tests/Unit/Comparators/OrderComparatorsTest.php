<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Comparators;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Services\ConditionEngineService;
use Wps\Types\CastOptions;
use Wps\Types\TypeCaster;

final class OrderComparatorsTest extends TestCase
{
    /**
     * @param array<string, mixed> $castOptions
     */
    private static function check(string $operator, mixed $actual, mixed $expected, string $cast, array $castOptions = [], string $zone = 'UTC'): bool
    {
        $caster = new TypeCaster(new \DateTimeZone($zone));
        $options = CastOptions::fromDefinition($cast, $castOptions);
        $comparator = ComparatorRegistry::default($caster)->get($operator);
        $a = $caster->cast($actual, $options);
        $e = $caster->cast($expected, $comparator->expectedCast($options));
        self::assertTrue($a->isOk() && $e->isOk(), 'both sides cast');
        return $comparator->compare($a->value, $e->value, $options);
    }

    /**
     * @return iterable<string, array{string, mixed, mixed, string, array<string, mixed>, bool}>
     */
    public static function cases(): iterable
    {
        yield 'int >' => ['greater_than', 5, '3', 'int', [], true];
        yield 'int > false' => ['greater_than', '3', 5, 'int', [], false];
        yield 'int >= equal' => ['greater_or_equal', '5', 5, 'int', [], true];
        yield 'int < negative' => ['less_than', '-1', '0', 'int', [], true];
        yield 'decimal >= equal values' => ['greater_or_equal', '100.00', '100', 'decimal', [], true];
        yield 'decimal > equal values' => ['greater_than', '100.00', '100', 'decimal', [], false];
        yield 'decimal with spaces and comma' => ['greater_than', '1 000 000,01', '1000000', 'decimal', [], true];
        yield 'decimal rounded by scale 10' => ['greater_than', '0.30000000000000004', '0.3', 'decimal', [], false];
        yield 'decimal scale 17' => ['greater_than', '0.30000000000000004', '0.3', 'decimal', ['scale' => 17], true];
        yield 'decimal <= negative zero' => ['less_or_equal', '-0', '0', 'decimal', [], true];
        yield 'date <' => ['less_than', '16.10.2026', '2026-10-17', 'date', [], true];
        yield 'date equal dates are not less' => ['less_than', '16.10.2026', '2026-10-16', 'date', [], false];
        yield 'datetime >= equal moments in different zones' => ['greater_or_equal', '2026-10-16 10:00:00', '2026-10-16T13:00:00+03:00', 'datetime', [], true];
        yield 'datetime > equal moments' => ['greater_than', '2026-10-16 10:00:00', '2026-10-16T13:00:00+03:00', 'datetime', [], false];
    }

    /**
     * @param array<string, mixed> $castOptions
     */
    #[DataProvider('cases')]
    public function testCompare(string $operator, mixed $actual, mixed $expected, string $cast, array $castOptions, bool $result): void
    {
        self::assertSame($result, self::check($operator, $actual, $expected, $cast, $castOptions));
    }

    public function testDateShiftByBusinessZone(): void
    {
        // 22:30 UTC is the next day in Moscow
        self::assertTrue(self::check('greater_or_equal', '2026-10-16 22:30:00', '2026-10-17', 'date', [], 'Europe/Moscow'));
        self::assertFalse(self::check('greater_or_equal', '2026-10-16 22:30:00', '2026-10-17', 'date'));
    }

    public function testEmptyAndUncastableAreFalse(): void
    {
        $engine = new ConditionEngineService(ComparatorRegistry::default());
        $context = ExecutionContext::fromEvent('e', ['id' => '1', 'amount' => '', 'bad' => 'abc'], 'Deals');
        $row = static fn (string $field, string $op) => ['field' => $field, 'operator' => $op, 'value' => '10', 'cast' => 'decimal'];
        foreach (['greater_than', 'less_than', 'greater_or_equal', 'less_or_equal'] as $op) {
            self::assertFalse($engine->evaluate([$row('object.amount', $op)], $context)->isMet(), "empty for {$op}");
            self::assertFalse($engine->evaluate([$row('object.bad', $op)], $context)->isMet(), "uncastable for {$op}");
            self::assertFalse($engine->evaluate([$row('object.missing', $op)], $context)->isMet(), "missing for {$op}");
        }
    }

    public function testDictionaryOrder(): void
    {
        $dictionary = ComparatorRegistry::default()->dictionary();
        $order = ['equals', 'not_equals', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal', 'contains', 'not_contains'];
        self::assertSame($order, array_values(array_intersect(array_column($dictionary, 'value'), $order)), 'relative order of the operators');
        $byValue = array_column($dictionary, null, 'value');
        self::assertSame(['int', 'decimal', 'date', 'datetime'], $byValue['greater_than']['casts']);
        self::assertSame('Больше', $byValue['greater_than']['label']);
    }
}
