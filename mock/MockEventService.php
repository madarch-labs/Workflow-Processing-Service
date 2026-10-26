<?php

declare(strict_types=1);

/**
 * Generates realistic CRM objects and publishes them to the Redis Stream:
 *   XADD crm_events * data '{...json...}'
 * Related objects (the account referenced by account_id) are stored in MockCrmStorage, so a
 * WPS preload "Accounts by object.account_id" finds them.
 */
final class MockEventService
{
    private const STATUSES = ['Active', 'Inactive', 'New', 'In Progress', 'Closed'];
    private const COMPANIES = ['ООО "Ромашка"', 'АО "Вектор"', 'ООО "Северный ветер"', 'ИП Иванов', 'ООО "ТехноСфера"', 'ПАО "Восток"'];
    private const PEOPLE = ['Иван Петров', 'Мария Смирнова', 'Алексей Кузнецов', 'Ольга Попова', 'Дмитрий Соколов'];

    public function __construct(
        private readonly Redis $redis,
        private readonly MockCrmStorage $storage,
        private readonly string $stream,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides fields to force (e.g. status=Active)
     * @param string|null $eventType contract 2.0: create | update | delete (null = an event of contract 1.0)
     * @param array<string, mixed> $before contract 2.0: old values of the changed fields
     * @return array{message_id: string, event: array<string, mixed>}
     */
    public function sendTestEvent(?string $module = null, array $overrides = [], ?string $eventType = null, array $before = []): array
    {
        if ($module === null || !MockModulesList::exists($module)) {
            $module = array_rand(MockModulesList::MODULES);
        }
        $event = $this->generate($module);
        foreach ($overrides as $key => $value) {
            if (is_string($key) && $key !== '' && is_scalar($value)) {
                $event[$key] = (string)$value;
            }
        }
        $this->storage->put($module, $event);
        if ($eventType !== null || $before !== []) {
            $event = self::v2($event, $eventType ?? 'update', array_map('strval', array_filter($before, 'is_scalar')));
        }
        $id = $this->publish($event);
        return ['message_id' => $id, 'event' => $event];
    }

    /**
     * Event of contract 2.0 for a write made through the mock CRM API (MOCK_PUBLISH_ON_WRITE).
     *
     * @param array<string, mixed> $object the record after the write
     * @param array<string, mixed> $before old values of the changed fields
     * @param array<string, mixed>|null $origin wps_origin from the X-WPS-* headers
     */
    public function publishWrite(string $module, string $eventType, array $object, array $before, ?array $origin): string
    {
        $event = self::v2(['id' => (string)$object['id'], 'module' => $module, 'object_type' => $module] + $object, $eventType, $before);
        if ($origin !== null) {
            $event['wps_origin'] = $origin;
        }
        return $this->publish($event);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, string|null> $before
     * @return array<string, mixed>
     */
    private static function v2(array $event, string $eventType, array $before): array
    {
        $event['event_type'] = $eventType;
        $event['before'] = (object)($eventType === 'update' ? $before : []);
        $service = ['id', 'module', 'object_type', 'event_type', 'before', 'changed_fields', 'created_at', 'updated_at'];
        $event['changed_fields'] = match ($eventType) {
            'create' => array_values(array_filter(array_keys($event), static fn ($k) => !in_array($k, $service, true) && ($event[$k] ?? '') !== '')),
            'update' => array_keys($before),
            default => [],
        };
        $event['wps_event_version'] = 2;
        return $event;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function publish(array $event): string
    {
        $id = $this->redis->xAdd($this->stream, '*', ['data' => json_encode($event, JSON_UNESCAPED_UNICODE)]);
        if (!is_string($id)) {
            throw new RuntimeException('XADD failed: ' . ($this->redis->getLastError() ?? 'unknown error'));
        }
        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $module): array
    {
        $id = (string)random_int(10000, 99999);
        $now = gmdate('c');
        $fields = MockModulesList::MODULES[$module]['fields'];
        $event = ['id' => $id, 'module' => $module, 'object_type' => $module];

        foreach ($fields as $field) {
            $event[$field] = match ($field) {
                'name' => $this->name($module, $id),
                'status' => self::STATUSES[array_rand(self::STATUSES)],
                'phone' => '+7900' . random_int(1000000, 9999999),
                'email' => "test_{$id}@example.com",
                'amount' => (string)(random_int(1, 500) * 100),
                'account_id' => '',
                default => '',
            };
        }
        // most objects belong to an account; make sure that account exists in the mock CRM
        if (in_array($module, ['Accounts', 'Contacts', 'Deals', 'Invoices', 'Orders', 'Payments', 'Contracts', 'Tickets'], true)) {
            $accountId = (string)random_int(100, 999);
            $event['account_id'] = $accountId;
            if ($this->storage->get('Accounts', $accountId) === null) {
                $this->storage->put('Accounts', [
                    'id' => $accountId,
                    'name' => self::COMPANIES[array_rand(self::COMPANIES)],
                    'status' => 'Active',
                    'inn' => (string)random_int(7700000000, 7799999999),
                    'phone' => '+7495' . random_int(1000000, 9999999),
                    'rating' => '',
                    'description' => '',
                ]);
            }
        }
        $event['assigned_user_id'] = (string)random_int(1, 9);
        $event['created_at'] = $now;
        $event['updated_at'] = $now;
        return $event;
    }

    private function name(string $module, string $id): string
    {
        return match ($module) {
            'Accounts' => self::COMPANIES[array_rand(self::COMPANIES)],
            'Contacts', 'Leads' => self::PEOPLE[array_rand(self::PEOPLE)],
            default => MockModulesList::MODULES[$module]['label'] . " №{$id}",
        };
    }
}
