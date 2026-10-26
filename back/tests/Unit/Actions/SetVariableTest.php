<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use Wps\Actions\ChangeTypes\CalculateChangeType;
use Wps\Actions\ChangeTypes\ChangeTypeRegistry;
use Wps\Actions\ChangeTypes\SetFromVariableChangeType;
use Wps\Actions\ChangeTypes\SetValueChangeType;
use Wps\Actions\SetVariableHandler;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Services\CrmObjectSaver;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;

final class SetVariableTest extends TestCase
{
    private static function handler(): SetVariableHandler
    {
        $registry = new ChangeTypeRegistry();
        $registry->register(new SetValueChangeType());
        $registry->register(new SetFromVariableChangeType());
        $registry->register(new CalculateChangeType(new ExpressionEngine()));
        return new SetVariableHandler($registry);
    }

    public function testExecute(): void
    {
        $context = ExecutionContext::fromEvent('e', ['id' => '1', 'name' => 'Deal', 'amount' => '500'], 'Deals');
        $handler = self::handler();
        $handler->execute($context, ['target' => 'calc.subject', 'change_type' => 'calculate', 'value' => "concat('Сделка ', object.name)"]);
        self::assertSame('Сделка Deal', $context->get('calc.subject'));
        self::assertTrue($context->isLocal('calc'));
        self::assertSame([], $context->dirtyFields('calc'));
        $result = $handler->execute($context, ['var' => 'totals', 'fields' => [
            'sum' => ['change_type' => 'calculate', 'value' => 'totals.sum + object.amount'],
            'twice' => ['change_type' => 'calculate', 'value' => 'totals.sum * 2'],
            'label' => ['change_type' => 'set_value', 'value' => 'Итого'],
        ]]);
        // without an initial value '' + '500' concatenates (rule of 5.2.3): initialize accumulators before a loop
        self::assertSame(['sum' => '500', 'twice' => 1000, 'label' => 'Итого'], $context->getVariable('totals'), 'fields in order: later ones see earlier ones');
        self::assertSame(['old_value' => null, 'new_value' => '500'], $result->result['fields']['sum']);
        $handler->execute($context, ['var' => 'totals', 'fields' => [['name' => 'sum', 'change_type' => 'calculate', 'value' => 'totals.sum + object.amount']]]);
        self::assertSame(1000, $context->get('totals.sum'));
        $context->set('calc.extra', 'x');
        self::assertSame([], $context->dirtyFields('calc'), 'modify of a local variable keeps it local');

        $saver = new CrmObjectSaver((new \ReflectionClass(ExternalApiAdapterService::class))->newInstanceWithoutConstructor());
        try {
            $saver->save($context, 'calc');
            self::fail('a local variable is saved');
        } catch (NonRecoverableException $e) {
            self::assertSame('Variable "calc" is local (set_variable) and cannot be saved to the CRM', $e->getMessage());
        }
        $context->setVariable('acc', ['id' => 'a1'], 'Accounts');
        $this->expectExceptionMessage('set_variable: "acc" is a CRM record variable; use modify');
        $handler->execute($context, ['target' => 'acc.x', 'change_type' => 'set_value', 'value' => '1']);
    }

    public function testValidation(): void
    {
        $scope = new DefinitionScope();
        $scope->declare('acc', 'preload');
        $handler = self::handler();
        $handler->validate([], $scope, 'a');
        $handler->validate(['target' => 'calc.x', 'var' => 'calc', 'fields' => []], $scope, 'b');
        $handler->validate(['target' => 'calc', 'change_type' => 'set_value', 'value' => '1'], $scope, 'c');
        $handler->validate(['target' => 'sys.x', 'change_type' => 'set_value', 'value' => '1'], $scope, 'd');
        $handler->validate(['target' => 'acc.x', 'change_type' => 'set_value', 'value' => '1'], $scope, 'e');
        $handler->validate(['var' => 'totals', 'fields' => []], $scope, 'f');
        $handler->validate(['var' => 'totals', 'fields' => ['bad-name' => [], 'n' => ['change_type' => 'magic'], 'm' => ['change_type' => 'calculate', 'value' => 'v9.x + 1']]], $scope, 'g');
        $handler->validate(['var' => 'acc2', 'fields' => ['sum' => ['change_type' => 'calculate', 'value' => 'acc2.sum + 1']]], $scope, 'ok');
        self::assertSame('local', $scope->kind('acc2'));
        $warn = new DefinitionScope();
        $handler->validate(['var' => 't', 'fields' => ['a' => ['change_type' => 'set_value', 'value' => '1'], 'b' => ['change_type' => 'calculate', 'value' => 't.a + 1']]], $warn, 'w');
        $handler->validate(['var' => 'u', 'fields' => [['name' => 'a', 'change_type' => 'set_value', 'value' => '1'], ['name' => 'b', 'change_type' => 'calculate', 'value' => 'u.a + 1'], ['change_type' => 'set_value']]], $warn, 'l');
        self::assertSame(['w.fields: The order of object keys is not kept: use a list of fields when a field uses another field of "t"'],
            array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $warn->warnings()));
        self::assertSame(['l.fields[2]: Must be an object {"name": ..., "change_type": ..., "value": ...}'],
            array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $warn->errors()));
        self::assertSame([
            'a: Either "target" or "var" with "fields" is required',
            'b: Either "target" or "var" with "fields" is required',
            'c.target: "calc" must point to a field, e.g. calc.name',
            'd.target: Name "sys" is reserved',
            'e.target: Variable "acc" is a CRM record variable; use modify',
            'f.fields: "fields" must be a non-empty list or object',
            'g.fields.bad-name: Invalid field name "bad-name"',
            'g.fields.n.change_type: Change type must be one of: set_value, set_from_variable, calculate',
            'g.fields.m.value: Variable "v9" is not defined at this point',
        ], array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors()));
    }
}
