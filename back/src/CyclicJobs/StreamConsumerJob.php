<?php

declare(strict_types=1);

namespace Wps\CyclicJobs;

use Sidalex\SwooleApp\Classes\CyclicJobs\AbstractCyclicJob;
use Wps\Kernel\Container;

/**
 * Main loop (TS §5.1): XREADGROUP (BLOCK) on the CRM event stream, every 0.5 s.
 * Runs in every HTTP worker; each worker is a separate consumer of the "wps_workers" group.
 */
final class StreamConsumerJob extends AbstractCyclicJob
{
    protected float $timeSleep = 0.5;
    protected float $startupSleep = 1;

    public function runJob(): void
    {
        $stream = Container::get()->stream();
        // drain quickly while there are messages, then let the orchestrator sleep
        for ($i = 0; $i < 10; $i++) {
            if ($stream->consumeBatch() === 0) {
                break;
            }
        }
    }
}
