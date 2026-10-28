<?php

declare(strict_types=1);

namespace Wps\Services\Validation;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Persistence\Process;

/**
 * Validation state while checking one process definition: collected errors and warnings, variables known
 * at the current position (in execution order), step ids and referenced child processes.
 *
 * Branches of a block are validated in forks (fork()): a fork has its own copy of the variables, while errors,
 * warnings, step ids and child references are shared. After the branches, mergeBranches() decides what is
 * known after the block (md/tasks_list/done/0.1-program-compiler.md §4.8): a variable set in every branch of an
 * exhaustive block keeps its kind, a variable set only in some branches becomes "conditional" — reading it is
 * allowed but produces a warning.
 */
final class DefinitionScope
{
    public const CONDITIONAL = 'conditional';

    /** @var array<string, string> variable => kind (event|preload|created|conditional|...) */
    private array $variables = [
        ExecutionContext::OBJECT => 'event',
        ExecutionContext::OBJECT_BEFORE => 'event_before',
        ExecutionContext::SYS => 'system',
        ExecutionContext::CONFIG => 'system',
    ];

    private ValidationLog $log;

    /**
     * @param bool $inheritsVariables child processes receive all variables of the parent,
     *                                so unknown variable names are allowed there
     * @param list<string> $constants names of definition.constants (5.2.9): allowed config.* keys
     */
    public function __construct(
        public readonly ?int $processId = null,
        public readonly bool $inheritsVariables = false,
        ?ValidationLog $log = null,
        /** @var list<string> event types of the process (6.2): saving "object" is pointless when it only runs on delete */
        public readonly array $triggerOn = Process::DEFAULT_TRIGGER_ON,
        private readonly array $constants = [],
    ) {
        $this->log = $log ?? new ValidationLog();
    }

    /**
     * The process runs only on delete (and is not a child): the event record is already deleted in the CRM.
     */
    public function onlyOnDelete(): bool
    {
        return !$this->inheritsVariables && $this->triggerOn === ['delete'];
    }

    public function error(string $field, string $message): void
    {
        $this->log->errors[] = ['field' => $field, 'message' => $message];
    }

    public function warning(string $field, string $message): void
    {
        foreach ($this->log->warnings as $warning) {
            if ($warning['field'] === $field && $warning['message'] === $message) {
                return;
            }
        }
        $this->log->warnings[] = ['field' => $field, 'message' => $message];
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    public function errors(): array
    {
        return $this->log->errors;
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    public function warnings(): array
    {
        return $this->log->warnings;
    }

    public function hasErrors(): bool
    {
        return $this->log->errors !== [];
    }

    public function declare(string $variable, string $kind): void
    {
        if (in_array($variable, ExecutionContext::RESERVED, true)) {
            return;
        }
        if (!isset($this->variables[$variable]) || $this->variables[$variable] === self::CONDITIONAL) {
            $this->variables[$variable] = $kind;
        }
    }

    /**
     * Forgets a variable (the loop variables after the loop body).
     */
    public function undeclare(string $variable): void
    {
        unset($this->variables[$variable]);
    }

    /**
     * Kind of a declared variable (event, preload, created, local, http, conditional …), null when not declared.
     */
    public function kind(string $variable): ?string
    {
        return $this->variables[$variable] ?? null;
    }

    public function isDeclared(string $variable): bool
    {
        return isset($this->variables[$variable]);
    }

    /**
     * Declared on every path to this point (not only in some branches).
     */
    public function isDefinitelyDeclared(string $variable): bool
    {
        return isset($this->variables[$variable]) && $this->variables[$variable] !== self::CONDITIONAL;
    }

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * A copy for one branch of a block: own variables, shared errors / warnings / step ids / child references.
     */
    public function fork(): self
    {
        $fork = new self($this->processId, $this->inheritsVariables, $this->log, $this->triggerOn, $this->constants);
        $fork->variables = $this->variables;
        return $fork;
    }

    /**
     * Variables after a block with the given branches. $exhaustive: one of the branches always runs
     * (a condition with "else"); otherwise "no branch ran" is a path too.
     *
     * @param list<self> $branches
     */
    public function mergeBranches(array $branches, bool $exhaustive): void
    {
        $new = [];
        foreach ($branches as $branch) {
            foreach ($branch->variables as $name => $kind) {
                if (!isset($this->variables[$name]) || $this->variables[$name] === self::CONDITIONAL) {
                    $new[$name] ??= $kind;
                }
            }
        }
        foreach ($new as $name => $kind) {
            $everywhere = $exhaustive && $branches !== [];
            foreach ($branches as $branch) {
                if (!$branch->isDefinitelyDeclared($name)) {
                    $everywhere = false;
                    break;
                }
            }
            $this->variables[$name] = $everywhere ? $kind : self::CONDITIONAL;
        }
    }

    /**
     * A user id of a step ("id"): unique in the whole process, nested blocks included.
     */
    public function registerStepId(string $id, string $field): bool
    {
        if (isset($this->log->stepIds[$id])) {
            $this->error($field, "Step id \"{$id}\" is already used at {$this->log->stepIds[$id]}");
            return false;
        }
        $this->log->stepIds[$id] = preg_replace('/\.id$/', '', $field) ?? $field;
        return true;
    }

    /**
     * @return array<string, string> step id => path of the step
     */
    public function stepIds(): array
    {
        return $this->log->stepIds;
    }

    public function addChildReference(int $processId, string $field): void
    {
        $this->log->childReferences[$processId] ??= $field;
    }

    /**
     * @return array<int, string>
     */
    public function childReferences(): array
    {
        return $this->log->childReferences;
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
        if ($var === ExecutionContext::SYS) {
            if ($name === null) {
                return true;
            }
            $sysField = explode('.', $name)[0];
            if (!in_array($sysField, ExecutionContext::SYS_FIELDS, true)) {
                $available = implode(', ', array_map(static fn (string $f) => ExecutionContext::SYS . ".{$f}", ExecutionContext::SYS_FIELDS));
                $this->error($field, "Unknown system variable \"{$var}.{$sysField}\"; available: {$available}");
                return false;
            }
            return true;
        }
        if ($var === ExecutionContext::CONFIG) {
            if ($name === null) {
                return true;
            }
            $constant = explode('.', $name)[0];
            if (!in_array($constant, $this->constants, true)) {
                $this->error($field, "Constant \"{$constant}\" is not defined in definition.constants");
                return false;
            }
            return true;
        }
        if (!$this->isDeclared($var) && !$this->inheritsVariables) {
            $this->error($field, "Variable \"{$var}\" is not defined at this point");
            return false;
        }
        if (($this->variables[$var] ?? null) === self::CONDITIONAL) {
            $this->warning($field, "Variable \"{$var}\" may be undefined here: it is set only in some branches");
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
        if (ExecutionContext::isReadOnly($var) || ($this->variables[$var] ?? null) === ExecutionContext::SOURCE_LOOP_INFO) {
            $this->error($field, "Variable \"{$var}\" is read-only");
            return false;
        }
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
