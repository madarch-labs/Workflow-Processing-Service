<?php

declare(strict_types=1);

namespace Wps\Secrets;

use Wps\Infrastructure\Database;
use Wps\Support\Json;

/**
 * Table "secrets": ciphertext and nonce are stored as base64 text (md/tasks_list/done/7.4-secrets.md §6).
 */
final class SecretRepository
{
    private const COLUMNS = 'id, name, kind, description, ciphertext, nonce, kek_id, version, mask, allowed_hosts,
        created_at, updated_at, last_used_at';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll('SELECT ' . self::COLUMNS . ' FROM secrets ORDER BY name'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $name): ?array
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM secrets WHERE name = :name', ['name' => $name]);
        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param list<string> $names
     * @return list<string> the names that exist
     */
    public function existing(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT name FROM secrets WHERE name = ANY(CAST(:names AS TEXT[]))',
            ['names' => '{' . implode(',', array_map(static fn (string $n) => '"' . $n . '"', $names)) . '}'],
        );
        return array_column($rows, 'name');
    }

    /**
     * @param array{name: string, kind: string, description: string, ciphertext: string, nonce: string, kek_id: string, mask: string, allowed_hosts: list<string>, user_id: ?int} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $row = $this->db->fetchOne(
            'INSERT INTO secrets (name, kind, description, ciphertext, nonce, kek_id, version, mask, allowed_hosts, created_by, updated_by)
             VALUES (:name, :kind, :description, :ciphertext, :nonce, :kek_id, 1, :mask, CAST(:allowed_hosts AS JSONB), :user_id, :user_id)
             RETURNING ' . self::COLUMNS,
            ['allowed_hosts' => Json::encode($data['allowed_hosts'])] + $data,
        );
        return self::hydrate((array)$row);
    }

    /**
     * @param array<string, mixed> $fields columns to change (ciphertext, nonce, kek_id, version, mask, description, allowed_hosts)
     * @return array<string, mixed>|null
     */
    public function update(string $name, array $fields, ?int $userId): ?array
    {
        $sets = [];
        $params = ['name' => $name, 'user_id' => $userId];
        foreach ($fields as $column => $value) {
            $sets[] = $column === 'allowed_hosts' ? "{$column} = CAST(:{$column} AS JSONB)" : "{$column} = :{$column}";
            $params[$column] = $column === 'allowed_hosts' ? Json::encode($value) : $value;
        }
        $row = $this->db->fetchOne(
            'UPDATE secrets SET ' . implode(', ', $sets) . ', updated_by = :user_id, updated_at = NOW()
             WHERE name = :name RETURNING ' . self::COLUMNS,
            $params,
        );
        return $row === null ? null : self::hydrate($row);
    }

    public function delete(string $name): bool
    {
        return $this->db->execute('DELETE FROM secrets WHERE name = :name', ['name' => $name]) > 0;
    }

    public function touch(string $name): void
    {
        $this->db->execute('UPDATE secrets SET last_used_at = NOW() WHERE name = :name', ['name' => $name]);
    }

    /**
     * Process definitions that mention the secret (exact check by SecretUsageScanner).
     *
     * @return list<array{id: int, name: string, definition: array<mixed>}>
     */
    public function processesMentioning(string $name): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, name, definition FROM processes WHERE deleted_at IS NULL AND definition::text LIKE :pattern ORDER BY name",
            ['pattern' => '%' . SecretReference::PREFIX . $name . '%'],
        );
        return array_map(static fn (array $r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'definition' => (array)Json::decode((string)$r['definition'], [])], $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'kind' => (string)$row['kind'],
            'description' => (string)$row['description'],
            'ciphertext' => (string)$row['ciphertext'],
            'nonce' => (string)$row['nonce'],
            'kek_id' => (string)$row['kek_id'],
            'version' => (int)$row['version'],
            'mask' => (string)$row['mask'],
            'allowed_hosts' => (array)Json::decode((string)$row['allowed_hosts'], []),
            'created_at' => Json::time($row['created_at'] ?? null),
            'updated_at' => Json::time($row['updated_at'] ?? null),
            'last_used_at' => Json::time($row['last_used_at'] ?? null),
        ];
    }
}
