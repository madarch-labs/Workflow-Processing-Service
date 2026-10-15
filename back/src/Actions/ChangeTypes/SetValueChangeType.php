<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Services\Validation\DefinitionScope;

/**
 * "Равен значению": a string or number typed by the user.
 */
final class SetValueChangeType implements ChangeTypeInterface
{
    public function name(): string
    {
        return 'set_value';
    }

    public function label(): string
    {
        return 'Равен значению';
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        return $config['value'] ?? null;
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $value = $config['value'] ?? null;
        if ($value !== null && !is_scalar($value)) {
            $scope->error("{$path}.value", 'Value must be a string or a number');
        }
    }
}
