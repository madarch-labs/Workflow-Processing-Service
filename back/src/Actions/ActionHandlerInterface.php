<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Context\ExecutionContext;
use Wps\Services\Validation\DefinitionScope;

/**
 * Extension point: a typed action of a process (TS §5.5, §13.2).
 * A new action (send e-mail, call a custom API, ...) = a class implementing this interface
 * + registration in ActionHandlerRegistry::default(). Business logic stays in the UI.
 *
 * Errors: throw RecoverableException (will be retried) or NonRecoverableException (fails the execution).
 */
interface ActionHandlerInterface
{
    /** Action code stored in the definition, e.g. "modify". */
    public function type(): string;

    public function label(): string;

    public function description(): string;

    /**
     * @param array<string, mixed> $actionConfig
     */
    public function execute(ExecutionContext $context, array $actionConfig): ActionResult;

    /**
     * Static validation of the action config when a process is saved.
     * Report problems with $scope->error(); declare created variables with $scope->declare().
     *
     * @param array<string, mixed> $actionConfig
     */
    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void;
}
