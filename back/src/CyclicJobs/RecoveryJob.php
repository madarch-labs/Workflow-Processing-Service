<?php

declare(strict_types=1);

namespace Wps\CyclicJobs;

use Sidalex\SwooleApp\Classes\CyclicJobs\AbstractCyclicJob;
use Swoole\Coroutine;
use Wps\Kernel\Container;

/**
 * Claim-based recovery (TS §10.1, §10.4):
 *  - re-claims stream messages a crashed consumer left unacknowledged (XAUTOCLAIM);
 *  - resumes unfinished executions (manual/child ones have no stream message) from their Redis
 *    context once their lock has expired; marks them failed if the Redis context is gone.
 */
final class RecoveryJob extends AbstractCyclicJob
{
    protected float $timeSleep = 30;
    protected float $startupSleep = 15;

    public function runJob(): void
    {
        $c = Container::get();
        $c->stream()->reclaimPending();

        $engine = $c->engine();
        $state = $c->stateStore();
        foreach ($c->executions()->findStaleIds($c->config()->recoveryStaleSeconds) as $id) {
            if ($state->load($id) === null) {
                $engine->markLost($id, 'Execution context expired or lost; it could not be resumed');
                continue;
            }
            Coroutine::create(static function () use ($engine, $id, $c): void {
                try {
                    $engine->resume($id);
                } catch (\Throwable $e) {
                    $c->logger()->error('Execution recovery failed', ['execution_id' => $id, 'error' => $e]);
                }
            });
        }
    }
}
