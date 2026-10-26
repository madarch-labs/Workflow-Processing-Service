<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Comparators;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Comparators\OperandKind;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConditionCastException;
use Wps\Services\ConditionEngineService;

final class ChangeComparatorsTest extends TestCase
{
    private ConditionEngineService $engine;

    protected function setUp(): void
    {
        $this->engine = new ConditionEngineService(ComparatorRegistry::default());
    }

    private static function update(): ExecutionContext
    {
        return ExecutionContext::fromEvent('e', [
            'id' => '1', 'module' => 'Opportunities', 'event_type' => 'update',
            'before' => ['sales_stage' => 'Prospecting', 'amount' => '1000.00', 'tags' => '^a^,^b^', 'phone' => '', 'closed' => '2026-10-01'],
            'changed_fields' => ['sales_stage', 'amount', 'tags', 'phone', 'note', 'closed'],
            'sales_stage' => 'Closed Won', 'amount' => '1000', 'tags' => '^b^,^a^', 'phone' => '+7', 'note' => 'new',
            'name' => 'Deal', 'closed' => 'not a date', 'target' => 'Closed Won',
        ], 'Opportunities');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function met(array $row, ?ExecutionContext $context = null): bool
    {
        return $this->engine->evaluate([$row], $context ?? self::update())->isMet();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function updateCases(): iterable
    {
        yield 'changed' => [['field' => 'object.sales_stage', 'operator' => 'changed'], true];
        yield 'changed_to' => [['field' => 'object.sales_stage', 'operator' => 'changed_to', 'value' => 'Closed Won'], true];
        yield 'changed_to other' => [['field' => 'object.sales_stage', 'operator' => 'changed_to', 'value' => 'Closed Lost'], false];
        yield 'changed_from' => [['field' => 'object.sales_stage', 'operator' => 'changed_from', 'value' => 'Prospecting'], true];
        yield 'changed_from other' => [['field' => 'object.sales_stage', 'operator' => 'changed_from', 'value' => 'Qualification'], false];
        yield 'not_changed of a changed field' => [['field' => 'object.sales_stage', 'operator' => 'not_changed'], false];
        yield 'field not in the list: changed' => [['field' => 'object.name', 'operator' => 'changed'], false];
        yield 'field not in the list: not_changed' => [['field' => 'object.name', 'operator' => 'not_changed'], true];
        yield 'phantom change without cast' => [['field' => 'object.amount', 'operator' => 'changed'], true];
        yield 'phantom change with cast decimal' => [['field' => 'object.amount', 'operator' => 'changed', 'cast' => 'decimal'], false];
        yield 'phantom: not_changed with cast' => [['field' => 'object.amount', 'operator' => 'not_changed', 'cast' => 'decimal'], true];
        yield 'multienum order with cast list' => [['field' => 'object.tags', 'operator' => 'changed', 'cast' => 'list', 'cast_options' => ['wrapper' => '^']], false];
        yield 'empty → value' => [['field' => 'object.phone', 'operator' => 'changed'], true];
        yield 'changed_from empty' => [['field' => 'object.phone', 'operator' => 'changed_from', 'value' => ''], true];
        yield 'in changed_fields without before' => [['field' => 'object.note', 'operator' => 'changed_from', 'value' => ''], true];
        yield 'case-insensitive changed_to' => [['field' => 'object.sales_stage', 'operator' => 'changed_to', 'value' => 'closed won', 'cast' => 'string', 'cast_options' => ['case_sensitive' => false]], true];
        yield 'changed_to from a field' => [['field' => 'object.sales_stage', 'operator' => 'changed_to', 'value_type' => 'from_object_field', 'value' => 'object.target'], true];
        yield 'changed_to from a missing field' => [['field' => 'object.sales_stage', 'operator' => 'changed_to', 'value_type' => 'from_object_field', 'value' => 'object.none'], false];
        yield 'cast error → false' => [['field' => 'object.closed', 'operator' => 'changed', 'cast' => 'date'], false];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('updateCases')]
    public function testUpdate(array $row, bool $expected): void
    {
        self::assertSame($expected, $this->met($row));
    }

    public function testStrictCastError(): void
    {
        $this->expectException(ConditionCastException::class);
        $this->met(['field' => 'object.closed', 'operator' => 'changed', 'cast' => 'date', 'on_cast_error' => 'error']);
    }

    public function testNewValueComesFromTheEvent(): void
    {
        $context = self::update();
        $context->set('object.sales_stage', 'Changed by modify');
        self::assertTrue($this->met(['field' => 'object.sales_stage', 'operator' => 'changed_to', 'value' => 'Closed Won'], $context));
        self::assertTrue($this->met(['field' => 'object.sales_stage', 'operator' => 'changed_to', 'value' => 'Closed Won'], $context->forChild()), 'a child sees the source event');
    }

    public function testCreate(): void
    {
        $context = ExecutionContext::fromEvent('e', ['id' => '1', 'event_type' => 'create', 'changed_fields' => ['status'], 'status' => 'New', 'empty' => ''], 'Leads');
        self::assertTrue($this->met(['field' => 'object.status', 'operator' => 'changed'], $context));
        self::assertTrue($this->met(['field' => 'object.status', 'operator' => 'changed_to', 'value' => 'New'], $context));
        self::assertFalse($this->met(['field' => 'object.status', 'operator' => 'changed_from', 'value' => 'New'], $context));
        self::assertFalse($this->met(['field' => 'object.status', 'operator' => 'not_changed'], $context));
        self::assertTrue($this->met(['field' => 'object.empty', 'operator' => 'not_changed'], $context));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unknownEvents(): iterable
    {
        yield 'old format' => [['id' => '1', 'status' => 'New'], 'missing'];
        yield 'delete' => [['id' => '1', 'event_type' => 'delete', 'before' => [], 'changed_fields' => ['deleted'], 'deleted' => '1'], 'delete'];
        yield 'create without changed_fields (mock)' => [['id' => '1', 'event_type' => 'create', 'status' => 'New'], 'missing'];
    }

    /**
     * @param array<string, mixed> $event
     */
    #[DataProvider('unknownEvents')]
    public function testUnknownChangesAreAlwaysFalse(array $event, string $info): void
    {
        $context = ExecutionContext::fromEvent('e', $event, 'Leads');
        foreach (['changed' => null, 'not_changed' => null, 'changed_to' => 'New', 'changed_from' => 'New'] as $operator => $value) {
            $row = ['field' => 'object.status', 'operator' => $operator] + ($value === null ? [] : ['value' => $value]);
            $result = $this->engine->evaluate([$row], $context);
            self::assertFalse($result->isMet(), $operator);
            self::assertSame($info, $result->trace[0]['change_info'], $operator);
        }
    }

    public function testTrace(): void
    {
        $result = $this->engine->evaluate([['field' => 'object.sales_stage', 'operator' => 'changed_to', 'value' => 'Closed Won', 'cast' => 'string']], self::update());
        $row = $result->trace[0];
        self::assertSame('Closed Won', $row['actual']);
        self::assertSame('Prospecting', $row['before']);
        self::assertSame('Prospecting', $row['before_cast']);
        self::assertSame('Closed Won', $row['expected_cast']);
        self::assertTrue($row['changed']);
        self::assertSame('update', $row['event_type']);
        self::assertTrue($row['result']);
    }

    public function testDictionary(): void
    {
        $registry = ComparatorRegistry::default();
        self::assertSame(OperandKind::Change, $registry->get('changed')->operandKind());
        self::assertSame(OperandKind::ChangeValue, $registry->get('changed_from')->operandKind());
        self::assertSame('Изменилось на', $registry->get('changed_to')->label());
    }
}
