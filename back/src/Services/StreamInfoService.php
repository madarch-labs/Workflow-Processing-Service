<?php

declare(strict_types=1);

namespace Wps\Services;

use Redis;
use Wps\Config\AppConfig;
use Wps\Infrastructure\RedisConnection;

/**
 * Consumer group state of the event stream (for /api/health and the wps_redis_stream_lag gauge).
 */
final class StreamInfoService
{
    public function __construct(
        private readonly RedisConnection $redis,
        private readonly AppConfig $config,
    ) {
    }

    /**
     * @return array{stream: string, group: string, length: int, lag: int|null, pending: int, consumers: int}
     */
    public function info(): array
    {
        $stream = $this->config->streamName;
        $group = $this->config->consumerGroup;
        return $this->redis->run(static function (Redis $r) use ($stream, $group) {
            $length = (int)$r->xLen($stream);
            $result = ['stream' => $stream, 'group' => $group, 'length' => $length, 'lag' => null, 'pending' => 0, 'consumers' => 0];
            try {
                $groups = $r->xInfo('GROUPS', $stream);
            } catch (\RedisException) {
                return $result; // the stream does not exist yet
            }
            foreach (is_array($groups) ? $groups : [] as $info) {
                if (is_array($info) && ($info['name'] ?? null) === $group) {
                    $result['lag'] = isset($info['lag']) ? (int)$info['lag'] : null;
                    $result['pending'] = (int)($info['pending'] ?? 0);
                    $result['consumers'] = (int)($info['consumers'] ?? 0);
                }
            }
            return $result;
        });
    }
}
