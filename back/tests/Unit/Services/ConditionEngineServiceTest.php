<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConditionCastException;
use Wps\Exceptions\ConfigurationException;
use Wps\Services\ConditionEngineService;

final class ConditionEngineServiceTest extends TestCase
{
    private ConditionEngineService $engine;
    private ExecutionContext $context;

    protected function setUp(): void
    {
        $this->engine = new ConditionEngineService(ComparatorRegistry::default());
        $this->context = ExecutionContext::fromEvent('e1', [
            'id' => '1', 'status' => 'Active', 'name' => 'VIP deal', 'owner' => 'u1',
            'amount' => '100.00', 'do_not_call' => '1', 'tags' => '^vip^,^partner^', 'empty' => '', 'bad' => 'abc',
        ], 'Deals');
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function row(string $field, string $operator, mixed $value, string $valueType = 'static', array $extra = []): array
    {
        return ['field' => $field, 'operator' => $operator, 'value_type' => $valueType, 'value' => $value] + $extra;
    }

    public function testTypedEquals(): void
    {
        $decimal = $this->engine->evaluate([self::row('object.amount', 'equals', '100', 'static', ['cast' => 'decimal'])], $this->context);
        self::assertTrue($decimal->isMet());
        self::assertSame('decimal', $decimal->trace[0]['cast']);
        self::assertSame('100.00', $decimal->trace[0]['actual'], 'actual keeps the raw value');
        self::assertSame('100', $decimal->trace[0]['actual_cast']);
        self::assertSame('100', $decimal->trace[0]['expected_cast']);

        self::assertFalse($this->engine->evaluate([self::row('object.amount', 'equals', '100')], $this->context)->isMet(), 'without cast: text');
        self::assertTrue($this->engine->evaluate([self::row('object.do_not_call', 'equals', true, 'static', ['cast' => 'bool'])], $this->context)->isMet());
        self::assertTrue($this->engine->evaluate([self::row('object.status', 'equals', 'active', 'static', ['cast' => 'string', 'cast_options' => ['case_sensitive' => false]])], $this->context)->isMet());
    }

    public function testListContainsCondition(): void
    {
        $result = $this->engine->evaluate([self::row('object.tags', 'contains', 'vip', 'static', ['cast' => 'list', 'cast_options' => ['wrapper' => '^']])], $this->context);
        self::assertTrue($result->isMet());
        self::assertSame(['vip', 'partner'], $result->trace[0]['actual_cast']);
    }

    public function testUntypedTraceHasNoCastKeys(): void
    {
        $row = $this->engine->evaluate([self::row('object.status', 'equals', 'Active')], $this->context)->trace[0];
        self::assertSame(['field', 'operator', 'value_type', 'value', 'actual', 'expected', 'result'], array_keys($row));
    }

    public function testCastErrorIsFalseWithTrace(): void
    {
        $result = $this->engine->evaluate([self::row('object.bad', 'not_equals', '1', 'static', ['cast' => 'decimal'])], $this->context);
        self::assertFalse($result->isMet(), 'a failed cast is false even for not_equals');
        self::assertSame(['side' => 'actual', 'message' => 'Value "abc" is not a valid decimal'], $result->trace[0]['cast_error']);
    }

    public function testStrictCastErrorThrows(): void
    {
        try {
            $this->engine->evaluate([self::row('object.bad', 'equals', '1', 'static', ['cast' => 'decimal', 'on_cast_error' => 'error'])], $this->context);
            self::fail('ConditionCastException expected');
        } catch (ConditionCastException $e) {
            self::assertSame('object.bad', $e->getDetails()['field']);
            self::assertSame('actual', $e->getDetails()['cast_error']['side']);
        }
    }

    public function testEmptyValueIsFalseForTypedCast(): void
    {
        $result = $this->engine->evaluate([self::row('object.empty', 'not_equals', '5', 'static', ['cast' => 'decimal'])], $this->context);
        self::assertFalse($result->isMet());
        self::assertSame('actual', $result->trace[0]['empty']);
        // without cast an empty field compares as an empty string (old behaviour)
        self::assertTrue($this->engine->evaluate([self::row('object.empty', 'equals', '')], $this->context)->isMet());
    }

    public function testMissingFieldIsMarkedInTrace(): void
    {
        $row = $this->engine->evaluate([self::row('object.nothing', 'equals', '5', 'static', ['cast' => 'int'])], $this->context)->trace[0];
        self::assertFalse($row['result']);
        self::assertSame('actual', $row['missing']);
    }

    public function testBadCastOptionsAreAConfigurationError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->engine->evaluate([self::row('object.status', 'equals', 'x', 'static', ['cast' => 'string', 'cast_options' => ['scale' => 1]])], $this->context);
    }

