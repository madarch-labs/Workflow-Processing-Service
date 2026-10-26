<?php

declare(strict_types=1);

namespace Wps\Persistence;

use Wps\Support\Json;

final class Process
{
    public const TRIGGER_TYPES = ['create', 'update', 'delete'];
    public const DEFAULT_TRIGGER_ON = ['create', 'update'];

    /**
     * @param array{conditions: array<mixed>, preload: array<mixed>, actions: array<mixed>} $definition
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $objectType,
        public readonly bool $isChild,
        public readonly bool $isActive,
        public readonly int $priority,
        public readonly int $version,
        public readonly array $definition,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly bool $runOnce = false,
        /** @var list<string> event types that start the process (6.2); ignored for child processes */
        public readonly array $triggerOn = self::DEFAULT_TRIGGER_ON,
    ) {
    }

    public function triggersOn(string $eventType): bool
    {
        return in_array($eventType, $this->triggerOn, true);
    }

    /**
     * PostgreSQL text array "{create,update}" → list.
     *
     * @return list<string>
     */
    public static function parsePgArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $inner = trim((string)$value, '{}');
        return $inner === '' ? [] : array_values(array_map(static fn (string $v) => trim($v, '"'), explode(',', $inner)));
    }

    /**
     * @param list<string> $values plain words only (checked by the validator)
     */
    public static function toPgArray(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }

    /**
     * @return list<string> known types in canonical order, duplicates removed
     */
    public static function normalizeTriggerOn(array $values): array
    {
        return array_values(array_intersect(self::TRIGGER_TYPES, $values));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $definition = is_array($row['definition']) ? $row['definition'] : Json::decode((string)$row['definition'], []);
        return new self(
            id: (int)$row['id'],
            name: (string)$row['name'],
            objectType: (string)$row['object_type'],
            isChild: self::bool($row['is_child']),
            isActive: self::bool($row['is_active']),
            priority: (int)$row['priority'],
            version: (int)$row['version'],
            definition: self::normalizeDefinition($definition),
            createdAt: isset($row['created_at']) ? Json::time((string)$row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? Json::time((string)$row['updated_at']) : null,
            runOnce: isset($row['run_once']) && self::bool($row['run_once']),
            triggerOn: array_key_exists('trigger_on', $row) ? self::parsePgArray($row['trigger_on']) : self::DEFAULT_TRIGGER_ON,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int)$data['id'],
            name: (string)$data['name'],
            objectType: (string)$data['object_type'],
            isChild: (bool)$data['is_child'],
            isActive: (bool)$data['is_active'],
            priority: (int)$data['priority'],
            version: (int)$data['version'],
            definition: self::normalizeDefinition((array)$data['definition']),
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
            runOnce: (bool)($data['run_once'] ?? false),
            // process cache and Redis state written before 6.2 have no key
            triggerOn: is_array($data['trigger_on'] ?? null) ? array_values($data['trigger_on']) : self::DEFAULT_TRIGGER_ON,
        );
    }

    /**
     * @param mixed $definition
     * @return array{conditions: array<mixed>, preload: array<mixed>, actions: array<mixed>}
     */
    public static function normalizeDefinition(mixed $definition): array
    {
        $definition = is_array($definition) ? $definition : [];
        return [
            'conditions' => is_array($definition['conditions'] ?? null) ? $definition['conditions'] : [],
            'preload' => array_values(is_array($definition['preload'] ?? null) ? $definition['preload'] : []),
            'actions' => array_values(is_array($definition['actions'] ?? null) ? $definition['actions'] : []),
        ];
    }

    /**
     * Execution order: preload rows first, then actions.
     *
     * @return list<array<string, mixed>>
     */
    public function steps(): array
    {
        return self::stepsOf($this->definition);
    }

    /**
     * @param array{conditions: array<mixed>, preload: array<mixed>, actions: array<mixed>} $definition
     * @return list<array<string, mixed>>
     */
    public static function stepsOf(array $definition): array
    {
        $steps = [];
        foreach ($definition['preload'] as $row) {
            $steps[] = ['type' => 'preload'] + (array)$row;
        }
        foreach ($definition['actions'] as $row) {
            $steps[] = (array)$row;
        }
        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'object_type' => $this->objectType,
            'is_child' => $this->isChild,
            'is_active' => $this->isActive,
            'priority' => $this->priority,
            'run_once' => $this->runOnce,
            'trigger_on' => $this->triggerOn,
            'version' => $this->version,
            'definition' => [
                'conditions' => $this->definition['conditions'],
                'preload' => $this->definition['preload'],
                'actions' => $this->definition['actions'],
            ],
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === 't' || $value === 'true' || $value === '1';
    }
}
