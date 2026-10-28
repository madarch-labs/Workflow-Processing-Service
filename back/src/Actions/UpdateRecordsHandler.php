<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Actions\ChangeTypes\ChangeTypeRegistry;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Services\Errors\ErrorClassifier;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\MetricsService;
use Wps\Services\Validation\DefinitionScope;
use Wps\Types\TypeCaster;

/**
 * "Изменить записи списка" (md/tasks_list/4.6-update-related.md): the same fields of every record of a find_records
 * list in one step and one audit record — AOW "Modify Record" of related records. Values are computed per record by
 * the change types of modify ("item" = the current record); only_changed sends a field only when it differs, a record
 * without changes is not sent. A PUT of the same values is idempotent, so a repeated step is safe.
 *
 * Errors of one record (4xx, not found) are collected (on_item_error = continue) and fail the step at the end;
 * recoverable errors (5xx, network) repeat the whole step through the usual retries.
 */
final class UpdateRecordsHandler implements ActionHandlerInterface
{
    public const MAX_RULES = 20;
    private const AUDIT_ITEMS = 200;
    private const FIELD = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(
        private readonly ChangeTypeRegistry $changeTypes,
        private readonly ExternalApiAdapterService $crm,
        private readonly AppConfig $config,
        private readonly ?MetricsService $metrics = null,
    ) {
    }

    public function type(): string
    {
        return 'update_records';
    }

    public function label(): string
    {
        return 'Изменить записи списка';
    }

    public function description(): string
    {
        return 'Меняет поля у всех записей списка, найденного шагом «Найти записи», одним шагом';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $listVar = (string)$actionConfig['list'];
        if (!$context->isList($listVar)) {
            throw new NonRecoverableException("update_records: variable \"{$listVar}\" is not a record list (use find_records)");
        }
        $list = (array)$context->getVariable($listVar);
        $items = array_values((array)($list['items'] ?? []));
        $module = isset($actionConfig['module']) && is_string($actionConfig['module']) && $actionConfig['module'] !== ''
            ? $actionConfig['module'] : $context->getModule($listVar);
        $itemVar = (string)($actionConfig['item_var'] ?? 'item');
        $onlyChanged = (bool)($actionConfig['only_changed'] ?? true);
        $stopOnError = ($actionConfig['on_item_error'] ?? 'continue') === 'stop';
        $max = max(1, min($this->config->updateRecordsMax, (int)($actionConfig['max'] ?? $this->config->updateRecordsMax)));
        $rules = array_values((array)$actionConfig['set']);
        $request = ['list' => $listVar, 'module' => $module, 'count' => count($items), 'set' => $rules, 'only_changed' => $onlyChanged, 'max' => $max];
        if ($items === []) {
            return ActionResult::skipped("List \"{$listVar}\" is empty — nothing to update", $request);
        }
        if (count($items) > $max) {
            throw new NonRecoverableException(sprintf('update_records: list has %d records, max is %d', count($items), $max));
        }
        if ($module === null || $module === '') {
            throw new NonRecoverableException("update_records: CRM module of \"{$listVar}\" is unknown (set \"module\")");
        }

        $totals = ['updated' => 0, 'unchanged' => 0, 'skipped_no_id' => 0, 'errors' => 0];
        $report = [];
        foreach ($items as $k => $item) {
            $item = is_array($item) ? $item : [];
            $outcome = $this->updateItem($context, $module, $item, $rules, $itemVar, $onlyChanged);
            $totals[$outcome['status'] === 'error' ? 'errors' : $outcome['status']]++;
            if (count($report) < self::AUDIT_ITEMS) {
                $report[] = array_diff_key($outcome, ['values' => true]);
            }
            if ($outcome['status'] === 'updated') {
                $items[$k] = $outcome['values'] + $item;
            }
            if ($outcome['status'] === 'error' && $stopOnError) {
                break;
            }
        }
        // later steps see the new values; the list itself is never saved
        $list['items'] = $items;
        $context->setVariable($listVar, $list, $module, ExecutionContext::SOURCE_LIST);
        $this->metrics?->inc(MetricsService::UPDATE_RECORDS_ITEMS, (float)$totals['updated']);
        $result = $totals + ['items' => $report];
        if ($totals['errors'] > 0) {
            throw new NonRecoverableException(sprintf('update_records: %d of %d records failed', $totals['errors'], count($items)), $result + ['request' => $request]);
        }
        return ActionResult::success($request, $result);
    }

