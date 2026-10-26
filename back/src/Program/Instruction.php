<?php

declare(strict_types=1);

namespace Wps\Program;

/**
 * One instruction of a compiled process program (md/tasks_list/done/0.1-program-compiler.md §4.2).
 *
 *   action         config = the step config (type, ...): run through the ActionHandlerRegistry, then pc + 1
 *   branch         config = {branches: [{conditions, target}], else_target, end_target}: first true branch wins
 *   jump           config = {target}
 *   jump_if_false  config = {conditions, target}
 *   halt           config = {status, message}: finishes the execution
 *   loop_begin / loop_next  — loops (foreach)
 */
final class Instruction
{
    public const ACTION = 'action';
    public const BRANCH = 'branch';
    public const JUMP = 'jump';
    public const JUMP_IF_FALSE = 'jump_if_false';
    public const HALT = 'halt';
    public const LOOP_BEGIN = 'loop_begin';
    public const LOOP_NEXT = 'loop_next';

    /** Instructions that leave a record in the audit (the others only move pc). */
    public const AUDITED = [self::ACTION, self::BRANCH, self::HALT, self::LOOP_BEGIN];

    /**
     * @param array<string, mixed> $config
     * @param string $path      place in the definition tree: preload[0], actions[2].branches[0].actions[1], ...
     * @param int    $topLevel  index of the top-level element (preload + actions) the instruction belongs to
     * @param int    $parent    pc of the block whose list contains the instruction, -1 at the top level
     */
    public function __construct(
        public readonly string $op,
        public readonly array $config,
        public readonly string $path,
        public readonly ?string $stepId,
        public readonly int $depth,
        public readonly int $topLevel,
        public readonly int $parent = -1,
    ) {
    }

    /** Action type for the audit: the step type for "action", the block type for block instructions. */
    public function auditType(): string
    {
        return (string)($this->config['type'] ?? $this->op);
    }

    /**
     * Path of the list the instruction is an element of ("actions[1].branches[0].actions" for
     * "actions[1].branches[0].actions[3]"), or null for a block helper instruction (jump at the end of a branch).
     */
    public function listPath(): ?string
    {
        return preg_match('/^(.*)\[\d+\]$/', $this->path, $m) ? $m[1] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['op' => $this->op, 'config' => $this->config, 'path' => $this->path, 'step_id' => $this->stepId,
            'depth' => $this->depth, 'top_level' => $this->topLevel, 'parent' => $this->parent];
    }
}
