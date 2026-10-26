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
        $scope->mergeBranches([$then, $else], exhaustive: true);
        self::assertTrue($scope->isDeclared('owner'));
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
}
