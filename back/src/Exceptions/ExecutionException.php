<?php

declare(strict_types=1);

namespace Wps\Exceptions;

/**
 * Base class for errors raised while executing a process step.
 * isRecoverable() decides whether the Execution Engine retries the step (TS §10.3).
 */
abstract class ExecutionException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, private readonly array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    abstract public function isRecoverable(): bool;

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
