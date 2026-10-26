<?php

declare(strict_types=1);

namespace Wps\Services;

use Wps\Actions\ActionHandlerRegistry;
use Wps\Comparators\ComparatorRegistry;
use Wps\Comparators\OperandKind;
use Wps\Http\ApiException;
use Wps\Persistence\Process;
use Wps\Services\Errors\ErrorPolicy;
use Wps\Services\Errors\RetryPolicy;
use Wps\Persistence\ProcessRepository;
use Wps\Program\BlockTypeRegistry;
use Wps\Program\BlockValidationContext;
use Wps\Program\ProgramCompiler;
use Wps\Secrets\SecretReference;
use Wps\Services\Conditions\ListOperand;
use Wps\Secrets\SecretRepository;
use Wps\SideEffects\DeliveryPolicy;
use Wps\SideEffects\HasDeliveryPolicy;
use Wps\Services\Validation\DefinitionScope;
use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Exceptions\ConfigurationException;
use Wps\Expression\ExpressionEngine;
use Wps\Types\RelativeDate;
use Wps\Types\TypeCaster;

/**
 * Validates a process before it is saved: header fields, every condition/preload/action row
 * (each action handler validates its own config), referenced child processes and start_process cycles.
 */
final class ProcessValidatorService
{
    private const MAX_CONDITION_EXPRESSION = 1000;
    public const STEP_ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    private readonly TypeCaster $caster;
    private readonly BlockTypeRegistry $blocks;

    public function __construct(
        private readonly ActionHandlerRegistry $actions,
        private readonly ComparatorRegistry $comparators,
        private readonly ProcessRepository $processes,
        ?TypeCaster $caster = null,
        ?BlockTypeRegistry $blocks = null,
        private readonly int $maxDepth = ProgramCompiler::DEFAULT_MAX_DEPTH,
        private readonly ?SecretRepository $secrets = null,
        private readonly ?ExpressionEngine $expressions = null,
    ) {
        $this->caster = $caster ?? new TypeCaster();
        $this->blocks = $blocks ?? new BlockTypeRegistry();
    }

    /**
     * @param array<string, mixed> $input request body
     * @return array{name: string, object_type: string, is_child: bool, is_active: bool, priority: int, run_once: bool, trigger_on: list<string>, definition: array<mixed>, warnings: list<array{field: string, message: string}>}
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
        $triggerOn = Process::DEFAULT_TRIGGER_ON;
        if (array_key_exists('trigger_on', $input)) {
            if (!is_array($input['trigger_on']) || !array_is_list($input['trigger_on'])) {
                $errors[] = ['field' => 'trigger_on', 'message' => 'Must be an array of event types'];
            } else {
                foreach ($input['trigger_on'] as $type) {
                    if (!is_string($type) || !in_array($type, Process::TRIGGER_TYPES, true)) {
                        $shown = is_scalar($type) ? (string)$type : gettype($type);
                        $errors[] = ['field' => 'trigger_on', 'message' => "Unknown event type \"{$shown}\" (allowed: " . implode(', ', Process::TRIGGER_TYPES) . ')'];
                    }
                }
                $triggerOn = Process::normalizeTriggerOn($input['trigger_on']);
            }
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

        $scope = new DefinitionScope($processId, inheritsVariables: $isChild === true, triggerOn: $triggerOn);
        $this->validateDefinition($definition, $scope);
        $this->checkGotoTargets($definition, $scope);
        $errors = array_merge($errors, $scope->errors());

        if ($errors === []) {
            $errors = array_merge($this->validateChildren($scope), $this->validateSecretReferences($definition));
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
            'trigger_on' => $triggerOn,
            'definition' => $definition,
            // not blocking: e.g. a variable read after a block that sets it only in some branches
            'warnings' => $scope->warnings(),
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
            if (array_key_exists('on_error', $row)) {
                // errors of lazy preload are handled before the execution starts
                $scope->error("definition.preload[{$i}].on_error", 'Not supported in the preload block');
            }
        }

        $this->validateConditions($definition['conditions'], $scope, 'definition.conditions');

        $this->validateActionList($definition['actions'], $scope, 'definition.actions', 0);
    }

    /**
     * Actions in execution order; blocks validate their nested lists through the same method (one level deeper).
     *
     * @param array<mixed> $actions
     */
    private function validateActionList(array $actions, DefinitionScope $scope, string $path, int $depth): void
    {
        $count = count($actions);
        foreach (array_values($actions) as $i => $row) {
            $rowPath = "{$path}[{$i}]";
            if (is_array($row) && ($row['type'] ?? null) === 'stop_process' && $i < $count - 1) {
                $scope->warning("{$path}[" . ($i + 1) . ']', 'Steps after stop_process in the same list are never executed');
            }
            if (!is_array($row)) {
                $scope->error($rowPath, 'Action must be an object');
                continue;
            }
            $this->validateStepHeader($row, $scope, $rowPath);
            $type = $row['type'] ?? null;
            if ($this->blocks->has($type)) {
                if ($depth + 1 > $this->maxDepth) {
                    $scope->error($rowPath, "Nesting is too deep (max {$this->maxDepth})");
                    continue;
                }
                $this->blocks->get($type)->validate($row, $scope, $rowPath, new BlockValidationContext(
                    fn (array $list, DefinitionScope $s, string $p) => $this->validateActionList($list, $s, $p, $depth + 1),
                    fn (array $conditions, DefinitionScope $s, string $p) => $this->validateConditions($conditions, $s, $p),
                ));
            } elseif (is_string($type) && $this->actions->has($type)) {
                $handler = $this->actions->get($type);
                $handler->validate($row, $scope, $rowPath);
                if ($handler instanceof HasDeliveryPolicy) {
                    DeliveryPolicy::validate($row, $scope, $rowPath);
                } elseif (array_key_exists('delivery', $row)) {
                    $scope->error("{$rowPath}.delivery", "Action \"{$type}\" does not support \"delivery\"");
                }
            } else {
                $scope->error("{$rowPath}.type", 'Unknown action type');
            }
        }
    }

