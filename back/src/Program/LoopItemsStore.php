<?php

declare(strict_types=1);

namespace Wps\Program;

use Redis;
use Wps\Infrastructure\RedisConnection;
use Wps\Support\Json;

/**
 * Snapshot of the items of a foreach loop (md/tasks_list/4.11-foreach.md §4.2–4.4): a Redis list
 * exec:{uuid}:loop:{pc} of JSON items written once at the start of the loop. The execution hash keeps only the frame
 * with the key, so it does not grow with the list; the loop goes over the same items after a crash.
 */
class LoopItemsStore
{
    public function __construct(
        private readonly RedisConnection $redis,
        private readonly int $ttlSeconds,
    ) {
    }

    public static function key(string $executionId, int $pc): string
    {
        return "exec:{$executionId}:loop:{$pc}";
    }

    /**
     * @param list<mixed> $items non-empty
     * @return string the key
     */
    public function save(string $executionId, int $pc, array $items): string
    {
        $key = self::key($executionId, $pc);
        $encoded = array_map(static fn ($item) => Json::encode($item), $items);
        $ttl = $this->ttlSeconds;
        $this->redis->run(static function (Redis $r) use ($key, $encoded, $ttl) {
            $r->multi(Redis::PIPELINE);
            $r->del($key);
            $r->rPush($key, ...$encoded);
            $r->expire($key, $ttl);
            $r->exec();
        });
        return $key;
    }

    /**
     * @return array{0: bool, 1: mixed} [found, item]; found = false when the snapshot is gone
     */
    public function item(string $key, int $index): array
    {
        $raw = $this->redis->run(static fn (Redis $r) => $r->lIndex($key, $index));
        if (!is_string($raw)) {
            return [false, null];
        }
        return [true, json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function delete(string ...$keys): void
    {
        if ($keys !== []) {
            $this->redis->run(static fn (Redis $r) => $r->del(...$keys));
        }
    }

    /**
     * Contents of the snapshots for a waiting execution (7.2): its Redis keys are removed while it waits.
     *
     * @param list<string> $keys
     * @return array<string, list<string>> key => raw JSON items
     */
    public function dump(array $keys): array
    {
        $dump = [];
        foreach ($keys as $key) {
            $items = $this->redis->run(static fn (Redis $r) => $r->lRange($key, 0, -1));
            $dump[$key] = is_array($items) ? array_values(array_map('strval', $items)) : [];
        }
        return $dump;
    }

    /**
     * @param array<string, list<string>> $dump
     */
    public function restore(array $dump): void
    {
        $ttl = $this->ttlSeconds;
        foreach ($dump as $key => $items) {
            if ($items === []) {
                continue;
            }
            $this->redis->run(static function (Redis $r) use ($key, $items, $ttl) {
                $r->multi(Redis::PIPELINE);
                $r->del($key);
                $r->rPush($key, ...$items);
                $r->expire($key, $ttl);
                $r->exec();
            });
        }
    }
}
