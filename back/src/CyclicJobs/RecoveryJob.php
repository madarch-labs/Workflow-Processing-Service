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
        $this->purgeSideEffects($c);

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

    /**
     * Once an hour across all workers (SET NX): side-effect journal rows older than SIDE_EFFECTS_RETENTION_DAYS.
     */
    private function purgeSideEffects(Container $c): void
    {
        try {
            $claimed = $c->redisData()->run(static fn (\Redis $r) => $r->set('side_effects:purge', '1', ['nx', 'ex' => 3600]));
            if ($claimed) {
                $deleted = $c->sideEffects()->purgeOlderThan($c->config()->sideEffectsRetentionDays);
                $c->logger()->info('Side-effect journal purged', ['deleted' => $deleted]);
            }
        } catch (\Throwable $e) {
            $c->logger()->warning('Side-effect journal purge failed', ['error' => $e->getMessage()]);
        }
    }
}
