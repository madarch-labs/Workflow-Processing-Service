<?php

declare(strict_types=1);

namespace Wps\Services;

/**
 * Result of the condition check before an execution is created.
 * $preloadSteps are preload steps already executed for lazy condition evaluation; they become the
 * first steps of the execution so the CRM is not queried twice.
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
    ) {
    }

    public function shouldStart(): bool
    {
        return $this->conditionsMet || $this->failedStep !== null;
    }
}
