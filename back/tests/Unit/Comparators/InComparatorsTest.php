<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Comparators;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConditionCastException;
use Wps\Services\ConditionEngineService;

final class InComparatorsTest extends TestCase
{
    private ConditionEngineService $engine;
    private ExecutionContext $context;

    protected function setUp(): void
    {
        $this->engine = new ConditionEngineService(ComparatorRegistry::default());
        $this->context = ExecutionContext::fromEvent('e', [
            'id' => '1', 'status' => 'Assigned', 'state' => ' MSK ', 'code' => '2', 'amount' => '100.00', 'empty' => '',
            'allowed' => '^u1^,^u2^', 'csv' => 'u1, u3', 'owner' => 'u2', 'none' => '[]', 'bad_list' => '1,x,3',
        ], 'Leads');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function met(array $row): bool
    {
        return $this->engine->evaluate([$row], $this->context)->isMet();
    }

    public function testStaticList(): void
    {
        self::assertTrue($this->met(['field' => 'object.status', 'operator' => 'in', 'value' => ['New', 'Assigned']]));
        self::assertFalse($this->met(['field' => 'object.status', 'operator' => 'in', 'value' => ['New', 'Closed']]));
        self::assertTrue($this->met(['field' => 'object.status', 'operator' => 'not_in', 'value' => ['New', 'Closed']]));
        self::assertFalse($this->met(['field' => 'object.status', 'operator' => 'not_in', 'value' => ['assigned', 'Assigned']]));
    }

    public function testTypedItems(): void
    {
        self::assertTrue($this->met(['field' => 'object.state', 'operator' => 'in', 'value' => ['msk', 'spb'], 'cast' => 'string', 'cast_options' => ['case_sensitive' => false, 'trim' => true]]));
        self::assertTrue($this->met(['field' => 'object.code', 'operator' => 'not_in', 'value' => [1, 3], 'cast' => 'int']));
        $row = $this->engine->evaluate([['field' => 'object.amount', 'operator' => 'in', 'value' => ['100', '200'], 'cast' => 'decimal']], $this->context)->trace[0];
        self::assertTrue($row['result']);
        self::assertSame(['100', '200'], $row['expected_cast']);
    }

    public function testListFromAField(): void
    {
        self::assertTrue($this->met(['field' => 'object.owner', 'operator' => 'in', 'value_type' => 'from_object_field', 'value' => 'object.allowed', 'value_list_options' => ['wrapper' => '^']]));
        self::assertFalse($this->met(['field' => 'object.owner', 'operator' => 'in', 'value_type' => 'from_object_field', 'value' => 'object.csv']));
        self::assertTrue($this->met(['field' => 'object.owner', 'operator' => 'not_in', 'value_type' => 'from_object_field', 'value' => 'object.none']), 'empty list: not_in is true');
        self::assertFalse($this->met(['field' => 'object.owner', 'operator' => 'in', 'value_type' => 'from_object_field', 'value' => 'object.none']));
        self::assertFalse($this->met(['field' => 'object.owner', 'operator' => 'not_in', 'value_type' => 'from_object_field', 'value' => 'object.nothing']), 'missing right side');
    }

    public function testMissingAndEmptyLeft(): void
    {
        self::assertFalse($this->met(['field' => 'object.nothing', 'operator' => 'not_in', 'value' => ['a']]));
        self::assertFalse($this->met(['field' => 'object.empty', 'operator' => 'not_in', 'value' => [1], 'cast' => 'int']), 'empty typed value');
        self::assertTrue($this->met(['field' => 'object.empty', 'operator' => 'in', 'value' => ['', 'x']]), "string cast: '' is a value");
    }

    public function testUncastableItemsAreSkippedOrStrict(): void
    {
        $row = $this->engine->evaluate([['field' => 'object.code', 'operator' => 'in', 'value_type' => 'from_object_field', 'value' => 'object.bad_list', 'cast' => 'int']], $this->context)->trace[0];
        self::assertFalse($row['result']);
        self::assertSame([1, 3], $row['expected_cast']);
        self::assertStringContainsString('Item 1', $row['cast_error']['message']);
        $this->expectException(ConditionCastException::class);
        $this->engine->evaluate([['field' => 'object.code', 'operator' => 'in', 'value_type' => 'from_object_field', 'value' => 'object.bad_list', 'cast' => 'int', 'on_cast_error' => 'error']], $this->context);
    }
}
