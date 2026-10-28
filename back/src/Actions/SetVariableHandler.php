<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Actions\ChangeTypes\ChangeTypeRegistry;
use Wps\Actions\ChangeTypes\ReportsDetails;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Services\Validation\DefinitionScope;

/**
 * "Записать в переменную процесса" (md/tasks_list/done/4.13-set-variable.md): an intermediate value in a variable that
 * is not a CRM record — never dirty, cannot be saved. One field ("target") or several ("var" + "fields", computed in
 * order: a later field sees an earlier one). Any change type of modify works here.
 *
 * Definitions are stored as jsonb, which does not keep the order of object keys: "fields" as a list
 * [{"name": ..., "change_type": ..., "value": ...}] keeps the order; the object form is for independent fields.
 */
final class SetVariableHandler implements ActionHandlerInterface
{
    public function __construct(private readonly ChangeTypeRegistry $changeTypes)
    {
    }

    public function type(): string
    {
        return 'set_variable';
    }

    public function label(): string
    {
        return 'Записать в переменную процесса';
    }

    public function description(): string
    {
        return 'Сохраняет промежуточное значение в переменную, которая не является записью CRM';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $fields = self::fields($actionConfig);
        $var = array_key_first($fields) !== null ? ExecutionContext::splitPath(array_key_first($fields))[0] : '';
        if (!in_array($var, ExecutionContext::RESERVED, true) && $context->hasVariable($var) && $context->getVariable($var) !== null && !$context->isLocal($var)) {
            throw new NonRecoverableException("set_variable: \"{$var}\" is a CRM record variable; use modify");
        }
        $request = [];
        $result = [];
        foreach ($fields as $target => $config) {
            $changeType = $this->changeTypes->get((string)($config['change_type'] ?? 'set_value'));
            $details = [];
            if ($changeType instanceof ReportsDetails) {
                [$value, $details] = $changeType->computeWithDetails($context, $config);
            } else {
                $value = $changeType->compute($context, $config);
            }
            [, $old] = $context->resolve($target);
            $context->setLocal($target, $value);
            $name = (string)ExecutionContext::splitPath($target)[1];
            $request[$name] = ['change_type' => $changeType->name(), 'value' => $config['value'] ?? null];
            $result[$name] = ['old_value' => $old, 'new_value' => $value] + $details;
        }
        return ActionResult::success(['var' => $var, 'fields' => $request], ['fields' => $result]);
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $hasTarget = array_key_exists('target', $actionConfig);
        $hasFields = array_key_exists('var', $actionConfig) || array_key_exists('fields', $actionConfig);
        if ($hasTarget === $hasFields) {
            $scope->error($path, 'Either "target" or "var" with "fields" is required');
            return;
        }
        if ($hasTarget) {
            $target = $actionConfig['target'];
            if (!is_string($target) || !str_contains($target, '.')) {
                $scope->error("{$path}.target", is_string($target) && $target !== '' ? "\"{$target}\" must point to a field, e.g. {$target}.name" : 'Field path is required');
                return;
            }
            $var = explode('.', $target, 2)[0];
        } else {
            $var = $actionConfig['var'] ?? null;
        }
        if (!is_string($var) || !ExecutionContext::isValidVariableName($var)) {
            $scope->error($hasTarget ? "{$path}.target" : "{$path}.var", sprintf('Invalid variable name "%s"', is_scalar($var) ? (string)$var : ''));
            return;
        }
        if (in_array($var, ExecutionContext::RESERVED, true)) {
            $scope->error($hasTarget ? "{$path}.target" : "{$path}.var", "Name \"{$var}\" is reserved");
            return;
        }
        $kind = $scope->kind($var);
        if ($kind !== null && !in_array($kind, [ExecutionContext::SOURCE_LOCAL, DefinitionScope::CONDITIONAL], true)) {
            $scope->error($hasTarget ? "{$path}.target" : "{$path}.var", "Variable \"{$var}\" is a CRM record variable; use modify");
            return;
        }
        // declared before the values: "totals.sum + item.amount" reads the variable itself (accumulation)
        $scope->declare($var, ExecutionContext::SOURCE_LOCAL);
        if ($hasTarget) {
            $this->validateField($actionConfig, $scope, $path);
        } else {
            $fields = $actionConfig['fields'] ?? null;
            if (!is_array($fields) || $fields === []) {
                $scope->error("{$path}.fields", '"fields" must be a non-empty list or object');
                return;
            }
            $isList = array_is_list($fields);
            if (!$isList && count($fields) > 1 && str_contains((string)json_encode($fields), "{$var}.")) {
                $scope->warning("{$path}.fields", "The order of object keys is not kept: use a list of fields when a field uses another field of \"{$var}\"");
            }
            $named = [];
            foreach ($fields as $key => $config) {
                if ($isList) {
                    if (!is_array($config) || !is_string($config['name'] ?? null)) {
                        $scope->error("{$path}.fields[{$key}]", 'Must be an object {"name": ..., "change_type": ..., "value": ...}');
                        continue;
                    }
                    $named[$config['name']] = ["{$path}.fields[{$key}]", $config];
                } else {
                    $named[(string)$key] = ["{$path}.fields.{$key}", $config];
                }
            }
            foreach ($named as $name => [$fieldPath, $config]) {
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$name)) {
                    $scope->error($fieldPath, "Invalid field name \"{$name}\"");
                    continue;
                }
                if (!is_array($config)) {
                    $scope->error($fieldPath, 'Must be an object {"change_type": ..., "value": ...}');
                    continue;
                }
                $this->validateField($config, $scope, $fieldPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateField(array $config, DefinitionScope $scope, string $path): void
    {
        $changeType = $config['change_type'] ?? null;
        if (!is_string($changeType) || !$this->changeTypes->has($changeType)) {
            $scope->error("{$path}.change_type", 'Change type must be one of: ' . implode(', ', array_column($this->changeTypes->dictionary(), 'value')));
            return;
        }
        $this->changeTypes->get($changeType)->validate($config, $scope, $path);
    }

    /**
     * target => change config (in order).
     *
     * @param array<string, mixed> $config
     * @return array<string, array<string, mixed>>
     */
    private static function fields(array $config): array
    {
        if (isset($config['target'])) {
            return [(string)$config['target'] => $config];
        }
        $var = (string)($config['var'] ?? '');
        $result = [];
        $fields = (array)($config['fields'] ?? []);
        foreach ($fields as $key => $field) {
            $name = array_is_list($fields) ? (string)($field['name'] ?? '') : (string)$key;
            $result["{$var}.{$name}"] = (array)$field;
        }
        return $result;
    }
}
