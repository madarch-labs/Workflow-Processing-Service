<?php

declare(strict_types=1);

namespace Wps\Services;

use Wps\Actions\ActionHandlerRegistry;
use Wps\Comparators\ComparatorRegistry;
use Wps\Http\ApiException;
use Wps\Persistence\Process;
use Wps\Persistence\ProcessRepository;
use Wps\Services\Validation\DefinitionScope;

/**
 * Validates a process before it is saved: header fields, every condition/preload/action row
 * (each action handler validates its own config), referenced child processes and start_process cycles.
 */
final class ProcessValidatorService
{
    public function __construct(
        private readonly ActionHandlerRegistry $actions,
        private readonly ComparatorRegistry $comparators,
        private readonly ProcessRepository $processes,
    ) {
    }

    /**
     * @param array<string, mixed> $input request body
     * @return array{name: string, object_type: string, is_child: bool, is_active: bool, priority: int, run_once: bool, definition: array<mixed>}
     */
    public function validate(array $input, ?int $processId = null): array
    {
        $errors = [];

        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'Process name is required'];
        } elseif (mb_strlen($name) > 255) {
            $errors[] = ['field' => 'name', 'message' => 'Process name is too long (max 255)'];
        }

        $objectType = is_string($input['object_type'] ?? null) ? trim($input['object_type']) : '';
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $objectType)) {
            $errors[] = ['field' => 'object_type', 'message' => 'Object type (CRM module) is required'];
        }

        $isChild = self::bool($input['is_child'] ?? false);
        if ($isChild === null) {
            $errors[] = ['field' => 'is_child', 'message' => 'Must be true or false'];
        }
        $isActive = self::bool($input['is_active'] ?? true);
        if ($isActive === null) {
            $errors[] = ['field' => 'is_active', 'message' => 'Must be true or false'];
        }
        $runOnce = self::bool($input['run_once'] ?? false);
        if ($runOnce === null) {
            $errors[] = ['field' => 'run_once', 'message' => 'Must be true or false'];
        }
        $priority = $input['priority'] ?? 0;
        if (!is_int($priority) && !(is_string($priority) && preg_match('/^-?\d+$/', $priority))) {
            $errors[] = ['field' => 'priority', 'message' => 'Priority must be an integer'];
            $priority = 0;
        }

        $rawDefinition = $input['definition'] ?? [];
        if (!is_array($rawDefinition)) {
            $errors[] = ['field' => 'definition', 'message' => 'Definition must be an object'];
            $rawDefinition = [];
        }
        foreach (['preload', 'actions'] as $block) {
            if (isset($rawDefinition[$block]) && (!is_array($rawDefinition[$block]) || !array_is_list($rawDefinition[$block]))) {
                $errors[] = ['field' => "definition.{$block}", 'message' => 'Must be an array'];
                unset($rawDefinition[$block]);
            }
        }
        if (isset($rawDefinition['conditions']) && !is_array($rawDefinition['conditions'])) {
            $errors[] = ['field' => 'definition.conditions', 'message' => 'Must be an array or a condition group'];
            unset($rawDefinition['conditions']);
        }
        $definition = Process::normalizeDefinition($rawDefinition);

        $scope = new DefinitionScope($processId, inheritsVariables: $isChild === true);
        $this->validateDefinition($definition, $scope);
        $errors = array_merge($errors, $scope->errors());

        if ($errors === []) {
            $errors = $this->validateChildren($scope);
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $this->assertNoCycle($processId, array_keys($scope->childReferences()));

        return [
            'name' => $name,
            'object_type' => $objectType,
            'is_child' => (bool)$isChild,
            'is_active' => (bool)$isActive,
            'priority' => (int)$priority,
            'run_once' => (bool)$runOnce,
            'definition' => $definition,
        ];
    }

    /**
     * Rows are validated in execution order: preload (declares variables), conditions (may use
     * event + preload variables thanks to lazy preload), then actions.
     *
     * @param array{conditions: array<mixed>, preload: array<mixed>, actions: array<mixed>} $definition
     */
    private function validateDefinition(array $definition, DefinitionScope $scope): void
    {
        $preload = $this->actions->get('preload');
        foreach ($definition['preload'] as $i => $row) {
            if (!is_array($row)) {
                $scope->error("definition.preload[{$i}]", 'Row must be an object');
                continue;
            }
            $preload->validate($row, $scope, "definition.preload[{$i}]");
        }

        $this->validateConditions($definition['conditions'], $scope, 'definition.conditions');

        foreach ($definition['actions'] as $i => $row) {
            $path = "definition.actions[{$i}]";
            if (!is_array($row)) {
                $scope->error($path, 'Action must be an object');
                continue;
            }
            $type = $row['type'] ?? null;
            if (!is_string($type) || !$this->actions->has($type)) {
                $scope->error("{$path}.type", 'Unknown action type');
                continue;
            }
            $this->actions->get($type)->validate($row, $scope, $path);
        }
    }

    /**
     * @param array<mixed> $conditions
     */
    private function validateConditions(array $conditions, DefinitionScope $scope, string $path): void
    {
        if (!array_is_list($conditions)) {
            $operator = strtoupper((string)($conditions['logical_operator'] ?? 'AND'));
            if (!in_array($operator, ['AND', 'OR'], true)) {
                $scope->error("{$path}.logical_operator", 'Must be AND or OR');
            }
            $this->validateConditions(array_values((array)($conditions['conditions'] ?? [])), $scope, "{$path}.conditions");
            foreach (array_values((array)($conditions['groups'] ?? [])) as $g => $group) {
                $this->validateConditions(is_array($group) ? $group : [], $scope, "{$path}.groups[{$g}]");
            }
            return;
        }
        foreach ($conditions as $i => $row) {
            $rowPath = "{$path}[{$i}]";
            if (!is_array($row)) {
                $scope->error($rowPath, 'Condition must be an object');
                continue;
            }
            if (isset($row['conditions']) || isset($row['groups'])) {
                $this->validateConditions($row, $scope, $rowPath);
                continue;
            }
            $scope->checkReadPath($row['field'] ?? null, "{$rowPath}.field");
            $operator = $row['operator'] ?? null;
            if (!is_string($operator) || !$this->comparators->has($operator)) {
                $scope->error("{$rowPath}.operator", 'Unknown comparison operator');
            }
            $valueType = $row['value_type'] ?? ConditionEngineService::VALUE_STATIC;
            if ($valueType === ConditionEngineService::VALUE_FROM_FIELD) {
                $scope->checkReadPath($row['value'] ?? null, "{$rowPath}.value");
            } elseif ($valueType === ConditionEngineService::VALUE_STATIC) {
                if (isset($row['value']) && !is_scalar($row['value'])) {
                    $scope->error("{$rowPath}.value", 'Value must be a string or a number');
                }
            } else {
                $scope->error("{$rowPath}.value_type", 'Must be "static" or "from_object_field"');
            }
        }
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function validateChildren(DefinitionScope $scope): array
    {
        $references = $scope->childReferences();
        if ($references === []) {
            return [];
        }
        $found = $this->processes->findMany(array_keys($references));
        $errors = [];
        foreach ($references as $id => $field) {
            if (!isset($found[$id])) {
                $errors[] = ['field' => $field, 'message' => "Process {$id} not found"];
            } elseif (!$found[$id]->isChild) {
                $errors[] = ['field' => $field, 'message' => "Process {$id} \"{$found[$id]->name}\" is not a child process"];
            }
        }
        return $errors;
    }

    /**
     * Simple cycle check (customer: "проверять циклические ссылки, но без глубокой детализации"):
     * DFS from the new children over existing start_process edges; reaching the process itself is a cycle.
     *
     * @param int[] $children
     */
    private function assertNoCycle(?int $processId, array $children): void
    {
        if ($processId === null || $children === []) {
            return; // a new process cannot be referenced by anybody yet
        }
        $graph = $this->processes->startProcessGraph();
        $edges = $graph['edges'];
        $edges[$processId] = $children;

        $stack = [[$processId, [$processId]]];
        $visited = [];
        while ($stack !== []) {
            [$node, $path] = array_pop($stack);
            foreach ($edges[$node] ?? [] as $next) {
                if ($next === $processId) {
                    $cycle = array_map(
                        static fn (int $id) => ['id' => $id, 'name' => $graph['names'][$id] ?? "#{$id}"],
                        [...$path, $processId],
                    );
                    throw new ApiException(422, 'CYCLE_DETECTED', 'Process launch cycle detected: '
                        . implode(' → ', array_column($cycle, 'name')), ['path' => $cycle]);
                }
                if (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $stack[] = [$next, [...$path, $next]];
                }
            }
        }
    }

    private static function bool(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1', $value === 'true' => true,
            $value === 0, $value === '0', $value === 'false' => false,
            default => null,
        };
    }
}
