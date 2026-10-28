<?php

declare(strict_types=1);

namespace Wps\Services;

/**
 * Outcome of one executed step (audit row in execution_actions).
 * stepIndex is the audit sequence number (seq); pc / path / iteration locate the instruction in the program.
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
        public readonly ?int $pc = null,
        public readonly ?string $path = null,
        public readonly string $iteration = '',
        public readonly ?string $stepId = null,
        /** classified error code of a failed step (7.1) */
        public readonly ?string $errorCode = null,
        /** how a failure was handled: null (stopped the execution), "continue" or "goto" (7.1) */
        public readonly ?string $handled = null,
        /** @var array{due_at: string, reason: string, info: array<string, mixed>}|null the step parks the execution (7.2) */
        public readonly ?array $wait = null,
    ) {
    }

    public function isWaiting(): bool
    {
        return $this->wait !== null;
    }

    public function withHandled(string $handled, array $extraResult = []): self
    {
        return new self($this->stepIndex, $this->actionType, $this->status, $this->attempts, $this->startedAt, $this->durationMs,
            $this->request, $this->result + $extraResult, $this->error, $this->recoverable, $this->pc, $this->path, $this->iteration,
            $this->stepId, $this->errorCode, $handled);
    }

    /**
     * The same record at another audit position (seq) — e.g. a preload step executed during the condition check.
     */
    public function withIndex(int $stepIndex): self
    {
        return new self($stepIndex, $this->actionType, $this->status, $this->attempts, $this->startedAt, $this->durationMs,
            $this->request, $this->result, $this->error, $this->recoverable, $this->pc, $this->path, $this->iteration, $this->stepId,
            $this->errorCode, $this->handled, $this->wait);
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }
}
