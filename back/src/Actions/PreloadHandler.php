<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\LoadStrategies\LoadStrategyRegistry;
use Wps\Services\ExternalApiAdapterService;
use Wps\Services\Validation\DefinitionScope;

/**
 * Loads an object from the CRM into a variable (v1, v2, ...).
 *
 * source: object_field   — lookup value from a field of the event object (object.account_id)
 *         variable_field — lookup value from a field of a previously loaded variable (v1.contact_id)
 *         manual         — lookup value typed by the user when configuring the process
 * Not found / empty lookup value → the variable is set to null (customer answer #17).
 */
final class PreloadHandler implements ActionHandlerInterface
{
    public const SOURCES = [
        'object_field' => 'Из поля объекта',
        'variable_field' => 'Из поля переменной',
        'manual' => 'Значение вручную',
    ];

    public function __construct(
        private readonly ExternalApiAdapterService $api,
        private readonly LoadStrategyRegistry $strategies,
    ) {
    }

    public function type(): string
    {
        return 'preload';
    }

    public function label(): string
    {
        return 'Предзагрузка объекта';
    }

    public function description(): string
    {
        return 'Загружает объект из CRM по ID или другому полю в переменную процесса';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $var = (string)($actionConfig['var'] ?? '');
        $module = (string)($actionConfig['module'] ?? '');
        $source = (string)($actionConfig['source'] ?? 'object_field');
        $value = $actionConfig['value'] ?? null;

        if (!ExecutionContext::isValidVariableName($var) || $var === ExecutionContext::OBJECT) {
            throw new ConfigurationException("Preload: invalid variable name \"{$var}\"");
        }
        if ($module === '') {
            throw new ConfigurationException("Preload {$var}: module is required");
        }

        $lookup = match ($source) {
            'object_field', 'variable_field' => $context->get(self::normalizePath((string)$value, $source)),
            'manual' => $value,
            default => throw new ConfigurationException("Preload {$var}: unknown source \"{$source}\""),
        };
        $lookupValue = ComparatorRegistry::stringify($lookup);
        $strategy = $this->strategies->resolve($actionConfig);

        $request = [
            'var' => $var,
            'module' => $module,
            'strategy' => $strategy->name(),
            'by_field' => (string)($actionConfig['by_field'] ?? 'id'),
            'source' => $source,
            'value' => $value,
            'lookup_value' => $lookupValue,
        ];

        if ($lookupValue === '') {
            $context->setVariable($var, null, $module);
            return ActionResult::success($request, ['found' => false, 'reason' => 'Lookup value is empty', 'object' => null]);
        }

        $object = $strategy->load($this->api, $module, $actionConfig, $lookupValue);
        $context->setVariable($var, $object, $module);

        return ActionResult::success($request, ['found' => $object !== null, 'object' => $object]);
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $var = $actionConfig['var'] ?? null;
        $source = $actionConfig['source'] ?? null;
        $value = $actionConfig['value'] ?? null;

        if (!is_string($var) || !ExecutionContext::isValidVariableName($var)) {
            $scope->error("{$path}.var", 'Variable name is required (letters, digits, "_"; e.g. v1)');
        } elseif ($var === ExecutionContext::OBJECT) {
            $scope->error("{$path}.var", 'Name "object" is reserved for the event object');
        } elseif ($scope->isDeclared($var)) {
            $scope->error("{$path}.var", "Variable \"{$var}\" is already defined");
        }

        if (!is_string($source) || !isset(self::SOURCES[$source])) {
            $scope->error("{$path}.source", 'Source must be one of: ' . implode(', ', array_keys(self::SOURCES)));
        }
        $module = $actionConfig['module'] ?? null;
        if (!is_string($module) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $module)) {
            $scope->error("{$path}.module", 'CRM module is required');
        }
        if (isset($actionConfig['by_field']) && (!is_string($actionConfig['by_field']) || trim($actionConfig['by_field']) === '')) {
            $scope->error("{$path}.by_field", 'Search field must be a non-empty string');
        }
        if (isset($actionConfig['strategy']) && !$this->strategies->has((string)$actionConfig['strategy'])) {
            $scope->error("{$path}.strategy", 'Unknown load strategy');
        }

        if ($source === 'manual') {
            if (!is_scalar($value) || trim((string)$value) === '') {
                $scope->error("{$path}.value", 'Value is required');
            }
        } elseif ($source === 'object_field' || $source === 'variable_field') {
            if (!is_string($value) || trim($value) === '') {
                $scope->error("{$path}.value", 'Field path is required');
            } else {
                $normalized = self::normalizePath($value, $source);
                if ($scope->checkReadPath($normalized, "{$path}.value")) {
                    [$refVar] = ExecutionContext::splitPath($normalized);
                    if ($source === 'object_field' && $refVar !== ExecutionContext::OBJECT) {
                        $scope->error("{$path}.value", 'For "from object field" the path must start with "object."');
                    }
                    if ($source === 'variable_field' && $refVar === ExecutionContext::OBJECT) {
                        $scope->error("{$path}.value", 'For "from variable field" the path must reference a variable (e.g. v1.contact_id)');
                    }
                }
            }
        }

        if (is_string($var) && ExecutionContext::isValidVariableName($var) && $var !== ExecutionContext::OBJECT) {
            $scope->declare($var, 'preload');
        }
    }

    /**
     * "account_id" with source object_field is accepted as "object.account_id".
     */
    private static function normalizePath(string $value, string $source): string
    {
        $value = trim($value);
        if ($source === 'object_field' && $value !== '' && !str_contains($value, '.')) {
            return ExecutionContext::OBJECT . '.' . $value;
        }
        return $value;
    }
}