    /**
     * Optional "id" (unique in the process, referenced by goto) and "name" of any step.
     *
     * @param array<string, mixed> $row
     */
    private function validateStepHeader(array $row, DefinitionScope $scope, string $rowPath): void
    {
        if (array_key_exists('id', $row)) {
            if (!is_string($row['id']) || !preg_match(self::STEP_ID_PATTERN, $row['id'])) {
                $scope->error("{$rowPath}.id", 'Step id must match ^[a-z][a-z0-9_]{0,63}$');
            } else {
                $scope->registerStepId($row['id'], "{$rowPath}.id");
            }
        }
        if (array_key_exists('name', $row) && (!is_string($row['name']) || mb_strlen($row['name']) > 255)) {
            $scope->error("{$rowPath}.name", 'Step name must be a string up to 255 characters');
        }
        // error handling of the step (md/tasks_list/done/7.1-on-error.md §8); goto targets are checked after the
        // whole definition (checkGotoTargets), since a target is a later step
        if (array_key_exists('on_error', $row) && ($error = ErrorPolicy::error($row['on_error'])) !== null) {
            $scope->error("{$rowPath}.on_error", $error);
        }
        if (array_key_exists('retry', $row)) {
            foreach (RetryPolicy::errors($row['retry']) as $field => $message) {
                $scope->error("{$rowPath}.retry" . ($field === '' ? '' : ".{$field}"), $message);
            }
        }
    }

