<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Wps\Services\Validation\DefinitionScope;

final class DefinitionScopeTest extends TestCase
{
    public function testVariableSetInEveryBranchOfAnExhaustiveBlockIsDeclared(): void
    {
        $scope = new DefinitionScope();
        $then = $scope->fork();
        $then->declare('owner', 'preload');
        $else = $scope->fork();
        $else->declare('owner', 'preload');
        $scope->mergeBranches([$then, $else], exhaustive: true);
        self::assertTrue($scope->isDefinitelyDeclared('owner'));
        $scope->checkReadPath('owner.id', 'f');
        self::assertSame([], $scope->warnings());
    }

    public function testVariableSetInSomeBranchesIsConditional(): void
    {
        $scope = new DefinitionScope();
        $then = $scope->fork();
        $then->declare('owner', 'preload');
        $else = $scope->fork();
        $scope->mergeBranches([$then, $else], exhaustive: true);        self::assertTrue($scope->isDeclared('owner'));
        self::assertFalse($scope->isDefinitelyDeclared('owner'));
        self::assertTrue($scope->checkReadPath('owner.id', 'definition.actions[1].value'));
        self::assertSame([['field' => 'definition.actions[1].value', 'message' => 'Variable "owner" may be undefined here: it is set only in some branches']], $scope->warnings());
        self::assertSame([], $scope->errors());
    }

    public function testBlockWithoutElseMakesVariablesConditional(): void
    {
        $scope = new DefinitionScope();
        $then = $scope->fork();
        $then->declare('task', 'created');
        $scope->mergeBranches([$then], exhaustive: false);
        self::assertSame(DefinitionScope::CONDITIONAL, $scope->variables()['task']);
        $scope->declare('task', 'created');
        self::assertTrue($scope->isDefinitelyDeclared('task'), 'a later unconditional write makes it definite');
    }

    public function testForksShareErrorsAndStepIds(): void
    {
        $scope = new DefinitionScope();
        $fork = $scope->fork();
        $fork->error('x', 'broken');
        self::assertTrue($fork->registerStepId('notify', 'definition.actions[0].branches[0].actions[0].id'));
        self::assertFalse($scope->registerStepId('notify', 'definition.actions[1].id'));
        self::assertSame('broken', $scope->errors()[0]['message']);
        self::assertSame('Step id "notify" is already used at definition.actions[0].branches[0].actions[0]', $scope->errors()[1]['message']);
        self::assertSame(['notify' => 'definition.actions[0].branches[0].actions[0]'], $scope->stepIds());
        self::assertFalse($scope->isDeclared('anything'));
    }

    public function testSystemAndConfigPathsAreKnown(): void
    {
        $scope = new DefinitionScope(constants: ['vip_threshold', 'open_stages']);
        self::assertSame('system', $scope->kind('sys'));
        self::assertSame('system', $scope->kind('config'));
        self::assertTrue($scope->checkReadPath('sys.execution_id', 'f'));
        self::assertTrue($scope->checkReadPath('sys.last_error.code', 'f'));
        self::assertTrue($scope->checkReadPath('sys', 'f', allowWholeVariable: true));
        self::assertTrue($scope->checkReadPath('config.vip_threshold', 'f'));
        self::assertTrue($scope->checkReadPath('config', 'f', allowWholeVariable: true));
        self::assertSame([], $scope->errors());
    }

    public function testUnknownSystemVariableIsAnError(): void
    {
        $scope = new DefinitionScope(constants: []);
        self::assertFalse($scope->checkReadPath('sys.unknown', 'definition.conditions[0].field'));
        $errors = $scope->errors();
        self::assertSame('definition.conditions[0].field', $errors[0]['field']);
        self::assertStringStartsWith('Unknown system variable "sys.unknown"; available: sys.execution_id, ', $errors[0]['message']);
    }

    public function testUndefinedConstantIsAnError(): void
    {
        $scope = new DefinitionScope(constants: ['known']);
        self::assertFalse($scope->checkReadPath('config.missing', 'definition.actions[0].value'));
        self::assertSame([['field' => 'definition.actions[0].value', 'message' => 'Constant "missing" is not defined in definition.constants']], $scope->errors());
    }

    public function testReservedNamesCannotBeWritten(): void
    {
        $scope = new DefinitionScope(constants: ['known']);
        foreach (['sys', 'config', 'object_before'] as $var) {
            self::assertFalse($scope->checkWritePath("{$var}.x", 'f'));
        }
        self::assertSame([
            ['field' => 'f', 'message' => 'Variable "sys" is read-only'],
            ['field' => 'f', 'message' => 'Variable "config" is read-only'],
            ['field' => 'f', 'message' => 'Variable "object_before" is read-only'],
        ], $scope->errors());
        self::assertTrue($scope->checkWritePath('object.status', 'f'), 'object itself is writable');
        self::assertTrue($scope->checkWritePath('created.value', 'f'));
    }

    public function testDeclareDoesNotOverrideReservedNames(): void
    {
        $scope = new DefinitionScope();
        $scope->declare('config', 'created');
        self::assertSame('system', $scope->kind('config'));
    }
}
