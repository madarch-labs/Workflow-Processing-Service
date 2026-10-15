<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Actions\ChangeTypes\ChangeTypeRegistry;
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
        return 'Изменяет поле объекта или переменной: значение, копия поля другой переменной или расчёт';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $target = (string)($actionConfig['target'] ?? '');
        $changeType = $this->changeTypes->get((string)($actionConfig['change_type'] ?? 'set_value'));

        $newValue = $changeType->compute($context, $actionConfig);
        [, $oldValue] = $context->resolve($target);
        $context->set($target, $newValue);

        $request = [
            'target' => $target,
            'change_type' => $changeType->name(),
            'value' => $actionConfig['expression'] ?? $actionConfig['value'] ?? null,
        ];
        $result = ['old_value' => $oldValue, 'new_value' => $newValue];

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
        $scope->checkWritePath($actionConfig['target'] ?? null, "{$path}.target");
        if (isset($actionConfig['save_to_crm']) && !is_bool($actionConfig['save_to_crm'])) {
            $scope->error("{$path}.save_to_crm", 'Must be true or false');
        }
    }
}
