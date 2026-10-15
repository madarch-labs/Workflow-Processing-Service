<?php

declare(strict_types=1);

namespace Wps\Services\Validation;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;

/**
 * Validation state while checking one process definition: collected errors, variables known
 * at the current position (in execution order) and referenced child processes.
 */
final class DefinitionScope
{
    /** @var list<array{field: string, message: string}> */
    private array $errors = [];

    /** @var array<string, string> variable => kind (event|preload|created) */
    private array $variables = [ExecutionContext::OBJECT => 'event'];

    /** @var array<int, string> child process id => field path (where it is referenced) */
    private array $childReferences = [];

    /**
     * @param bool $inheritsVariables child processes receive all variables of the parent,
     *                                so unknown variable names are allowed there
     */
    public function __construct(
        public readonly ?int $processId = null,
        public readonly bool $inheritsVariables = false,
    ) {
    }

    public function error(string $field, string $message): void
    {
        $this->errors[] = ['field' => $field, 'message' => $message];
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function declare(string $variable, string $kind): void
    {
        $this->variables[$variable] ??= $kind;
    }

    public function isDeclared(string $variable): bool
    {
        return isset($this->variables[$variable]);
    }

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    public function addChildReference(int $processId, string $field): void
    {
        $this->childReferences[$processId] ??= $field;
    }

    /**
     * @return array<int, string>
     */
    public function childReferences(): array
    {
        return $this->childReferences;
    }

    /**
     * A path that is read: syntax + the variable must already be known at this point.
     */
    public function checkReadPath(mixed $path, string $field, bool $allowWholeVariable = false): bool
    {
        $parts = $this->parsePath($path, $field);
        if ($parts === null) {
            return false;
        }
        [$var, $name] = $parts;
        if ($name === null && !$allowWholeVariable) {
            $this->error($field, "\"{$path}\" must point to a field, e.g. {$var}.name");
            return false;
        }
        if (!$this->isDeclared($var) && !$this->inheritsVariables) {
            $this->error($field, "Variable \"{$var}\" is not defined at this point");
            return false;
        }
        return true;
    }

    /**
     * A path that is written: the variable may be new (it is created by the action).
     */
    public function checkWritePath(mixed $path, string $field): bool
    {
        $parts = $this->parsePath($path, $field);
        if ($parts === null) {
            return false;
        }
        [$var, $name] = $parts;
        if ($name === null) {
            $this->error($field, "\"{$path}\" must point to a field, e.g. {$var}.name");
            return false;
        }
        $this->declare($var, 'created');
        return true;
    }

    /**
     * @return array{0: string, 1: string|null}|null
     */
    private function parsePath(mixed $path, string $field): ?array
    {
        if (!is_string($path) || trim($path) === '') {
            $this->error($field, 'Field path is required');
            return null;
        }
        try {
            [$var, $name] = ExecutionContext::splitPath($path);
        } catch (ConfigurationException $e) {
            $this->error($field, $e->getMessage());
            return null;
        }
        if (!ExecutionContext::isValidVariableName($var)) {
            $this->error($field, "Invalid variable name \"{$var}\"");
            return null;
        }
        return [$var, $name];
    }
}
