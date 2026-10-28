<?php

declare(strict_types=1);

namespace Wps\Context;

use Wps\Events\EventEnvelope;
use Wps\Exceptions\ConfigurationException;
use Wps\Secrets\SecretMasker;
use Wps\Time\Clock;
use Wps\Time\SystemClock;

/**
 * Runtime state of one process execution: the source event, variables and their metadata.
 *
 * Variables are addressed by paths: "object.status", "v1.phone", or just "v1" for the whole variable.
 * "object" is the object from the event. A variable value is an associative array or null
 * (null = "loaded, but not found in the CRM").
 */
final class ExecutionContext
{
    public const OBJECT = 'object';
    /** old values of the changed fields of the event, null when unknown (6.1); read-only */
    public const OBJECT_BEFORE = 'object_before';
    /** virtual system variable computed by the engine (5.2.9); read-only */
    public const SYS = 'sys';
    /** virtual process constants from definition.constants (5.2.9); read-only */
    public const CONFIG = 'config';
    /** names that cannot be declared as process variables (5.2.9) */
    public const RESERVED = [self::OBJECT, self::OBJECT_BEFORE, self::SYS, self::CONFIG];
    /** reserved names that cannot be written at all ("object" may be modified and saved) */
    public const READ_ONLY = [self::OBJECT_BEFORE, self::SYS, self::CONFIG];
    /** fields of sys.* (5.2.9 §4.1), including the handled-error fields of 7.1 */
    public const SYS_FIELDS = [
        'execution_id', 'parent_execution_id', 'process_id', 'process_name', 'process_version',
        'event_id', 'trigger', 'depth', 'module', 'record_id', 'event_type', 'changed_fields',
        'user_id', 'now', 'started_at', 'last_error', 'errors_count',
    ];
    /** a process variable that is not a CRM record (set_variable, 4.13): never dirty, cannot be saved */
    public const SOURCE_LOCAL = 'local';
    /** a record list of find_records (4.10): {items, count, has_more, ids}; cannot be modified or saved */
    public const SOURCE_LIST = 'list';
    /** the current item of a foreach loop (4.11): a copy, changes are saved with save_object */
    public const SOURCE_LOOP = 'loop';
    /** data of the current iteration ({index, number, count, first, last}); read-only */
    public const SOURCE_LOOP_INFO = 'loop_info';

    /** Runtime info about the step being executed (not persisted, set by the Execution Engine). */
    public ?string $executionId = null;
    public ?int $processId = null;
    /** sys.* runtime values of the calling execution (5.2.9); not persisted */
    public string $trigger = '';
    public string $parentExecutionId = '';
    public ?string $processName = null;
    public ?int $processVersion = null;
    public string $startedAt = '';
    /** audit sequence number of the current step (seq); used e.g. in the idempotency key of child processes */
    public int $stepIndex = 0;
    /** pc of the current instruction and the loop iteration ("2/0" — indexes of nested loops, "" outside loops) */
    public int $pc = 0;
    public string $iteration = '';
    /** masker of the current step: secret values resolved by the step are removed from its audit record */
    public ?SecretMasker $secretMasker = null;
    /** one attempt of a step (all its in-step retries): tells a retry from the trace of a crashed worker (0.4) */
    public ?string $attemptToken = null;
    /** test run: external calls are not made (md/tasks_list/7.6-dry-run.md) */
    public bool $dryRun = false;

    /** @var array<string, mixed> process constants, exposed as config.* (5.2.9) */
    private array $constants = [];
    private Clock $clock;

