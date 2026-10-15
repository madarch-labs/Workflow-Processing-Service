<?php

declare(strict_types=1);

/**
 * In-memory "CRM" kept in Redis: hash mock_crm:{module}, field = id, value = JSON object.
 * Lets the WPS preload / save actions work end-to-end without a real SuiteCRM.
 */
final class MockCrmStorage
{
    public function __construct(private readonly Redis $redis)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $module, string $id): ?array
    {
        $json = $this->redis->hGet('mock_crm:' . $module, $id);
        if (!is_string($json)) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $object
     */
    public function put(string $module, array $object): void
    {
        $this->redis->hSet('mock_crm:' . $module, (string)$object['id'], json_encode($object, JSON_UNESCAPED_UNICODE));
    }

    /**
     * First object whose field equals the value (duplicates possible, the first one wins).
     *
     * @return list<array<string, mixed>>
     */
    public function findBy(string $module, string $field, string $value, int $limit = 1): array
    {
        $result = [];
        $all = $this->redis->hGetAll('mock_crm:' . $module) ?: [];
        ksort($all, SORT_NATURAL);
        foreach ($all as $json) {
            $object = json_decode((string)$json, true);
            if (is_array($object) && array_key_exists($field, $object) && (string)$object[$field] === $value) {
                $result[] = $object;
                if (count($result) >= $limit) {
                    break;
                }
            }
        }
        return $result;
    }
}
