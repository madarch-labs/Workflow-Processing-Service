<?php

declare(strict_types=1);

namespace Wps\CyclicJobs;

use Sidalex\SwooleApp\Classes\CyclicJobs\AbstractCyclicJob;
use Wps\Kernel\Container;

/**
 * Wakes waiting executions whose time has come (md/tasks_list/done/7.2-waiting-executions.md §4.4), in every
 * worker; rows are claimed with SKIP LOCKED and a lease, so each execution is continued by one worker.
 */
final class WaitResumeJob extends AbstractCyclicJob
{
    protected float $timeSleep = 1.0;
    protected float $startupSleep = 5;

    public function runJob(): void
    {
        $c = Container::get();
        $this->timeSleep = $c->config()->waitPollIntervalMs / 1000;
        $c->waits()->wakeDue($c->stream()->consumerName());
    }
}
