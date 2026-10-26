<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Services\CrmObjectSaver;
use Wps\Services\Validation\DefinitionScope;

/**
 * Saves a variable (or the event object) to the CRM — a separate typed action (customer answer #18).
 */
final class SaveHandler implements ActionHandlerInterface
{
    public function __construct(private readonly CrmObjectSaver $saver)
    {
    }

    public function type(): string
    {
        return 'save_object';
    }

    public function label(): string
    {
        return 'Сохранить объект в CRM';
    }

    public function description(): string
    {
        return 'Отправляет изменённые поля переменной в CRM (PUT; для нового объекта — POST)';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $target = trim((string)($actionConfig['target'] ?? ''));
        if ($target === '') {
            throw new ConfigurationException('save_object: target is required');
        }
        [$var] = ExecutionContext::splitPath($target);
        $module = isset($actionConfig['module']) && is_string($actionConfig['module']) && $actionConfig['module'] !== ''
            ? $actionConfig['module']
            : null;
        return $this->saver->save($context, $var, $module, ($actionConfig['mode'] ?? 'changed') === 'full');
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $target = $actionConfig['target'] ?? null;
        if (is_string($target) && $scope->kind(explode('.', $target)[0]) === ExecutionContext::SOURCE_LIST) {
            $scope->error("{$path}.target", 'Variable "' . explode('.', $target)[0] . '" is a record list; use foreach to change its records');
        }
        if (is_string($target) && $scope->kind($target) === ExecutionContext::SOURCE_LOCAL) {
            $scope->error("{$path}.target", "Variable \"{$target}\" is local (set_variable) and cannot be saved to the CRM");
        }
        if ($target === ExecutionContext::OBJECT && $scope->onlyOnDelete()) {
            $scope->error($path, 'The process runs only on delete: the deleted record cannot be saved');
        }
        if (is_string($target) && (ExecutionContext::isReadOnly(explode('.', $target)[0]) || $scope->kind(explode('.', $target)[0]) === ExecutionContext::SOURCE_LOOP_INFO)) {
            $scope->error("{$path}.target", "Variable \"{$target}\" is read-only");
        } else {
            $scope->checkReadPath($target, "{$path}.target", allowWholeVariable: true);
        }
        if (isset($actionConfig['module']) && $actionConfig['module'] !== ''
            && (!is_string($actionConfig['module']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $actionConfig['module']))) {
            $scope->error("{$path}.module", 'Invalid CRM module');
        }
        if (isset($actionConfig['mode']) && !in_array($actionConfig['mode'], ['changed', 'full'], true)) {
            $scope->error("{$path}.mode", 'Mode must be "changed" or "full"');
        }
    }
}
