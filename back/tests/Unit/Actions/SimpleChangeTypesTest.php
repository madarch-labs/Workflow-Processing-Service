<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use Wps\Actions\ChangeTypes\AppendChangeType;
use Wps\Actions\ChangeTypes\ClearChangeType;
use Wps\Actions\ChangeTypes\IncrementChangeType;
use Wps\Actions\ChangeTypes\MapChangeType;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Services\Validation\DefinitionScope;
use Wps\Template\TemplateEngine;
use Wps\Types\TypeCaster;

/**
 * increment / decrement (5.1.3), clear (5.1.4), append / prepend / remove (5.1.5), map (5.1.6).
 */
final class SimpleChangeTypesTest extends TestCase
{
    private static function context(): ExecutionContext
    {
        $context = ExecutionContext::fromEvent('e', ['id' => '1', 'cases' => '4', 'bonus' => '100.50', 'used' => '20.25', 'bad' => 'abc', 'status' => 'New',
            'description' => 'Старое', 'tags_c' => '^vip^,^cold^', 'rating' => 'hot', 'amount' => '50000'], 'Accounts');
        $context->setVariable('json', ['tags' => ['a', 'b']], null, 'http');
        return $context;
    }

    private static function templates(): TemplateEngine
    {
        return new TemplateEngine(new ExpressionEngine());
    }

    public function testIncrement(): void
    {
        $inc = new IncrementChangeType(new TypeCaster(), 1);
        $dec = new IncrementChangeType(new TypeCaster(), -1);
        $c = self::context();
        self::assertSame(5, $inc->compute($c, ['target' => 'object.cases']));
        self::assertSame(7, $inc->compute($c, ['target' => 'object.cases', 'value' => 3]));
        self::assertSame(1, $inc->compute($c, ['target' => 'object.none']), 'missing counts as 0');
        self::assertSame('80.25', $dec->compute($c, ['target' => 'object.bonus', 'value_type' => 'from_object_field', 'value' => 'object.used']));
        self::assertSame('5.5', $inc->compute($c, ['target' => 'object.cases', 'value' => '1,5']));
        self::assertSame(2, $inc->compute($c, ['target' => 'object.bad', 'value' => 2, 'on_invalid' => 'reset']));
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('increment: current value "abc" of object.bad is not a number');
        $inc->compute($c, ['target' => 'object.bad']);
    }

    public function testClearAndAppend(): void
    {
        $c = self::context();
        self::assertSame('', (new ClearChangeType())->compute($c, []));
        self::assertNull((new ClearChangeType())->compute($c, ['empty' => 'null']));
        $append = new AppendChangeType(self::templates(), new TypeCaster(), 'append');
        $prepend = new AppendChangeType(self::templates(), new TypeCaster(), 'prepend');
        $remove = new AppendChangeType(self::templates(), new TypeCaster(), 'remove');
        self::assertSame("Старое\nстатус New", $append->compute($c, ['target' => 'object.description', 'value' => 'статус {{ object.status }}', 'separator' => "\n"]));
        self::assertSame('новое', $append->compute($c, ['target' => 'object.empty', 'value' => 'новое', 'separator' => "\n"]), 'no separator for an empty value');
        self::assertSame('Старое', $append->compute($c, ['target' => 'object.description', 'value' => '{{ object.none }}']), 'nothing to add');
        self::assertSame('Новое | Старое', $prepend->compute($c, ['target' => 'object.description', 'value' => 'Новое', 'separator' => ' | ']));
        self::assertSame('ое+123', $append->compute($c, ['target' => 'object.description', 'value' => '123', 'separator' => '+', 'max_length' => 6]), 'append keeps the end');
        $list = ['mode' => 'list', 'list' => ['wrapper' => '^']];
        self::assertSame('^vip^,^cold^,^gold^', $append->compute($c, ['target' => 'object.tags_c', 'value' => 'gold'] + $list));
        self::assertSame('^vip^,^cold^', $append->compute($c, ['target' => 'object.tags_c', 'value' => 'vip'] + $list), 'unique');
        self::assertSame('^gold^,^vip^,^cold^', $prepend->compute($c, ['target' => 'object.tags_c', 'value' => 'gold'] + $list));
        self::assertSame('^vip^', $remove->compute($c, ['target' => 'object.tags_c', 'value' => 'cold'] + $list));
        self::assertSame('^vip^,^cold^', $remove->compute($c, ['target' => 'object.tags_c', 'value' => 'none'] + $list));
        self::assertSame('', $remove->compute($c, ['target' => 'object.tags_c', 'value' => '^vip^,^cold^'] + $list));
        self::assertSame(['a', 'b', 'c'], $append->compute($c, ['target' => 'json.tags', 'value' => 'c', 'mode' => 'list']), 'an array stays an array');
    }

