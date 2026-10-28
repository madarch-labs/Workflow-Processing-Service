<?php

declare(strict_types=1);

namespace Wps\Events;

use Wps\Context\EventInfo;
use Wps\Support\Json;

/**
 * Normalized CRM event (md/tasks_list/done/6.1-event-before-values.md §4.2): the record without service keys, the
 * event type, old values of the changed fields ("before"), the changed field list and the WPS origin. One parser
 * for the stream, manual runs and (later) webhooks.
 *
 * Service fields of a wrong type are dropped with a warning — the event itself is never rejected because of them:
 * losing the save is worse than losing only the old values.
 */
final class EventEnvelope
{
    public const SERVICE_KEYS = ['event_type', 'before', 'changed_fields', 'wps_origin'];
    public const EVENT_TYPES = ['create', 'update', 'delete'];

    /**
     * @param array<string, mixed> $object the record without SERVICE_KEYS (old wps_* keys stay)
     * @param array<string, mixed>|null $before null = old values are unknown; [] = known to be none (create)
     * @param list<string>|null $changedFields null = unknown
     * @param array{execution_id?: string, process_id?: int, depth?: int}|null $origin
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $module,
        public readonly array $object,
        public readonly string $eventType,
        public readonly ?array $before,
        public readonly ?array $changedFields,
        public readonly ?array $origin,
        public readonly bool $legacy,
        public readonly array $warnings = [],
        /** event_type is not create / update / delete (link, unlink, typo): no process is started (6.2) */
        public readonly bool $unsupportedType = false,
    ) {
    }

    /**
     * Parses an event object. module / id are not checked here (EventProcessorService::parse() does it).
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw, ?string $module = null): self
    {
        $warnings = [];
        $module ??= (string)($raw['module'] ?? $raw['object_type'] ?? '');
        $legacy = !array_key_exists('event_type', $raw);

        // old plugin without event_type: wps_is_update = '0' means a new record (md/tasks_list/done/6.2-event-type.md §4.1)
        $eventType = $legacy && isset($raw['wps_is_update']) && (string)$raw['wps_is_update'] === '0' ? 'create' : 'update';
        $unsupported = false;
        if (!$legacy) {
            $type = is_scalar($raw['event_type']) ? strtolower(trim((string)$raw['event_type'])) : '';
            if (in_array($type, self::EVENT_TYPES, true)) {
                $eventType = $type;
            } else {
                $eventType = $type === '' ? 'unknown' : $type;
                $unsupported = true;
            }
        }

        $before = null;
        if (array_key_exists('before', $raw) && $raw['before'] !== null) {
            $value = self::decode($raw['before']);
            if (is_array($value) && ($value === [] || !array_is_list($value))) {
                unset($value['id'], $value['module']);
                $before = $value;
            } else {
                $warnings[] = 'Invalid "before" in event (must be an object), old values are unknown';
            }
        }

        $changed = null;
        if (array_key_exists('changed_fields', $raw) && $raw['changed_fields'] !== null) {
            $value = self::decode($raw['changed_fields']);
            if (is_array($value) && array_is_list($value)) {
                $changed = array_values(array_unique(array_filter($value, static fn ($f) => is_string($f) && $f !== '')));
            } else {
                $warnings[] = 'Invalid "changed_fields" in event (must be a list), changed fields are unknown';
            }
        }

        if ($before === null && $eventType === 'create' && !$legacy) {
            $before = [];
        }
        if ($changed === null && $before !== null && $eventType !== 'create') {
            $changed = array_values(array_map('strval', array_keys($before)));
        }

        $origin = null;
        if (isset($raw['wps_origin'])) {
            $value = self::decode($raw['wps_origin']);
            $origin = self::origin($value);
            if ($origin === null) {
                $warnings[] = 'Invalid "wps_origin" in event, ignored';
            }
        }

        $object = array_diff_key($raw, array_flip(self::SERVICE_KEYS));
        return new self($module, $object, $eventType, $before, $changed, $origin, $legacy, $warnings, $unsupported);
    }

    public function eventInfo(): EventInfo
    {
        return new EventInfo($this->eventType, $this->changedFields, $this->before !== null, $this->origin, $this->legacy);
    }

    /**
     * The flat XADD format cannot carry nested values: a JSON string is accepted.
     */
    private static function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = ltrim($value);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                return Json::decode($value, $value);
            }
        }
        return $value;
    }

    /**
     * @return array{execution_id?: string, process_id?: int, depth?: int}|null
     */
    private static function origin(mixed $value): ?array
    {
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }
        $origin = [];
        if (isset($value['execution_id'])) {
            if (!is_string($value['execution_id']) || $value['execution_id'] === '') {
                return null;
            }
            $origin['execution_id'] = $value['execution_id'];
        }
        foreach (['process_id', 'depth'] as $key) {
            if (isset($value[$key])) {
                if (!is_int($value[$key]) && !(is_string($value[$key]) && ctype_digit($value[$key]))) {
                    return null;
                }
                $origin[$key] = (int)$value[$key];
            }
        }
        return $origin === [] ? null : $origin;
    }
}
