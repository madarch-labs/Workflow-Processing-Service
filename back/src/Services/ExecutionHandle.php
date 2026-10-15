<?php

declare(strict_types=1);

namespace Wps\Services;

use Wps\Context\ExecutionContext;
use Wps\Persistence\Process;

/**
 * Mutable state of an execution while the engine runs it.
 */
final class ExecutionHandle
{
    public bool $finished = false;

    /**
     * @param list<array<string, mixed>> $steps
     * @param list<array<string, mixed>> $conditionTrace
     */
    public function __construct(
        public readonly string $id,
        public readonly Process $process,
        public readonly array $steps,
        public readonly ExecutionContext $context,
        public readonly string $trigger,
        public readonly ?string $parentId,
        public readonly string $lockToken,
        public int $currentStep,
        public int $retryCount,
        public readonly array $conditionTrace,
        public readonly float $startedAt,
    ) {
    }
}
