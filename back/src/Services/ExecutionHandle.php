<?php

declare(strict_types=1);

namespace Wps\Services;

use Wps\Context\ExecutionContext;
use Wps\Persistence\Process;
use Wps\Program\Program;

/**
 * Mutable state of an execution while the engine runs it: the program, the cursor (pc), the audit sequence
 * number of the next record (seq) and the loop frames (md/tasks_list/done/0.1-program-compiler.md §4.6).
 */
final class ExecutionHandle
{
    public bool $finished = false;

    /** @var array{status: string, code: string|null, message: string}|null set by stop_process (4.14) */
    public ?array $stop = null;

    /** the execution was parked (waiting, 7.2): the run loop stops without finishing it */
    public bool $parked = false;

    /** attempts already made by the step woken after a long retry delay (7.1 + 7.2) */
    public int $retryAttemptsDone = 0;

    /**
     * @param list<array<string, mixed>> $frames loop frames (foreach), innermost last
     * @param list<array<string, mixed>> $conditionTrace
     */
    public function __construct(
        public readonly string $id,
        public readonly Process $process,
        public readonly Program $program,
        public readonly ExecutionContext $context,
        public readonly string $trigger,
        public readonly ?string $parentId,
        public readonly string $lockToken,
        public int $pc,
        public int $seq,
        public array $frames,
        public int $retryCount,
        public readonly array $conditionTrace,
        public readonly float $startedAt,
        public int $instructionsExecuted = 0,
    ) {
    }
}
