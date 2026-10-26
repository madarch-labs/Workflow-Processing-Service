<?php

declare(strict_types=1);

namespace Wps\Program;

use Wps\Services\StepRecord;

/**
 * Result of one interpreted instruction: where to go next and what to write to the audit.
 */
final class InstructionOutcome
{
    /**
     * @param array{status: string, message: ?string}|null $halt stop the execution with this status
     * @param array<string, mixed>|null $wait park the execution (waiting executions, md/tasks_list/7.2-waiting-executions.md)
     * @param bool $overwrite the record replaces an earlier one (the totals of a foreach step): the audit number does not advance
     */
    public function __construct(
        public readonly int $nextPc,
        public readonly ?StepRecord $record = null,
        public readonly bool $failed = false,
        public readonly ?array $halt = null,
        public readonly ?array $wait = null,
        public readonly bool $overwrite = false,
    ) {
    }
}
