<?php

declare(strict_types=1);

namespace Wps\Persistence;

use Wps\Infrastructure\Database;
use Wps\Support\Json;

final class UserRepository
{
    public const ROLES = ['admin', 'editor', 'viewer'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null including password_hash (internal use only)
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE email = :email', ['email' => self::normalizeEmail($email)]);
    }

    /**
     * @return array<string, mixed>|null public representation
     */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        return $row === null ? null : self::toPublic($row);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function paginate(int $page, int $perPage): array
    {
        $total = (int)$this->db->fetchValue('SELECT COUNT(*) FROM users');
        $rows = $this->db->fetchAll(
            'SELECT * FROM users ORDER BY id LIMIT :limit OFFSET :offset',
            ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );
        return [array_map(self::toPublic(...), $rows), $total];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $email, string $password, string $role): array
    {
        $row = $this->db->fetchOne(
            'INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, :role) RETURNING *',
            ['email' => self::normalizeEmail($email), 'hash' => password_hash($password, PASSWORD_BCRYPT), 'role' => $role],
        );
        return self::toPublic((array)$row);
    }

    /**
     * @param array{email?: string, password?: string, role?: string} $changes
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $changes): ?array
    {
        $set = ['updated_at = NOW()'];
        $params = ['id' => $id];
        if (isset($changes['email'])) {
            $set[] = 'email = :email';
            $params['email'] = self::normalizeEmail($changes['email']);
        }
        if (isset($changes['password'])) {
            $set[] = 'password_hash = :hash';
            $params['hash'] = password_hash($changes['password'], PASSWORD_BCRYPT);
        }
        if (isset($changes['role'])) {
            $set[] = 'role = :role';
            $params['role'] = $changes['role'];
        }
        $row = $this->db->fetchOne('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id RETURNING *', $params);
        return $row === null ? null : self::toPublic($row);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM users WHERE id = :id', ['id' => $id]) > 0;
    }

    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM users WHERE email = :email AND id <> :id',
            ['email' => self::normalizeEmail($email), 'id' => $exceptId ?? 0],
        ) !== null;
    }

    public function countAdmins(): int
    {
        return (int)$this->db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toPublic(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'role' => (string)$row['role'],
            'created_at' => Json::time($row['created_at'] ?? null),
            'updated_at' => Json::time($row['updated_at'] ?? null),
        ];
    }
}
