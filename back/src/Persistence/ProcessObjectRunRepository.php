<?php

declare(strict_types=1);

namespace Wps\Persistence;

use Wps\Infrastructure\Database;
use Wps\Support\Json;

/**
 * Marks of the "run once per record" processes (process_object_runs): process P has already been started
 * for the CRM record (object_type, object_id). The primary key makes the claim atomic across workers.
 */
final class ProcessObjectRunRepository
{
    private const COLUMNS = 'process_id, object_type, object_id, execution_id, event_id, trigger, created_at, blocked_count, last_blocked_at';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Atomic claim: true — the slot is ours (the process may start), false — the record already has a mark.
     */
    public function claim(int $processId, string $objectType, string $objectId, string $executionId, string $eventId, string $trigger): bool
    {
        $row = $this->db->fetchOne(
            'INSERT INTO process_object_runs (process_id, object_type, object_id, execution_id, event_id, trigger)
             VALUES (:process_id, :object_type, :object_id, CAST(:execution_id AS UUID), :event_id, :trigger)
             ON CONFLICT (process_id, object_type, object_id) DO NOTHING
             RETURNING execution_id',
            [
                'process_id' => $processId, 'object_type' => $objectType, 'object_id' => $objectId,
                'execution_id' => $executionId, 'event_id' => $eventId, 'trigger' => $trigger,
            ],
        );
        return $row !== null;
    }

    /**
     * Counts a blocked attempt and returns the blocking mark (null if it was removed in the meantime).
     *
     * @return array<string, mixed>|null
     */
    public function registerBlocked(int $processId, string $objectType, string $objectId): ?array
    {
        $row = $this->db->fetchOne(
            'UPDATE process_object_runs SET blocked_count = blocked_count + 1, last_blocked_at = NOW()
             WHERE process_id = :process_id AND object_type = :object_type AND object_id = :object_id
             RETURNING ' . self::COLUMNS,
            ['process_id' => $processId, 'object_type' => $objectType, 'object_id' => $objectId],
        );
        return $row === null ? null : self::map($row);
    }

    /**
     * Removes a mark (reset from the API/UI, or compensation when the execution could not be created).
     */
    public function release(int $processId, string $objectType, string $objectId): bool
    {
        return $this->db->execute(
            'DELETE FROM process_object_runs WHERE process_id = :process_id AND object_type = :object_type AND object_id = :object_id',
            ['process_id' => $processId, 'object_type' => $objectType, 'object_id' => $objectId],
        ) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $processId, string $objectType, string $objectId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM process_object_runs
             WHERE process_id = :process_id AND object_type = :object_type AND object_id = :object_id',
            ['process_id' => $processId, 'object_type' => $objectType, 'object_id' => $objectId],
        );
        return $row === null ? null : self::map($row);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function paginate(int $processId, ?string $objectId, int $page, int $perPage): array
    {
        $where = 'process_id = :process_id';
        $params = ['process_id' => $processId];
        if ($objectId !== null && $objectId !== '') {
            $where .= ' AND object_id = :object_id';
            $params['object_id'] = $objectId;
        }
        $total = (int)$this->db->fetchValue("SELECT COUNT(*) FROM process_object_runs WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . " FROM process_object_runs WHERE {$where}
             ORDER BY created_at DESC, object_id ASC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );
        return [array_map(self::map(...), $rows), $total];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function map(array $row): array
    {
        return [
            'process_id' => (int)$row['process_id'],
            'object_type' => (string)$row['object_type'],
            'object_id' => (string)$row['object_id'],
            'execution_id' => (string)$row['execution_id'],
            'event_id' => (string)$row['event_id'],
            'trigger' => (string)$row['trigger'],
            'created_at' => Json::time((string)$row['created_at']),
            'blocked_count' => (int)$row['blocked_count'],
            'last_blocked_at' => isset($row['last_blocked_at']) ? Json::time((string)$row['last_blocked_at']) : null,
        ];
    }
}
