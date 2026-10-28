<?php

declare(strict_types=1);

namespace Wps\Crm;

use Psr\Log\LoggerInterface;
use Redis;
use Wps\Config\AppConfig;
use Wps\Infrastructure\RedisConnection;
use Wps\Services\ExternalApiAdapterService;
use Wps\Support\Json;

/**
 * Capabilities of the CRM plugin, cached in Redis crm:capabilities for DICT_CACHE_TTL (refreshed together with the
 * modules dictionary). An old plugin (no /capabilities: 404 / 405) is contract 1.0; a network error is thrown
 * (recoverable — the step is retried).
 */
final class CrmCapabilitiesService
{
    public const CACHE_KEY = 'crm:capabilities';

    public function __construct(
        private readonly ExternalApiAdapterService $crm,
        private readonly RedisConnection $redis,
        private readonly AppConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(): CrmCapabilities
    {
        return CrmCapabilities::fromArray($this->describe()['capabilities']);
    }

    /**
     * @return array{capabilities: array<string, mixed>, source: string, cached_at: string}
     */
    public function describe(bool $refresh = false): array
    {
        if (!$refresh) {
            try {
                $cached = $this->redis->run(static fn (Redis $r) => $r->get(self::CACHE_KEY));
                if (is_string($cached)) {
                    return (array)Json::decode($cached, []);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('CRM capabilities cache unavailable', ['error' => $e->getMessage()]);
            }
        }
        $raw = $this->crm->capabilities();
        $entry = [
            'capabilities' => $raw ?? CrmCapabilities::legacy()->toArray(),
            'source' => $raw !== null ? 'crm' : 'fallback',
            'cached_at' => gmdate(\DATE_ATOM),
        ];
        try {
            $ttl = $this->config->dictCacheTtl;
            $this->redis->run(static fn (Redis $r) => $r->set(self::CACHE_KEY, Json::encode($entry), ['ex' => $ttl]));
        } catch (\Throwable $e) {
            $this->logger->warning('CRM capabilities cache write failed', ['error' => $e->getMessage()]);
        }
        return $entry;
    }
}
