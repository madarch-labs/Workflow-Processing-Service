<?php

declare(strict_types=1);

namespace Wps\Services;

use Wps\Persistence\ExecutionActionRepository;

/**
 * Writes every executed step into execution_actions (TS §5, AuditService).
 */
final class AuditService
{
    public function __construct(
        private readonly ExecutionActionRepository $actions,
        private readonly MetricsService $metrics,
    ) {
    }

    public function recordStep(string $executionId, StepRecord $step): void
    {
        $this->actions->save($executionId, $step);
        // steps that were never attempted (after a failure) do not count as action runs
        if ($step->attempts > 0) {
            $this->metrics->observe(MetricsService::ACTION_DURATION, $step->durationMs / 1000);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function steps(string $executionId): array
    {
        return $this->actions->listByExecution($executionId);
    }
}
