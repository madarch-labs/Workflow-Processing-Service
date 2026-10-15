<?php

declare(strict_types=1);

namespace Wps\Infrastructure;

use Redis;
use RedisException;
use Swoole\ConnectionPool;

/**
 * phpredis connection pool (Swoole 6 has no Coroutine\Redis; phpredis is made
 * non-blocking by the runtime hooks). Two instances exist: the event bus and the data store.
 */
final class RedisConnection
{
    private ConnectionPool $pool;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $password = null,
        private readonly int $db = 0,
        int $poolSize = 16,
        private readonly float $readTimeout = 5.0,
    ) {
        $this->pool = new ConnectionPool(fn (): Redis => $this->connect(), $poolSize);
    }

    public function connect(): Redis
    {
        $redis = new Redis();
        $redis->connect($this->host, $this->port, 3.0);
        if ($this->password !== null && $this->password !== '') {
            $redis->auth($this->password);
        }
        if ($this->db !== 0) {
            $redis->select($this->db);
        }
        $redis->setOption(Redis::OPT_READ_TIMEOUT, $this->readTimeout);
        return $redis;
    }

    /**
     * @template T
     * @param callable(Redis): T $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        /** @var Redis $redis */
        $redis = $this->pool->get();
        try {
            $result = $fn($redis);
        } catch (RedisException $e) {
            // the connection state is unknown after a transport error — drop it
            $this->pool->put(null);
            throw $e;
        } catch (\Throwable $e) {
            $this->pool->put($redis);
            throw $e;
        }
        $this->pool->put($redis);
        return $result;
    }
}
