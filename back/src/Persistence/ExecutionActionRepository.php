<?php

declare(strict_types=1);

namespace Wps\Persistence;

use Wps\Infrastructure\Database;
use Wps\Services\StepRecord;
use Wps\Support\Json;

final class ExecutionActionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Upsert by (execution_id, step_index): a step re-executed after recovery overwrites its previous record.
     */
    public function save(string $executionId, StepRecord $step): void
    {
        $this->db->execute(
            'INSERT INTO execution_actions (execution_id, step_index, action_type, status, attempts, started_at, duration_ms,
                                            request_data, result_data, error_message, pc, step_path, iteration, step_id, error_code, handled)
             VALUES (CAST(:execution_id AS UUID), :step_index, :action_type, :status, :attempts, TO_TIMESTAMP(CAST(:started_at AS DOUBLE PRECISION)) AT TIME ZONE \'UTC\',
                     :duration_ms, CAST(:request_data AS JSONB), CAST(:result_data AS JSONB), :error_message, :pc, :step_path, :iteration, :step_id, :error_code, :handled)
             ON CONFLICT (execution_id, step_index) DO UPDATE SET
                action_type = EXCLUDED.action_type, status = EXCLUDED.status, attempts = EXCLUDED.attempts,
                started_at = EXCLUDED.started_at, duration_ms = EXCLUDED.duration_ms, request_data = EXCLUDED.request_data,
                result_data = EXCLUDED.result_data, error_message = EXCLUDED.error_message, pc = EXCLUDED.pc,
                step_path = EXCLUDED.step_path, iteration = EXCLUDED.iteration, step_id = EXCLUDED.step_id,
                error_code = EXCLUDED.error_code, handled = EXCLUDED.handled',
            [
                'execution_id' => $executionId,
                'step_index' => $step->stepIndex,
                'action_type' => $step->actionType,
                'status' => $step->status,
                'attempts' => $step->attempts,
                'started_at' => sprintf('%.6F', $step->startedAt),
                'duration_ms' => $step->durationMs,
                'request_data' => (object)$step->request,
                'result_data' => (object)$step->result,
                'error_message' => $step->error,
                'pc' => $step->pc ?? $step->stepIndex,
                'step_path' => $step->path,
                'iteration' => $step->iteration,
                'step_id' => $step->stepId,
                'error_code' => $step->errorCode,
                'handled' => $step->handled,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByExecution(string $executionId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, step_index, action_type, status, attempts, started_at, duration_ms, request_data, result_data, error_message,
                    pc, step_path, iteration, step_id, error_code, handled
             FROM execution_actions WHERE execution_id = CAST(:id AS UUID) ORDER BY step_index',
            ['id' => $executionId],
        );
        return array_map(static fn (array $r) => [
            'id' => (int)$r['id'],
            'step_index' => (int)$r['step_index'],
            'action_type' => (string)$r['action_type'],
            'status' => (string)$r['status'],
            'attempts' => (int)$r['attempts'],
            'started_at' => Json::time($r['started_at'] ?? null),
            'duration_ms' => $r['duration_ms'] !== null ? (int)$r['duration_ms'] : null,
            'request_data' => Json::decode($r['request_data'] ?? null),
            'result_data' => Json::decode($r['result_data'] ?? null),
            'error_message' => $r['error_message'] !== null ? (string)$r['error_message'] : null,
            'pc' => $r['pc'] !== null ? (int)$r['pc'] : null,
            'step_path' => $r['step_path'] !== null ? (string)$r['step_path'] : null,
            'iteration' => (string)($r['iteration'] ?? ''),
            'step_id' => $r['step_id'] !== null ? (string)$r['step_id'] : null,
            'error_code' => $r['error_code'] !== null ? (string)$r['error_code'] : null,
            'handled' => $r['handled'] !== null ? (string)$r['handled'] : null,
        ], $rows);
    }
}
