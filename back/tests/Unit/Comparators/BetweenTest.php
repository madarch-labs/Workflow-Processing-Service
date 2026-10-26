<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Comparators;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Crm\FilterTranslator;
use Wps\Services\ConditionEngineService;
use Wps\Types\TypeCaster;

final class BetweenTest extends TestCase
{
    /**
     * @return array{0: bool|null, 1: array<string, mixed>}
     */
    private static function check(array $row, array $object = [], array $vars = []): array
    {
        $context = ExecutionContext::fromEvent('e', $object + ['id' => '1'], 'Opportunities');
        foreach ($vars as $name => $value) {
            $context->setVariable($name, $value, 'Accounts');
        }
        $result = (new ConditionEngineService(ComparatorRegistry::default()))->evaluate([$row], $context);
        return [$result->isMet(), $result->trace[0]];
    }

    public function testBetween(): void
    {
        $row = ['field' => 'object.amount', 'operator' => 'between', 'cast' => 'decimal', 'value' => ['10000', '50000']];
        self::assertTrue(self::check($row, ['amount' => '10000'])[0], 'bounds are included');
        self::assertTrue(self::check($row, ['amount' => '50000.00'])[0]);
        self::assertFalse(self::check($row, ['amount' => '50000.01'])[0]);
        self::assertFalse(self::check($row, ['amount' => ''])[0], 'empty field');
        self::assertFalse(self::check($row, [])[0], 'missing field');
        self::assertFalse(self::check(['operator' => 'not_between'] + $row, ['amount' => '20000'])[0]);
        self::assertTrue(self::check(['operator' => 'not_between'] + $row, ['amount' => '9999.99'])[0]);
        self::assertFalse(self::check(['operator' => 'not_between'] + $row, [])[0], 'missing field: false for not_between too');
        $dates = ['field' => 'object.date_closed', 'operator' => 'between', 'cast' => 'date', 'value' => ['2026-10-01', '31.12.2026']];
        self::assertTrue(self::check($dates, ['date_closed' => '2026-12-31'])[0]);
        self::assertFalse(self::check($dates, ['date_closed' => '2027-01-01'])[0]);
    }

    public function testBoundsFromFields(): void
    {
        $row = ['field' => 'object.amount', 'operator' => 'not_between', 'cast' => 'decimal', 'value_type' => 'from_object_field', 'value' => ['v1.min_limit', 'v1.max_limit']];
        [$met, $trace] = self::check($row, ['amount' => '700'], ['v1' => ['min_limit' => '100', 'max_limit' => '500']]);
        self::assertTrue($met);
        self::assertSame(['100', '500'], $trace['expected']);
        [$met, $trace] = self::check($row, ['amount' => '700'], ['v1' => ['min_limit' => '900', 'max_limit' => '500']]);
        self::assertFalse($met);
        self::assertSame(['range_inverted'], $trace['warnings']);
        [$met, $trace] = self::check($row, ['amount' => '700'], ['v1' => ['min_limit' => '100']]);
        self::assertSame([false, 'expected[1]'], [$met, $trace['missing']]);
        $unknown = (new ConditionEngineService(ComparatorRegistry::default()))->evaluate([$row], ExecutionContext::fromEvent('e', ['id' => '1', 'amount' => '5'], 'Opportunities'), allowUnknown: true);
        self::assertTrue($unknown->isUnknown(), 'a bound on a variable triggers the lazy preload');
    }

    public function testCrmFilter(): void
    {
        $filter = (new FilterTranslator(new TypeCaster()))->translate([['field' => 'amount', 'operator' => 'between', 'cast' => 'decimal', 'value' => ['1 000', '5000,5']]],
            ExecutionContext::fromEvent('e', ['id' => '1'], 'Accounts'));
        self::assertSame(['filter' => ['amount' => ['gte' => '1000', 'lte' => '5000.5']]], $filter->toQuery());
    }
}