    /**
     * @param array<string, mixed> $event
     * @param array<string, array<string, mixed>|null> $variables
     * @param array<string, array{module?: string|null, source?: string}> $meta
     * @param array<string, array<string, true>> $dirty changed fields per variable (not yet saved to the CRM)
     */
    public function __construct(
        public readonly string $eventId,
        public readonly array $event,
        private array $variables,
        private array $meta = [],
        private array $dirty = [],
        public int $depth = 0,
        public readonly EventInfo $eventInfo = new EventInfo(),
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Context of a normalized event: "object" without service keys, "object_before" and the event info.
     */
    public static function fromEnvelope(string $eventId, EventEnvelope $envelope): self
    {
        return new self(
            eventId: $eventId,
            event: $envelope->object,
            variables: [self::OBJECT => $envelope->object, self::OBJECT_BEFORE => $envelope->before],
            meta: [
                self::OBJECT => ['module' => $envelope->module, 'source' => 'event'],
                self::OBJECT_BEFORE => ['module' => $envelope->module, 'source' => 'event_before'],
            ],
            eventInfo: $envelope->eventInfo(),
        );
    }

    /**
     * A step error handled by on_error (continue / goto): sys.last_error and sys.errors_count (7.1).
     *
     * @param array<string, mixed> $error
     */
    public function recordHandledError(array $error): void
    {
        $sys = is_array($this->variables[self::SYS] ?? null) ? $this->variables[self::SYS] : [];
        $sys['last_error'] = $error;
        $sys['errors_count'] = (int)($sys['errors_count'] ?? 0) + 1;
        $this->variables[self::SYS] = $sys;
        $this->meta[self::SYS] = ['module' => null, 'source' => 'system'];
    }

    public function handledErrors(): int
    {
        return (int)($this->variables[self::SYS]['errors_count'] ?? 0);
    }

    /**
     * Values of the virtual sys.* variable (5.2.9 §4.1). sys.now is read at the moment of the call;
     * sys.last_error / sys.errors_count come from the handled-error state of 7.1.
     *
     * @return array<string, mixed>
     */
    public function systemValues(): array
    {
        $stored = is_array($this->variables[self::SYS] ?? null) ? $this->variables[self::SYS] : [];
        $rawUserId = $this->event['user_id'] ?? $this->event['wps_user_id'] ?? '';
        $rawRecordId = $this->event['id'] ?? '';
        $values = [
            'execution_id' => $this->executionId ?? '',
            'parent_execution_id' => $this->parentExecutionId,
            'process_id' => $this->processId ?? 0,
            'process_name' => $this->processName ?? '',
            'process_version' => $this->processVersion ?? 0,
            'event_id' => $this->eventId,
            'trigger' => $this->trigger,
            'depth' => $this->depth,
            'module' => $this->getModule(self::OBJECT) ?? '',
            'record_id' => is_scalar($rawRecordId) ? (string)$rawRecordId : '',
            'event_type' => $this->eventInfo->eventType,
            'changed_fields' => $this->eventInfo->changedFields ?? [],
            'user_id' => is_scalar($rawUserId) ? (string)$rawUserId : '',
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'started_at' => $this->startedAt,
            'errors_count' => $this->handledErrors(),
        ];
        if (array_key_exists('last_error', $stored)) {
            $values['last_error'] = $stored['last_error'];
        }
        return $values;
    }

    /**
     * @param array<string, mixed> $constants
     */
    public function setConstants(array $constants): void
    {
        $this->constants = $constants;
    }

    public function setClock(Clock $clock): void
    {
        $this->clock = $clock;
    }

    public static function isReadOnly(string $variable): bool
    {
        return in_array($variable, self::READ_ONLY, true);
    }

    private static function assertWritable(string $variable): void
    {
        if (self::isReadOnly($variable)) {
            throw new ConfigurationException("Variable \"{$variable}\" is read-only");
        }
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function fromEvent(string $eventId, array $object, string $module): self
    {
        return self::fromEnvelope($eventId, EventEnvelope::fromArray($object, $module));
    }

    /**
     * @return array{0: string, 1: string|null} [variable, field|null]
     */
    public static function splitPath(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            throw new ConfigurationException('Empty field path');
        }
        $pos = strpos($path, '.');
        if ($pos === false) {
            return [$path, null];
        }
        $var = substr($path, 0, $pos);
        $field = substr($path, $pos + 1);
        if ($var === '' || $field === '') {
            throw new ConfigurationException("Invalid field path \"{$path}\"");
        }
        return [$var, $field];
    }

    public static function isValidVariableName(string $name): bool
    {
        return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }

    public function hasVariable(string $name): bool
    {
        return $name === self::SYS || $name === self::CONFIG || array_key_exists($name, $this->variables);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVariable(string $name): ?array
    {
        if ($name === self::SYS) {
            return $this->systemValues();
        }
        if ($name === self::CONFIG) {
            return $this->constants;
        }
        return $this->variables[$name] ?? null;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    public function setVariable(string $name, ?array $value, ?string $module = null, string $source = 'preload'): void
    {
        self::assertWritable($name);
        $this->variables[$name] = $value;
        $this->meta[$name] = ['module' => $module, 'source' => $source];
        unset($this->dirty[$name]);
    }

    public function getModule(string $name): ?string
    {
        return $this->meta[$name]['module'] ?? null;
    }

    /**
     * Resolves a path. Returns [found, value]; found=false when the variable is missing/null
     * or the field does not exist in it.
     *
     * @return array{0: bool, 1: mixed}
     */
    public function resolve(string $path): array
    {
        [$var, $field] = self::splitPath($path);
        if ($var === self::SYS) {
            return self::resolveField($this->systemValues(), $field);
        }
        if ($var === self::CONFIG) {
            return self::resolveField($this->constants, $field);
        }
        if (!array_key_exists($var, $this->variables)) {
            return [false, null];
        }
        $value = $this->variables[$var];
        if ($field === null) {
            return [$value !== null, $value];
        }
        return self::resolveField(is_array($value) ? $value : [], $field);
    }

    /**
     * @param array<string, mixed> $value
     * @return array{0: bool, 1: mixed}
     */
    private static function resolveField(array $value, ?string $field): array
    {
        if ($field === null) {
            return [true, $value];
        }
        if (array_key_exists($field, $value)) {
            return [true, $value[$field]];
        }
        // nested fields ("address.city", "sys.last_error.code")
        $current = $value;
        foreach (explode('.', $field) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }
            $current = $current[$segment];
        }
        return [true, $current];
    }

    public function get(string $path): mixed
    {
        return $this->resolve($path)[1];
    }

    /**
     * Sets a field. A variable that does not exist yet is created as a new object ("created in process").
     */
    public function set(string $path, mixed $value): void
    {
        [$var, $field] = self::splitPath($path);
        if ($field === null) {
            throw new ConfigurationException("Target \"{$path}\" must point to a field (e.g. {$path}.name)");
        }
        self::assertWritable($var);
        $this->assertNotLoopInfo($var);
        if ($this->isList($var)) {
            throw new ConfigurationException("Variable \"{$var}\" is a record list; use foreach to change its records");
        }
        if (!isset($this->variables[$var]) || !is_array($this->variables[$var])) {
            $this->variables[$var] = [];
            if (!isset($this->meta[$var])) {
                $this->meta[$var] = ['module' => null, 'source' => 'created'];
            }
        }
        $this->variables[$var][$field] = $value;
        if (!$this->isLocal($var)) {
            $this->dirty[$var][$field] = true;
        }
    }

    /**
     * Writes a field of a local variable (created as local when missing).
     */
    public function setLocal(string $path, mixed $value): void
    {
        [$var, $field] = self::splitPath($path);
        if ($field === null) {
            throw new ConfigurationException("Target \"{$path}\" must point to a field (e.g. {$path}.name)");
        }
        self::assertWritable($var);
        $this->assertNotLoopInfo($var);
        if (!$this->hasVariable($var) || $this->variables[$var] === null) {
            $this->variables[$var] = [];
            $this->meta[$var] = ['module' => null, 'source' => self::SOURCE_LOCAL];
        }
        $this->variables[$var][$field] = $value;
    }

    private function assertNotLoopInfo(string $var): void
    {
        if (($this->meta[$var]['source'] ?? null) === self::SOURCE_LOOP_INFO) {
            throw new ConfigurationException("Variable \"{$var}\" is read-only");
        }
    }

    /**
     * Runs $fn with a temporary variable that is removed afterwards (update_records: the current record).
     *
     * @template T
     * @param array<string, mixed> $value
     * @param callable(): T $fn
     * @return T
     */
    public function withTemporaryVariable(string $name, array $value, ?string $module, callable $fn): mixed
    {
        $this->setVariable($name, $value, $module, self::SOURCE_LOOP);
        try {
            return $fn();
        } finally {
            $this->unsetVariable($name);
        }
    }

    /**
     * Removes a variable (the loop variables after the loop).
     */
    public function unsetVariable(string $name): void
    {
        unset($this->variables[$name], $this->meta[$name], $this->dirty[$name]);
    }

    public function isList(string $var): bool
    {
        return ($this->meta[$var]['source'] ?? null) === self::SOURCE_LIST;
    }

    public function isLocal(string $var): bool
    {
        return ($this->meta[$var]['source'] ?? null) === self::SOURCE_LOCAL;
    }

    /**
     * @return string[]
     */
    public function dirtyFields(string $var): array
    {
        return array_keys($this->dirty[$var] ?? []);
    }

    public function clearDirty(string $var): void
    {
        unset($this->dirty[$var]);
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * @return array<string, array{module?: string|null, source?: string}>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * Mutable part of the state, used to roll back a failed step attempt before a retry
     * (otherwise e.g. "x = x * 2" would be applied twice).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    public function snapshotState(): array
    {
        return [$this->variables, $this->meta, $this->dirty];
    }

    /**
     * @param array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} $state
     */
    public function restoreState(array $state): void
    {
        [$this->variables, $this->meta, $this->dirty] = $state;
    }

    /**
     * Sets a field coming from the CRM itself (e.g. the id of a created record): not marked as dirty.
     */
    public function setClean(string $var, string $field, mixed $value): void
    {
        self::assertWritable($var);
        if (!isset($this->variables[$var]) || !is_array($this->variables[$var])) {
            $this->variables[$var] = [];
        }
        $this->variables[$var][$field] = $value;
    }

    public function setModule(string $var, ?string $module): void
    {
        self::assertWritable($var);
        $this->meta[$var]['module'] = $module;
        $this->meta[$var]['source'] ??= 'created';
    }

    /**
     * Copy for a child process: same event and variables, nothing is dirty. The child gets its own runtime fields
     * and constants when the engine starts it (5.2.9).
     */
    public function forChild(): self
    {
        return new self($this->eventId, $this->event, $this->variables, $this->meta, [], $this->depth + 1, $this->eventInfo, $this->clock);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event' => $this->event,
            'variables' => $this->variables,
            'meta' => $this->meta,
            'dirty' => array_map(static fn (array $f) => array_keys($f), $this->dirty),
            'depth' => $this->depth,
            'event_info' => $this->eventInfo->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?Clock $clock = null): self
    {
        $dirty = [];
        foreach ((array)($data['dirty'] ?? []) as $var => $fields) {
            foreach ((array)$fields as $field) {
                $dirty[$var][(string)$field] = true;
            }
        }
        return new self(
            eventId: (string)($data['event_id'] ?? ''),
            event: (array)($data['event'] ?? []),
            variables: (array)($data['variables'] ?? []),
            meta: (array)($data['meta'] ?? []),
            dirty: $dirty,
            depth: (int)($data['depth'] ?? 0),
            eventInfo: EventInfo::fromArray(is_array($data['event_info'] ?? null) ? $data['event_info'] : []),
            clock: $clock,
        );
    }
}
