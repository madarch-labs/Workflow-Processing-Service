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

    public function delete(string $module, string $id): bool
    {
        return (bool)$this->redis->hDel('mock_crm:' . $module, $id);
    }

    /**
     * Search of contract 2.0: conditions (AND), sort (≤ 2 fields + id), fields, keyset cursor, total.
     * Values are compared numerically when both sides are numeric, otherwise as strings.
     *
     * @param list<array{field: string, op: string, value: mixed}> $conditions
     * @param list<array{field: string, desc: bool}> $sort
     * @param list<string>|null $fields
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string, total: int}
     */
    public function find(string $module, array $conditions, array $sort, ?array $fields, int $size, ?string $after): array
    {
        $all = array_values(array_filter(array_map(
            static fn ($json) => json_decode((string)$json, true),
            $this->redis->hGetAll('mock_crm:' . $module) ?: [],
        ), 'is_array'));
        $matching = array_values(array_filter($all, static fn (array $o) => self::matches($o, $conditions)));
        $keys = array_merge($sort, [['field' => 'id', 'desc' => $sort !== [] ? end($sort)['desc'] : false]]);
        usort($matching, static fn (array $a, array $b) => self::compareKeys(self::keyValues($a, $keys), self::keyValues($b, $keys), $keys));
        $signature = substr(sha1((string)json_encode([$module, $conditions, $sort])), 0, 12);
        if ($after !== null && $after !== '') {
            $cursor = json_decode((string)base64_decode(strtr($after, '-_', '+/')), true);
            if (!is_array($cursor) || ($cursor['s'] ?? '') !== $signature) {
                throw new InvalidArgumentException('Invalid cursor (it belongs to another query)');
            }
            $matching = array_values(array_filter($matching, static fn (array $o) => self::compareKeys(self::keyValues($o, $keys), (array)$cursor['v'], $keys) > 0));
        }
        $page = array_slice($matching, 0, $size);
        $next = null;
        if (count($matching) > $size) {
            $last = end($page);
            $next = rtrim(strtr(base64_encode((string)json_encode(['v' => self::keyValues($last, $keys), 's' => $signature])), '+/', '-_'), '=');
        }
        if ($fields !== null) {
            $page = array_map(static fn (array $o) => array_intersect_key($o, array_flip(array_merge(['id'], $fields))), $page);
        }
        return ['items' => $page, 'next_cursor' => $next, 'total' => count(array_filter($all, static fn (array $o) => self::matches($o, $conditions)))];
    }

    /**
     * @param list<array{field: string, op: string, value: mixed}> $conditions
     * @param array<string, mixed> $object
     */
    private static function matches(array $object, array $conditions): bool
    {
        foreach ($conditions as $c) {
            $actual = isset($object[$c['field']]) ? (string)$object[$c['field']] : '';
            $value = $c['value'];
            $ok = match ($c['op']) {
                'eq' => $value === '' ? $actual === '' : self::cmp($actual, (string)$value) === 0,
                'neq' => $value === '' ? $actual !== '' : self::cmp($actual, (string)$value) !== 0,
                'gt' => $actual !== '' && self::cmp($actual, (string)$value) > 0,
                'gte' => $actual !== '' && self::cmp($actual, (string)$value) >= 0,
                'lt' => $actual !== '' && self::cmp($actual, (string)$value) < 0,
                'lte' => $actual !== '' && self::cmp($actual, (string)$value) <= 0,
                'like' => (bool)preg_match('/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote((string)$value, '/')) . '$/is', $actual),
                'in' => in_array($actual, array_map('strval', (array)$value), true),
                'nin' => !in_array($actual, array_map('strval', (array)$value), true),
                'null' => $actual === '',
                'notnull' => $actual !== '',
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    private static function cmp(string $a, string $b): int
    {
        return is_numeric($a) && is_numeric($b) ? ((float)$a <=> (float)$b) : strcmp($a, $b);
    }

    /**
     * @param array<string, mixed> $object
     * @param list<array{field: string, desc: bool}> $keys
     * @return list<string>
     */
    private static function keyValues(array $object, array $keys): array
    {
        return array_map(static fn (array $k) => isset($object[$k['field']]) ? (string)$object[$k['field']] : '', $keys);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @param list<array{field: string, desc: bool}> $keys
     */
    private static function compareKeys(array $a, array $b, array $keys): int
    {
        foreach ($keys as $i => $key) {
            $c = $key['field'] === 'id' ? strnatcmp($a[$i] ?? '', $b[$i] ?? '') : self::cmp($a[$i] ?? '', $b[$i] ?? '');
            if ($c !== 0) {
                return $key['desc'] ? -$c : $c;
            }
        }
        return 0;
    }

    /**
     * Relationships: hash mock_crm_rel:{module}:{id}:{link}, field = related id, value = {related_module, fields}.
     *
     * @return array<string, array{related_module: string, fields: array<string, string>}>
     */
    public function relations(string $module, string $id, string $link): array
    {
        $result = [];
        foreach ($this->redis->hGetAll("mock_crm_rel:{$module}:{$id}:{$link}") ?: [] as $rid => $json) {
            $data = json_decode((string)$json, true);
            $result[(string)$rid] = ['related_module' => (string)($data['related_module'] ?? ''), 'fields' => (array)($data['fields'] ?? [])];
        }
        ksort($result, SORT_NATURAL);
        return $result;
    }

    /**
     * @param array<string, string> $fields
     * @return bool true when the link is new
     */
    public function link(string $module, string $id, string $link, string $relatedId, string $relatedModule, array $fields): bool
    {
        $key = "mock_crm_rel:{$module}:{$id}:{$link}";
        $existing = $this->redis->hGet($key, $relatedId);
        $current = is_string($existing) ? (array)json_decode($existing, true) : [];
        $this->redis->hSet($key, $relatedId, json_encode([
            'related_module' => $relatedModule !== '' ? $relatedModule : (string)($current['related_module'] ?? ''),
            'fields' => array_merge((array)($current['fields'] ?? []), $fields),
        ], JSON_UNESCAPED_UNICODE));
        return !is_string($existing);
    }

    public function unlink(string $module, string $id, string $link, string $relatedId): bool
    {
        return (bool)$this->redis->hDel("mock_crm_rel:{$module}:{$id}:{$link}", $relatedId);
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
