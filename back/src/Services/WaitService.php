<?php

declare(strict_types=1);

namespace Wps\Services;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Wps\Config\AppConfig;
use Wps\Exceptions\NonRecoverableException;
use Wps\Persistence\ExecutionRepository;
use Wps\Persistence\ExecutionWaitRepository;
use Wps\Persistence\WaitRow;
use Wps\Program\LoopItemsStore;
use Wps\Program\LoopRunner;
use Wps\Support\Uuid;

/**
 * Parking and waking of executions (md/tasks_list/done/7.2-waiting-executions.md): a waiting execution holds no worker
 * and no Redis key — its state is in execution_waits (PostgreSQL) until due_at; WaitResumeJob claims due rows with a
 * lease and continues them. Every transition is a compare-and-set on executions.status plus the execution lock, so an
 * execution is continued exactly once even with several workers and containers.
 */
final class WaitService
{
    /**
     * @param \Closure(): ExecutionEngineService $engine lazy: the engine depends on this service
     */
    public function __construct(
        private readonly ExecutionWaitRepository $waits,
        private readonly ExecutionStateStore $state,
        private readonly ExecutionRepository $executions,
        private readonly AppConfig $config,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
        private readonly \Closure $engine,
        /** foreach snapshots of a waiting execution go to execution_waits.state with it (4.11) */
        private readonly ?LoopItemsStore $loopItems = null,
    ) {
    }

    /**
     * Short waits stay in the coroutine (WAIT_INLINE_MAX_SECONDS), longer ones park the execution.
     */
    public function shouldPark(\DateTimeImmutable $dueAt, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return (float)$dueAt->format('U.u') - (float)$now->format('U.u') > $this->config->waitInlineMaxSeconds;
    }

    /**
     * Moves the execution from Redis to execution_waits (the progress must be saved before). false: the execution is
     * no longer running (cancelled / finished) — it stops without parking.
     *
     * @param array<string, mixed> $info
     */
    public function park(ExecutionHandle $handle, \DateTimeImmutable $dueAt, string $reason, array $info): bool
    {
        $state = $this->state->load($handle->id) ?? throw new NonRecoverableException('Execution state is missing, it cannot wait');
        $state['status'] = 'waiting';
        $loopKeys = LoopRunner::itemKeys(array_values((array)($state['frames'] ?? [])));
        if ($loopKeys !== [] && $this->loopItems !== null) {
            $state['loop_items'] = $this->loopItems->dump($loopKeys);
        }
        $bytes = strlen((string)json_encode($state));
        if ($bytes > $this->config->waitStateMaxBytes) {
            throw new NonRecoverableException("Execution state is too large to wait ({$bytes} bytes, limit {$this->config->waitStateMaxBytes})");
        }
        if (!$this->waits->park($handle->id, $handle->process->id, $dueAt, $reason, $state, $info)) {
            $this->logger->warning('Execution is no longer running, it was not parked', ['execution_id' => $handle->id]);
            $this->state->releaseLock($handle->id, $handle->lockToken);
            $handle->parked = true;
            return false;
        }
        // PostgreSQL first, Redis after: a crash in between leaves both copies, and waking overwrites Redis
        $this->state->delete($handle->id);
        $this->loopItems?->delete(...$loopKeys);
        $this->state->releaseLock($handle->id, $handle->lockToken);
        $handle->parked = true;
        $this->metrics->inc(MetricsService::EXECUTIONS_PARKED);
        $this->logger->info('Execution parked', ['execution_id' => $handle->id, 'process_id' => $handle->process->id,
            'due_at' => $dueAt->format(\DATE_ATOM), 'reason' => $reason, 'state_bytes' => $bytes]);
        return true;
    }

    /**
     * One iteration of WaitResumeJob: claims due waits and continues each in its own coroutine.
     */
    public function wakeDue(string $owner): int
    {
        $rows = $this->waits->claimDue($owner, $this->config->waitBatch, $this->config->waitLeaseSeconds);
        foreach ($rows as $row) {
            Coroutine::create(function () use ($row): void {
                $this->wake($row);
            });
        }
        return count($rows);
    }

    public function wake(WaitRow $row): void
    {
        $id = $row->executionId;
        try {
            if (!$this->waits->markRunning($id)) {
                $this->waits->delete($id); // cancelled or finished meanwhile
                return;
            }
            $state = $row->state;
            if (isset($state['loop_items']) && $this->loopItems !== null) {
                $this->loopItems->restore((array)$state['loop_items']);
            }
            unset($state['loop_items']);
            $this->state->create($id, ['status' => 'running'] + $state);
            $token = Uuid::v4();
            if (!$this->state->acquireLock($id, $token)) {
                // a previous run still holds the lock: try again a bit later
                $this->waits->markWaitingAgain($id, $row->reason);
                $this->waits->release($id, 30, 'execution lock is held');
                return;
            }
            $this->waits->delete($id);
        } catch (\Throwable $e) {
            $this->failedWake($row, $e);
            return;
        }
        $lateness = max(0.0, microtime(true) - (float)$row->dueAt->format('U.u'));
        $this->metrics->inc(MetricsService::EXECUTIONS_WOKEN);
        $this->metrics->observe(MetricsService::WAIT_LATENESS, $lateness);
        $this->logger->info('Execution woken', ['execution_id' => $id, 'reason' => $row->reason, 'lateness_ms' => (int)round($lateness * 1000)]);
        try {
            ($this->engine)()->resumeAfterWait($id, $token, $row->info + ['due_at' => $row->dueAt->format(\DATE_ATOM), 'reason' => $row->reason]);
        } catch (\Throwable $e) {
            // the state is in Redis and the execution is "running": RecoveryJob continues it
            $this->logger->error('Execution interrupted after waking', ['execution_id' => $id, 'error' => $e]);
        }
    }

    private function failedWake(WaitRow $row, \Throwable $e): void
    {
        $this->metrics->inc(MetricsService::WAIT_RESUME_FAILED);
        $this->logger->warning('Resume attempt failed', ['execution_id' => $row->executionId, 'attempt' => $row->attempts, 'error' => $e->getMessage()]);
        if ($row->attempts >= $this->config->waitMaxAttempts) {
            $this->executions->finish($row->executionId, 'failed', 0, ['variables' => $row->state['variables'] ?? []],
                'Could not resume waiting execution: ' . $e->getMessage());
            $this->waits->delete($row->executionId);
            return;
        }
        try {
            $this->waits->markWaitingAgain($row->executionId, $row->reason);
            $this->waits->release($row->executionId, 30, $e->getMessage());
        } catch (\Throwable) {
            // the lease expires anyway
        }
    }
}
