<?php

declare(strict_types=1);

namespace Wps\Services;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Wps\Actions\ActionHandlerRegistry;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ExecutionException;
use Wps\Infrastructure\Database;
use Wps\Persistence\ExecutionRepository;
use Wps\Persistence\Process;
use Wps\Persistence\ProcessObjectRunRepository;
use Wps\Support\Uuid;

/**
 * Orchestrates one process run for one event (TS §5.4):
 *
 *  1. idempotency  SET NX idem:{event_id}:{process_id} — a redelivered event resumes/skips instead of re-running;
 *  2. conditions   object.* rows first; rows on variables trigger a lazy preload (PreparedRun);
 *     run_once     process flag: one start per CRM record — atomic mark in process_object_runs
 *                  (doc/idempotentie_plan.md), taken only when the conditions are met;
 *  3. execution    row in PostgreSQL + context Hash exec:{uuid} in Redis;
 *  4. steps        preload rows, then actions, strictly sequentially; after every step the context and
 *                  current_step are written to Redis and the step to the audit table;
 *  5. retry        recoverable errors (CRM timeout/5xx, network, Redis) — ACTION_RETRY_MAX retries with
 *                  exponential backoff; other errors fail the execution and stop the remaining steps;
 *  6. finish       status completed/failed + context snapshot in PostgreSQL, Redis state kept for 1h.
 *
 * Child processes (start_process) run in their own coroutines and are not awaited.
 * An interrupted execution (worker crash) is resumed from current_step by resume().
 */
