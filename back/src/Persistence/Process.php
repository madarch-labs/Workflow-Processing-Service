<?php

declare(strict_types=1);

namespace Wps\Persistence;

use Wps\Support\Json;

final class Process
{
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
    ) {
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
