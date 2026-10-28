<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Comparators;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Services\ConditionEngineService;

/**
 * Truth table of md/tasks_list/done/3.2-is-empty.md §4.4.
 */
final class PresenceComparatorsTest extends TestCase
{
    private static function context(): ExecutionContext
    {
        $context = ExecutionContext::fromEvent('e', [
            'id' => '1', 'null' => null, 'blank' => '', 'spaces' => '   ', 'zero' => '0', 'false' => false,
            'list' => [], 'value' => 'x', 'list_string' => '[]',
        ], 'Deals');
        $context->setVariable('v1', null, 'Accounts'); // loaded, not found in the CRM
        return $context;
    }

    /**
     * @return iterable<string, array{string, array{bool, bool, bool, bool}}>
     */
    public static function table(): iterable
    {
        // field => [is_empty, is_not_empty, is_null, is_not_null]
        yield 'missing field' => ['object.nothing', [false, false, true, false]];
        yield 'variable not found in the CRM' => ['v1.id', [false, false, true, false]];
        yield 'null' => ['object.null', [true, false, true, false]];
        yield 'empty string' => ['object.blank', [true, false, false, true]];
        yield 'only spaces' => ['object.spaces', [true, false, false, true]];
        yield "'0'" => ['object.zero', [false, true, false, true]];
        yield 'false' => ['object.false', [false, true, false, true]];
        yield 'empty list' => ['object.list', [true, false, false, true]];
        yield "string '[]' is a value" => ['object.list_string', [false, true, false, true]];
        yield 'value' => ['object.value', [false, true, false, true]];
    }

    /**
     * @param array{bool, bool, bool, bool} $expected
     */
    #[DataProvider('table')]
    public function testTruthTable(string $field, array $expected): void
    {
        $engine = new ConditionEngineService(ComparatorRegistry::default());
        $context = self::context();
        foreach (['is_empty', 'is_not_empty', 'is_null', 'is_not_null'] as $i => $operator) {
            $result = $engine->evaluate([['field' => $field, 'operator' => $operator]], $context);
            self::assertSame($expected[$i], $result->isMet(), "{$operator} on {$field}");
        }
    }

    public function testTraceHasFoundAndMissing(): void
    {
        $engine = new ConditionEngineService(ComparatorRegistry::default());
        $row = $engine->evaluate([['field' => 'object.nothing', 'operator' => 'is_null']], self::context())->trace[0];
        self::assertFalse($row['found']);
        self::assertSame('actual', $row['missing']);
        self::assertTrue($row['result']);
        self::assertNull($row['expected']);
        $row = $engine->evaluate([['field' => 'object.blank', 'operator' => 'is_empty']], self::context())->trace[0];
        self::assertTrue($row['found']);
        self::assertArrayNotHasKey('missing', $row);
    }

    public function testLazyPreloadIsUnknown(): void
    {
        $engine = new ConditionEngineService(ComparatorRegistry::default());
        $context = ExecutionContext::fromEvent('e', ['id' => '1'], 'Deals');
        self::assertTrue($engine->evaluate([['field' => 'v2.id', 'operator' => 'is_not_null']], $context, allowUnknown: true)->isUnknown());
    }

    public function testCastIsIgnored(): void
    {
        $engine = new ConditionEngineService(ComparatorRegistry::default());
        self::assertTrue($engine->evaluate([['field' => 'object.value', 'operator' => 'is_not_empty', 'cast' => 'decimal']], self::context())->isMet());
    }

    public function testDictionary(): void
    {
        $dictionary = array_column(ComparatorRegistry::default()->dictionary(), null, 'value');
        self::assertSame('none', $dictionary['is_empty']['operands']);
        self::assertSame('presence', $dictionary['is_null']['operands']);
        self::assertSame('Данные отсутствуют', $dictionary['is_null']['label']);
    }
}
