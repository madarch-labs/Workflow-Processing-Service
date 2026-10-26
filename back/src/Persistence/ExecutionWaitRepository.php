<?php

declare(strict_types=1);

namespace Wps\Persistence;

use PDO;
use Wps\Infrastructure\Database;

/**
 * execution_waits (md/tasks_list/done/7.2-waiting-executions.md §4.2–4.4). Every status change of a waiting
 * execution is a compare-and-set on executions.status, so a cancelled or finished execution is never woken twice.
 */
final class ExecutionWaitRepository
{
    private const COLUMNS = 'execution_id, process_id, due_at, reason, state, info, attempts';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * One transaction: the wait row + executions running → waiting. false when the execution is no longer running.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $info
     */
    public function park(string $executionId, int $processId, \DateTimeImmutable $dueAt, string $reason, array $state, array $info): bool
    {
        return $this->db->transaction(static function (PDO $pdo) use ($executionId, $processId, $dueAt, $reason, $state, $info): bool {
            $updated = Database::prepare($pdo,
                "UPDATE executions SET status = 'waiting', waiting_until = CAST(:due AS TIMESTAMPTZ), wait_reason = :reason
                 WHERE id = CAST(:id AS UUID) AND status IN ('running', 'retrying') AND finished_at IS NULL",
                ['id' => $executionId, 'due' => $dueAt->format(\DATE_ATOM), 'reason' => $reason])->rowCount();
            if ($updated === 0) {
                $pdo->rollBack();
                $pdo->beginTransaction(); // Database::transaction() commits at the end
                return false;
            }
            Database::prepare($pdo,
                'INSERT INTO execution_waits (execution_id, process_id, due_at, reason, state, info)
                 VALUES (CAST(:id AS UUID), :process_id, CAST(:due AS TIMESTAMPTZ), :reason, CAST(:state AS JSONB), CAST(:info AS JSONB))
                 ON CONFLICT (execution_id) DO UPDATE SET due_at = EXCLUDED.due_at, reason = EXCLUDED.reason, state = EXCLUDED.state,
                     info = EXCLUDED.info, attempts = 0, locked_until = NULL, locked_by = NULL, last_error = NULL',
                ['id' => $executionId, 'process_id' => $processId, 'due' => $dueAt->format(\DATE_ATOM), 'reason' => $reason,
                    'state' => (object)$state, 'info' => (object)$info]);
            return true;
        });
    }

    /**
     * Due rows for this worker, leased for $leaseSeconds (SKIP LOCKED: workers do not wait for each other).
     *
     * @return list<WaitRow>
     */
    public function claimDue(string $owner, int $limit, int $leaseSeconds): array
    {
        $rows = $this->db->fetchAll(
            'UPDATE execution_waits w
                SET locked_until = now() + make_interval(secs => :lease), locked_by = :owner, attempts = w.attempts + 1
              WHERE w.execution_id IN (
                    SELECT execution_id FROM execution_waits
                     WHERE due_at <= now() AND (locked_until IS NULL OR locked_until < now())
                     ORDER BY due_at
                     LIMIT :limit
                     FOR UPDATE SKIP LOCKED)
              RETURNING w.execution_id, w.process_id, w.due_at, w.reason, w.state, w.info, w.attempts',
            ['lease' => $leaseSeconds, 'owner' => $owner, 'limit' => $limit],
        );
        return array_map(WaitRow::fromRow(...), $rows);
    }

    /**
     * executions waiting → running; false when the status is no longer "waiting".
     */
    public function markRunning(string $executionId): bool
    {
        return $this->db->execute(
            "UPDATE executions SET status = 'running', waiting_until = NULL, wait_reason = NULL
             WHERE id = CAST(:id AS UUID) AND status = 'waiting'",
            ['id' => $executionId],
        ) > 0;
    }

    /**
     * Back to waiting after a failed wake (the lock of a previous run is still held …).
     */
    public function markWaitingAgain(string $executionId, string $reason): void
    {
        $this->db->execute(
            "UPDATE executions SET status = 'waiting', wait_reason = :reason WHERE id = CAST(:id AS UUID) AND status = 'running'",
            ['id' => $executionId, 'reason' => $reason],
        );
    }

    public function delete(string $executionId): void
    {
        $this->db->execute('DELETE FROM execution_waits WHERE execution_id = CAST(:id AS UUID)', ['id' => $executionId]);
    }

    public function release(string $executionId, int $delaySeconds, ?string $error): void
    {
        $this->db->execute(
            'UPDATE execution_waits SET locked_until = now() + make_interval(secs => :delay), locked_by = NULL, last_error = :error
             WHERE execution_id = CAST(:id AS UUID)',
            ['id' => $executionId, 'delay' => $delaySeconds, 'error' => $error],
        );
    }

    public function find(string $executionId): ?WaitRow
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM execution_waits WHERE execution_id = CAST(:id AS UUID)', ['id' => $executionId]);
        return $row === null ? null : WaitRow::fromRow($row);
    }

    public function countByProcess(int $processId): int
    {
        return (int)$this->db->fetchValue('SELECT COUNT(*) FROM execution_waits WHERE process_id = :id', ['id' => $processId]);
    }

    public function count(): int
    {
        return (int)$this->db->fetchValue('SELECT COUNT(*) FROM execution_waits');
    }

    /**
     * Ends every waiting execution of a process ($status: cancelled | failed) and removes the wait rows.
     */
    public function cancelByProcess(int $processId, string $status, string $reason): int
    {
        return $this->db->transaction(static function (PDO $pdo) use ($processId, $status, $reason): int {
            $count = Database::prepare($pdo,
                "UPDATE executions SET status = :status, error_message = :reason, finished_at = NOW(), waiting_until = NULL
                 WHERE id IN (SELECT execution_id FROM execution_waits WHERE process_id = :id) AND status = 'waiting'",
                ['status' => $status, 'reason' => $reason, 'id' => $processId])->rowCount();
            Database::prepare($pdo, 'DELETE FROM execution_waits WHERE process_id = :id', ['id' => $processId]);
            return $count;
        });
    }
}
