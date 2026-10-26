<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;

/**
 * Optional for a change type: computes the value together with details for the step result (audit), e.g. the
 * missing paths of a template. ModifyHandler calls computeWithDetails() instead of compute(). Stateless on purpose:
 * one change type instance serves concurrent executions.
 */
interface ReportsDetails
{
    /**
     * @param array<string, mixed> $config
     * @return array{0: mixed, 1: array<string, mixed>} [value, details merged into the step result]
     */
    public function computeWithDetails(ExecutionContext $context, array $config): array;
}
