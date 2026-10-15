<?php

declare(strict_types=1);

namespace Wps\Config;

use Wps\Kernel\Env;

/**
 * Immutable application configuration built from the environment once at bootstrap
 * (master process, before workers fork) and shared through the SwooleApp state container.
 */
final class AppConfig
{
    public function __construct(
        public readonly bool $debug,
        public readonly string $logLevel,
        // Redis: event bus
        public readonly string $streamHost,
        public readonly int $streamPort,
        public readonly ?string $streamPassword,
        public readonly string $streamName,
        public readonly string $consumerGroup,
        public readonly int $streamBatch,
        public readonly int $streamBlockMs,
        public readonly int $streamClaimIdleMs,
        // Redis: data
        public readonly string $dataHost,
        public readonly int $dataPort,
        public readonly ?string $dataPassword,
        public readonly int $dataDb,
        // PostgreSQL
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly int $dbPoolSize,
        // CRM
        public readonly string $crmBaseUrl,
        public readonly string $crmStaticKey,
        public readonly float $crmTimeout,
        public readonly ?string $mockServiceUrl,
        // Execution
        public readonly int $processCacheTtl,
        public readonly int $dictCacheTtl,
        public readonly int $retryMax,
        public readonly int $retryBaseDelayMs,
        public readonly float $retryBackoff,
        public readonly int $executionTtlSeconds,
        public readonly int $executionFinishedTtlSeconds,
        public readonly int $idempotencyTtlSeconds,
        public readonly int $executionLockTtl,
        public readonly int $maxChildDepth,
        public readonly int $recoveryStaleSeconds,
        // Auth
        public readonly string $jwtSecret,
        public readonly int $jwtAccessTtl,
        public readonly int $jwtRefreshTtl,
        public readonly string $corsAllowOrigin,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            debug: Env::bool('APP_DEBUG', false),
            logLevel: strtolower(Env::get('LOG_LEVEL', 'info') ?? 'info'),
            streamHost: Env::get('REDIS_STREAM_HOST', '127.0.0.1') ?? '127.0.0.1',
            streamPort: Env::int('REDIS_STREAM_PORT', 6379),
            streamPassword: Env::get('REDIS_STREAM_PASSWORD') ?: null,
            streamName: Env::get('REDIS_STREAM_NAME', 'crm_events') ?? 'crm_events',
            consumerGroup: Env::get('REDIS_STREAM_CONSUMER_GROUP', 'wps_workers') ?? 'wps_workers',
            streamBatch: max(1, Env::int('REDIS_STREAM_BATCH', 10)),
            streamBlockMs: max(0, Env::int('REDIS_STREAM_BLOCK_MS', 2000)),
            streamClaimIdleMs: max(1000, Env::int('REDIS_STREAM_CLAIM_IDLE_MS', 300000)),
            dataHost: Env::get('REDIS_DATA_HOST', '127.0.0.1') ?? '127.0.0.1',
            dataPort: Env::int('REDIS_DATA_PORT', 6379),
            dataPassword: Env::get('REDIS_DATA_PASSWORD') ?: null,
            dataDb: Env::int('REDIS_DATA_DB', 0),
            dbHost: Env::first(['DB_POSTGRES_HOST', 'DB_HOST'], '127.0.0.1') ?? '127.0.0.1',
            dbPort: (int)(Env::first(['DB_POSTGRES_PORT', 'DB_PORT'], '5432') ?? 5432),
            dbName: Env::first(['DB_POSTGRES_DB', 'DB_NAME'], 'wps') ?? 'wps',
            dbUser: Env::first(['DB_POSTGRES_USER', 'DB_USER'], 'wps') ?? 'wps',
            dbPassword: Env::first(['DB_POSTGRES_PASSWORD', 'DB_PASSWORD'], '') ?? '',
            dbPoolSize: max(2, Env::int('DB_POOL_SIZE', 16)),
            crmBaseUrl: rtrim(Env::get('CRM_API_BASE_URL', 'http://127.0.0.1:9502') ?? '', '/'),
            crmStaticKey: Env::get('CRM_API_STATIC_KEY', '') ?? '',
            crmTimeout: Env::float('CRM_API_TIMEOUT', 10.0),
            mockServiceUrl: (($u = Env::get('MOCK_SERVICE_URL')) !== null && $u !== '') ? rtrim($u, '/') : null,
            processCacheTtl: max(1, Env::int('PROCESS_CACHE_TTL', 60)),
            dictCacheTtl: max(1, Env::int('DICT_CACHE_TTL', 300)),
            retryMax: max(0, Env::int('ACTION_RETRY_MAX', 3)),
            retryBaseDelayMs: max(0, Env::int('ACTION_RETRY_BASE_DELAY_MS', 1000)),
            retryBackoff: max(1.0, Env::float('ACTION_RETRY_BACKOFF', 2.0)),
            executionTtlSeconds: max(1, Env::int('EXECUTION_TTL_HOURS', 24)) * 3600,
            executionFinishedTtlSeconds: max(1, Env::int('EXECUTION_FINISHED_TTL_SECONDS', 3600)),
            idempotencyTtlSeconds: max(1, Env::int('IDEMPOTENCY_TTL_HOURS', 24)) * 3600,
            executionLockTtl: max(5, Env::int('EXECUTION_LOCK_TTL', 30)),
            maxChildDepth: max(1, Env::int('MAX_CHILD_DEPTH', 10)),
            recoveryStaleSeconds: max(30, Env::int('RECOVERY_STALE_SECONDS', 300)),
            jwtSecret: Env::get('JWT_SECRET', '') ?: 'insecure-dev-secret',
            jwtAccessTtl: max(60, Env::int('JWT_ACCESS_TTL', 900)),
            jwtRefreshTtl: max(300, Env::int('JWT_REFRESH_TTL', 604800)),
            corsAllowOrigin: Env::get('CORS_ALLOW_ORIGIN', '*') ?? '*',
        );
    }

    /**
     * Full CRM URL for an API path such as "/Accounts/123".
     * CRM_API_BASE_URL may be given with or without the "/api/v8" suffix.
     */
    public function crmUrl(string $path): string
    {
        $base = $this->crmBaseUrl;
        if (!preg_match('#/api/v\d+$#', $base)) {
            $base .= '/api/v8';
        }
        return $base . '/' . ltrim($path, '/');
    }
}
