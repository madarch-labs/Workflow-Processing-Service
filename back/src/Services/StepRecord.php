<?php

declare(strict_types=1);

namespace Wps\Services;

/**
 * Outcome of one executed step (audit row in execution_actions).
 */
final class StepRecord
{
    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $result
     */
    public function __construct(
        public readonly int $stepIndex,
        public readonly string $actionType,
        public readonly string $status,
        public readonly int $attempts,
        public readonly float $startedAt,
        public readonly int $durationMs,
        public readonly array $request = [],
        public readonly array $result = [],
        public readonly ?string $error = null,
        public readonly bool $recoverable = false,
    ) {
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }
}
