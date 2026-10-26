<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use Wps\Actions\ChangeTypes\CalculateChangeType;
use Wps\Actions\ChangeTypes\ChangeTypeRegistry;
use Wps\Actions\ChangeTypes\SetFromVariableChangeType;
use Wps\Actions\ChangeTypes\SetValueChangeType;
use Wps\Actions\UpdateRecordsHandler;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Expression\ExpressionEngine;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;

final class UpdateRecordsTest extends TestCase
{
    private static function handler(): UpdateRecordsHandler
    {
        $changeTypes = new ChangeTypeRegistry();
        $changeTypes->register(new SetValueChangeType());
        $changeTypes->register(new SetFromVariableChangeType());
        $changeTypes->register(new CalculateChangeType(new ExpressionEngine()));
        $config = (new \ReflectionClass(AppConfig::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AppConfig::class, 'updateRecordsMax'))->setValue($config, 200);
        return new UpdateRecordsHandler($changeTypes, (new \ReflectionClass(ExternalApiAdapterService::class))->newInstanceWithoutConstructor(), $config);
    }

    public function testValidation(): void
    {
        $scope = new DefinitionScope();
        $scope->declare('contacts', ExecutionContext::SOURCE_LIST);
        $scope->declare('item', 'created');
        $scope->declare('acc', 'preload');
        $h = self::handler();
        $h->validate(['list' => 'acc', 'set' => [], 'max' => 900, 'on_item_error' => 'skip', 'only_changed' => 'yes', 'module' => '1'], $scope, 'a');
        $h->validate(['list' => 'v9', 'item_var' => 'c', 'set' => [
            ['field' => 'id', 'change_type' => 'set_value', 'value' => 'x'],
            ['field' => 'a.b', 'change_type' => 'set_value', 'value' => 'x'],
            ['field' => 'title', 'change_type' => 'nope'],
            ['field' => 'description', 'change_type' => 'calculate', 'value' => "concat(c.description, ' / ', v8.x)"],
            ['field' => 'description', 'change_type' => 'set_value', 'value' => '1'],
        ]], $scope, 'b');
        $h->validate(['list' => 'contacts', 'set' => [['field' => 'x', 'change_type' => 'set_value', 'value' => '1']]], $scope, 'c');
        $h->validate(['list' => 'contacts', 'item_var' => 'c', 'set' => [['field' => 'assigned_user_id', 'change_type' => 'set_from_variable', 'value' => 'c.created_by']]], $scope, 'ok');
        $messages = array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $scope->errors());
        self::assertSame([
            'a.list: Variable "acc" is not a record list (use find_records)',
            'a.item_var: Variable "item" already exists; set "item_var"',
            'a.set: 1..20 field rules are required: [{"field": ..., "change_type": ..., "value": ...}]',
            'a.module: Invalid CRM module',
            'a.max: Max must be between 1 and 200',
            'a.on_item_error: Must be one of: continue, stop',
            'a.only_changed: Must be true or false',
            'b.list: Variable "v9" is not defined at this point',
            'b.set[0].field: Field "id" cannot be changed',
            'b.set[1].field: Field name is required',
            'b.set[2].change_type: Change type must be one of: set_value, set_from_variable, calculate',
            'b.set[3].value: Variable "v8" is not defined at this point',
            'b.set[4].field: Field "description" is set twice',
            'c.item_var: Variable "item" already exists; set "item_var"',
        ], $messages);
        self::assertFalse($scope->isDeclared('c'), 'the record variable exists only inside the step');
    }

    public function testTemporaryVariable(): void
    {
        $context = ExecutionContext::fromEvent('e', ['id' => '1'], 'Accounts');
        $value = $context->withTemporaryVariable('item', ['id' => 'c1', 'n' => 2], 'Contacts', static fn () => $context->get('item.n') * 10);
        self::assertSame(20, $value);
        self::assertFalse($context->hasVariable('item'));
        try {
            $context->withTemporaryVariable('item', ['id' => 'c1'], 'Contacts', static fn () => throw new \RuntimeException('x'));
        } catch (\RuntimeException) {
        }
        self::assertFalse($context->hasVariable('item'), 'removed after an error too');
    }
}
