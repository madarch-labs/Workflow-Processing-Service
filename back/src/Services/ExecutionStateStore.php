<?php

declare(strict_types=1);

namespace Wps\Services;

use Redis;
use Wps\Config\AppConfig;
use Wps\Infrastructure\RedisConnection;
use Wps\Support\Json;

/**
 * Intermediate execution state in the data Redis (TS §4.2):
 *   exec:{uuid}                   Hash   context while running, kept 1h after completion; current_step = pc of the
 *                                        next instruction, seq = audit number of the next record, frames = loops
 *   idem:{event_id}:{process_id}  String execution id (or "skipped"), SET NX, 24h
 *   lock:exec:{uuid}              String owner token, SET NX, refreshed on every step
 */
final class ExecutionStateStore
{
    public const SKIPPED = 'skipped';

    public function __construct(
        private readonly RedisConnection $redis,
        private readonly AppConfig $config,
    ) {
    }

    public static function idempotencyKey(string $eventId, int $processId): string
    {
        return "idem:{$eventId}:{$processId}";
    }

    public function claimIdempotency(string $key, string $executionId): bool
    {
        return (bool)$this->redis->run(fn (Redis $r) => $r->set($key, $executionId, ['nx', 'ex' => $this->config->idempotencyTtlSeconds]));
    }

    public function getIdempotency(string $key): ?string
    {
        $value = $this->redis->run(static fn (Redis $r) => $r->get($key));
        return is_string($value) ? $value : null;
    }

    public function setIdempotency(string $key, string $value): void
    {
        $this->redis->run(fn (Redis $r) => $r->set($key, $value, ['ex' => $this->config->idempotencyTtlSeconds]));
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function create(string $executionId, array $fields): void
    {
        $key = "exec:{$executionId}";
        $hash = array_map(static fn ($v) => is_array($v) ? Json::encode($v) : (string)$v, $fields);
        $ttl = $this->config->executionTtlSeconds;
        $this->redis->run(static function (Redis $r) use ($key, $hash, $ttl) {
            $r->multi(Redis::PIPELINE);
            $r->hMSet($key, $hash);
            $r->expire($key, $ttl);
            $r->exec();
        });
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(string $executionId, array $fields): void
    {
        $hash = array_map(static fn ($v) => is_array($v) ? Json::encode($v) : (string)$v, $fields);
        $this->redis->run(static fn (Redis $r) => $r->hMSet("exec:{$executionId}", $hash));
    }

    /**
     * @return array<string, mixed>|null decoded hash
     */
    public function load(string $executionId): ?array
    {
        $hash = $this->redis->run(static fn (Redis $r) => $r->hGetAll("exec:{$executionId}"));
        if (!is_array($hash) || $hash === []) {
            return null;
        }
        foreach (['event', 'variables', 'meta', 'dirty', 'definition', 'conditions', 'process', 'frames', 'event_info'] as $jsonField) {
            if (isset($hash[$jsonField])) {
                $hash[$jsonField] = Json::decode($hash[$jsonField], []);
            }
        }
        foreach (['current_step', 'seq', 'instructions_executed', 'retry_count', 'depth', 'process_id', 'process_version'] as $intField) {
            if (isset($hash[$intField])) {
                $hash[$intField] = (int)$hash[$intField];
            }
        }
        return $hash;
    }

    /**
     * The state leaves Redis when the execution is parked (it waits in execution_waits).
     */
    public function delete(string $executionId): void
    {
        $this->redis->run(static fn (Redis $r) => $r->del("exec:{$executionId}"));
    }

    public function finish(string $executionId, string $status): void
    {
        $key = "exec:{$executionId}";
        $ttl = $this->config->executionFinishedTtlSeconds;
        $this->redis->run(static function (Redis $r) use ($key, $status, $ttl) {
            $r->multi(Redis::PIPELINE);
            $r->hSet($key, 'status', $status);
            $r->hSet($key, 'finished_at', gmdate(\DATE_ATOM));
            $r->expire($key, $ttl);
            $r->exec();
        });
    }

    public function acquireLock(string $executionId, string $token): bool
    {
        return (bool)$this->redis->run(fn (Redis $r) => $r->set(
            "lock:exec:{$executionId}",
            $token,
            ['nx', 'ex' => $this->config->executionLockTtl],
        ));
    }

    public function refreshLock(string $executionId, string $token): void
    {
        $key = "lock:exec:{$executionId}";
        $ttl = $this->config->executionLockTtl;
        $this->redis->run(static fn (Redis $r) => $r->eval(
            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('expire', KEYS[1], ARGV[2]) else return 0 end",
            [$key, $token, (string)$ttl],
            1,
        ));
    }

    public function releaseLock(string $executionId, string $token): void
    {
        $key = "lock:exec:{$executionId}";
        $this->redis->run(static fn (Redis $r) => $r->eval(
            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
            [$key, $token],
            1,
        ));
    }
}
