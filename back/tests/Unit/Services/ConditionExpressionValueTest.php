<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConditionCastException;
use Wps\Expression\ExpressionEngine;
use Wps\Services\ConditionEngineService;
use Wps\Types\TypeCaster;

final class ConditionExpressionValueTest extends TestCase
{
    private static function engine(): ConditionEngineService
    {
        $caster = new TypeCaster();
        return new ConditionEngineService(ComparatorRegistry::default($caster), $caster, null, null, null, new ExpressionEngine($caster));
    }

    private static function context(array $object = [], array $vars = []): ExecutionContext
    {
        $context = ExecutionContext::fromEvent('e', $object + ['id' => '1'], 'Opportunities');
        foreach ($vars as $name => $value) {
            $context->setVariable($name, $value, 'Accounts');
        }
        return $context;
    }

    public function testExpressionValue(): void
    {
        $row = ['field' => 'object.amount', 'operator' => 'greater_than', 'cast' => 'decimal', 'value_type' => 'expression', 'value' => 'v1.credit_limit * 0.8'];
        $result = self::engine()->evaluate([$row], self::context(['amount' => '90000'], ['v1' => ['credit_limit' => '100000']]));
        self::assertTrue($result->isMet());
        self::assertSame(80000, $result->trace[0]['expected']);
        self::assertSame('80000', $result->trace[0]['expected_cast']);
        self::assertFalse(self::engine()->evaluate([$row], self::context(['amount' => '70000'], ['v1' => ['credit_limit' => '100000']]))->isMet());

        $unknown = self::engine()->evaluate([$row], self::context(['amount' => '90000']), allowUnknown: true);
        self::assertTrue($unknown->isUnknown(), 'a variable of the expression triggers the lazy preload');

        $coalesce = ['value' => 'coalesce(v1.max_discount, 10)', 'field' => 'object.discount'] + $row;
        self::assertTrue(self::engine()->evaluate([$coalesce], self::context(['discount' => '15'], ['v1' => []]))->isMet());

        $text = ['field' => 'object.amount', 'operator' => 'equals', 'cast' => 'decimal', 'value_type' => 'expression', 'value' => "'1 200,50'"];
        self::assertTrue(self::engine()->evaluate([$text], self::context(['amount' => '1200.5']))->isMet());

        $lower = ['field' => 'object.email1', 'operator' => 'equals', 'value_type' => 'expression', 'value' => 'lower(owner.email1)'];
        self::assertTrue(self::engine()->evaluate([$lower], self::context(['email1' => 'ivan@x.ru'], ['owner' => ['email1' => 'IVAN@X.RU']]))->isMet());
    }

    public function testErrorsAndNull(): void
    {
        $row = ['field' => 'object.amount', 'operator' => 'greater_than', 'cast' => 'decimal', 'value_type' => 'expression', 'value' => 'object.amount / object.zero'];
        $result = self::engine()->evaluate([$row], self::context(['amount' => '5', 'zero' => '0']));
        self::assertFalse($result->isMet());
        self::assertSame('Division by zero in expression', $result->trace[0]['expression_error']);
        $null = ['value' => 'v1.none'] + $row;
        $missing = self::engine()->evaluate([$null], self::context(['amount' => '5'], ['v1' => []]));
        self::assertSame([false, 'expected'], [$missing->isMet(), $missing->trace[0]['missing'] ?? $missing->trace[0]['empty'] ?? null]);
        $this->expectException(ConditionCastException::class);
        self::engine()->evaluate([['on_cast_error' => 'error'] + $row], self::context(['amount' => '5', 'zero' => '0']));
    }

    public function testRange(): void
    {
        $row = ['field' => 'object.amount', 'operator' => 'between', 'cast' => 'decimal', 'value_type' => 'expression', 'value' => ['v1.plan * 0.9', 'v1.plan * 1.1']];
        $result = self::engine()->evaluate([$row], self::context(['amount' => '1050'], ['v1' => ['plan' => '1000']]));
        self::assertTrue($result->isMet());
        self::assertEquals([900, 1100], $result->trace[0]['expected']);
        self::assertFalse(self::engine()->evaluate([$row], self::context(['amount' => '1150'], ['v1' => ['plan' => '1000']]))->isMet());
    }
}
