<?php

declare(strict_types=1);

namespace Wps\Services;

use Wps\Exceptions\ConditionCastException;

/**
 * Result of the condition check before an execution is created.
 * $preloadSteps are preload steps already executed for lazy condition evaluation; they become the
 * first steps of the execution so the CRM is not queried twice.
 * $castError: a condition row with "on_cast_error": "error" failed to cast (the execution is recorded as failed).
 */
final class PreparedRun
{
    /**
     * @param list<array<string, mixed>> $trace
     * @param list<StepRecord> $preloadSteps
     */
    public function __construct(
        public readonly bool $conditionsMet,
        public readonly array $trace,
        public readonly array $preloadSteps = [],
        public readonly ?StepRecord $failedStep = null,
        public readonly ?ConditionCastException $castError = null,
    ) {
    }

    public function shouldStart(): bool
    {
        return $this->conditionsMet || $this->failedStep !== null;
    }
}
