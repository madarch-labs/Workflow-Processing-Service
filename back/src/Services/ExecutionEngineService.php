<?php

declare(strict_types=1);

namespace Wps\Services;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Wps\Actions\ActionHandlerRegistry;
use Wps\Actions\ActionResult;
use Wps\Actions\ResumableActionInterface;
use Wps\Config\AppConfig;
use Wps\Context\ExecutionContext;
use Wps\Crm\CrmCallOrigin;
use Wps\Exceptions\ConditionCastException;
use Wps\Exceptions\ExecutionException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Persistence\ExecutionRepository;
use Wps\Persistence\Process;
use Wps\Persistence\ProcessObjectRunRepository;
use Wps\Program\Instruction;
use Wps\Program\Interpreter;
use Wps\Program\LoopRunner;
use Wps\Program\ProgramCompiler;
use Wps\Secrets\SecretMasker;
use Wps\Services\Errors\ErrorClassifier;
use Wps\Services\Errors\ErrorPolicy;
use Wps\Services\Errors\RetryPolicy;
use Wps\Support\Uuid;
use Wps\Template\TemplateEngine;
use Wps\Time\Clock;
use Wps\Time\SystemClock;

/**
 * Orchestrates one process run for one event (TS §5.4):
 *
 *  1. idempotency  SET NX idem:{event_id}:{process_id} — a redelivered event resumes/skips instead of re-running;
 *  2. conditions   object.* rows first; rows on variables trigger a lazy preload (PreparedRun);
 *     run_once     process flag: one start per CRM record — atomic mark in process_object_runs
 *                  (md/idempotentie_plan.md), taken only when the conditions are met;
 *  3. execution    row in PostgreSQL + context Hash exec:{uuid} in Redis;
 *  4. program      the definition is compiled into a flat program (preload rows, then actions; blocks become
 *                  jumps — md/tasks_list/done/0.1-program-compiler.md); instructions run one by one, after every one
 *                  the context, pc (current_step), seq and loop frames are written to Redis and the step to the audit;
 *  5. retry        recoverable errors (CRM timeout/5xx, network, Redis) — ACTION_RETRY_MAX retries with
 *                  exponential backoff; other errors fail the execution and stop the remaining steps;
 *  6. finish       status completed/failed + context snapshot in PostgreSQL, Redis state kept for 1h.
 *
 * Child processes (start_process) run in their own coroutines and are not awaited.
 * An interrupted execution (worker crash) is resumed from current_step (pc) by resume().
 */
final class ExecutionEngineService
{
    /** retry delays above this are logged: they should be parked (7.2) */
    private const INLINE_RETRY_WARN_MS = 5000;
    /** the execution lock is refreshed at least this often while a retry delay runs */
    private const LOCK_REFRESH_MS = 5000;

