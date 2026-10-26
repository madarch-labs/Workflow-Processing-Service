<?php

declare(strict_types=1);

namespace Wps\Program;

use Wps\Services\Validation\DefinitionScope;

/**
 * What a block may ask the process validator to check: a nested action list and a condition list.
 */
final class BlockValidationContext
{
    /**
     * @param \Closure(array<mixed>, DefinitionScope, string): void $validateList
     * @param \Closure(array<mixed>, DefinitionScope, string): void $validateConditions
     */
    public function __construct(
        private readonly \Closure $validateList,
        private readonly \Closure $validateConditions,
    ) {
    }

    /**
     * @param array<mixed> $actions
     */
    public function validateList(array $actions, DefinitionScope $scope, string $path): void
    {
        ($this->validateList)($actions, $scope, $path);
    }

    /**
     * @param array<mixed> $conditions
     */
    public function validateConditions(array $conditions, DefinitionScope $scope, string $path): void
    {
        ($this->validateConditions)($conditions, $scope, $path);
    }
}
