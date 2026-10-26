<?php

declare(strict_types=1);

namespace Wps\Program;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConditionCastException;
use Wps\Services\ConditionEngineService;
use Wps\Services\ExecutionHandle;
use Wps\Services\StepRecord;
use Wps\Template\TemplateEngine;

/**
 * Executes one instruction of a program (md/tasks_list/done/0.1-program-compiler.md §4.7).
 * Actions are delegated to the engine (retries, audit request/result); control instructions are handled here.
 */
final class Interpreter
{
    /**
     * @param \Closure(Instruction, int, int, ExecutionContext, mixed): StepRecord $executeAction (instruction, pc, seq, context, owner)
     */
    public function __construct(
        private readonly ConditionEngineService $conditions,
        private readonly \Closure $executeAction,
        private readonly ?TemplateEngine $templates = null,
        private readonly ?LoopRunner $loops = null,
    ) {
    }

    /**
     * @param int   $seq   audit number the record of this instruction gets
     * @param mixed $owner passed to the action executor as is (the engine passes its ExecutionHandle)
     */
    public function step(Instruction $instruction, int $pc, int $seq, ExecutionContext $context, mixed $owner = null): InstructionOutcome
    {
        $context->pc = $pc;
        $context->stepIndex = $seq;
        return match ($instruction->op) {
            Instruction::ACTION => $this->action($instruction, $pc, $seq, $context, $owner),
            Instruction::BRANCH => $this->branch($instruction, $pc, $seq, $context),
            Instruction::JUMP => new InstructionOutcome((int)$instruction->config['target']),
            Instruction::JUMP_IF_FALSE => $this->jumpIfFalse($instruction, $pc, $seq, $context),
            Instruction::HALT => $this->halt($instruction, $pc, $seq, $context),
            Instruction::LOOP_BEGIN => $this->loops($owner)->begin($instruction, $pc, $seq, $context, $owner),
            Instruction::LOOP_NEXT => $this->loops($owner)->next($instruction, $pc, $context, $owner),
            default => new InstructionOutcome($pc + 1, $this->record($instruction, $pc, $seq, $context, 'failed', microtime(true), [],
                ['error_class' => 'ConfigurationException', 'recoverable' => false], "Unknown instruction \"{$instruction->op}\""), failed: true),
        };
    }

    private function loops(mixed $owner): LoopRunner
    {
        if ($this->loops === null || !$owner instanceof ExecutionHandle) {
            throw new \LogicException('Loops run only inside an execution');
        }
        return $this->loops;
    }

    private function action(Instruction $instruction, int $pc, int $seq, ExecutionContext $context, mixed $owner): InstructionOutcome
    {
        $record = ($this->executeAction)($instruction, $pc, $seq, $context, $owner);
        return new InstructionOutcome($pc + 1, $record, failed: $record->failed());
    }

    /**
     * First branch whose conditions are met wins; none → else (or the end of the block).
     * One audit record: the trace of every checked branch and the selected one.
     */
    private function branch(Instruction $instruction, int $pc, int $seq, ExecutionContext $context): InstructionOutcome
    {
        $startedAt = microtime(true);
        $config = $instruction->config;
        $checked = [];
        $request = ['branches' => count((array)$config['branches']), 'has_else' => (bool)($config['has_else'] ?? false)];
        foreach ((array)$config['branches'] as $i => $branch) {
            try {
                $result = $this->conditions->evaluate((array)($branch['conditions'] ?? []), $context);
            } catch (ConditionCastException $e) {
                $checked[] = ['branch' => $i, 'result' => false, 'conditions' => [$e->getDetails()]];
                return new InstructionOutcome($pc + 1, $this->record($instruction, $pc, $seq, $context, 'failed', $startedAt, $request,
                    ['branches' => $checked, 'error_class' => 'ConditionCastException', 'recoverable' => false], $e->getMessage()), failed: true);
            }
            $checked[] = ['branch' => $i] + (isset($branch['name']) ? ['name' => $branch['name']] : []) + ['result' => $result->isMet(), 'conditions' => $result->trace];
            if ($result->isMet()) {
                return new InstructionOutcome((int)$branch['target'], $this->record($instruction, $pc, $seq, $context, 'success', $startedAt, $request,
                    ['selected' => $i, 'branches' => $checked]));
            }
        }
        $selected = ($config['has_else'] ?? false) ? 'else' : 'none';
        return new InstructionOutcome((int)$config['else_target'], $this->record($instruction, $pc, $seq, $context, 'success', $startedAt, $request,
            ['selected' => $selected, 'branches' => $checked]));
    }

    private function jumpIfFalse(Instruction $instruction, int $pc, int $seq, ExecutionContext $context): InstructionOutcome
    {
        try {
            $met = $this->conditions->evaluate((array)($instruction->config['conditions'] ?? []), $context)->isMet();
        } catch (ConditionCastException $e) {
            return new InstructionOutcome($pc + 1, $this->record($instruction, $pc, $seq, $context, 'failed', microtime(true), [],
                ['conditions' => [$e->getDetails()], 'error_class' => 'ConditionCastException', 'recoverable' => false], $e->getMessage()), failed: true);
        }
        return new InstructionOutcome($met ? $pc + 1 : (int)$instruction->config['target']);
    }

    private function halt(Instruction $instruction, int $pc, int $seq, ExecutionContext $context): InstructionOutcome
    {
        $status = ($instruction->config['status'] ?? 'completed') === 'failed' ? 'failed' : 'completed';
        $template = isset($instruction->config['message']) ? (string)$instruction->config['message'] : null;
        $code = isset($instruction->config['code']) ? (string)$instruction->config['code'] : null;
        $message = $template;
        $result = ['stopped' => true, 'status' => $status];
        if ($template !== null && $this->templates !== null) {
            try {
                $message = (string)$this->templates->render($template, static fn (string $path) => $context->resolve($path))->value;
            } catch (\Throwable $e) {
                // a broken message must not prevent the stop: the raw text is used
                $result['message_error'] = $e->getMessage();
            }
        }
        $result += ['code' => $code, 'message' => $message];
        return new InstructionOutcome(
            $pc + 1,
            $this->record($instruction, $pc, $seq, $context, 'success', microtime(true), ['status' => $status, 'code' => $code, 'message' => $template], $result),
            halt: ['status' => $status, 'message' => $message, 'code' => $code],
        );
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $result
     */
    private function record(Instruction $instruction, int $pc, int $seq, ExecutionContext $context, string $status, float $startedAt, array $request, array $result, ?string $error = null): StepRecord
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
            iteration: $context->iteration,
            stepId: $instruction->stepId,
        );
    }
}
