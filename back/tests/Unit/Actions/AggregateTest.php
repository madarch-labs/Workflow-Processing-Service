<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use Wps\Actions\ChangeTypes\AggregateChangeType;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Crm\FilterRulesValidator;
use Wps\Crm\FilterTranslator;
use Wps\Exceptions\NonRecoverableException;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;
use Wps\Types\TypeCaster;

final class AggregateTest extends TestCase
{
    private static function type(): AggregateChangeType
    {
        $caster = new TypeCaster(new \DateTimeZone('UTC'));
        return new AggregateChangeType((new \ReflectionClass(ExternalApiAdapterService::class))->newInstanceWithoutConstructor(),
            new FilterTranslator($caster), new FilterRulesValidator(ComparatorRegistry::default(), $caster), $caster, 5000);
    }

    private static function fold(string $function, array $records, array $config = []): mixed
    {
        $method = new \ReflectionMethod(AggregateChangeType::class, 'fold');
        return $method->invoke(self::type(), $function, $records, $config + ['field' => 'v'], 'Deals');
    }

    public function testFold(): void
    {
        $rows = [['id' => '1', 'v' => '1 200,50'], ['id' => '2', 'v' => null], ['id' => '3', 'v' => '300.25'], ['id' => '4', 'v' => '']];
        self::assertSame(4, self::fold('count', $rows));
        self::assertSame(1500.75, self::fold('sum', $rows));
        self::assertSame(750.38, self::fold('avg', $rows), 'avg of non-empty values, scale 2');
        self::assertSame(750.375, self::fold('avg', $rows, ['scale' => 3]));
        self::assertSame(300.25, self::fold('min', $rows, ['cast' => 'decimal']));
        self::assertSame(2, self::fold('count_distinct', [['v' => 'a'], ['v' => 'b'], ['v' => 'a'], ['v' => '']]));
        $dates = [['v' => '2026-10-01'], ['v' => '15.11.2026'], ['v' => '2026-09-30']];
        self::assertSame('2026-11-15', self::fold('max', $dates, ['cast' => 'date']));
        self::assertSame('2026-09-30', self::fold('min', $dates, ['cast' => 'date']));
        self::assertSame('2026-10-16 07:00:00', self::fold('max', [['v' => '2026-10-16 07:00:00'], ['v' => '2026-10-15 23:59:59']], ['cast' => 'datetime']));
        self::assertSame('Б', self::fold('max', [['v' => 'А'], ['v' => 'Б']], ['cast' => 'string']));
        self::assertSame(0, self::fold('sum', [['v' => null]]));
        self::assertNull(self::fold('avg', [['v' => '']]));
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('aggregate: value "abc" of Deals.v (id 9) is not a decimal');
        self::fold('sum', [['id' => '9', 'v' => 'abc']]);
    }

    public function testEmptyFilterValueSkipsTheRequest(): void
    {
        $context = ExecutionContext::fromEvent('e', ['id' => '1'], 'Accounts');
        [$value, $details] = self::type()->computeWithDetails($context, ['function' => 'sum', 'field' => 'amount',
            'source' => ['module' => 'Opportunities', 'filter' => [['field' => 'account_id', 'operator' => 'equals', 'value_type' => 'from_object_field', 'value' => 'v1.id']]]]);
        self::assertSame(0, $value);
        self::assertStringStartsWith('filter value is empty', $details['skipped_reason']);
        [$value] = self::type()->computeWithDetails($context, ['function' => 'max', 'field' => 'date_start', 'cast' => 'date',
            'source' => ['relationship' => ['module' => 'Accounts', 'id' => 'object.account_id', 'link' => 'meetings']]]);
        self::assertNull($value);
    }

    public function testValidation(): void
    {
        $scope = new DefinitionScope();
        $type = self::type();
        $type->validate(['function' => 'median'], $scope, 'a');
        $type->validate(['function' => 'sum', 'cast' => 'date', 'scale' => 20, 'source' => ['module' => 'Deals', 'relationship' => []], 'limit' => 0, 'on_limit' => 'cut'], $scope, 'b');
        $type->validate(['function' => 'min', 'field' => 'amount', 'source' => ['module' => 'Deals']], $scope, 'c');
        $type->validate(['function' => 'count', 'source' => ['module' => 'Deals', 'filter' => [['field' => 'x', 'operator' => 'is_null']]]], $scope, 'd');
        $type->validate(['function' => 'count', 'source' => ['relationship' => ['module' => 'Accounts', 'id' => 'v2.id', 'link' => 'a-b']]], $scope, 'e');
        $type->validate(['function' => 'max', 'field' => 'date_start', 'cast' => 'datetime', 'source' => ['relationship' => ['module' => 'Accounts', 'id' => 'object.account_id', 'link' => 'meetings']]], $scope, 'ok');
        self::assertSame([
            'a.function: Function must be one of: count, count_distinct, sum, avg, min, max',
            'a.source: Specify either "module" with "filter" or "relationship"',
            'b.field: Field is required for "sum"',
            'b.cast: Function "sum" requires cast: int or decimal',
            'b.scale: Scale must be an integer between 0 and 10',
            'b.source: Specify either "module" with "filter" or "relationship"',
            'b.limit: Limit must be between 1 and 5000',
            'b.on_limit: Must be "error" or "partial"',
            'c.cast: Function "min" requires cast: int, decimal, date, datetime or string',
            'c.source.filter: Filter is required (aggregating a whole module is not allowed)',
            'd.source.filter[0].operator: Operator "is_null" is not supported in aggregate filter: use is_empty',
            'e.source.relationship.link: Link name is required (e.g. contacts)',
            'e.source.relationship.id: Variable "v2" is not defined at this point',
        ], array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors()));
    }
}
