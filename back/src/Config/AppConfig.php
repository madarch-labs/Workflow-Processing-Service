<?php

declare(strict_types=1);

namespace Wps\Config;

use Wps\Kernel\Env;
use Wps\Secrets\SecretCipher;

/**
 * Immutable application configuration built from the environment once at bootstrap
 * (master process, before workers fork) and shared through the SwooleApp state container.
 */
final class AppConfig
{
    public function __construct(
        public readonly bool $debug,
        public readonly string $logLevel,
        // business time zone (IANA): "today", start of day, date casts (WPS_TIMEZONE, default UTC)
        public readonly string $timezone,
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
        /** upper bound of one retry delay (ACTION_RETRY_MAX_DELAY, ms) and the default jitter (7.1) */
        public readonly int $retryMaxDelayMs,
        public readonly string $retryJitter,
        public readonly int $executionTtlSeconds,
        public readonly int $executionFinishedTtlSeconds,
        public readonly int $idempotencyTtlSeconds,
        public readonly int $executionLockTtl,
        public readonly int $maxChildDepth,
        public readonly int $recoveryStaleSeconds,
        // flat program (md/tasks_list/done/0.1-program-compiler.md)
        public readonly int $programMaxDepth,
        public readonly int $maxInstructionsPerExecution,
        // secrets (md/tasks_list/done/7.4-secrets.md): raw 32-byte keys, null = secrets are not available
        public readonly ?string $secretsKey,
        public readonly ?string $secretsKeyPrevious,
        public readonly int $secretsCacheTtl,
        // side-effect journal (md/tasks_list/done/0.4-side-effect-ledger.md)
        public readonly int $sideEffectsRetentionDays,
        public readonly string $mailMessageIdDomain,
        /** send_email (md/tasks_list/done/4.1-send-email.md): default transport, sender and limits */
        public readonly string $mailerDsn,
        public readonly string $mailerFrom,
        public readonly string $mailerFromName,
        /** @var list<string> addresses or "@domain" */
        public readonly array $mailerAllowedFrom,
        public readonly int $mailerTimeout,
        public readonly int $mailerMaxRecipients,
        public readonly int $mailerRatePerMinute,
        public readonly int $mailerRatePerRecipientHour,
        /** http_request (md/tasks_list/done/4.2-http-request.md): SSRF protection and limits */
        public readonly bool $httpAllowPlainHttp,
        /** @var list<int> */
        public readonly array $httpDenyPorts,
        /** @var list<int> */
        public readonly array $httpAllowPorts,
        /** @var list<string> */
        public readonly array $httpAllowHosts,
        /** @var list<string> */
        public readonly array $httpDenyHosts,
        /** @var list<string> */
        public readonly array $httpAllowPrivateCidrs,
        public readonly bool $httpTlsVerify,
        public readonly string $httpCaFile,
        public readonly int $httpMaxTimeout,
        public readonly int $httpMaxResponseBytes,
        public readonly int $httpMaxRequestBytes,
        public readonly int $httpRetryAfterMax,
        public readonly int $httpMaxStoredBodyBytes,
        /** related ids per link_records / unlink_records step (4.12) */
        public readonly int $linkRecordsMax,
        /** waiting executions (md/tasks_list/done/7.2-waiting-executions.md) */
        public readonly int $waitPollIntervalMs,
        public readonly int $waitBatch,
        public readonly int $waitLeaseSeconds,
        public readonly int $waitMaxAttempts,
        public readonly int $waitInlineMaxSeconds,
        public readonly int $waitStateMaxBytes,
        /** longest delay step (4.7), ISO 8601 or a short form */
        public readonly string $delayMax,
        /** find_records (4.10): records per step, related records scanned, size of the variable */
        public readonly int $findRecordsMax,
        public readonly int $findRecordsScanMax,
        public readonly int $findRecordsMaxBytes,
        /** foreach: most items of one loop (LOOP_MAX_ITEMS, ≤ 5000) */
        public readonly int $loopMaxItems,
        /** update_records: most records changed by one step (UPDATE_RECORDS_MAX) */
        public readonly int $updateRecordsMax,
        /** aggregate: most records read by one aggregation (AGGREGATE_MAX_RECORDS) */
        public readonly int $aggregateMaxRecords,
        /** notify: fields of CRM alerts and record links (CRM_ALERT_PROFILE: suitecrm8 | suitecrm7 | custom) */
        public readonly string $crmAlertProfile,
        /** notify: link to a record, {module} {module_lower} {module_kebab} {id}; empty — by the profile */
        public readonly string $crmRecordUrlTemplate,
        /** notify: base URL of the CRM UI for absolute links in messengers (default: CRM_API_BASE_URL without /api/…) */
        public readonly string $crmUiBaseUrl,
        public readonly string $telegramApiBase,
        /** assign: members of a security group are cached this many seconds (ASSIGN_POOL_CACHE_TTL) */
        public readonly int $assignPoolCacheTtl,
        /** assign least_busy: most users in the pool (one count request each) */
        public readonly int $assignLeastBusyMaxPool,
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
            timezone: self::timezone(Env::get('WPS_TIMEZONE', 'UTC') ?: 'UTC'),
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
            retryMaxDelayMs: \Wps\Time\Duration::toMs(Env::get('ACTION_RETRY_MAX_DELAY', '1h') ?: '1h') ?? 3600000,
            retryJitter: in_array(Env::get('ACTION_RETRY_JITTER', 'full'), ['none', 'full', 'equal'], true) ? (string)Env::get('ACTION_RETRY_JITTER', 'full') : 'full',
            executionTtlSeconds: max(1, Env::int('EXECUTION_TTL_HOURS', 24)) * 3600,
            executionFinishedTtlSeconds: max(1, Env::int('EXECUTION_FINISHED_TTL_SECONDS', 3600)),
            idempotencyTtlSeconds: max(1, Env::int('IDEMPOTENCY_TTL_HOURS', 24)) * 3600,
            executionLockTtl: max(5, Env::int('EXECUTION_LOCK_TTL', 30)),
            maxChildDepth: max(1, Env::int('MAX_CHILD_DEPTH', 10)),
            recoveryStaleSeconds: max(30, Env::int('RECOVERY_STALE_SECONDS', 300)),
            programMaxDepth: max(1, Env::int('PROGRAM_MAX_DEPTH', 5)),
            maxInstructionsPerExecution: max(10, Env::int('EXECUTION_MAX_INSTRUCTIONS', 5000)),
            secretsKey: ($k = Env::get('WPS_SECRETS_KEY')) !== null && $k !== '' ? SecretCipher::decodeKey($k, 'WPS_SECRETS_KEY') : null,
            secretsKeyPrevious: ($k = Env::get('WPS_SECRETS_KEY_PREVIOUS')) !== null && $k !== '' ? SecretCipher::decodeKey($k, 'WPS_SECRETS_KEY_PREVIOUS') : null,
            secretsCacheTtl: max(0, Env::int('SECRETS_CACHE_TTL', 60)),
            sideEffectsRetentionDays: max(1, Env::int('SIDE_EFFECTS_RETENTION_DAYS', 30)),
            mailMessageIdDomain: Env::get('WPS_MAIL_MESSAGE_ID_DOMAIN', 'wps.local') ?: 'wps.local',
            mailerDsn: (string)(Env::get('MAILER_DSN', '') ?? ''),
            mailerFrom: (string)(Env::get('MAILER_FROM', '') ?? ''),
            mailerFromName: (string)(Env::get('MAILER_FROM_NAME', '') ?? ''),
            mailerAllowedFrom: array_values(array_filter(array_map(static fn (string $v) => strtolower(trim($v)), explode(',', (string)(Env::get('MAILER_ALLOWED_FROM', '') ?? ''))))),
            mailerTimeout: max(1, Env::int('MAILER_TIMEOUT', 15)),
            mailerMaxRecipients: max(1, Env::int('MAILER_MAX_RECIPIENTS', 50)),
            mailerRatePerMinute: max(0, Env::int('MAILER_RATE_PER_MINUTE', 60)),
            mailerRatePerRecipientHour: max(0, Env::int('MAILER_RATE_PER_RECIPIENT_HOUR', 10)),
            httpAllowPlainHttp: Env::get('HTTP_ALLOW_PLAIN_HTTP', 'true') !== 'false',
            httpDenyPorts: self::intList(Env::get('HTTP_DENY_PORTS', '22,25,3306,5432,6379,6380,9200,11211')),
            httpAllowPorts: self::intList(Env::get('HTTP_ALLOW_PORTS', '')),
            httpAllowHosts: self::list(Env::get('HTTP_ALLOW_HOSTS', '')),
            httpDenyHosts: self::list(Env::get('HTTP_DENY_HOSTS', null) ?? implode(',', array_filter(['localhost', 'metadata.google.internal', 'postgres', 'redis-stream', 'redis-data', 'mock',
                (string)parse_url((string)(Env::get('CRM_API_BASE_URL', '') ?? ''), PHP_URL_HOST)]))),
            httpAllowPrivateCidrs: self::list(Env::get('HTTP_ALLOW_PRIVATE_CIDRS', '')),
            httpTlsVerify: Env::get('HTTP_TLS_VERIFY', 'true') !== 'false',
            httpCaFile: (string)(Env::get('HTTP_CA_FILE', '') ?? ''),
            httpMaxTimeout: max(1, Env::int('HTTP_MAX_TIMEOUT', 30)),
            httpMaxResponseBytes: max(1024, Env::int('HTTP_MAX_RESPONSE_BYTES', 5242880)),
            httpMaxRequestBytes: max(1024, Env::int('HTTP_MAX_REQUEST_BYTES', 1048576)),
            httpRetryAfterMax: max(1, Env::int('HTTP_RETRY_AFTER_MAX', 60)),
            httpMaxStoredBodyBytes: max(0, Env::int('HTTP_MAX_STORED_BODY_BYTES', 65536)),
            linkRecordsMax: max(1, Env::int('LINK_RECORDS_MAX', 500)),
            waitPollIntervalMs: max(100, Env::int('WAIT_POLL_INTERVAL_MS', 1000)),
            waitBatch: max(1, Env::int('WAIT_BATCH', 20)),
            waitLeaseSeconds: max(10, Env::int('WAIT_LEASE_SECONDS', 120)),
            waitMaxAttempts: max(1, Env::int('WAIT_MAX_ATTEMPTS', 10)),
            waitInlineMaxSeconds: max(0, Env::int('WAIT_INLINE_MAX_SECONDS', 5)),
            waitStateMaxBytes: max(10000, Env::int('WAIT_STATE_MAX_BYTES', 1048576)),
            delayMax: Env::get('DELAY_MAX', 'P400D') ?: 'P400D',
            findRecordsMax: max(1, Env::int('FIND_RECORDS_MAX', 500)),
            findRecordsScanMax: max(1, Env::int('FIND_RECORDS_SCAN_MAX', 1000)),
            findRecordsMaxBytes: max(10000, Env::int('FIND_RECORDS_MAX_BYTES', 1048576)),
            loopMaxItems: max(1, min(5000, Env::int('LOOP_MAX_ITEMS', 500))),
            updateRecordsMax: max(1, min(5000, Env::int('UPDATE_RECORDS_MAX', 200))),
            aggregateMaxRecords: max(1, min(50000, Env::int('AGGREGATE_MAX_RECORDS', 5000))),
            crmAlertProfile: in_array($p = (string)Env::get('CRM_ALERT_PROFILE', 'suitecrm8'), ['suitecrm8', 'suitecrm7', 'custom'], true) ? $p : 'suitecrm8',
            crmRecordUrlTemplate: (string)Env::get('CRM_RECORD_URL_TEMPLATE', ''),
            crmUiBaseUrl: (string)Env::get('CRM_UI_BASE_URL', '') !== '' ? (string)Env::get('CRM_UI_BASE_URL', '')
                : (string)preg_replace('#/api(/.*)?$#', '/', (string)Env::get('CRM_API_BASE_URL', '')),
            telegramApiBase: (string)Env::get('TELEGRAM_API_BASE', 'https://api.telegram.org'),
            assignPoolCacheTtl: max(0, Env::int('ASSIGN_POOL_CACHE_TTL', 60)),
            assignLeastBusyMaxPool: max(1, min(200, Env::int('ASSIGN_LEAST_BUSY_MAX_POOL', 50))),
            jwtSecret: Env::get('JWT_SECRET', '') ?: 'insecure-dev-secret',
            jwtAccessTtl: max(60, Env::int('JWT_ACCESS_TTL', 900)),
            jwtRefreshTtl: max(300, Env::int('JWT_REFRESH_TTL', 604800)),
            corsAllowOrigin: Env::get('CORS_ALLOW_ORIGIN', '*') ?? '*',
        );
    }

    /**
     * "a, b ,c" → ["a", "b", "c"] (lower case)
     *
     * @return list<string>
     */
    private static function list(?string $value): array
    {
        return array_values(array_filter(array_map(static fn (string $v) => strtolower(trim($v)), explode(',', (string)$value)), static fn ($v) => $v !== ''));
    }

    /**
     * @return list<int>
     */
    private static function intList(?string $value): array
    {
        return array_values(array_map('intval', array_filter(self::list($value), 'ctype_digit')));
    }

    /**
     * Validates WPS_TIMEZONE at bootstrap: a wrong value stops the service with a clear message.
     */
    private static function timezone(string $name): string
    {
        try {
            return (new \DateTimeZone($name))->getName();
        } catch (\Exception) {
            throw new \InvalidArgumentException("WPS_TIMEZONE: unknown time zone \"{$name}\" (use an IANA name, e.g. Europe/Moscow)");
        }
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