    private readonly Interpreter $interpreter;
    private readonly Clock $clock;

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
        private readonly ProgramCompiler $compiler,
        ?TemplateEngine $templates = null,
        /** @var (\Closure(): WaitService)|null lazy: parking of waiting executions (7.2) */
        private readonly ?\Closure $waits = null,
        private readonly ?LoopRunner $loops = null,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->interpreter = new Interpreter(
            $conditions,
            fn (Instruction $instruction, int $pc, int $seq, ExecutionContext $context, mixed $handle): StepRecord
                => $this->executeAction($instruction, $pc, $seq, $context, $handle, $handle->process),
            $templates,
            $loops,
        );
    }

    /**
     * Entry point for stream events and child processes.
     *
     * @return string "started" | "skipped" (conditions not met) | "skipped_run_once" (run_once: the record
     *                already has a mark) | "duplicate" (already handled / resumed) | "cast_error" (a strict
     *                condition could not cast its value — recorded as a failed execution)
     */
    public function handle(Process $process, ExecutionContext $context, string $trigger, ?string $parentId = null, ?string $idempotencyKey = null): string
    {
        $this->configureContext($context, $process, $trigger, $parentId);
        $executionId = Uuid::v4();
        if ($idempotencyKey !== null && !$this->state->claimIdempotency($idempotencyKey, $executionId)) {
            $existing = $this->state->getIdempotency($idempotencyKey);
            if ($existing === null || $existing === ExecutionStateStore::SKIPPED) {
                return 'duplicate';
            }
            return $this->resumeOrRestart($existing, $process, $context, $trigger, $parentId, $idempotencyKey);
        }

        $prepared = $this->prepare($process, $context, false);
        if ($prepared->castError !== null) {
            $this->recordConditionFailure($process, $context, $prepared, $trigger, $parentId, $executionId);
            return 'cast_error';
        }
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
        $this->configureContext($context, $process, 'manual', null);
        $result = ['conditions_met' => false, 'execution_id' => null, 'conditions' => [],
            'run_once_applied' => false, 'run_once_blocked' => false, 'previous_execution_id' => null];

        $prepared = $this->prepare($process, $context, $force);
        $result['conditions'] = $prepared->trace;
        if ($prepared->castError !== null) {
            return $result + ['error' => 'CAST_ERROR', 'error_message' => $prepared->castError->getMessage()];
        }
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
     * run_once (md/idempotentie_plan.md): atomically takes the slot "process × CRM record" before the start.
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

        // the program is compiled from the definition snapshot of this execution, not from the current process;
        // executions started before the flat program have no seq / frames: their programs are linear (seq == pc)
        $handle = $this->handleFromState($executionId, $state, $token);
        $process = $handle->process;
        $context = $handle->context;
        $program = $handle->program;
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
        if (isset($state['program_hash']) && $state['program_hash'] !== '' && $state['program_hash'] !== $program->hash) {
            // the compiler changed between releases: continuing could run the wrong instruction
            $this->metrics->inc(MetricsService::PROGRAM_RESUME_MISMATCH);
            $this->logger->error('Program changed after an upgrade', ['execution_id' => $executionId, 'pc' => $handle->pc]);
            $this->finish($handle, 'failed', 'Program changed after an upgrade; the execution cannot be resumed safely');
            return true;
        }
        $this->logger->info('Resuming interrupted execution', ['execution_id' => $executionId, 'pc' => $handle->pc, 'seq' => $handle->seq]);
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
        // one "now" for both passes of the lazy preload (relative dates, 3.7)
        $now = $this->conditions->now();
        try {
            $first = $this->conditions->evaluate($conditions, $context, allowUnknown: true, now: $now);
        } catch (ConditionCastException $e) {
            return new PreparedRun(false, [$e->getDetails()], castError: $e);
        }
        if (!$first->isUnknown()) {
            return new PreparedRun($first->isMet(), $first->trace);
        }

        // some rows use variables: run the preload block now (the first instructions of the program) and evaluate the rest
        $records = [];
        $program = $this->compiler->compile($process->definition);
        foreach (array_keys($process->definition['preload']) as $index) {
            $record = $this->executeAction($program->at($index), $index, $index, $context, null, $process);
            $records[] = $record;
            if ($record->failed()) {
                return new PreparedRun(false, $first->trace, $records, $record);
            }
        }
        try {
            $second = $this->conditions->evaluate($conditions, $context, now: $now);
        } catch (ConditionCastException $e) {
            return new PreparedRun(false, [$e->getDetails()], $records, castError: $e);
        }
        return new PreparedRun($second->isMet(), $second->trace, $records);
    }

    /**
     * Strict condition ("on_cast_error": "error") could not cast a value: the run is recorded as a failed execution
     * with a "conditions" step, so the author sees it in the journal; the event itself is acknowledged.
     */
    private function recordConditionFailure(Process $process, ExecutionContext $context, PreparedRun $prepared, string $trigger, ?string $parentId, string $executionId): void
    {
        $error = (string)$prepared->castError?->getMessage();
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
        $index = 0;
        foreach ($prepared->preloadSteps as $record) {
            $this->audit->recordStep($executionId, $record);
            $index = $record->stepIndex + 1;
        }
        $this->audit->recordStep($executionId, new StepRecord(
            stepIndex: $index,
            actionType: 'conditions',
            status: 'failed',
            attempts: 1,
            startedAt: microtime(true),
            durationMs: 0,
            request: ['conditions' => $process->definition['conditions']],
            result: ['trace' => $prepared->trace, 'error_class' => 'ConditionCastException', 'recoverable' => false],
            error: $error,
        ));
        $this->executions->finish($executionId, 'failed', 0, [
            'event' => $context->event,
            'variables' => $context->variables(),
            'meta' => $context->meta(),
            'event_info' => $context->eventInfo->toArray(),
            'conditions' => $prepared->trace,
            'system' => $context->systemValues(),
        ], $error);
        $this->metrics->inc(MetricsService::EXECUTIONS_FAILED);
        $this->logger->error('Process failed on conditions', [
            'execution_id' => $executionId, 'process_id' => $process->id, 'event_id' => $context->eventId, 'error' => $error,
        ]);
    }

    private function begin(Process $process, ExecutionContext $context, PreparedRun $prepared, string $trigger, ?string $parentId, string $executionId): ExecutionHandle
    {
        $handle = new ExecutionHandle(
            id: $executionId,
            process: $process,
            program: $this->compiler->compile($process->definition),
            context: $context,
            trigger: $trigger,
            parentId: $parentId,
            lockToken: Uuid::v4(),
            pc: 0,
            seq: 0,
            frames: [],
            retryCount: 0,
            conditionTrace: $prepared->trace,
            startedAt: microtime(true),
        );
        $context->executionId = $executionId;
        $context->processId = $process->id;
        $context->trigger = $trigger;
        $context->parentExecutionId = $parentId ?? '';
        $context->processName = $process->name;
        $context->processVersion = $process->version;
        $context->setClock($this->clock);
        $context->setConstants($process->definition['constants'] ?? []);
        $now = $this->clock->now();
        $context->startedAt = $now->format('Y-m-d H:i:s');

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
            'seq' => 0,
            'frames' => [],
            'instructions_executed' => 0,
            'program_hash' => $handle->program->hash,
            'retry_count' => 0,
            'trigger' => $trigger,
            'parent_execution_id' => $parentId ?? '',
            'process' => ['id' => $process->id, 'name' => $process->name, 'object_type' => $process->objectType,
                'is_child' => $process->isChild, 'is_active' => $process->isActive, 'priority' => $process->priority,
                'run_once' => $process->runOnce, 'version' => $process->version],
            'definition' => $process->definition,
            'conditions' => $prepared->trace,
            'started_at' => $now->format(\DATE_ATOM),
            'started_ts' => sprintf('%.6F', $handle->startedAt),
        ] + $this->contextFields($context));

        $this->metrics->inc(MetricsService::PROCESSES_STARTED);
        $this->logger->info('Process started', [
            'execution_id' => $executionId, 'process_id' => $process->id, 'event_id' => $context->eventId,
            'object_type' => $process->objectType, 'trigger' => $trigger, 'pc' => 0,
        ]);

        // preload steps already executed for the lazy condition check are the first instructions: pc == seq == index
        foreach ($prepared->preloadSteps as $record) {
            $this->audit->recordStep($executionId, $record);
            $handle->pc = $handle->seq = $record->stepIndex + 1;
            $handle->instructionsExecuted++;
        }
        if ($prepared->preloadSteps !== []) {
            $this->saveProgress($handle);
        }
        if ($prepared->failedStep !== null) {
            $this->failFrom($handle, $prepared->failedStep->stepIndex, 'Not executed: a previous step failed');
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
        $context->trigger = $handle->trigger;
        $context->parentExecutionId = $handle->parentId ?? '';
        $context->processName = $handle->process->name;
        $context->processVersion = $handle->process->version;
        $context->setClock($this->clock);
        $context->setConstants($handle->process->definition['constants'] ?? []);

        $program = $handle->program;
        while ($handle->pc < $program->count()) {
            if ($handle->instructionsExecuted >= $this->config->maxInstructionsPerExecution) {
                // e.g. an endless goto loop (md/tasks_list/7.5-limits.md)
                $this->closeLoops($handle, 'failed');
                $this->failFrom($handle, max(0, $handle->pc - 1), 'Not executed: the execution limit was exceeded');
                $this->finish($handle, 'failed', "LIMIT_EXCEEDED: more than {$this->config->maxInstructionsPerExecution} instructions in one execution");
                return;
            }
            $pc = $handle->pc;
            $instruction = $program->at($pc);
            $outcome = $this->interpreter->step($instruction, $pc, $handle->seq, $context, $handle);
            $record = $outcome->record;
            $nextPc = $outcome->nextPc;
            $handledBy = null;
            if ($outcome->failed && $record !== null) {
                [$handledBy, $nextPc, $record] = $this->handleFailure($handle, $instruction, $pc, $record, $nextPc);
            }
            if ($record !== null) {
                $this->audit->recordStep($handle->id, $record);
                if (!$outcome->overwrite) {
                    $handle->seq++;
                }
                if ($instruction->op === Instruction::BRANCH && !$record->failed()) {
                    $this->metrics->inc(MetricsService::CONDITION_BRANCHES);
                    $this->logger->debug('Branch selected', ['execution_id' => $handle->id, 'pc' => $pc, 'step_id' => $instruction->stepId,
                        'selected' => $record->result['selected'] ?? null]);
                }
            }
            $handle->pc = $nextPc;
            $handle->instructionsExecuted++;
            $this->metrics->inc(MetricsService::INSTRUCTIONS_EXECUTED);
            if ($record !== null && $record->isWaiting()) {
                // the step parks the execution: it continues at the same pc after waking (7.2)
                $handle->pc = $pc;
                $this->saveProgress($handle);
                $waits = $this->waits !== null ? ($this->waits)() : throw new \LogicException('Waiting executions are not available');
                $wait = (array)$record->wait;
                try {
                    $waits->park($handle, new \DateTimeImmutable((string)$wait['due_at']), (string)$wait['reason'],
                        ['pc' => $pc, 'seq' => $record->stepIndex, 'mode' => $wait['reason'] === 'retry' ? 'retry' : 'after_wait',
                            'parked_at' => gmdate(\DATE_ATOM)] + (array)($wait['info'] ?? []));
                } catch (NonRecoverableException $e) {
                    $this->audit->recordStep($handle->id, new StepRecord($record->stepIndex, $record->actionType, 'failed', $record->attempts,
                        $record->startedAt, $record->durationMs, $record->request, $record->result, $e->getMessage(), pc: $pc,
                        path: $record->path, iteration: $record->iteration, stepId: $record->stepId));
                    $this->failFrom($handle, $pc, 'Not executed: a previous step failed');
                    $this->finish($handle, 'failed', $e->getMessage());
                }
                return;
            }
            $this->saveProgress($handle);

            if ($outcome->halt !== null) {
                $this->failFrom($handle, $pc, 'Not executed: the process was stopped by stop_process');
                $failed = $outcome->halt['status'] === 'failed';
                $code = $outcome->halt['code'] ?? null;
                $message = (string)($outcome->halt['message'] ?? '');
                $this->closeLoops($handle, 'success', ['stopped' => true]);
                $handle->stop = ['status' => $outcome->halt['status'], 'code' => $code, 'message' => $message];
                $this->metrics->inc(MetricsService::PROCESS_STOPPED);
                $this->logger->info('Process stopped', ['execution_id' => $handle->id, 'status' => $outcome->halt['status'], 'code' => $code]);
                $this->finish($handle, $failed ? 'failed' : 'completed', $failed
                    ? ($code !== null ? "[{$code}] " : '') . ($message !== '' ? $message : 'Stopped by stop_process')
                    : null);
                return;
            }
            if ($outcome->failed && $handledBy === null) {
                $this->closeLoops($handle, 'failed');
                $this->failFrom($handle, $pc, 'Not executed: a previous step failed');
                $this->finish($handle, 'failed', $outcome->record?->error);
                return;
            }
        }
        $this->finish($handle, 'completed', null);
    }

    /**
     * on_error of the failed step (md/tasks_list/done/7.1-on-error.md §4.1): "stop" keeps the former behaviour,
     * "continue" goes to the next instruction (after the whole block for a failed condition), {"goto": id} jumps
     * forward to a top-level step and leaves loops and branches. A handled error is kept in sys.last_error.
     *
     * @return array{0: string|null, 1: int, 2: StepRecord} [handled by, next pc, audit record]
     */
    private function handleFailure(ExecutionHandle $handle, Instruction $instruction, int $pc, StepRecord $record, int $nextPc): array
    {
        $config = $instruction->op === Instruction::ACTION ? $instruction->config : ['on_error' => $instruction->config['on_error'] ?? null];
        $policy = ErrorPolicy::fromStep($config);
        if ($policy->mode() === ErrorPolicy::STOP) {
            // inside foreach with on_item_error = continue the loop goes on with its next item (4.11)
            $continued = $this->loops !== null && $handle->frames !== [] ? $this->loops->itemError($handle, $instruction) : null;
            if ($continued === null) {
                return [null, $nextPc, $record];
            }
            [$nextPc, $closed] = $continued;
            foreach ($closed as $loopRecord) {
                $this->audit->recordStep($handle->id, $loopRecord);
            }
            $handle->context->recordHandledError(self::handledError($instruction, $record));
            $this->logger->warning('Loop item failed, the loop continues', [
                'execution_id' => $handle->id, 'process_id' => $handle->process->id, 'pc' => $pc, 'iteration' => $record->iteration,
                'code' => $record->errorCode,
            ]);
            return ['item_continue', $nextPc, $record->withHandled('item_continue', [])];
        }
        if ($policy->mode() === ErrorPolicy::GOTO) {
            $target = $handle->program->pcOf((string)$policy->target());
            if ($target === null || $target <= $pc) {
                // the validator forbids it; a definition saved before may still carry it
                return [null, $nextPc, $record];
            }
            $nextPc = $target;
            $this->closeLoops($handle, 'failed', ['left_by' => 'goto']);
            $handle->context->iteration = '';
        } elseif ($instruction->op === Instruction::BRANCH) {
            $nextPc = (int)($instruction->config['end_target'] ?? $nextPc);
        }
        $error = self::handledError($instruction, $record);
        $handle->context->recordHandledError($error);
        $this->metrics->inc(MetricsService::STEP_ERRORS_HANDLED);
        $this->logger->warning('Step error handled', [
            'execution_id' => $handle->id, 'process_id' => $handle->process->id, 'pc' => $pc, 'policy' => $policy->mode(),
            'target' => $policy->target(), 'code' => $error['code'],
        ]);
        return [$policy->mode(), $nextPc, $record->withHandled($policy->mode(), $policy->target() !== null ? ['goto' => $policy->target()] : [])];
    }

    /**
     * sys.last_error of a handled step error (7.1).
     *
     * @return array<string, mixed>
     */
    private static function handledError(Instruction $instruction, StepRecord $record): array
    {
        return [
            'step_id' => $instruction->stepId, 'step_path' => $instruction->path, 'action_type' => $record->actionType,
            'code' => $record->errorCode ?? (string)($record->result['error_class'] ?? 'ERROR'),
            'message' => $record->error, 'http_status' => $record->result['error']['http_status'] ?? null,
            'attempts' => $record->attempts, 'at' => gmdate(\DATE_ATOM),
        ] + ($record->iteration !== '' ? ['iteration' => $record->iteration] : []);
    }

    /**
     * Closes the open foreach loops (the execution fails, stops or leaves them with goto): their records get the totals.
     *
     * @param array<string, mixed> $extra
     */
    private function closeLoops(ExecutionHandle $handle, string $status, array $extra = []): void
    {
        if ($handle->frames === []) {
            return;
        }
        if ($this->loops === null) {
            $handle->frames = [];
            return;
        }
        foreach ($this->loops->closeAll($handle, $status, $extra) as $record) {
            $this->audit->recordStep($handle->id, $record);
        }
    }

    /**
     * Continues an execution woken by WaitService (the state is back in Redis, the lock is held): the waiting step is
     * finished by its handler (ResumableActionInterface) or marked successful; a parked retry runs the step again.
     *
     * @param array<string, mixed> $info pc, seq, mode (after_wait | retry), attempts, due_at, reason, parked_at …
     */
    public function resumeAfterWait(string $executionId, string $lockToken, array $info): void
    {
        $state = $this->state->load($executionId) ?? throw new \RuntimeException("State of {$executionId} is missing after waking");
        $handle = $this->handleFromState($executionId, $state, $lockToken);
        $pc = (int)($info['pc'] ?? $handle->pc);
        $seq = (int)($info['seq'] ?? $handle->seq);
        if (($info['mode'] ?? 'after_wait') === 'retry') {
            $handle->pc = $pc;
            $handle->seq = $seq; // the repeated attempt overwrites the "waiting" record
            $handle->retryAttemptsDone = (int)($info['attempts'] ?? 0);
            $this->run($handle);
            return;
        }
        $instruction = $handle->program->at($pc);
        $type = (string)($instruction->config['type'] ?? '');
        $startedAt = microtime(true);
        $waited = max(0, (int)round(microtime(true) - strtotime((string)($info['parked_at'] ?? 'now'))));
        try {
            $handler = $this->actions->has($type) ? $this->actions->get($type) : null;
            $result = $handler instanceof ResumableActionInterface
                ? $handler->resume($handle->context, $instruction->config, $info)
                : ActionResult::success([], []);
            $record = new StepRecord($seq, $type, $result->status, 1, $startedAt, self::elapsedMs($startedAt), $result->request,
                $result->result + ['waited_seconds' => $waited], pc: $pc, path: $instruction->path, iteration: $handle->context->iteration,
                stepId: $instruction->stepId);
        } catch (\Throwable $e) {
            $record = new StepRecord($seq, $type, 'failed', 1, $startedAt, self::elapsedMs($startedAt), [], ['waited_seconds' => $waited],
                $e->getMessage(), pc: $pc, path: $instruction->path, stepId: $instruction->stepId);
        }
        $this->audit->recordStep($executionId, $record);
        if ($record->failed()) {
            $this->failFrom($handle, $pc, 'Not executed: a previous step failed');
            $this->finish($handle, 'failed', $record->error);
            return;
        }
        $handle->pc = $pc + 1;
        $this->saveProgress($handle);
        $this->run($handle);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function handleFromState(string $executionId, array $state, string $token): ExecutionHandle
    {
        $processData = (array)($state['process'] ?? []);
        $process = Process::fromArray($processData + ['definition' => $state['definition'] ?? []]);
        $context = ExecutionContext::fromArray($state, $this->clock);
        $context->executionId = $executionId;
        $context->processId = $process->id;
        $context->processName = $process->name;
        $context->processVersion = $process->version;
        $context->trigger = (string)($state['trigger'] ?? 'stream');
        $context->parentExecutionId = ($state['parent_execution_id'] ?? '') !== '' ? (string)$state['parent_execution_id'] : '';
        $context->setConstants($process->definition['constants'] ?? []);
        $context->startedAt = self::startedAtFromState($state);
        $context->iteration = LoopRunner::iteration(array_values((array)($state['frames'] ?? [])));
        $currentStep = (int)($state['current_step'] ?? 0);
        return new ExecutionHandle(
            id: $executionId,
            process: $process,
            program: $this->compiler->compile($process->definition),
            context: $context,
            trigger: (string)($state['trigger'] ?? 'stream'),
            parentId: ($state['parent_execution_id'] ?? '') !== '' ? (string)$state['parent_execution_id'] : null,
            lockToken: $token,
            pc: $currentStep,
            seq: isset($state['seq']) ? (int)$state['seq'] : $currentStep,
            frames: array_values((array)($state['frames'] ?? [])),
            retryCount: (int)($state['retry_count'] ?? 0),
            conditionTrace: (array)($state['conditions'] ?? []),
            startedAt: (float)($state['started_ts'] ?? microtime(true)),
            instructionsExecuted: (int)($state['instructions_executed'] ?? 0),
        );
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
     * Runs one "action" instruction with retries of recoverable errors.
     */
    private function executeAction(Instruction $instruction, int $pc, int $seq, ExecutionContext $context, ?ExecutionHandle $handle, Process $process): StepRecord
    {
        $step = $instruction->config;
        $index = $seq;
        $type = (string)($step['type'] ?? '');
        $startedAt = microtime(true);
        // a step woken after a long retry delay continues counting its attempts (7.1 + 7.2)
        $attempts = $handle !== null ? $handle->retryAttemptsDone : 0;
        if ($handle !== null) {
            $handle->retryAttemptsDone = 0;
        }
        // the same token for all retries of this step; a repeated step after a crash gets a new one (0.4)
        $context->attemptToken = Uuid::v4();
        $policy = null;

        while (true) {
            $attempts++;
            if ($handle !== null) {
                $this->state->refreshLock($handle->id, $handle->lockToken);
            }
            $context->stepIndex = $seq;
            $context->pc = $pc;
            // secrets resolved by the step register here; nothing of them may reach the audit (7.4)
            $masker = $context->secretMasker = new SecretMasker();
            // CRM saves of this step carry X-WPS-* headers → "wps_origin" of the events they cause (0.2, 7.5)
            CrmCallOrigin::set($context->executionId, $context->processId, $context->depth);
            $snapshot = $context->snapshotState();

            try {
                $result = $this->actions->get($type)->execute($context, $step);
                if ($result->status === ActionResult::WAITING) {
                    if ($handle === null || $result->dueAt === null) {
                        throw new NonRecoverableException('This step cannot wait here (condition evaluation or a test run)');
                    }
                    return new StepRecord(
                        stepIndex: $index,
                        actionType: $type,
                        status: ActionResult::WAITING,
                        attempts: $attempts,
                        startedAt: $startedAt,
                        durationMs: self::elapsedMs($startedAt),
                        request: $masker->mask($result->request),
                        result: $masker->mask($result->result) + ['wait' => ['due_at' => $result->dueAt->format(\DATE_ATOM), 'reason' => (string)$result->waitReason]],
                        pc: $pc,
                        path: $instruction->path,
                        iteration: $context->iteration,
                        stepId: $instruction->stepId,
                        wait: ['due_at' => $result->dueAt->format(\DATE_ATOM), 'reason' => (string)$result->waitReason, 'info' => $result->waitInfo],
                    );
                }
                return new StepRecord(
                    stepIndex: $index,
                    actionType: $type,
                    status: $result->status,
                    attempts: $attempts,
                    startedAt: $startedAt,
                    durationMs: self::elapsedMs($startedAt),
                    request: $masker->mask($result->request),
                    result: $masker->mask($result->result),
                    pc: $pc,
                    path: $instruction->path,
                    iteration: $context->iteration,
                    stepId: $instruction->stepId,
                );
            } catch (\Throwable $e) {
                $context->restoreState($snapshot);
                $error = ErrorClassifier::classify($e);
                $policy ??= RetryPolicy::fromStep($step, $this->config);

                if ($policy->shouldRetry($error, $attempts)) {
                    $delayMs = $policy->delayMs($attempts, $error->retryAfterSeconds);
                    $waits = $this->waits !== null ? ($this->waits)() : null;
                    if ($handle !== null && $waits !== null && $delayMs > $this->config->waitInlineMaxSeconds * 1000) {
                        // a long retry delay parks the execution instead of holding the worker (7.1 §4.3)
                        $dueAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('+%d milliseconds', $delayMs));
                        $handle->retryCount++;
                        $this->metrics->inc(MetricsService::RETRY_COUNT);
                        $this->logger->warning('Step failed, retry parked', [
                            'execution_id' => $handle->id, 'pc' => $pc, 'action' => $type, 'attempt' => $attempts, 'delay_ms' => $delayMs,
                            'code' => $error->code, 'error' => $masker->mask($e->getMessage()),
                        ]);
                        return new StepRecord(
                            stepIndex: $index,
                            actionType: $type,
                            status: ActionResult::WAITING,
                            attempts: $attempts,
                            startedAt: $startedAt,
                            durationMs: self::elapsedMs($startedAt),
                            request: $masker->mask($step),
                            result: ['retry' => ['attempt' => $attempts, 'next_at' => $dueAt->format(\DATE_ATOM), 'error' => $masker->mask($error->toArray())],
                                'wait' => ['due_at' => $dueAt->format(\DATE_ATOM), 'reason' => 'retry']],
                            pc: $pc,
                            path: $instruction->path,
                            iteration: $context->iteration,
                            stepId: $instruction->stepId,
                            wait: ['due_at' => $dueAt->format(\DATE_ATOM), 'reason' => 'retry', 'info' => ['attempts' => $attempts]],
                        );
                    }
                    $this->logger->warning('Step failed, retrying', [
                        'execution_id' => $handle?->id, 'process_id' => $process->id, 'pc' => $pc, 'seq' => $index,
                        'action' => $type, 'attempt' => $attempts, 'delay_ms' => $delayMs, 'code' => $error->code,
                        'retry_after' => $error->retryAfterSeconds, 'error' => $masker->mask($e->getMessage()),
                    ]);
                    $this->metrics->inc(MetricsService::RETRY_COUNT);
                    if ($handle !== null) {
                        $handle->retryCount++;
                        $this->setStatus($handle, 'retrying');
                    }
                    $this->sleepKeepingLock($delayMs, $handle);
                    if ($handle !== null) {
                        $this->setStatus($handle, 'running');
                    }
                    continue;
                }

                $this->logger->warning('Step failed', [
                    'execution_id' => $handle?->id, 'process_id' => $process->id, 'pc' => $pc, 'seq' => $index,
                    'action' => $type, 'attempts' => $attempts, 'code' => $error->code, 'recoverable' => $error->transient,
                    'error' => $masker->mask($e->getMessage()),
                ]);
                $details = $masker->mask($e instanceof ExecutionException ? $e->getDetails() : []);
                return new StepRecord(
                    stepIndex: $index,
                    actionType: $type !== '' ? $type : 'unknown',
                    status: 'failed',
                    attempts: $attempts,
                    startedAt: $startedAt,
                    durationMs: self::elapsedMs($startedAt),
                    request: $masker->mask($step),
                    result: ['error_class' => (new \ReflectionClass($e))->getShortName(), 'recoverable' => $error->transient,
                        'error_code' => $error->code, 'error' => $masker->mask($error->toArray())]
                        + ($details !== [] ? ['details' => $details] : []),
                    error: (string)$masker->mask($e->getMessage()),
                    recoverable: $error->transient,
                    pc: $pc,
                    path: $instruction->path,
                    iteration: $context->iteration,
                    stepId: $instruction->stepId,
                    errorCode: $error->code,
                );
            }
        }
    }

    /**
     * Retry delay inside the coroutine; the lock is refreshed so that RecoveryJob does not take the execution over.
     * Long delays should park the execution (waits, md/tasks_list/7.2-waiting-executions.md) — until then they run
     * here as well.
     */
    private function sleepKeepingLock(int $delayMs, ?ExecutionHandle $handle): void
    {
        if ($delayMs > self::INLINE_RETRY_WARN_MS) {
            $this->logger->warning('Long retry delay runs inside the worker until waiting executions are available', [
                'execution_id' => $handle?->id, 'delay_ms' => $delayMs,
            ]);
        }
        $left = $delayMs;
        while ($left > 0) {
            $chunk = min($left, self::LOCK_REFRESH_MS);
            Coroutine::sleep($chunk / 1000);
            $left -= $chunk;
            if ($handle !== null && $left > 0) {
                $this->state->refreshLock($handle->id, $handle->lockToken);
            }
        }
    }

    /**
     * Writes "skipped" for the audited instructions that will not run after the instruction at $pc failed or
     * stopped the process: the following elements of its list and of every enclosing list up to the top level.
     */
    private function failFrom(ExecutionHandle $handle, int $pc, string $reason): void
    {
        if ($handle->program->count() === 0) {
            return;
        }
        foreach ($handle->program->remainingAfter(min($pc, $handle->program->count() - 1)) as $skippedPc) {
            $instruction = $handle->program->at($skippedPc);
            $this->audit->recordStep($handle->id, new StepRecord(
                stepIndex: $handle->seq++,
                actionType: $instruction->auditType(),
                status: 'skipped',
                attempts: 0,
                startedAt: microtime(true),
                durationMs: 0,
                request: $instruction->config,
                result: ['reason' => $reason],
                pc: $skippedPc,
                path: $instruction->path,
                stepId: $instruction->stepId,
            ));
        }
    }

    private function finish(ExecutionHandle $handle, string $status, ?string $error): void
    {
        $this->closeLoops($handle, $status === 'completed' ? 'success' : 'failed');
        $snapshot = [
            'event' => $handle->context->event,
            'variables' => $handle->context->variables(),
            'meta' => $handle->context->meta(),
            'event_info' => $handle->context->eventInfo->toArray(),
            'conditions' => $handle->conditionTrace,
            'system' => $handle->context->systemValues(),
        ] + ($handle->frames !== [] ? ['frames' => $handle->frames] : []) + ($handle->stop !== null ? ['stop' => $handle->stop] : []);
        $this->executions->finish($handle->id, $status, $handle->retryCount, $snapshot, $error, $handle->context->handledErrors());
        $this->state->finish($handle->id, $status);
        $this->state->releaseLock($handle->id, $handle->lockToken);
        $handle->finished = true;

        $duration = microtime(true) - $handle->startedAt;
        $this->metrics->inc($status === 'completed' ? MetricsService::EXECUTIONS_COMPLETED : MetricsService::EXECUTIONS_FAILED);
        $this->metrics->observe(MetricsService::EXECUTION_DURATION, $duration);
        $this->logger->info($status === 'completed' ? 'Process completed' : 'Process failed', [
            'execution_id' => $handle->id, 'process_id' => $handle->process->id, 'event_id' => $handle->context->eventId,
            'object_type' => $handle->process->objectType, 'pc' => $handle->pc, 'seq' => $handle->seq,
            'duration_ms' => (int)round($duration * 1000), 'error' => $error,
        ]);
    }

    private function saveProgress(ExecutionHandle $handle): void
    {
        $this->state->update($handle->id, [
            'current_step' => $handle->pc,
            'seq' => $handle->seq,
            'frames' => $handle->frames,
            'instructions_executed' => $handle->instructionsExecuted,
        ] + $this->contextFields($handle->context));
    }

    private function setStatus(ExecutionHandle $handle, string $status): void
    {
        $this->executions->updateStatus($handle->id, $status, $handle->retryCount);
        $this->state->update($handle->id, ['status' => $status, 'retry_count' => $handle->retryCount]);
    }

    /**
     * Runtime values of the execution that conditions and sys.* need before the execution row exists (5.2.9).
     * execution_id and started_at stay empty until begin().
     */
    private function configureContext(ExecutionContext $context, Process $process, string $trigger, ?string $parentId): void
    {
        $context->setClock($this->clock);
        $context->processId = $process->id;
        $context->processName = $process->name;
        $context->processVersion = $process->version;
        $context->trigger = $trigger;
        $context->parentExecutionId = $parentId ?? '';
        $context->setConstants($process->definition['constants'] ?? []);
    }

    /**
     * @param array<string, mixed> $state Redis state of a running execution
     */
    private static function startedAtFromState(array $state): string
    {
        $started = $state['started_at'] ?? null;
        if (is_string($started) && $started !== '') {
            try {
                return (new \DateTimeImmutable($started))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                // fall back to the microtime timestamp below
            }
        }
        $ts = $state['started_ts'] ?? null;
        return is_numeric($ts) ? gmdate('Y-m-d H:i:s', (int)((float)$ts)) : '';
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
            'event_info' => $data['event_info'],
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
        if ($prepared->castError !== null) {
            $this->recordConditionFailure($process, $context, $prepared, $trigger, $parentId, $newId);
            return 'cast_error';
        }
        if (!$prepared->shouldStart()) {
            $this->state->setIdempotency($key, ExecutionStateStore::SKIPPED);
            return 'skipped';
        }
        $this->run($this->begin($process, $context, $prepared, $trigger, $parentId, $newId));
        return 'started';
    }

    private static function elapsedMs(float $since): int
    {
        return (int)round((microtime(true) - $since) * 1000);
    }
}
