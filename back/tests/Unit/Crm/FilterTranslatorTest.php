<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Crm;

use PHPUnit\Framework\TestCase;
use Wps\Actions\FindRecordsHandler;
use Wps\Comparators\ComparatorRegistry;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Crm\EmptyFilterValue;
use Wps\Crm\FilterTranslator;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Services\ConditionEngineService;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;
use Wps\Types\TypeCaster;

final class FilterTranslatorTest extends TestCase
{
    private static function context(): ExecutionContext
    {
        return ExecutionContext::fromEvent('e', ['id' => '1', 'account_id' => 'acc-1', 'empty' => '', 'amount' => '1 000,50'], 'Accounts');
    }

    public function testTranslate(): void
    {
        $translator = new FilterTranslator(new TypeCaster(new \DateTimeZone('Europe/Moscow')));
        $filter = $translator->translate([
            ['field' => 'account_id', 'operator' => 'equals', 'value_type' => 'from_object_field', 'value' => 'object.account_id'],
            ['field' => 'sales_stage', 'operator' => 'not_in', 'value' => ['Closed Won', 'Closed Lost']],
            ['field' => 'amount', 'operator' => 'greater_or_equal', 'value' => '1 000,5', 'cast' => 'decimal'],
            ['field' => 'date_closed', 'operator' => 'less_than', 'value' => '31.12.2026', 'cast' => 'date'],
            ['field' => 'date_modified', 'operator' => 'greater_than', 'value' => '2026-10-16 10:00', 'cast' => 'datetime'],
            ['field' => 'name', 'operator' => 'contains', 'value' => '50%_off'],
            ['field' => 'lead_source', 'operator' => 'in', 'value' => ['Web', 'Email']],
            ['field' => 'description', 'operator' => 'is_empty'],
            ['field' => 'do_not_call', 'operator' => 'equals', 'value' => 'yes', 'cast' => 'bool'],
        ], self::context());
        self::assertSame([
            ['account_id', 'eq', 'acc-1'],
            ['sales_stage', 'nin', ['Closed Won', 'Closed Lost']],
            ['amount', 'gte', '1000.5'],
            ['date_closed', 'lt', '2026-12-31'],
            ['date_modified', 'gt', '2026-10-16 10:00:00'],
            ['name', 'like', '%50\\%\\_off%'],
            ['lead_source', 'in', ['Web', 'Email']],
            ['description', 'null', null],
            ['do_not_call', 'eq', '1'],
        ], array_map(static fn (array $c) => [$c['field'], $c['op'], $c['value'] ?? null], $filter->conditions()));
    }

    public function testNotInQuery(): void
    {
        $filter = (new FilterTranslator(new TypeCaster()))->translate([['field' => 's', 'operator' => 'not_in', 'value' => 'a, b']], self::context());
        self::assertSame(['filter' => ['s' => ['nin' => ['a', 'b']]]], $filter->toQuery());
        self::assertTrue($filter->hasOperator('nin'));
    }

    public function testEmptyValues(): void
    {
        $translator = new FilterTranslator(new TypeCaster());
        $rows = [['field' => 'parent_id', 'operator' => 'equals', 'value_type' => 'from_object_field', 'value' => 'object.empty'], ['field' => 'x', 'operator' => 'equals', 'value' => '1']];
        self::assertCount(1, $translator->translate($rows, self::context(), 'ignore_condition')->conditions());
        try {
            $translator->translate($rows, self::context(), 'error');
            self::fail('error policy');
        } catch (NonRecoverableException $e) {
            self::assertStringContainsString('filter[0] (object.empty) is empty', $e->getMessage());
        }
        $this->expectException(EmptyFilterValue::class);
        $translator->translate($rows, self::context());
    }

