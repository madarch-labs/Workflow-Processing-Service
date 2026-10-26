<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Services\Validation\DefinitionScope;

/**
 * "Очистить" (md/tasks_list/done/5.1.4-clear.md): an empty string (written reliably by the SuiteCRM plugin for any
 * field type) or null ("empty": "null", for CRMs where the difference matters; the plugin clears the field too).
 */
final class ClearChangeType implements ChangeTypeInterface
{
    public function name(): string
    {
        return 'clear';
    }

    public function label(): string
    {
        return 'Очистить';
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        return ($config['empty'] ?? '') === 'null' ? null : '';
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        if (array_key_exists('empty', $config) && !in_array($config['empty'], ['', 'null'], true)) {
            $scope->error("{$path}.empty", 'Must be "" or "null"');
        }
    }
}
