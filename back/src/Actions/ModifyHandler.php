<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Actions\ChangeTypes\ChangeTypeRegistry;
use Wps\Actions\ChangeTypes\ReportsDetails;
use Wps\Context\ExecutionContext;
use Wps\Services\CrmObjectSaver;
use Wps\Services\Validation\DefinitionScope;

/**
 * Changes a field of the object or of a variable in the process memory.
 * The CRM is not touched unless save_to_crm is true (customer answer #18).
 */
final class ModifyHandler implements ActionHandlerInterface
{
    public function __construct(
        private readonly ChangeTypeRegistry $changeTypes,
        private readonly CrmObjectSaver $saver,
    ) {
    }

    public function type(): string
    {
        return 'modify';
    }

    public function label(): string
    {
        return 'Изменить объект/переменную';
    }

    public function description(): string
    {
        return 'Изменяет поле объекта или переменной: значение, копия поля другой переменной, расчёт или шаблон';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $target = (string)($actionConfig['target'] ?? '');
        $changeType = $this->changeTypes->get((string)($actionConfig['change_type'] ?? 'set_value'));

        $details = [];
        if ($changeType instanceof ReportsDetails) {
            [$newValue, $details] = $changeType->computeWithDetails($context, $actionConfig);
        } else {
            $newValue = $changeType->compute($context, $actionConfig);
        }
        [, $oldValue] = $context->resolve($target);
        $context->set($target, $newValue);

        $request = [
            'target' => $target,
            'change_type' => $changeType->name(),
            'value' => $actionConfig['expression'] ?? $actionConfig['value'] ?? null,
        ];
        $result = ['old_value' => $oldValue, 'new_value' => $newValue] + $details;

        if (!empty($actionConfig['save_to_crm'])) {
            [$var] = ExecutionContext::splitPath($target);
            $module = isset($actionConfig['module']) && is_string($actionConfig['module']) ? $actionConfig['module'] : null;
            $save = $this->saver->save($context, $var, $module);
            $request['save'] = $save->request;
            $result['save'] = ['status' => $save->status] + $save->result;
        }

        return ActionResult::success($request, $result);
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $changeType = $actionConfig['change_type'] ?? null;
        if (!is_string($changeType) || !$this->changeTypes->has($changeType)) {
            $scope->error("{$path}.change_type", 'Change type must be one of: ' . implode(', ', array_column($this->changeTypes->dictionary(), 'value')));
        } else {
            // the value is computed before the target is written, so validate it first
            $this->changeTypes->get($changeType)->validate($actionConfig, $scope, $path);
        }
        $target = $actionConfig['target'] ?? null;
        if (is_string($target) && $scope->kind(explode('.', $target)[0]) === ExecutionContext::SOURCE_LIST) {
            $scope->error("{$path}.target", 'Variable "' . explode('.', $target)[0] . '" is a record list; use foreach to change its records');
        }
        $scope->checkWritePath($target, "{$path}.target");
        if (!empty($actionConfig['save_to_crm']) && is_string($target) && $scope->kind(explode('.', $target)[0]) === ExecutionContext::SOURCE_LOCAL) {
            $scope->error("{$path}.save_to_crm", 'Variable "' . explode('.', $target)[0] . '" is local (set_variable) and cannot be saved to the CRM');
        }
        if (!empty($actionConfig['save_to_crm']) && is_string($target) && str_starts_with($target, ExecutionContext::OBJECT . '.') && $scope->onlyOnDelete()) {
            $scope->error($path, 'The process runs only on delete: the deleted record cannot be saved');
        }
        if (isset($actionConfig['save_to_crm']) && !is_bool($actionConfig['save_to_crm'])) {
            $scope->error("{$path}.save_to_crm", 'Must be true or false');
        }
    }
}
