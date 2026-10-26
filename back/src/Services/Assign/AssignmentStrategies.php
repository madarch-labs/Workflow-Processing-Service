<?php

declare(strict_types=1);

namespace Wps\Services\Assign;

use Redis;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Wps\Crm\CrmFilter;
use Wps\Exceptions\NonRecoverableException;
use Wps\Infrastructure\RedisConnection;
use Wps\Services\ExternalApiAdapterService;

/**
 * How assign chooses a user from a pool (md/tasks_list/4.9-assign.md §4.3). Round-robin keeps the last assigned
 * user, not a counter: the next one is the first id after it in the sorted pool, so changes of the pool do not give
 * somebody two records in a row. The choice is atomic (a Lua script).
 */
class AssignmentStrategies
{
    public const ROUND_ROBIN_LUA = <<<'LUA'
local last = redis.call('GET', KEYS[1])
local chosen = ARGV[1]
if last then
  for i = 1, #ARGV do
    if ARGV[i] > last then chosen = ARGV[i]; break end
  end
end
redis.call('SET', KEYS[1], chosen)
return chosen
LUA;
    private const PARALLEL = 10;
    private const LAST_TTL = 2592000;

    public function __construct(
        private readonly RedisConnection $redis,
        private readonly ExternalApiAdapterService $crm,
    ) {
    }

    /**
     * @param list<string> $pool sorted, non-empty
     * @return array{0: string, 1: string|null} [chosen, previous]
     */
    public function roundRobin(string $queueKey, array $pool): array
    {
        $key = "assign:rr:{$queueKey}";
        $previous = $this->redis->run(static fn (Redis $r) => $r->get($key));
        $chosen = (string)$this->redis->run(static fn (Redis $r) => $r->eval(self::ROUND_ROBIN_LUA, array_merge([$key], $pool), 1));
        return [$chosen, is_string($previous) ? $previous : null];
    }

    /**
     * The fewest open records; a tie — the one assigned longest ago, then by id.
     *
     * @param list<string> $pool
     * @return array{0: string, 1: array<string, int>} [chosen, counts]
     */
    public function leastBusy(array $pool, string $module, string $userField, CrmFilter $open, string $queueKey): array
    {
        $counts = [];
        $count = fn (string $user): int => $this->crm->count($module, $open->add($userField, 'eq', $user));
        if (Coroutine::getCid() > 0) {
            $limit = new Channel(self::PARALLEL);
            $group = new WaitGroup();
            $errors = [];
            foreach ($pool as $user) {
                $group->add();
                $limit->push(true);
                Coroutine::create(function () use ($user, $count, &$counts, &$errors, $limit, $group): void {
                    try {
                        $counts[$user] = $count($user);
                    } catch (\Throwable $e) {
                        $errors[] = $e;
                    } finally {
                        $limit->pop();
                        $group->done();
                    }
                });
            }
            $group->wait();
            if ($errors !== []) {
                throw $errors[0];
            }
        } else {
            foreach ($pool as $user) {
                $counts[$user] = $count($user);
            }
        }
        $last = $this->lastAssigned($queueKey, $pool);
        $best = null;
        foreach ($pool as $user) {
            if ($best === null || [$counts[$user], $last[$user] ?? 0, $user] < [$counts[$best], $last[$best] ?? 0, $best]) {
                $best = $user;
            }
        }
        $ttl = self::LAST_TTL;
        $this->redis->run(static fn (Redis $r) => $r->set("assign:last:{$queueKey}:{$best}", (string)microtime(true), ['ex' => $ttl]));
        ksort($counts, SORT_STRING);
        return [(string)$best, $counts];
    }

    /**
     * @param list<string> $pool
     */
    public function random(array $pool): string
    {
        return $pool[random_int(0, count($pool) - 1)];
    }

    /**
     * @param list<string> $pool
     * @return array<string, float>
     */
    private function lastAssigned(string $queueKey, array $pool): array
    {
        $values = $this->redis->run(static fn (Redis $r) => $r->mget(array_map(static fn (string $u) => "assign:last:{$queueKey}:{$u}", $pool)));
        $result = [];
        foreach ($pool as $i => $user) {
            $result[$user] = is_string($values[$i] ?? null) ? (float)$values[$i] : 0.0;
        }
        return $result;
    }

    public function forget(string $queueKey): void
    {
        $this->redis->run(static fn (Redis $r) => $r->del("assign:rr:{$queueKey}"));
    }

    /**
     * Throws when a pool is too large for least_busy (one count request per user).
     */
    public static function assertLeastBusyPool(int $size, int $max): void
    {
        if ($size > $max) {
            throw new NonRecoverableException("Pool is too large for least_busy ({$size} > {$max})");
        }
    }
}
