<?php

declare(strict_types=1);

namespace Wps\Services;

use Psr\Log\LoggerInterface;
use Redis;
use Wps\Config\AppConfig;
use Wps\Infrastructure\RedisConnection;
use Wps\Persistence\Process;
use Wps\Persistence\ProcessRepository;
use Wps\Support\Json;

/**
 * Finds the active top-level (is_child = FALSE) processes for an object type (TS §5.2).
 * Cached in Redis proc_cache:{object_type} for PROCESS_CACHE_TTL seconds; invalidated on CRUD.
 */
final class ProcessResolverService
{
    public function __construct(
        private readonly ProcessRepository $processes,
        private readonly RedisConnection $redis,
        private readonly AppConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<Process>
     */
    public function resolve(string $objectType): array
    {
        $key = self::cacheKey($objectType);
        try {
            $cached = $this->redis->run(static fn (Redis $r) => $r->get($key));
            if (is_string($cached)) {
                return array_map(Process::fromArray(...), (array)Json::decode($cached, []));
            }
        } catch (\RedisException $e) {
            $this->logger->warning('Process cache unavailable, reading from database', ['error' => $e->getMessage()]);
        }

        $processes = $this->processes->findActiveTopLevel($objectType);
        try {
            $payload = Json::encode(array_map(static fn (Process $p) => $p->toArray(), $processes));
            $this->redis->run(fn (Redis $r) => $r->set($key, $payload, ['ex' => $this->config->processCacheTtl]));
        } catch (\RedisException) {
        }
        return $processes;
    }

    public function invalidate(string ...$objectTypes): void
    {
        $keys = array_values(array_unique(array_map(self::cacheKey(...), array_filter($objectTypes))));
        if ($keys === []) {
            return;
        }
        try {
            $this->redis->run(static fn (Redis $r) => $r->del($keys));
        } catch (\RedisException $e) {
            $this->logger->warning('Process cache invalidation failed', ['error' => $e->getMessage()]);
        }
    }

    public static function cacheKey(string $objectType): string
    {
        return 'proc_cache:' . $objectType;
    }
}
