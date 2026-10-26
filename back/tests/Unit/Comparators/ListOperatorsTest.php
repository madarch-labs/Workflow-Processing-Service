<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Comparators;

use PHPUnit\Framework\TestCase;
use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Services\ConditionEngineService;

final class ListOperatorsTest extends TestCase
{
    private const MULTIENUM = ['cast' => 'list', 'cast_options' => ['wrapper' => '^']];

    /**
     * @return array{0: bool, 1: array<string, mixed>}
     */
    private static function check(string $operator, mixed $value, array $object, array $extra = [], array $vars = []): array
    {
        $context = ExecutionContext::fromEvent('e', $object + ['id' => '1'], 'Leads');
        foreach ($vars as $n => $v) {
            $context->setVariable($n, $v, 'Users');
        }
        $result = (new ConditionEngineService(ComparatorRegistry::default()))->evaluate([['field' => 'object.tags_c', 'operator' => $operator, 'value' => $value] + $extra + self::MULTIENUM], $context);
        return [$result->isMet(), $result->trace[0]];
    }

    public function testOperators(): void
    {
        self::assertTrue(self::check('contains_any', ['vip'], ['tags_c' => '^vip^,^partner^'])[0]);
        self::assertFalse(self::check('contains_any', ['vip'], ['tags_c' => '^vipclient^'])[0], 'no substring match');
        self::assertTrue(self::check('contains_all', ['vip', 'partner'], ['tags_c' => '^partner^,^vip^,^x^'])[0]);
        self::assertFalse(self::check('contains_all', ['vip', 'partner'], ['tags_c' => '^vip^'])[0]);
        self::assertTrue(self::check('contains_all', ['vip', 'vip'], ['tags_c' => '^vip^'])[0], 'duplicates on the right do not matter');
        self::assertTrue(self::check('contains_none', ['spam', 'test'], ['tags_c' => ''])[0], 'no tags: contains none');
        self::assertFalse(self::check('contains_none', ['spam'], [])[0], 'missing field');
        self::assertFalse(self::check('contains_any', ['spam'], ['tags_c' => ''])[0]);
        self::assertTrue(self::check('contains_any', ['vip'], ['tags_c' => '^VIP^'], ['cast_options' => ['wrapper' => '^', 'case_sensitive' => false]])[0]);
        self::assertTrue(self::check('contains_any', '^vip^,^gold^', ['tags_c' => '^gold^'])[0], 'a string on the right is parsed like the field');
        [$met, $trace] = self::check('contains_none', ['spam', 'vip'], ['tags_c' => '^vip^,^x^']);
        self::assertSame([false, ['vip']], [$met, $trace['matched']]);
    }

    public function testFromField(): void
    {
        [$met] = self::check('contains_any', 'v1.regions_c', ['tags_c' => '^north^,^west^'], ['value_type' => 'from_object_field'], ['v1' => ['regions_c' => '^south^,^west^']]);
        self::assertTrue($met);
        [$met, $trace] = self::check('contains_all', 'v1.regions_c', ['tags_c' => '^north^'], ['value_type' => 'from_object_field'], ['v1' => ['regions_c' => '']]);
        self::assertSame([false, 'expected'], [$met, $trace['empty']], 'an empty right side is always false');
    }
}