final class ExecutionEngineService
{
    public function __construct(
        private readonly AppConfig $config,
        private readonly ActionHandlerRegistry $actions,
        private readonly ConditionEngineService $conditions,
        private readonly ExecutionRepository $executions,
        private readonly ProcessObjectRunRepository $runs,
        private readonly AuditService $audit,
        private readonly ExecutionStateStore $state,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Entry point for stream events and child processes.
     *
     * @return string "started" | "skipped" (conditions not met) | "skipped_run_once" (run_once: the record
     *                already has a mark) | "duplicate" (already handled / resumed)
     */
    public function handle(Process $process, ExecutionContext $context, string $trigger, ?string $parentId = null, ?string $idempotencyKey = null): string
    {
        $executionId = Uuid::v4();
        if ($idempotencyKey !== null && !$this->state->claimIdempotency($idempotencyKey, $executionId)) {
            $existing = $this->state->getIdempotency($idempotencyKey);
            if ($existing === null || $existing === ExecutionStateStore::SKIPPED) {
                return 'duplicate';
            }
            return $this->resumeOrRestart($existing, $process, $context, $trigger, $parentId, $idempotencyKey);
        }

        $prepared = $this->prepare($process, $context, false);
        if (!$prepared->shouldStart()) {
            if ($idempotencyKey !== null) {
                $this->state->setIdempotency($idempotencyKey, ExecutionStateStore::SKIPPED);
            }
            return 'skipped';
        }

        $runOnce = $this->claimRunOnce($process, $context, $executionId, $trigger);
        if ($runOnce['blocked']) {
            if ($idempotencyKey !== null) {
                $this->state->setIdempotency($idempotencyKey, ExecutionStateStore::SKIPPED);
            }
            return 'skipped_run_once';
        }

        $handle = $this->beginClaimed($process, $context, $prepared, $trigger, $parentId, $executionId, $runOnce);
        $this->run($handle);
        return 'started';
    }

    /**
     * Manual run from the UI: conditions are checked synchronously, the steps run in the background.
     *
     * @param array<string, mixed> $object
     * "force" skips the conditions only: it does not bypass run_once (a mark is reset through the API).
     *
     * @return array{conditions_met: bool, execution_id: string|null, conditions: list<array<string, mixed>>,
     *               run_once_applied: bool, run_once_blocked: bool, previous_execution_id: string|null}
     */
    public function runManual(Process $process, array $object, bool $force): array
    {
        $module = (string)($object['module'] ?? $object['object_type'] ?? $process->objectType);
        $context = ExecutionContext::fromEvent('manual-' . Uuid::v4(), $object, $module);
        $result = ['conditions_met' => false, 'execution_id' => null, 'conditions' => [],
            'run_once_applied' => false, 'run_once_blocked' => false, 'previous_execution_id' => null];

        $prepared = $this->prepare($process, $context, $force);
        $result['conditions'] = $prepared->trace;
        if (!$prepared->shouldStart()) {
            return $result;
        }
        $result['conditions_met'] = true;

        $executionId = Uuid::v4();
        $runOnce = $this->claimRunOnce($process, $context, $executionId, 'manual');
        $result['run_once_applied'] = $runOnce['applied'];
        if ($runOnce['blocked']) {
            $result['run_once_blocked'] = true;
            $result['previous_execution_id'] = $runOnce['previous'];
            return $result;
        }

        $handle = $this->beginClaimed($process, $context, $prepared, 'manual', null, $executionId, $runOnce);
        $this->runInBackground($handle);
        $result['execution_id'] = $handle->id;
        return $result;
    }

    /**
     * run_once (doc/idempotentie_plan.md): atomically takes the slot "process × CRM record" before the start.
     * The record is the object of the event — its "id" is the id of the saved CRM record (for a child
     * process: the record of the parent event). Without an id the flag is not applied (accepted limitation).
     *
     * @return array{applied: bool, blocked: bool, previous: string|null, object_type: string, object_id: string}
     */
    private function claimRunOnce(Process $process, ExecutionContext $context, string $executionId, string $trigger): array
    {
        $objectType = (string)($context->getModule(ExecutionContext::OBJECT) ?? $process->objectType);
        $rawId = $context->event['id'] ?? null;
        $objectId = is_scalar($rawId) ? trim((string)$rawId) : '';
        $result = ['applied' => false, 'blocked' => false, 'previous' => null, 'object_type' => $objectType, 'object_id' => $objectId];
        if (!$process->runOnce) {
            return $result; // no DB query for ordinary processes
        }
        if ($objectId === '') {
            $this->metrics->inc(MetricsService::RUN_ONCE_NO_ID);
            $this->logger->warning('run_once ignored: object has no id', [
                'process_id' => $process->id, 'event_id' => $context->eventId, 'trigger' => $trigger,
            ]);
            return $result;
        }
        $result['applied'] = true;
        if ($this->runs->claim($process->id, $objectType, $objectId, $executionId, $context->eventId, $trigger)) {
            return $result;
        }

        $mark = $this->runs->registerBlocked($process->id, $objectType, $objectId);
        $result['blocked'] = true;
        $result['previous'] = $mark['execution_id'] ?? null;
        $this->metrics->inc(MetricsService::RUN_ONCE_SKIPPED);
        $this->logger->info('Process skipped: run_once', [
            'process_id' => $process->id, 'object_type' => $objectType, 'object_id' => $objectId,
            'previous_execution_id' => $result['previous'], 'event_id' => $context->eventId, 'trigger' => $trigger,
        ]);
        return $result;
    }

    /**
     * begin() with compensation: when the execution row could not be created, the run_once mark taken for it
     * is removed — otherwise the record would stay blocked without any execution in the audit.
     *
     * @param array{applied: bool, blocked: bool, previous: string|null, object_type: string, object_id: string} $runOnce
     */
    private function beginClaimed(Process $process, ExecutionContext $context, PreparedRun $prepared, string $trigger, ?string $parentId, string $executionId, array $runOnce): ExecutionHandle
    {
        try {
            return $this->begin($process, $context, $prepared, $trigger, $parentId, $executionId);
        } catch (\Throwable $e) {
            if ($runOnce['applied'] && $this->executions->find($executionId) === null) {
                try {
                    $this->runs->release($process->id, $runOnce['object_type'], $runOnce['object_id']);
                } catch (\Throwable $releaseError) {
                    $this->logger->error('run_once mark could not be released', [
                        'process_id' => $process->id, 'object_id' => $runOnce['object_id'], 'error' => $releaseError,
                    ]);
                }
            }
            throw $e;
        }
    }

    /**
     * start_process: launches a child process in a new coroutine without waiting for it.
     */
    public function spawnChild(Process $child, ExecutionContext $parentContext): void
    {
        $parentId = $parentContext->executionId;
        $childContext = $parentContext->forChild();
        // one child per (parent execution, step, process) even if the parent step is re-executed after recovery
        $key = 'idem:' . ($parentId ?? $parentContext->eventId) . '.' . $parentContext->stepIndex . ':' . $child->id;

        Coroutine::create(function () use ($child, $childContext, $parentId, $key): void {
            try {
                $outcome = $this->handle($child, $childContext, 'child', $parentId, $key);
                $this->logger->info('Child process handled', [
                    'process_id' => $child->id, 'parent_execution_id' => $parentId, 'outcome' => $outcome,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Child process crashed', [
                    'process_id' => $child->id, 'parent_execution_id' => $parentId, 'error' => $e,
                ]);
            }
        });
    }

    /**
     * Continues an interrupted execution from its Redis state (claim-based recovery, TS §10.4).
     *
     * @return bool true when there is nothing left to do for this execution
     */
    public function resume(string $executionId): bool
    {
        $state = $this->state->load($executionId);
        if ($state === null) {
            return false;
        }
        $status = (string)($state['status'] ?? '');
        if ($status !== 'running' && $status !== 'retrying') {
            return true;
        }
        $token = Uuid::v4();
        if (!$this->state->acquireLock($executionId, $token)) {
            return false; // somebody is still working on it
        }

        $processData = (array)($state['process'] ?? []);
        $process = Process::fromArray($processData + ['definition' => $state['definition'] ?? []]);
        $context = ExecutionContext::fromArray($state);
        $handle = new ExecutionHandle(
            id: $executionId,
            process: $process,
            steps: $process->steps(),
            context: $context,
            trigger: (string)($state['trigger'] ?? 'stream'),
            parentId: ($state['parent_execution_id'] ?? '') !== '' ? (string)$state['parent_execution_id'] : null,
            lockToken: $token,
            currentStep: (int)($state['current_step'] ?? 0),
            retryCount: (int)($state['retry_count'] ?? 0),
            conditionTrace: (array)($state['conditions'] ?? []),
            startedAt: (float)($state['started_ts'] ?? microtime(true)),
        );
        $this->executions->create([
            'id' => $executionId,
            'process_id' => $process->id,
            'process_version' => $process->version,
            'parent_execution_id' => $handle->parentId,
            'trigger' => $handle->trigger,
            'event_id' => $context->eventId,
            'object_type' => (string)($context->getModule(ExecutionContext::OBJECT) ?? $process->objectType),
            'object_id' => (string)($context->event['id'] ?? ''),
        ]);
        $this->logger->info('Resuming interrupted execution', ['execution_id' => $executionId, 'step' => $handle->currentStep]);
        $this->run($handle);
        return true;
    }

    /**
     * Marks an unfinished execution whose Redis state is gone as failed (cannot be resumed).
     */
    public function markLost(string $executionId, string $reason): void
    {
        $this->executions->finish($executionId, 'failed', 0, ['lost' => true], $reason);
        $this->metrics->inc(MetricsService::EXECUTIONS_FAILED);
        $this->logger->warning('Execution state lost', ['execution_id' => $executionId, 'reason' => $reason]);
    }

    public function prepare(Process $process, ExecutionContext $context, bool $force): PreparedRun
    {
        if ($force) {
            return new PreparedRun(true, []);
        }
        $conditions = $process->definition['conditions'];
        $first = $this->conditions->evaluate($conditions, $context, allowUnknown: true);
        if (!$first->isUnknown()) {
            return new PreparedRun($first->isMet(), $first->trace);
        }

        // some rows use variables: run the preload block now and evaluate the rest
        $records = [];
        foreach ($process->definition['preload'] as $index => $row) {
            $record = $this->executeStep($index, ['type' => 'preload'] + (array)$row, $context, null, $process);
            $records[] = $record;
            if ($record->failed()) {
                return new PreparedRun(false, $first->trace, $records, $record);
            }
        }
        $second = $this->conditions->evaluate($conditions, $context);
        return new PreparedRun($second->isMet(), $second->trace, $records);
    }

    private function begin(Process $process, ExecutionContext $context, PreparedRun $prepared, string $trigger, ?string $parentId, string $executionId): ExecutionHandle
    {
        $handle = new ExecutionHandle(
            id: $executionId,
            process: $process,
            steps: $process->steps(),
            context: $context,
            trigger: $trigger,
            parentId: $parentId,
            lockToken: Uuid::v4(),
            currentStep: 0,
            retryCount: 0,
            conditionTrace: $prepared->trace,
            startedAt: microtime(true),
        );
        $context->executionId = $executionId;
        $context->processId = $process->id;

        $this->executions->create([
            'id' => $executionId,
            'process_id' => $process->id,
            'process_version' => $process->version,
            'parent_execution_id' => $parentId,
            'trigger' => $trigger,
            'event_id' => $context->eventId,
            'object_type' => (string)($context->getModule(ExecutionContext::OBJECT) ?? $process->objectType),
            'object_id' => (string)($context->event['id'] ?? ''),
        ]);
        $this->state->acquireLock($executionId, $handle->lockToken);
        $this->state->create($executionId, [
            'status' => 'running',
            'current_step' => 0,
            'retry_count' => 0,
            'trigger' => $trigger,
            'parent_execution_id' => $parentId ?? '',
            'process' => ['id' => $process->id, 'name' => $process->name, 'object_type' => $process->objectType,
                'is_child' => $process->isChild, 'is_active' => $process->isActive, 'priority' => $process->priority,
                'run_once' => $process->runOnce, 'version' => $process->version],
            'definition' => $process->definition,
            'conditions' => $prepared->trace,
            'started_at' => gmdate(\DATE_ATOM),
            'started_ts' => sprintf('%.6F', $handle->startedAt),
        ] + $this->contextFields($context));

        $this->metrics->inc(MetricsService::PROCESSES_STARTED);
        $this->logger->info('Process started', [
            'execution_id' => $executionId, 'process_id' => $process->id, 'event_id' => $context->eventId,
            'object_type' => $process->objectType, 'trigger' => $trigger, 'step' => 0,
        ]);

        foreach ($prepared->preloadSteps as $record) {
            $this->audit->recordStep($executionId, $record);
            $handle->currentStep = $record->stepIndex + 1;
        }
        if ($prepared->preloadSteps !== []) {
            $this->saveProgress($handle);
        }
        if ($prepared->failedStep !== null) {
            $this->skipRemaining($handle, $handle->currentStep);
            $this->finish($handle, 'failed', $prepared->failedStep->error);
        }
        return $handle;
    }

    public function run(ExecutionHandle $handle): void
    {
        if ($handle->finished) {
            return;
        }
        $context = $handle->context;
        $context->executionId = $handle->id;
        $context->processId = $handle->process->id;

        $count = count($handle->steps);
        for ($index = $handle->currentStep; $index < $count; $index++) {
            $record = $this->executeStep($index, $handle->steps[$index], $context, $handle, $handle->process);
            $this->audit->recordStep($handle->id, $record);
            $handle->currentStep = $index + 1;
            $this->saveProgress($handle);

            if ($record->failed()) {
                $this->skipRemaining($handle, $index + 1);
                $this->finish($handle, 'failed', $record->error);
                return;
            }
        }
        $this->finish($handle, 'completed', null);
    }

    private function runInBackground(ExecutionHandle $handle): void
    {
        Coroutine::create(function () use ($handle): void {
            try {
                $this->run($handle);
            } catch (\Throwable $e) {
                // the execution stays unfinished; RecoveryJob resumes it once the lock expires
                $this->logger->error('Execution interrupted', ['execution_id' => $handle->id, 'error' => $e]);
            }
        });
    }

    /**
     * @param array<string, mixed> $step
     */
    private function executeStep(int $index, array $step, ExecutionContext $context, ?ExecutionHandle $handle, Process $process): StepRecord
    {
        $type = (string)($step['type'] ?? '');
        $startedAt = microtime(true);
        $attempts = 0;

        while (true) {
            $attempts++;
            if ($handle !== null) {
                $this->state->refreshLock($handle->id, $handle->lockToken);
            }
            $context->stepIndex = $index;
            $snapshot = $context->snapshotState();

            try {
                $result = $this->actions->get($type)->execute($context, $step);
                return new StepRecord(
                    stepIndex: $index,
                    actionType: $type,
                    status: $result->status,
                    attempts: $attempts,
                    startedAt: $startedAt,
                    durationMs: self::elapsedMs($startedAt),
                    request: $result->request,
                    result: $result->result,
                );
            } catch (\Throwable $e) {
                $context->restoreState($snapshot);
                $recoverable = self::isRecoverable($e);

                if ($recoverable && $attempts <= $this->config->retryMax) {
                    $delayMs = (int)round($this->config->retryBaseDelayMs * ($this->config->retryBackoff ** ($attempts - 1)));
                    $this->logger->warning('Step failed, retrying', [
                        'execution_id' => $handle?->id, 'process_id' => $process->id, 'step' => $index,
                        'action' => $type, 'attempt' => $attempts, 'delay_ms' => $delayMs, 'error' => $e->getMessage(),
                    ]);
                    $this->metrics->inc(MetricsService::RETRY_COUNT);
                    if ($handle !== null) {
                        $handle->retryCount++;
                        $this->setStatus($handle, 'retrying');
                    }
                    Coroutine::sleep($delayMs / 1000);
                    if ($handle !== null) {
                        $this->setStatus($handle, 'running');
                    }
                    continue;
                }

                $this->logger->warning('Step failed', [
                    'execution_id' => $handle?->id, 'process_id' => $process->id, 'step' => $index,
                    'action' => $type, 'attempts' => $attempts, 'recoverable' => $recoverable, 'error' => $e->getMessage(),
                ]);
                $details = $e instanceof ExecutionException ? $e->getDetails() : [];
                return new StepRecord(
                    stepIndex: $index,
                    actionType: $type !== '' ? $type : 'unknown',
                    status: 'failed',
                    attempts: $attempts,
                    startedAt: $startedAt,
                    durationMs: self::elapsedMs($startedAt),
                    request: $step,
                    result: ['error_class' => (new \ReflectionClass($e))->getShortName(), 'recoverable' => $recoverable] + ($details !== [] ? ['details' => $details] : []),
                    error: $e->getMessage(),
                    recoverable: $recoverable,
                );
            }
        }
    }

    private function skipRemaining(ExecutionHandle $handle, int $from): void
    {
        $count = count($handle->steps);
        for ($i = $from; $i < $count; $i++) {
            $this->audit->recordStep($handle->id, new StepRecord(
                stepIndex: $i,
                actionType: (string)($handle->steps[$i]['type'] ?? 'unknown'),
                status: 'skipped',
                attempts: 0,
                startedAt: microtime(true),
                durationMs: 0,
                request: $handle->steps[$i],
                result: ['reason' => 'Not executed: a previous step failed'],
            ));
        }
    }

    private function finish(ExecutionHandle $handle, string $status, ?string $error): void
    {
        $snapshot = [
            'event' => $handle->context->event,
            'variables' => $handle->context->variables(),
            'meta' => $handle->context->meta(),
            'conditions' => $handle->conditionTrace,
        ];
        $this->executions->finish($handle->id, $status, $handle->retryCount, $snapshot, $error);
        $this->state->finish($handle->id, $status);
        $this->state->releaseLock($handle->id, $handle->lockToken);
        $handle->finished = true;

        $duration = microtime(true) - $handle->startedAt;
        $this->metrics->inc($status === 'completed' ? MetricsService::EXECUTIONS_COMPLETED : MetricsService::EXECUTIONS_FAILED);
        $this->metrics->observe(MetricsService::EXECUTION_DURATION, $duration);
        $this->logger->info($status === 'completed' ? 'Process completed' : 'Process failed', [
            'execution_id' => $handle->id, 'process_id' => $handle->process->id, 'event_id' => $handle->context->eventId,
            'object_type' => $handle->process->objectType, 'step' => $handle->currentStep,
            'duration_ms' => (int)round($duration * 1000), 'error' => $error,
        ]);
    }

    private function saveProgress(ExecutionHandle $handle): void
    {
        $this->state->update($handle->id, ['current_step' => $handle->currentStep] + $this->contextFields($handle->context));
    }

    private function setStatus(ExecutionHandle $handle, string $status): void
    {
        $this->executions->updateStatus($handle->id, $status, $handle->retryCount);
        $this->state->update($handle->id, ['status' => $status, 'retry_count' => $handle->retryCount]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFields(ExecutionContext $context): array
    {
        $data = $context->toArray();
        return [
            'event_id' => $data['event_id'],
            'event' => $data['event'],
            'variables' => $data['variables'],
            'meta' => $data['meta'],
            'dirty' => $data['dirty'],
            'depth' => $data['depth'],
        ];
    }

    private function resumeOrRestart(string $executionId, Process $process, ExecutionContext $context, string $trigger, ?string $parentId, string $key): string
    {
        $state = $this->state->load($executionId);
        if ($state !== null) {
            $this->resume($executionId);
            return 'duplicate';
        }
        $row = $this->executions->status($executionId);
        if ($row !== null && $row['finished']) {
            return 'duplicate';
        }
        if ($row !== null) {
            $this->markLost($executionId, 'Execution state lost after a crash; the event was processed again');
        }
        // the previous attempt never got far enough to be resumed — start over (TS §10.4)
        $newId = Uuid::v4();
        $this->state->setIdempotency($key, $newId);
        $prepared = $this->prepare($process, $context, false);
        if (!$prepared->shouldStart()) {
            $this->state->setIdempotency($key, ExecutionStateStore::SKIPPED);
            return 'skipped';
        }
        $this->run($this->begin($process, $context, $prepared, $trigger, $parentId, $newId));
        return 'started';
    }

    private static function isRecoverable(\Throwable $e): bool
    {
        if ($e instanceof ExecutionException) {
            return $e->isRecoverable();
        }
        if ($e instanceof \RedisException) {
            return true;
        }
        return Database::isConnectionError($e);
    }

    private static function elapsedMs(float $since): int
    {
        return (int)round((microtime(true) - $since) * 1000);
    }
}
