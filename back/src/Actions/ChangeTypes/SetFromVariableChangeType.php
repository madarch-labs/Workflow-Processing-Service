<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Services\Validation\DefinitionScope;

/**
 * "Равен переменной из поля": copies the value of another field (v2.sale, object.phone).
 * A missing source field results in null.
 */
final class SetFromVariableChangeType implements ChangeTypeInterface
{
    public function name(): string
    {
        return 'set_from_variable';
    }

    public function label(): string
    {
        return 'Равен переменной из поля';
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        return $context->get((string)($config['value'] ?? ''));
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $scope->checkReadPath($config['value'] ?? null, "{$path}.value");
    }
}
