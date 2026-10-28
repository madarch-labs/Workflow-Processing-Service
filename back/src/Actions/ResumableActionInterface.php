<?php

declare(strict_types=1);

namespace Wps\Actions;

use Wps\Context\ExecutionContext;

/**
 * An action that parks the execution (ActionResult::wait()) and finishes the step after waking up
 * (md/tasks_list/done/7.2-waiting-executions.md §4.2). Without it the step is simply marked successful.
 */
interface ResumableActionInterface
{
    /**
     * @param array<string, mixed> $actionConfig
     * @param array<string, mixed> $waitInfo info of ActionResult::wait() + due_at, parked_at
     */
    public function resume(ExecutionContext $context, array $actionConfig, array $waitInfo): ActionResult;
}
