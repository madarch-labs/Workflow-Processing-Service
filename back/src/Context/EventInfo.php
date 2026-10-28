<?php

declare(strict_types=1);

namespace Wps\Context;

/**
 * Facts about the source event that are not fields of the record (md/tasks_list/done/6.1-event-before-values.md
 * §4.3): event type, changed fields (null = unknown), whether old values came, the WPS origin of the change.
 * Used by the "changed" operators (3.3), sys.* variables (5.2.9) and the cascade guard (7.5).
 */
final class EventInfo
{
    /**
     * @param list<string>|null $changedFields
     * @param array{execution_id?: string, process_id?: int, depth?: int}|null $origin
     */
    public function __construct(
        public readonly string $eventType = 'update',
        public readonly ?array $changedFields = null,
        public readonly bool $hasBefore = false,
        public readonly ?array $origin = null,
        public readonly bool $legacy = true,
    ) {
    }

    /**
     * true / false, or null when the event does not say which fields changed.
     */
    public function isChanged(string $field): ?bool
    {
        return $this->changedFields === null ? null : in_array($field, $this->changedFields, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'changed_fields' => $this->changedFields,
            'has_before' => $this->hasBefore,
            'origin' => $this->origin,
            'legacy' => $this->legacy,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $changed = $data['changed_fields'] ?? null;
        return new self(
            eventType: is_string($data['event_type'] ?? null) ? $data['event_type'] : 'update',
            changedFields: is_array($changed) ? array_values(array_map('strval', $changed)) : null,
            hasBefore: (bool)($data['has_before'] ?? false),
            origin: is_array($data['origin'] ?? null) ? $data['origin'] : null,
            legacy: (bool)($data['legacy'] ?? true),
        );
    }
}
