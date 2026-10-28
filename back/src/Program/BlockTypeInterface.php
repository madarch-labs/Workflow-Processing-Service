<?php

declare(strict_types=1);

namespace Wps\Program;

use Wps\Services\Validation\DefinitionScope;

/**
 * Extension point: a control-flow block in a process definition ("condition", "foreach", "stop_process").
 * A block is compiled into instructions of the flat program; its nested action lists are compiled through
 * ProgramEmitter::compileList(). A new block = a class implementing this interface + registration in
 * Container::blocks() (doc/{ru,en}/07-developer-guide/extending/new-block-type.md).
 */
interface BlockTypeInterface
{
    /** Code in the definition, e.g. "condition". */
    public function type(): string;

    public function label(): string;

    public function description(): string;

    /**
     * @param array<string, mixed> $config the block element of the definition
     */
    public function compile(array $config, string $path, ProgramEmitter $emitter): void;

    /**
     * Static validation when the process is saved. Nested lists and conditions are validated through $context;
     * branches use $scope->fork() and $scope->mergeBranches() for variable visibility.
     *
     * @param array<string, mixed> $config
     */
    public function validate(array $config, DefinitionScope $scope, string $path, BlockValidationContext $context): void;
}
