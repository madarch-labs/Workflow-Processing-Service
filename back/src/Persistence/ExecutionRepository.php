<?php

declare(strict_types=1);

namespace Wps\Persistence;

use Wps\Infrastructure\Database;
use Wps\Support\Json;

final class ExecutionRepository
{
    private const SUMMARY = 'e.id, e.process_id, p.name AS process_name, e.process_version, e.parent_execution_id, e.trigger,
        e.event_id, e.object_type, e.object_id, e.status, e.retry_count, e.handled_errors, e.error_message, e.started_at, e.finished_at,
        e.waiting_until, e.wait_reason';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Idempotent insert (an execution may be re-created when a crashed worker's message is re-processed).
     *
     * @param array{id: string, process_id: int, process_version: int, parent_execution_id: ?string, trigger: string,
     *              event_id: string, object_type: string, object_id: string} $data
     */
    public function create(array $data): void
    {
        $this->db->execute(
            "INSERT INTO executions (id, process_id, process_version, parent_execution_id, trigger, event_id, object_type, object_id, status)
             VALUES (CAST(:id AS UUID), :process_id, :process_version, CAST(:parent_execution_id AS UUID), :trigger, :event_id,
                     :object_type, :object_id, 'running')
             ON CONFLICT (id) DO NOTHING",
            $data,
        );
    }

    public function updateStatus(string $id, string $status, int $retryCount): void
    {
        $this->db->execute(
            'UPDATE executions SET status = :status, retry_count = :retry_count WHERE id = CAST(:id AS UUID) AND finished_at IS NULL',
            ['id' => $id, 'status' => $status, 'retry_count' => $retryCount],
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function finish(string $id, string $status, int $retryCount, array $snapshot, ?string $error, int $handledErrors = 0): void
    {
        $this->db->execute(
            'UPDATE executions SET status = :status, retry_count = :retry_count, handled_errors = :handled_errors, finished_at = NOW(),
                    context_snapshot = CAST(:snapshot AS JSONB), error_message = :error
             WHERE id = CAST(:id AS UUID)',
            ['id' => $id, 'status' => $status, 'retry_count' => $retryCount, 'handled_errors' => $handledErrors, 'snapshot' => $snapshot, 'error' => $error],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT ' . self::SUMMARY . ', e.context_snapshot FROM executions e JOIN processes p ON p.id = e.process_id
             WHERE e.id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        if ($row === null) {
            return null;
        }
        $result = self::map($row);
        $result['context_snapshot'] = Json::decode($row['context_snapshot'] ?? null);
        return $result;
    }

    /**
     * @param array{status?: string|null, date_from?: string|null, date_to?: string|null} $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function paginateByProcess(int $processId, array $filters, int $page, int $perPage): array
    {
        return $this->paginate(['process_id' => $processId] + $filters, $page, $perPage);
    }

    /**
     * Executions of every process (GET /api/executions): status, process_id, due_before (waiting until), dates.
     *
     * @param array<string, mixed> $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $where = ['TRUE'];
        $params = [];
        if (($filters['process_id'] ?? null) !== null && $filters['process_id'] !== '') {
            $where[] = 'e.process_id = :process_id';
            $params['process_id'] = (int)$filters['process_id'];
        }
        if (($filters['due_before'] ?? '') !== '') {
            $where[] = 'e.waiting_until <= CAST(:due_before AS TIMESTAMPTZ)';
            $params['due_before'] = $filters['due_before'];
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'e.status = :status';
            $params['status'] = $filters['status'];
        }
        if (($filters['date_from'] ?? '') !== '') {
            $where[] = 'e.started_at >= CAST(:date_from AS TIMESTAMPTZ) AT TIME ZONE \'UTC\'';
            $params['date_from'] = $filters['date_from'];
        }
        if (($filters['date_to'] ?? '') !== '') {
            $to = (string)$filters['date_to'];
            // a bare date means "up to the end of that day"
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $where[] = 'e.started_at < (CAST(:date_to AS DATE) + 1)';
            } else {
                $where[] = 'e.started_at <= CAST(:date_to AS TIMESTAMPTZ) AT TIME ZONE \'UTC\'';
            }
            $params['date_to'] = $to;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int)$this->db->fetchValue("SELECT COUNT(*) FROM executions e WHERE {$whereSql}", $params);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::SUMMARY . " FROM executions e JOIN processes p ON p.id = e.process_id
             WHERE {$whereSql} ORDER BY e.started_at DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );
        return [array_map(self::map(...), $rows), $total];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function children(string $parentId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::SUMMARY . ' FROM executions e JOIN processes p ON p.id = e.process_id
             WHERE e.parent_execution_id = CAST(:id AS UUID) ORDER BY e.started_at',
            ['id' => $parentId],
        );
        return array_map(self::map(...), $rows);
    }

    /**
     * Unfinished executions older than $seconds (candidates for recovery after a crash).
     *
     * @return list<string>
     */
    public function findStaleIds(int $seconds, int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM executions
             WHERE finished_at IS NULL AND status IN ('running', 'retrying')
               AND started_at < NOW() - make_interval(secs => :secs)
             ORDER BY started_at LIMIT :limit",
            ['secs' => $seconds, 'limit' => $limit],
        );
        return array_map(static fn (array $r) => (string)$r['id'], $rows);
    }

    /**
     * @return array{status: string, finished: bool}|null
     */
    public function status(string $id): ?array
    {
        $row = $this->db->fetchOne('SELECT status, finished_at FROM executions WHERE id = CAST(:id AS UUID)', ['id' => $id]);
        return $row === null ? null : ['status' => (string)$row['status'], 'finished' => $row['finished_at'] !== null];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function map(array $row): array
    {
        $started = Json::time($row['started_at'] ?? null);
        $finished = Json::time($row['finished_at'] ?? null);
        $duration = null;
        if ($row['finished_at'] ?? null) {
            $duration = (int)round(
                ((new \DateTimeImmutable((string)$row['finished_at'], new \DateTimeZone('UTC')))->format('U.u')
                    - (new \DateTimeImmutable((string)$row['started_at'], new \DateTimeZone('UTC')))->format('U.u')) * 1000,
            );
        }
        return [
            'id' => (string)$row['id'],
            'process_id' => (int)$row['process_id'],
            'process_name' => (string)$row['process_name'],
            'process_version' => (int)$row['process_version'],
            'parent_execution_id' => $row['parent_execution_id'] !== null ? (string)$row['parent_execution_id'] : null,
            'trigger' => (string)$row['trigger'],
            'event_id' => (string)$row['event_id'],
            'object_type' => (string)$row['object_type'],
            'object_id' => (string)$row['object_id'],
            'status' => (string)$row['status'],
            'retry_count' => (int)$row['retry_count'],
            'handled_errors' => (int)($row['handled_errors'] ?? 0),
            'waiting_until' => isset($row['waiting_until']) ? Json::time((string)$row['waiting_until']) : null,
            'wait_reason' => $row['wait_reason'] ?? null,
            'error_message' => $row['error_message'] !== null ? (string)$row['error_message'] : null,
            'started_at' => $started,
            'finished_at' => $finished,
            'duration_ms' => $duration,
        ];
    }
}