    public function testMap(): void
    {
        $map = new MapChangeType(new TypeCaster(), self::templates());
        $c = self::context();
        $exact = ['target' => 'object.status', 'source' => 'object.rating', 'map' => ['Hot' => 'High', 'Warm' => 'Medium'], 'default' => 'Low'];
        self::assertSame('Low', $map->compute($c, $exact), 'case-sensitive by default');
        self::assertSame('High', $map->compute($c, $exact + ['cast' => 'string', 'cast_options' => ['case_sensitive' => false]]));
        self::assertSame('New', $map->compute($c, ['on_no_match' => 'keep'] + $exact), 'keep the target');
        self::assertSame('M', $map->compute($c, ['target' => 'object.size', 'source' => 'object.amount', 'mode' => 'ranges', 'cast' => 'decimal',
            'ranges' => [['lt' => 10000, 'value' => 'S'], ['lte' => 50000, 'value' => 'M']], 'default' => 'L']));
        self::assertSame('Статус New', $map->compute($c, ['target' => 'object.x', 'source' => 'object.amount', 'cast' => 'decimal', 'map' => ['50000.00' => 'Статус {{ object.status }}'], 'value_template' => true]));
        self::assertSame('пусто', $map->compute($c, ['target' => 'object.x', 'source' => 'object.none', 'map' => ['' => 'пусто', 'a' => 'b']]));
        $this->expectExceptionMessage('map: no match for "hot" in object.rating');
        $map->compute($c, ['on_no_match' => 'error'] + $exact);
    }

    public function testValidation(): void
    {
        $scope = new DefinitionScope();
        (new IncrementChangeType(new TypeCaster(), 1))->validate(['value' => 'abc', 'cast' => 'date', 'scale' => 11, 'on_invalid' => 'zero'], $scope, 'i');
        (new ClearChangeType())->validate(['empty' => 'none'], $scope, 'c');
        (new AppendChangeType(self::templates(), new TypeCaster(), 'remove'))->validate(['value' => '', 'mode' => 'text', 'max_length' => 0], $scope, 'a');
        (new MapChangeType(new TypeCaster(), self::templates()))->validate(['source' => 'v9.x', 'map' => ['100' => 'a', '100.0' => 'b', 'x' => 'c'], 'cast' => 'decimal', 'on_no_match' => 'default'], $scope, 'm');
        (new MapChangeType(new TypeCaster(), self::templates()))->validate(['source' => 'object.amount', 'mode' => 'ranges', 'ranges' => [['lt' => 10, 'lte' => 5], ['lt' => 100, 'value' => 1], ['lt' => 50, 'value' => 2]]], $scope, 'r');
        self::assertSame([
            'i.value: Step must be a number',
            'i.cast: Cast must be "int" or "decimal"',
            'i.scale: Scale must be an integer from 0 to 10',
            'i.on_invalid: Must be "error" or "reset"',
            'c.empty: Must be "" or "null"',
            'a.value: Value is required',
            'a.mode: "remove" works only with mode "list"',
            'a.max_length: Must be a positive integer',
            'm.source: Variable "v9" is not defined at this point',
            'm.map: Keys "100" and "100.0" are equal after cast',
            'm.map: Key "x" cannot be cast to decimal',
            'm.default: Default value is required',
            'r.cast: Mode "ranges" requires cast: int, decimal, date or datetime',
            'r.ranges[0]: Range must have exactly one of "lt", "lte"',
        ], array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors()));
    }
}