    public function testCastError(): void
    {
        $this->expectExceptionMessage('find_records: value "1 000,50" of filter[0] is not a int');
        (new FilterTranslator(new TypeCaster()))->translate([['field' => 'n', 'operator' => 'equals', 'value_type' => 'from_object_field', 'value' => 'object.amount', 'cast' => 'int']], self::context());
    }

    public function testValidation(): void
    {
        $make = static fn (string $class) => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $config = $make(AppConfig::class);
        (new \ReflectionProperty(AppConfig::class, 'findRecordsMax'))->setValue($config, 500);
        $handler = new FindRecordsHandler($make(ExternalApiAdapterService::class), new FilterTranslator(new TypeCaster()),
            new ConditionEngineService(ComparatorRegistry::default()), ComparatorRegistry::default(), new TypeCaster(), $config);
        $scope = new DefinitionScope();
        $handler->validate(['var' => 'object', 'module' => 'Deals'], $scope, 'a');
        $handler->validate(['var' => 'deals', 'module' => 'Deals', 'related_to' => ['record' => 'object', 'link' => 'x']], $scope, 'b');
        $handler->validate(['var' => 'd2', 'module' => 'Deals', 'filter' => [
            ['field' => 'a.b', 'operator' => 'equals', 'value' => '1'],
            ['field' => 'x', 'operator' => 'is_null'],
            ['field' => 'x', 'operator' => 'not_contains', 'value' => '1'],
            ['field' => 'x', 'operator' => 'greater_than', 'value' => '1'],
            ['field' => 'x', 'operator' => 'equals', 'value' => 'abc', 'cast' => 'int'],
            ['field' => 'x', 'operator' => 'in', 'value' => []],
            ['field' => 'x', 'operator' => 'equals', 'value_type' => 'from_object_field', 'value' => 'v9.y'],
        ], 'sort' => [['field' => 'a'], ['field' => 'b'], ['field' => 'c']], 'limit' => 900, 'fields' => [], 'on_limit' => 'warn'], $scope, 'c');
        $handler->validate(['var' => 'rel', 'related_to' => ['record' => 'v7', 'link' => 'contacts'], 'sort' => [], 'filter' => [['field' => 'email1', 'operator' => 'is_null']]], $scope, 'd');
        $handler->validate(['var' => 'ok', 'module' => 'Opportunities', 'filter' => [['field' => 'amount', 'operator' => 'greater_than', 'cast' => 'decimal', 'value' => '1000']], 'sort' => [['field' => 'amount', 'direction' => 'desc']]], $scope, 'ok');
        self::assertSame('list', $scope->kind('ok'));
        self::assertSame([
            'a.var: Name "object" is reserved',
            'b: Either "module" or "related_to" is required',
            'c.filter[0].field: Field name of the found records is required (e.g. sales_stage)',
            'c.filter[1].operator: Operator "is_null" is not supported in find_records filter: use is_empty',
            'c.filter[2].operator: Operator "not_contains" is not supported in find_records filter',
            'c.filter[3].cast: Operator "greater_than" requires cast: int, decimal, date or datetime',
            'c.filter[4].value: Value "abc" is not an integer',
            'c.filter[5].value: List of 1..100 values is required',
            'c.filter[6].value: Variable "v9" is not defined at this point',
            'c.sort: At most 2 sort fields: [{"field": ..., "direction": "asc|desc"}]',
            'c.limit: Must be an integer between 1 and 500',
            'c.fields: Must be a non-empty list of field names',
            'c.on_limit: Must be one of: truncate, error',
            'd.related_to.record: Variable "v7" is not defined at this point',
            'd.sort: Sorting is not available for related records',
        ], array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors()));
    }

    public function testListVariableIsReadOnlyForModify(): void
    {
        $context = self::context();
        $context->setVariable('deals', ['items' => [], 'count' => 0], 'Opportunities', ExecutionContext::SOURCE_LIST);
        self::assertTrue($context->isList('deals'));
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Variable "deals" is a record list; use foreach to change its records');
        $context->set('deals.count', 5);
    }
}
