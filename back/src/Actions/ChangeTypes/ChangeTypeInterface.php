<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Services\Validation\DefinitionScope;

/**
 * Extension point: how the new value of a "modify" action is computed.
 */
interface ChangeTypeInterface
{
    public function name(): string;

    public function label(): string;

    /**
     * @param array<string, mixed> $config the modify action
     */
    public function compute(ExecutionContext $context, array $config): mixed;

    /**
     * @param array<string, mixed> $config
     */
    public function validate(array $config, DefinitionScope $scope, string $path): void;
}
