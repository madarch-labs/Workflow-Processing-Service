<?php

declare(strict_types=1);

namespace Wps\Program;

use Psr\Log\LoggerInterface;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Services\ConditionEngineService;
use Wps\Services\ExecutionHandle;
use Wps\Services\MetricsService;
use Wps\Services\StepRecord;

/**
 * Loops of the program (foreach, md/tasks_list/4.11-foreach.md): the instructions loop_begin / loop_next, item errors
 * with on_item_error = continue and closing of loops left early (error, goto, stop_process).
 *
 * A loop is a frame in ExecutionHandle::$frames (innermost last): the key of the item snapshot, the current index,
 * counters. The items are a snapshot taken at loop_begin (LoopItemsStore) — the loop goes over the same items after a
 * crash. The foreach step has one audit record: written by loop_begin and overwritten with the totals when the loop
 * ends. Items rejected by "where" leave no records.
 */
final class LoopRunner
{
    public const WHERE_TRACES = 20;
    public const FAILED_INDEXES = 50;

    public function __construct(
        private readonly ConditionEngineService $conditions,
        private readonly ExpressionEngine $expressions,
        private readonly LoopItemsStore $store,
        private readonly int $maxItems,
        private readonly ?MetricsService $metrics = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function begin(Instruction $instruction, int $pc, int $seq, ExecutionContext $context, ExecutionHandle $handle): InstructionOutcome
    {
        $startedAt = microtime(true);
        $outer = $context->iteration;
        $config = $instruction->config;
        $request = ['source' => $config['source'] ?? null, 'as' => $config['as'], 'max_items' => $this->limit($config),
            'on_overflow' => $config['on_overflow'] ?? 'fail', 'on_item_error' => $config['on_item_error'] ?? 'stop', 'has_where' => !empty($config['where'])];
        try {
            [$items, $missing, $module] = $this->source($config, $context);
            $truncated = false;
            $limit = $this->limit($config);
            if (count($items) > $limit) {
                if (($config['on_overflow'] ?? 'fail') !== 'truncate') {
                    $this->metrics?->inc(MetricsService::LOOP_OVERFLOW);
                    throw new NonRecoverableException(sprintf('foreach: %d items exceed max_items (%d)', count($items), $limit));
                }
                $items = array_slice($items, 0, $limit);
                $truncated = true;
            }
        } catch (\Throwable $e) {
            return new InstructionOutcome($pc + 1, $this->record($instruction, $pc, $seq, $context->iteration, 'failed', $startedAt, $request,
                ['error_class' => (new \ReflectionClass($e))->getShortName(), 'recoverable' => false], $e->getMessage()), failed: true);
        }
        $stats = ['count' => count($items), 'processed' => 0, 'skipped_by_where' => 0, 'failed_items' => 0, 'failed_indexes' => [],
            'unsaved_items' => 0, 'truncated' => $truncated, 'source_missing' => $missing];
        if ($items === []) {
            return new InstructionOutcome((int)$config['end'], $this->record($instruction, $pc, $seq, $context->iteration, 'success', $startedAt, $request, $stats));
        }
        $handle->frames[] = [
            'begin' => $pc, 'next' => (int)$config['next'], 'end' => (int)$config['end'],
            'as' => (string)$config['as'], 'loop_var' => (string)$config['loop_var'], 'module' => $module,
            'items_key' => $this->store->save($handle->id, $pc, $items), 'index' => -1, 'count' => count($items),
            'audit_seq' => $seq, 'started_at' => $startedAt, 'request' => $request, 'stats' => $stats,
            'where_traces' => [], 'on_item_error' => (string)($config['on_item_error'] ?? 'stop'), 'item_failed' => false,
        ];
        try {
            $found = $this->advance($handle, $context, 0, (array)($config['where'] ?? []));
        } catch (\Throwable $e) {
            return new InstructionOutcome($pc + 1, $this->close($handle, 'failed', [], $e->getMessage()), failed: true);
        }
        if (!$found) {
            // every item was rejected by "where"
            return new InstructionOutcome((int)$config['end'], $this->close($handle, 'success'));
        }
        return new InstructionOutcome($pc + 1, $this->record($instruction, $pc, $seq, $outer, 'success', $startedAt, $request,
            ['count' => count($items), 'truncated' => $truncated, 'source_missing' => $missing]));
    }

    public function next(Instruction $instruction, int $pc, ExecutionContext $context, ExecutionHandle $handle): InstructionOutcome
    {
        $top = count($handle->frames) - 1;
        $begin = (int)$instruction->config['begin'];
        if ($top < 0 || (int)$handle->frames[$top]['begin'] !== $begin) {
            throw new \LogicException("loop_next at pc {$pc} without its loop frame");
        }
        $frame = &$handle->frames[$top];
        if ($frame['item_failed']) {
            $frame['item_failed'] = false;
        } else {
            $frame['stats']['processed']++;
            if ($context->dirtyFields($frame['as']) !== []) {
                // a frequent mistake: the item was changed but not saved with save_object
                $frame['stats']['unsaved_items']++;
            }
        }
        $index = (int)$frame['index'] + 1;
        unset($frame);
        $where = (array)($handle->program->at($begin)->config['where'] ?? []);
        try {
            $found = $this->advance($handle, $context, $index, $where);
        } catch (\Throwable $e) {
            return new InstructionOutcome($pc + 1, $this->close($handle, 'failed', [], $e->getMessage()), failed: true, overwrite: true);
        }
        if ($found) {
            return new InstructionOutcome($begin + 1);
        }
        $end = (int)$handle->frames[$top]['end'];
        return new InstructionOutcome($end, $this->close($handle, 'success'), overwrite: true);
    }

    /**
     * An unhandled error of a step inside loops: the innermost loop with on_item_error = continue goes on with its next
     * item; the loops inside it are closed as failed. Null when no enclosing loop continues — the execution fails.
     *
     * @return array{0: int, 1: list<StepRecord>}|null [next pc, records of the closed inner loops]
     */
    public function itemError(ExecutionHandle $handle, Instruction $failed): ?array
    {
        $continueAt = null;
        for ($i = count($handle->frames) - 1; $i >= 0; $i--) {
            if ($failed->op === Instruction::LOOP_NEXT && $i === count($handle->frames) - 1 && (int)$handle->frames[$i]['begin'] === (int)$failed->config['begin']) {
                continue; // the loop itself is broken (e.g. its snapshot is lost)
            }
            if ($handle->frames[$i]['on_item_error'] === 'continue') {
                $continueAt = $i;
                break;
            }
        }
        if ($continueAt === null) {
            return null;
        }
        $records = [];
        while (count($handle->frames) - 1 > $continueAt) {
            $records[] = $this->close($handle, 'failed');
        }
        $frame = &$handle->frames[$continueAt];
        $frame['item_failed'] = true;
        $frame['stats']['failed_items']++;
        if (count($frame['stats']['failed_indexes']) < self::FAILED_INDEXES) {
            $frame['stats']['failed_indexes'][] = (int)$frame['index'];
        }
        $next = (int)$frame['next'];
        unset($frame);
        $this->metrics?->inc(MetricsService::LOOP_ITEM_FAILURES);
        $handle->context->iteration = self::iteration($handle->frames);
        return [$next, $records];
    }

    /**
     * Closes every open loop (the execution failed, stop_process, goto out of the loops).
     *
     * @param array<string, mixed> $extra added to the totals of every loop
     * @return list<StepRecord>
     */
    public function closeAll(ExecutionHandle $handle, string $status, array $extra = []): array
    {
        $records = [];
        while ($handle->frames !== []) {
            $records[] = $this->close($handle, $status, $extra);
        }
        return $records;
    }

    /**
     * Snapshot keys of the open loops.
     *
     * @param list<array<string, mixed>> $frames
     * @return list<string>
     */
    public static function itemKeys(array $frames): array
    {
        return array_values(array_filter(array_map(static fn (array $f) => (string)($f['items_key'] ?? ''), $frames), static fn ($k) => $k !== ''));
    }

    /**
     * The iteration address for the audit: indexes of the open loops from the outer one ("3", "3/0"), "" outside loops.
     *
     * @param list<array<string, mixed>> $frames
     */
    public static function iteration(array $frames): string
    {
        return implode('/', array_map(static fn (array $f) => (string)max(0, (int)($f['index'] ?? 0)), $frames));
    }

    /**
     * Moves the innermost loop to the first item from $index on that passes "where"; false when the items are over.
     *
     * @param array<mixed> $where
     */
    private function advance(ExecutionHandle $handle, ExecutionContext $context, int $index, array $where): bool
    {
        $top = count($handle->frames) - 1;
        $frame = &$handle->frames[$top];
        for (; $index < (int)$frame['count']; $index++) {
            [$found, $item] = $this->store->item((string)$frame['items_key'], $index);
            if (!$found) {
                throw new NonRecoverableException('Loop items snapshot is lost; the execution cannot be resumed');
            }
            $frame['index'] = $index;
            $context->setVariable($frame['as'], is_array($item) && !array_is_list($item) ? $item : ['value' => $item],
                $frame['module'], ExecutionContext::SOURCE_LOOP);
            $context->setVariable($frame['loop_var'], ['index' => $index, 'number' => $index + 1, 'count' => (int)$frame['count'],
                'first' => $index === 0, 'last' => $index === (int)$frame['count'] - 1], null, ExecutionContext::SOURCE_LOOP_INFO);
            $context->iteration = self::iteration($handle->frames);
            if ($where === []) {
                return true;
            }
            $result = $this->conditions->evaluate($where, $context);
            if ($result->isMet()) {
                return true;
            }
            $frame['stats']['skipped_by_where']++;
            if (count($frame['where_traces']) < self::WHERE_TRACES) {
                $frame['where_traces'][] = ['index' => $index, 'conditions' => $result->trace];
            }
        }
        return false;
    }

    /**
     * Removes the innermost loop: snapshot, loop variables; the record of the foreach step gets the totals.
     *
     * @param array<string, mixed> $extra
     */
    private function close(ExecutionHandle $handle, string $status, array $extra = [], ?string $error = null): StepRecord
    {
        $frame = array_pop($handle->frames);
        $this->store->delete((string)$frame['items_key']);
        $context = $handle->context;
        $context->unsetVariable((string)$frame['as']);
        $context->unsetVariable((string)$frame['loop_var']);
        $context->iteration = self::iteration($handle->frames);
        $stats = (array)$frame['stats'];
        $result = $stats + ($frame['where_traces'] !== [] ? ['where_traces' => $frame['where_traces']] : [])
            + ($status === 'failed' ? ['failed_at_index' => (int)$frame['index']] : []) + $extra;
        $durationMs = (int)round((microtime(true) - (float)$frame['started_at']) * 1000);
        $this->metrics?->inc(MetricsService::LOOP_ITEMS, (float)$stats['processed']);
        $this->logger?->info('Loop finished', ['execution_id' => $handle->id, 'pc' => $frame['begin'], 'count' => $stats['count'],
            'processed' => $stats['processed'], 'failed_items' => $stats['failed_items'], 'status' => $status, 'duration_ms' => $durationMs]);
        $instruction = $handle->program->at((int)$frame['begin']);
        return new StepRecord(
            stepIndex: (int)$frame['audit_seq'],
            actionType: $instruction->auditType(),
            status: $status,
            attempts: 1,
            startedAt: (float)$frame['started_at'],
            durationMs: $durationMs,
            request: (array)$frame['request'],
            result: $result,
            error: $error,
            pc: (int)$frame['begin'],
            path: $instruction->path,
            iteration: $context->iteration,
            stepId: $instruction->stepId,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: list<mixed>, 1: bool, 2: string|null} [items, source missing, module of the items]
     */
    private function source(array $config, ExecutionContext $context): array
    {
        $source = $config['source'] ?? null;
        $module = isset($config['module']) && is_string($config['module']) && $config['module'] !== '' ? $config['module'] : null;
        if (is_array($source)) {
            $value = $this->expressions->evaluate((string)($source['expression'] ?? ''), static fn (string $path) => $context->get($path));
            $label = 'expression';
        } else {
            $label = (string)$source;
            [$found, $value] = $context->resolve($label);
            $module ??= $context->getModule(ExecutionContext::splitPath($label)[0]);
            if (!$found || $value === null) {
                return [[], true, $module];
            }
        }
        if ($value === null) {
            return [[], true, $module];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new NonRecoverableException("foreach: source \"{$label}\" is not a list");
        }
        return [$value, false, $module];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function limit(array $config): int
    {
        return max(1, min($this->maxItems, (int)($config['max_items'] ?? $this->maxItems)));
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $result
     */
    private function record(Instruction $instruction, int $pc, int $seq, string $iteration, string $status, float $startedAt, array $request, array $result, ?string $error = null): StepRecord
    {
        return new StepRecord(
            stepIndex: $seq,
            actionType: $instruction->auditType(),
            status: $status,
            attempts: 1,
            startedAt: $startedAt,
            durationMs: (int)round((microtime(true) - $startedAt) * 1000),
            request: $request,
            result: $result,
            error: $error,
            pc: $pc,
            path: $instruction->path,
            iteration: $iteration,
            stepId: $instruction->stepId,
        );
    }
}