    /**
     * {"goto": id}: the target is a top-level step after the top-level step that contains the failing one
     * (forward only, so goto cannot make loops).
     *
     * @param array{conditions: array<mixed>, preload: array<mixed>, actions: array<mixed>} $definition
     */
    private function checkGotoTargets(array $definition, DefinitionScope $scope): void
    {
        $topLevel = [];
        foreach ($definition['actions'] as $i => $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $topLevel[$row['id']] = $i;
            }
        }
        $allIds = $scope->stepIds();
        foreach ($definition['actions'] as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (self::gotos($row, "definition.actions[{$i}]") as [$path, $target]) {
                if (!isset($topLevel[$target])) {
                    $scope->error("{$path}.on_error.goto", isset($allIds[$target])
                        ? "Step \"{$target}\" must be a top-level step after the failing step"
                        : "Step \"{$target}\" not found");
                } elseif ($topLevel[$target] <= $i) {
                    $scope->error("{$path}.on_error.goto", "Step \"{$target}\" must be a top-level step after the failing step");
                }
            }
        }
    }

    /**
     * on_error.goto of a step and of every nested step: [path, target].
     *
     * @param array<string, mixed> $row
     * @return list<array{0: string, 1: string}>
     */
    private static function gotos(array $row, string $path): array
    {
        $found = [];
        if (is_array($row['on_error'] ?? null) && is_string($row['on_error']['goto'] ?? null)) {
            $found[] = [$path, $row['on_error']['goto']];
        }
        foreach ($row as $key => $value) {
            if (!is_array($value) || !array_is_list($value)) {
                continue;
            }
            foreach ($value as $j => $item) {
                if (!is_array($item)) {
                    continue;
                }
                // nested action lists: "actions", "else", branches[].actions …
                if (isset($item['type'])) {
                    array_push($found, ...self::gotos($item, "{$path}.{$key}[{$j}]"));
                } elseif (is_array($item['actions'] ?? null)) {
                    foreach (array_values($item['actions']) as $k => $nested) {
                        if (is_array($nested)) {
                            array_push($found, ...self::gotos($nested, "{$path}.{$key}[{$j}].actions[{$k}]"));
                        }
                    }
                }
            }
        }
        return $found;
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
                $operator = null;
            }
            if ($operator !== null && in_array($this->comparators->get($operator)->operandKind(), [OperandKind::None, OperandKind::Presence], true)) {
                // is_empty / is_null …: a value would only mislead the author
                foreach (['value', 'value_type'] as $key) {
                    if (array_key_exists($key, $row)) {
                        $scope->error("{$rowPath}.{$key}", "Operator \"{$operator}\" does not take a value");
                    }
                }
                continue;
            }
            $kind = $operator === null ? null : $this->comparators->get($operator)->operandKind();
            if ($kind === OperandKind::Change || $kind === OperandKind::ChangeValue) {
                // changes are known only for the event object (md/tasks_list/done/3.3-changed-operators.md)
                $field = $row['field'] ?? null;
                if (is_string($field) && !preg_match('/^object\.[^.]+/', $field)) {
                    $scope->error("{$rowPath}.field", "Operator \"{$operator}\" works only with event object fields (object.<field>)");
                }
                if ($kind === OperandKind::Change) {
                    foreach (['value', 'value_type'] as $key) {
                        if (array_key_exists($key, $row)) {
                            $scope->error("{$rowPath}.{$key}", "Operator \"{$operator}\" does not take a value");
                        }
                    }
                    $this->validateCast($row, $operator, false, $scope, $rowPath);
                    continue;
                }
                if (!array_key_exists('value', $row)) {
                    $scope->error("{$rowPath}.value", 'Value is required');
                    continue;
                }
                if (($row['value_type'] ?? null) === ConditionEngineService::VALUE_EXPRESSION) {
                    $scope->error("{$rowPath}.value_type", "Operator \"{$operator}\" does not support value_type \"expression\"");
                    continue;
                }
            }
            $valueType = $row['value_type'] ?? ConditionEngineService::VALUE_STATIC;
            if ($kind === OperandKind::List) {
                $this->validateListCondition($row, $operator, $scope, $rowPath);
                continue;
            }
            if ($kind === OperandKind::Range) {
                $this->validateRangeCondition($row, $operator, $scope, $rowPath);
                continue;
            }
            $staticValueOk = false;
            if ($valueType === ConditionEngineService::VALUE_EXPRESSION) {
                $this->checkConditionExpression($row['value'] ?? null, $scope, "{$rowPath}.value");
            } elseif ($valueType === ConditionEngineService::VALUE_FROM_FIELD) {
                $scope->checkReadPath($row['value'] ?? null, "{$rowPath}.value");
            } elseif ($valueType === ConditionEngineService::VALUE_STATIC) {
                if (isset($row['value']) && !is_scalar($row['value'])) {
                    $scope->error("{$rowPath}.value", 'Value must be a string or a number');
                } else {
                    $staticValueOk = true;
                }
            } else {
                $scope->error("{$rowPath}.value_type", 'Must be "static", "from_object_field" or "expression"');
            }
            $this->validateCast($row, $operator, $valueType === ConditionEngineService::VALUE_STATIC && $staticValueOk, $scope, $rowPath);
        }
    }

    /**
     * value_type "expression" (md/tasks_list/done/3.10-expression-value.md): syntax, functions, variables, length.
     */
    private function checkConditionExpression(mixed $expression, DefinitionScope $scope, string $field): void
    {
        if (!is_string($expression) || trim($expression) === '') {
            $scope->error($field, 'Expression is required');
            return;
        }
        if (mb_strlen($expression) > self::MAX_CONDITION_EXPRESSION) {
            $scope->error($field, 'Expression is too long (max ' . self::MAX_CONDITION_EXPRESSION . ' characters)');
            return;
        }
        if ($this->expressions === null) {
            return;
        }
        try {
            foreach ($this->expressions->paths($expression) as $path) {
                $scope->checkReadPath($path, $field, allowWholeVariable: true);
            }
        } catch (ConfigurationException $e) {
            $scope->error($field, $e->getMessage());
        }
    }

    /**
     * between / not_between (md/tasks_list/done/3.6-between.md): a pair [from, to] of static values that cast to the type
     * (from ≤ to) or of paths.
     *
     * @param array<string, mixed> $row
     */
    private function validateRangeCondition(array $row, string $operator, DefinitionScope $scope, string $rowPath): void
    {
        $this->validateCast($row, $operator, false, $scope, $rowPath);
        $pair = $row['value'] ?? null;
        if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2) {
            $scope->error("{$rowPath}.value", "Operator \"{$operator}\" expects a pair [from, to]");
            return;
        }
        $valueType = $row['value_type'] ?? ConditionEngineService::VALUE_STATIC;
        if ($valueType === ConditionEngineService::VALUE_FROM_FIELD) {
            foreach ($pair as $i => $path) {
                $scope->checkReadPath($path, "{$rowPath}.value[{$i}]");
            }
            return;
        }
        if ($valueType === ConditionEngineService::VALUE_EXPRESSION) {
            foreach ($pair as $i => $expression) {
                $this->checkConditionExpression($expression, $scope, "{$rowPath}.value[{$i}]");
            }
            return;
        }
        if ($valueType !== ConditionEngineService::VALUE_STATIC) {
            $scope->error("{$rowPath}.value_type", 'Must be "static", "from_object_field" or "expression"');
            return;
        }
        if (CastOptions::errors($row['cast'] ?? null, $row['cast_options'] ?? null) !== []) {
            return;
        }
        $options = CastOptions::fromDefinition($row['cast'] ?? null, $row['cast_options'] ?? null);
        if (!in_array($options->type, CastType::orderable(), true)) {
            return; // reported by validateCast()
        }
        $cast = [];
        foreach ($pair as $i => $bound) {
            if ($bound === null || (is_string($bound) && trim($bound) === '') || !is_scalar($bound)) {
                $scope->error("{$rowPath}.value[{$i}]", 'Range bound is required');
                continue;
            }
            if (RelativeDate::looksRelative($bound)) {
                if (($error = RelativeDate::error((string)$bound)) !== null) {
                    $scope->error("{$rowPath}.value[{$i}]", $error);
                }
                continue; // the order of relative bounds is known only at run time
            }
            $result = $this->caster->cast($bound, $options);
            if ($result->isError()) {
                $scope->error("{$rowPath}.value[{$i}]", (string)$result->error);
            } elseif ($result->isOk()) {
                $cast[$i] = $result->value;
            }
        }
        if (count($cast) === 2 && $this->caster->compare($cast[0], $cast[1], $options) > 0) {
            $scope->error("{$rowPath}.value", 'Range start must not be greater than range end');
        }
    }

    /**
     * in / not_in (md/tasks_list/done/3.4-in-not-in.md): a static non-empty list of scalars that cast to the type,
     * or a field; value_list_options only for a field.
     *
     * @param array<string, mixed> $row
     */
    private function validateListCondition(array $row, string $operator, DefinitionScope $scope, string $rowPath): void
    {
        $valueType = $row['value_type'] ?? ConditionEngineService::VALUE_STATIC;
        $this->validateCast($row, $operator, false, $scope, $rowPath);
        if ($valueType === ConditionEngineService::VALUE_EXPRESSION) {
            $this->checkConditionExpression($row['value'] ?? null, $scope, "{$rowPath}.value");
            return;
        }
        if ($valueType === ConditionEngineService::VALUE_FROM_FIELD) {
            $scope->checkReadPath($row['value'] ?? null, "{$rowPath}.value");
            if (array_key_exists('value_list_options', $row)) {
                foreach (ListOperand::optionErrors($row['value_list_options']) as $error) {
                    $scope->error("{$rowPath}.{$error['field']}", $error['message']);
                }
            }
            return;
        }
        if ($valueType !== ConditionEngineService::VALUE_STATIC) {
            $scope->error("{$rowPath}.value_type", 'Must be "static", "from_object_field" or "expression"');
            return;
        }
        if (array_key_exists('value_list_options', $row)) {
            $scope->error("{$rowPath}.value_list_options", 'Only used with value_type "from_object_field"');
        }
        $items = $row['value'] ?? null;
        $castOptions = CastOptions::errors($row['cast'] ?? null, $row['cast_options'] ?? null) === []
            ? CastOptions::fromDefinition($row['cast'] ?? null, $row['cast_options'] ?? null) : null;
        if (is_string($items) && $castOptions?->type === CastType::List) {
            // a list field accepts the value copied from the CRM: "^vip^,^partner^" or "vip,partner"
            $items = $this->caster->cast($items, $castOptions)->value ?? [];
            if ($items === []) {
                $scope->error("{$rowPath}.value", 'List of values must not be empty');
                return;
            }
        }
        if (!is_array($items) || !array_is_list($items)) {
            $scope->error("{$rowPath}.value", "Operator \"{$operator}\" requires a list of values");
            return;
        }
        if ($items === []) {
            $scope->error("{$rowPath}.value", 'List must not be empty');
            return;
        }
        if (count($items) > ListOperand::MAX_ITEMS) {
            $scope->error("{$rowPath}.value", 'List is too long (max ' . ListOperand::MAX_ITEMS . ' items)');
            return;
        }
        if (CastOptions::errors($row['cast'] ?? null, $row['cast_options'] ?? null) !== []) {
            return; // reported by validateCast()
        }
        $options = CastOptions::fromDefinition($row['cast'] ?? null, $row['cast_options'] ?? null);
        foreach ($items as $k => $item) {
            if ($item !== null && !is_scalar($item)) {
                $scope->error("{$rowPath}.value[{$k}]", 'List item must be a string or a number');
                continue;
            }
            if ($options->type === CastType::String) {
                continue;
            }
            $cast = $this->caster->cast($item, $options);
            if ($cast->isEmpty()) {
                $scope->error("{$rowPath}.value[{$k}]", "Empty item is not allowed for cast \"{$options->type->value}\"");
            } elseif ($cast->isError()) {
                $scope->error("{$rowPath}.value[{$k}]", (string)$cast->error);
            }
        }
    }

    /**
     * "cast", "cast_options", "on_cast_error" of a condition row (md/tasks_list/done/3.0-type-cast.md §8).
     *
     * @param array<string, mixed> $row
     */
    private function validateCast(array $row, ?string $operator, bool $checkStaticValue, DefinitionScope $scope, string $rowPath): void
    {
        foreach (['case_sensitive', 'trim'] as $flat) {
            if (array_key_exists($flat, $row)) {
                // the options of the cast live in cast_options (md/tasks_list/done/3.0-type-cast.md)
                $scope->error("{$rowPath}.{$flat}", "Use \"cast_options\": {\"{$flat}\": ...}");
            }
        }
        if (isset($row['on_cast_error']) && !in_array($row['on_cast_error'], [ConditionEngineService::ON_CAST_ERROR_FALSE, ConditionEngineService::ON_CAST_ERROR_ERROR], true)) {
            $scope->error("{$rowPath}.on_cast_error", 'Must be "false" or "error"');
        }
        $errors = CastOptions::errors($row['cast'] ?? null, $row['cast_options'] ?? null);
        foreach ($errors as $error) {
            $scope->error($rowPath . '.' . ($error['field'] === '' ? 'cast' : $error['field']), $error['message']);
        }
        if ($errors !== [] || $operator === null) {
            return;
        }
        $options = CastOptions::fromDefinition($row['cast'] ?? null, $row['cast_options'] ?? null);
        $comparator = $this->comparators->get($operator);
        if (!in_array($options->type, $comparator->supportedCasts(), true)) {
            $casts = array_map(static fn (CastType $t) => $t->value, $comparator->supportedCasts());
            if (!in_array(CastType::String, $comparator->supportedCasts(), true)) {
                // e.g. greater_than: a type is mandatory (md/tasks_list/done/3.1-order-operators.md §8)
                $last = array_pop($casts);
                $scope->error("{$rowPath}.cast", "Operator \"{$operator}\" requires cast: " . ($casts !== [] ? implode(', ', $casts) . ' or ' : '') . $last);
            } else {
                $scope->error("{$rowPath}.cast", "Operator \"{$operator}\" does not support cast \"{$options->type->value}\" (supported: " . implode(', ', $casts) . ')');
            }
            return;
        }
        if ($checkStaticValue && in_array($operator, ['starts_with', 'ends_with'], true) && trim(TypeCaster::stringify($row['value'] ?? null)) === '') {
            // an empty prefix / suffix matches every string
            $scope->error("{$rowPath}.value", "Value is required for operator \"{$operator}\"");
            return;
        }
        if (!$checkStaticValue || $options->type === CastType::String) {
            return;
        }
        if (in_array($options->type, [CastType::Date, CastType::DateTime], true) && RelativeDate::looksRelative($row['value'] ?? null)) {
            if (($error = RelativeDate::error((string)$row['value'])) !== null) {
                $scope->error("{$rowPath}.value", $error);
            }
            return;
        }
        $cast = $this->caster->cast($row['value'] ?? null, $comparator->expectedCast($options));
        if ($cast->isEmpty()) {
            $scope->error("{$rowPath}.value", "Value is required for cast \"{$options->type->value}\"");
        } elseif ($cast->isError()) {
            $scope->error("{$rowPath}.value", (string)$cast->error);
        }
    }

    /**
     * "secret://name[.field]" (md/tasks_list/done/7.4-secrets.md §8): only as the whole value of a credential field
     * of an action — never inside a template, a step value, an expression or a condition — and the secret must exist.
     *
     * @param array<mixed> $definition
     * @return list<array{field: string, message: string}>
     */
    private function validateSecretReferences(array $definition): array
    {
        $errors = [];
        $names = [];
        foreach (SecretReference::findAll($definition) as $found) {
            $reference = SecretReference::parse($found['value']);
            $dataField = (bool)preg_match('/(^definition\.conditions|\.conditions\[|\.(value|expression|field|target|template|subject|body|text|html)$)/', $found['path']);
            if ($reference === null || $dataField) {
                $errors[] = ['field' => $found['path'], 'message' => $reference === null && !str_starts_with($found['value'], SecretReference::PREFIX)
                    ? 'Secrets can only be used in credential fields'
                    : ($dataField ? 'Secrets can only be used in credential fields' : 'Invalid secret reference (expected secret://name or secret://name.field)')];
                continue;
            }
            $names[$reference->name][] = $found['path'];
        }
        if ($names !== [] && $this->secrets !== null) {
            $existing = $this->secrets->existing(array_keys($names));
            foreach ($names as $name => $paths) {
                if (!in_array($name, $existing, true)) {
                    foreach ($paths as $path) {
                        $errors[] = ['field' => $path, 'message' => "Secret \"{$name}\" does not exist"];
                    }
                }
            }
        }
        return $errors;
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