    public function testNoConditionsMeansMet(): void
    {
        self::assertTrue($this->engine->evaluate([], $this->context)->isMet());
        self::assertTrue($this->engine->evaluate(null, $this->context)->isMet());
    }

    public function testFlatListIsAnd(): void
    {
        $met = $this->engine->evaluate([
            self::row('object.status', 'equals', 'Active'),
            self::row('object.name', 'contains', 'VIP'),
        ], $this->context);
        self::assertTrue($met->isMet());
        self::assertCount(2, $met->trace);

        $notMet = $this->engine->evaluate([
            self::row('object.status', 'equals', 'Active'),
            self::row('object.name', 'contains', 'test'),
        ], $this->context);
        self::assertFalse($notMet->isMet());
    }

    public function testOrGroupInsideAnd(): void
    {
        $conditions = [
            'logical_operator' => 'AND',
            'conditions' => [self::row('object.status', 'equals', 'Active')],
            'groups' => [[
                'logical_operator' => 'OR',
                'conditions' => [
                    self::row('object.name', 'contains', 'nothing'),
                    self::row('object.name', 'contains', 'VIP'),
                ],
            ]],
        ];
        self::assertTrue($this->engine->evaluate($conditions, $this->context)->isMet());
    }

    public function testMissingFieldNeverSatisfiesACondition(): void
    {
        // customer answer #10: also for not_equals
        $result = $this->engine->evaluate([self::row('object.nofield', 'not_equals', 'x')], $this->context);
        self::assertFalse($result->isMet());
        self::assertFalse($result->trace[0]['result']);
    }

    public function testMissingRightSideFieldIsFalse(): void
    {
        $result = $this->engine->evaluate([self::row('object.owner', 'not_equals', 'object.nofield', 'from_object_field')], $this->context);
        self::assertFalse($result->isMet());
    }

    public function testCompareWithAnotherField(): void
    {
        $this->context->setVariable('v1', ['assigned' => 'u1'], 'Accounts');
        $result = $this->engine->evaluate([self::row('object.owner', 'equals', 'v1.assigned', 'from_object_field')], $this->context);
        self::assertTrue($result->isMet());
    }

    public function testRowOnAMissingVariableIsUnknownForLazyPreload(): void
    {
        $conditions = [self::row('object.status', 'equals', 'Active'), self::row('v1.status', 'equals', 'Active')];
        self::assertTrue($this->engine->evaluate($conditions, $this->context, allowUnknown: true)->isUnknown());
        self::assertFalse($this->engine->evaluate($conditions, $this->context)->isMet());
    }

    public function testFalseObjectRowWinsOverUnknownRow(): void
    {
        $conditions = [self::row('object.status', 'equals', 'Closed'), self::row('v1.status', 'equals', 'Active')];
        $result = $this->engine->evaluate($conditions, $this->context, allowUnknown: true);
        self::assertFalse($result->isUnknown());
        self::assertFalse($result->isMet());
    }

    public function testReferencedVariables(): void
    {
        $vars = $this->engine->referencedVariables([
            self::row('object.status', 'equals', 'Active'),
            self::row('v1.status', 'equals', 'v2.status', 'from_object_field'),
        ]);
        self::assertSame(['v1', 'v2'], $vars);
    }

    public function testUnknownValueTypeIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->engine->evaluate([self::row('object.status', 'equals', 'x', 'magic')], $this->context);
    }

    public function testUnsupportedLogicalOperator(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->engine->evaluate(['logical_operator' => 'XOR', 'conditions' => []], $this->context);
    }
}
