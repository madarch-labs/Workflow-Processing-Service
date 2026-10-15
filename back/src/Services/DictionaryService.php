<?php

declare(strict_types=1);

namespace Wps\Services;

use Psr\Log\LoggerInterface;
use Redis;
use Wps\Config\AppConfig;
use Wps\Infrastructure\RedisConnection;
use Wps\Support\Json;

/**
 * CRM modules dictionary, cached in Redis dict:modules with DICT_CACHE_TTL (TS §9.5).
 */
final class DictionaryService
{
    private const KEY = 'dict:modules';

    public function __construct(
        private readonly ExternalApiAdapterService $api,
        private readonly RedisConnection $redis,
        private readonly AppConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function modules(bool $refresh = false): array
    {
        if (!$refresh) {
            $cached = $this->redis->run(static fn (Redis $r) => $r->get(self::KEY));
            if (is_string($cached)) {
                return (array)Json::decode($cached, []);
            }
        }
        try {
            $modules = $this->api->getModules();
        } catch (\Throwable $e) {
            $this->logger->warning('Modules dictionary could not be loaded', ['error' => $e->getMessage()]);
            return [];
        }
        if ($modules !== []) {
            $payload = Json::encode($modules);
            $this->redis->run(fn (Redis $r) => $r->set(self::KEY, $payload, ['ex' => $this->config->dictCacheTtl]));
        }
        return $modules;
    }
}
