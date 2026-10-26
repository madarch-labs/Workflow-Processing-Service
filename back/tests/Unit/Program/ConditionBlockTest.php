<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Program;

use PHPUnit\Framework\TestCase;
use Wps\Program\BlockValidationContext;
use Wps\Program\Blocks\ConditionBlock;
use Wps\Services\Validation\DefinitionScope;

final class ConditionBlockTest extends TestCase
{
    private static function validate(array $config, DefinitionScope $scope): void
    {
        $context = new BlockValidationContext(
            static function (array $list, DefinitionScope $s, string $p): void {
                foreach ($list as $i => $row) {
                    if (isset($row['target'])) {
                        $s->checkWritePath($row['target'], "{$p}[{$i}].target");
                    }
                }
            },
            static function (array $conditions, DefinitionScope $s, string $p): void {
                foreach ($conditions as $i => $row) {
                    $s->checkReadPath($row['field'] ?? null, "{$p}[{$i}].field");
                }
            },
        );
        (new ConditionBlock())->validate($config, $scope, 'definition.actions[0]', $context);
    }

    private static function messages(array $entries): array
    {
        return array_map(static fn (array $e) => $e['field'] . ': ' . $e['message'], $entries);
    }

    public function testRules(): void
    {
        $scope = new DefinitionScope();
        self::validate(['branches' => [
            ['name' => str_repeat('x', 300), 'conditions' => 'amount > 1', 'actions' => []],
            ['conditions' => [['field' => 'v9.x']], 'actions' => 'none'],
            ['conditions' => [], 'actions' => []],
            ['conditions' => [['field' => 'object.a']], 'actions' => []],
        ], 'else' => 'nothing'], $scope);
        self::assertSame([
            'definition.actions[0].else: Must be an array',
            'definition.actions[0].branches[0].name: Branch name must be a string up to 255 characters',
            'definition.actions[0].branches[0].conditions: Must be an array or a condition group',
            'definition.actions[0].branches[1].conditions[0].field: Variable "v9" is not defined at this point',
            'definition.actions[0].branches[1].actions: Must be an array',
        ], self::messages($scope->errors()));
        self::assertSame(['definition.actions[0].branches[2].conditions: Branch 2 has no conditions: the following branches are never reached'], self::messages($scope->warnings()));

        $tooMany = new DefinitionScope();
        self::validate(['branches' => array_fill(0, 21, ['conditions' => [['field' => 'object.a']], 'actions' => []])], $tooMany);
        self::assertSame(['definition.actions[0].branches: Too many branches (max 20)'], self::messages($tooMany->errors()));

        $elseUnreachable = new DefinitionScope();
        self::validate(['branches' => [['conditions' => [], 'actions' => []]], 'else' => []], $elseUnreachable);
        self::assertSame(['definition.actions[0].branches[0].conditions: Branch 0 has no conditions: "else" is never reached'], self::messages($elseUnreachable->warnings()));
    }

    public function testScopes(): void
    {
        // a branch without conditions makes the block exhaustive: a variable set in every branch is definitely known
        $scope = new DefinitionScope();
        self::validate(['branches' => [
            ['conditions' => [['field' => 'object.a']], 'actions' => [['target' => 'task.name']]],
            ['conditions' => [], 'actions' => [['target' => 'task.name']]],
        ]], $scope);
        self::assertTrue($scope->isDefinitelyDeclared('task'));
        // without else only in some paths: conditional
        $partial = new DefinitionScope();
        self::validate(['branches' => [['conditions' => [['field' => 'object.a']], 'actions' => [['target' => 'task.name']]]]], $partial);
        self::assertTrue($partial->isDeclared('task'));
        self::assertFalse($partial->isDefinitelyDeclared('task'));
    }
}
