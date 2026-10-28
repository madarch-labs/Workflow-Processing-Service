<?php

declare(strict_types=1);

namespace Wps\Program\Blocks;

use Wps\Program\BlockTypeInterface;
use Wps\Program\BlockValidationContext;
use Wps\Program\Instruction;
use Wps\Program\ProgramEmitter;
use Wps\Services\Validation\DefinitionScope;

/**
 * if / else-if / else inside a process:
 *
 *   { "type": "condition", "id": "big_deal",
 *     "branches": [ { "conditions": [...], "actions": [...] }, ... ],
 *     "else": [ ... ] }
 *
 * Compiles into one "branch" instruction (one audit record: which branch was chosen and why), the branch bodies
 * each followed by a jump to the end, and the else body.
 */
final class ConditionBlock implements BlockTypeInterface
{
    public const MAX_BRANCHES = 20;

    public function type(): string
    {
        return 'condition';
    }

    public function label(): string
    {
        return 'Условие (если / иначе)';
    }

    public function description(): string
    {
        return 'Выполняет действия первой ветки, условия которой выполнены, иначе — действия блока «иначе»';
    }

    public function compile(array $config, string $path, ProgramEmitter $emitter): void
    {
        $branches = array_values(is_array($config['branches'] ?? null) ? $config['branches'] : []);
        $hasElse = array_key_exists('else', $config) && is_array($config['else']);
        $branchPc = $emitter->emit(Instruction::BRANCH, [
            'type' => $this->type(),
            'branches' => array_map(static fn ($b) => ['conditions' => is_array($b['conditions'] ?? null) ? $b['conditions'] : [], 'target' => -1]
                + (isset($b['name']) && is_string($b['name']) ? ['name' => $b['name']] : []), $branches),
            'else_target' => -1,
            'end_target' => -1,
            'has_else' => $hasElse,
        ] + (isset($config['on_error']) ? ['on_error' => $config['on_error']] : []), $path, ProgramEmitter::stepId($config));

        $jumps = [];
        foreach ($branches as $i => $branch) {
            $emitter->patch($branchPc, "branches.{$i}.target", $emitter->nextPc());
            $emitter->compileList(is_array($branch['actions'] ?? null) ? $branch['actions'] : [], "{$path}.branches[{$i}].actions", $branchPc);
            $jumps[] = $emitter->emit(Instruction::JUMP, ['target' => -1], "{$path}.branches[{$i}]");
        }
        $emitter->patch($branchPc, 'else_target', $emitter->nextPc());
        if ($hasElse) {
            $emitter->compileList($config['else'], "{$path}.else", $branchPc);
        }
        $end = $emitter->nextPc();
        $emitter->patch($branchPc, 'end_target', $end);
        foreach ($jumps as $jump) {
            $emitter->patch($jump, 'target', $end);
        }
    }

    public function validate(array $config, DefinitionScope $scope, string $path, BlockValidationContext $context): void
    {
        $branches = $config['branches'] ?? null;
        if (!is_array($branches) || $branches === [] || !array_is_list($branches)) {
            $scope->error("{$path}.branches", 'At least one branch is required');
            $branches = [];
        } elseif (count($branches) > self::MAX_BRANCHES) {
            $scope->error("{$path}.branches", 'Too many branches (max ' . self::MAX_BRANCHES . ')');
        }
        if (array_key_exists('else', $config) && (!is_array($config['else']) || !array_is_list($config['else']))) {
            $scope->error("{$path}.else", 'Must be an array');
        }

        $forks = [];
        $alwaysTrue = false;
        $hasElse = isset($config['else']) && is_array($config['else']) && array_is_list($config['else']);
        foreach ($branches as $i => $branch) {
            $branchPath = "{$path}.branches[{$i}]";
            if (!is_array($branch)) {
                $scope->error($branchPath, 'Branch must be an object');
                continue;
            }
            if (array_key_exists('name', $branch) && (!is_string($branch['name']) || mb_strlen($branch['name']) > 255)) {
                $scope->error("{$branchPath}.name", 'Branch name must be a string up to 255 characters');
            }
            $conditions = $branch['conditions'] ?? [];
            if (!is_array($conditions)) {
                $scope->error("{$branchPath}.conditions", 'Must be an array or a condition group');
            } elseif ($conditions === []) {
                // no conditions: the branch always matches (like process conditions); what follows is unreachable
                if ($i < count($branches) - 1) {
                    $scope->warning("{$branchPath}.conditions", "Branch {$i} has no conditions: the following branches are never reached");
                } elseif ($hasElse) {
                    $scope->warning("{$branchPath}.conditions", "Branch {$i} has no conditions: \"else\" is never reached");
                }
                $alwaysTrue = true;
            } else {
                // conditions see the scope before the block: branches exclude each other
                $context->validateConditions($conditions, $scope, "{$branchPath}.conditions");
            }
            $actions = $branch['actions'] ?? [];
            if (!is_array($actions) || !array_is_list($actions)) {
                $scope->error("{$branchPath}.actions", 'Must be an array');
                $actions = [];
            }
            $fork = $scope->fork();
            $context->validateList($actions, $fork, "{$branchPath}.actions");
            $forks[] = $fork;
        }
        if ($hasElse) {
            $fork = $scope->fork();
            $context->validateList($config['else'], $fork, "{$path}.else");
            $forks[] = $fork;
        }
        $scope->mergeBranches($forks, exhaustive: $hasElse || $alwaysTrue);
    }
}