    /**
     * @param array<string, mixed> $item
     * @param list<array<string, mixed>> $rules
     * @return array{status: string, id: string|null, fields?: list<string>, error?: string, values?: array<string, mixed>}
     */
    private function updateItem(ExecutionContext $context, string $module, array $item, array $rules, string $itemVar, bool $onlyChanged): array
    {
        $id = isset($item['id']) && $item['id'] !== '' ? (string)$item['id'] : null;
        if ($id === null) {
            return ['status' => 'skipped_no_id', 'id' => null];
        }
        try {
            $values = $context->withTemporaryVariable($itemVar, $item, $module, function () use ($context, $rules, $itemVar): array {
                $values = [];
                foreach ($rules as $rule) {
                    $field = (string)$rule['field'];
                    $values[$field] = $this->changeTypes->get((string)($rule['change_type'] ?? 'set_value'))
                        ->compute($context, ['target' => "{$itemVar}.{$field}"] + $rule);
                }
                return $values;
            });
        } catch (\Throwable $e) {
            return ['status' => 'error', 'id' => $id, 'error' => $e->getMessage()];
        }
        $attributes = $onlyChanged
            ? array_filter($values, static fn ($v, $f) => TypeCaster::stringify($v) !== TypeCaster::stringify($item[$f] ?? null), ARRAY_FILTER_USE_BOTH)
            : $values;
        if ($attributes === []) {
            return ['status' => 'unchanged', 'id' => $id];
        }
        try {
            $saved = $this->crm->update($module, $id, $attributes);
        } catch (\Throwable $e) {
            if (ErrorClassifier::classify($e)->transient) {
                throw $e; // the whole step is retried
            }
            return ['status' => 'error', 'id' => $id, 'fields' => array_keys($attributes), 'error' => $e->getMessage()];
        }
        return ['status' => 'updated', 'id' => $id, 'fields' => array_keys($attributes), 'values' => array_intersect_key($saved, $attributes) + $attributes];
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $list = $actionConfig['list'] ?? null;
        if (!is_string($list) || !ExecutionContext::isValidVariableName($list)) {
            $scope->error("{$path}.list", 'Record list variable is required (e.g. contacts)');
        } elseif (!$scope->isDeclared($list) && !$scope->inheritsVariables) {
            $scope->error("{$path}.list", "Variable \"{$list}\" is not defined at this point");
        } elseif ($scope->isDeclared($list) && $scope->kind($list) !== ExecutionContext::SOURCE_LIST) {
            $scope->error("{$path}.list", "Variable \"{$list}\" is not a record list (use find_records)");
        }
        $itemVar = $actionConfig['item_var'] ?? 'item';
        $itemValid = is_string($itemVar) && ExecutionContext::isValidVariableName($itemVar);
        if (!$itemValid) {
            $scope->error("{$path}.item_var", 'Variable name is required (letters, digits, "_"; e.g. item)');
        } elseif (in_array($itemVar, ExecutionContext::RESERVED, true) || $scope->isDeclared($itemVar)) {
            $scope->error("{$path}.item_var", "Variable \"{$itemVar}\" already exists; set \"item_var\"");
            $itemValid = false;
        }
        $rules = $actionConfig['set'] ?? null;
        if (!is_array($rules) || !array_is_list($rules) || $rules === [] || count($rules) > self::MAX_RULES) {
            $scope->error("{$path}.set", sprintf('1..%d field rules are required: [{"field": ..., "change_type": ..., "value": ...}]', self::MAX_RULES));
            $rules = [];
        }
        $declared = $itemValid && !$scope->isDeclared((string)$itemVar);
        if ($declared) {
            $scope->declare((string)$itemVar, ExecutionContext::SOURCE_LOOP);
        }
        $fields = [];
        foreach ($rules as $k => $rule) {
            $rulePath = "{$path}.set[{$k}]";
            if (!is_array($rule)) {
                $scope->error($rulePath, 'Rule must be an object');
                continue;
            }
            $field = $rule['field'] ?? null;
            if (!is_string($field) || !preg_match(self::FIELD, $field)) {
                $scope->error("{$rulePath}.field", 'Field name is required');
            } elseif ($field === 'id') {
                $scope->error("{$rulePath}.field", 'Field "id" cannot be changed');
            } elseif (isset($fields[$field])) {
                $scope->error("{$rulePath}.field", "Field \"{$field}\" is set twice");
            } else {
                $fields[$field] = true;
            }
            $changeType = $rule['change_type'] ?? 'set_value';
            if (!is_string($changeType) || !$this->changeTypes->has($changeType)) {
                $scope->error("{$rulePath}.change_type", 'Change type must be one of: ' . implode(', ', array_column($this->changeTypes->dictionary(), 'value')));
            } else {
                $this->changeTypes->get($changeType)->validate($rule + ['target' => "{$itemVar}.{$field}"], $scope, $rulePath);
            }
        }
        if ($declared) {
            $scope->undeclare((string)$itemVar);
        }
        if (isset($actionConfig['module']) && (!is_string($actionConfig['module']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $actionConfig['module']))) {
            $scope->error("{$path}.module", 'Invalid CRM module');
        }
        if (array_key_exists('max', $actionConfig) && (!is_int($actionConfig['max']) || $actionConfig['max'] < 1 || $actionConfig['max'] > $this->config->updateRecordsMax)) {
            $scope->error("{$path}.max", "Max must be between 1 and {$this->config->updateRecordsMax}");
        }
        if (array_key_exists('on_item_error', $actionConfig) && !in_array($actionConfig['on_item_error'], ['continue', 'stop'], true)) {
            $scope->error("{$path}.on_item_error", 'Must be one of: continue, stop');
        }
        if (array_key_exists('only_changed', $actionConfig) && !is_bool($actionConfig['only_changed'])) {
            $scope->error("{$path}.only_changed", 'Must be true or false');
        }
    }
}
