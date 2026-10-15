# Configuration

Every environment variable and configuration key of WPS, with defaults and meaning.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/04-operations/configuration.md)
<!-- /lang-switch -->

## Where values come from

| Level | File | Used by |
|---|---|---|
| Host ports and CRM connection | `./.env` in the repository root | `docker compose` (variable substitution in `docker-compose.yml`) |
| Container environment | `environment:` sections of `docker-compose.yml` | backend, mock, postgres |
| Backend file defaults | `back/.env` (copied from `back/.env.example` by the entrypoint if missing) | `back/server.php`, `back/migrations/migrate.php` |
| Code defaults | `back/src/Config/AppConfig.php`, `back/server.php` | when a variable is set nowhere |
| Framework config | `back/config/config.json` | SwooleApp: controllers, middlewares, cyclic jobs |

Resolution order inside the backend (`Wps\Kernel\Env`): **a real environment variable wins over `back/.env`**, `back/.env`
wins over the code default. `back/.env` never overrides a variable that already exists in the process environment, even an
empty one. Values are read once at start: restart the backend after a change.

> **Note**
> `back/.env` is excluded from the Docker build context (`.dockerignore`), so the container always starts from a copy of
> `back/.env.example`. Configure a containerised backend through `docker-compose.yml` / `./.env`, not through `back/.env`.

## Root `./.env` (Docker Compose)

| Variable | Default in `docker-compose.yml` | Description |
|---|---|---|
| `WPS_UI_PORT` | `3000` | Host port of the UI (this repo sets `3080`) |
| `WPS_API_PORT` | `9501` | Host port of the backend API |
| `WPS_MOCK_PORT` | `9502` | Host port of the mock service |
| `WPS_PG_PORT` | `5432` | Host port of PostgreSQL (this repo sets `55432`) |
| `WPS_REDIS_STREAM_PORT` | `6379` | Host port of the event-bus Redis |
| `WPS_REDIS_DATA_PORT` | `6380` | Host port of the data Redis |
| `CRM_API_BASE_URL` | `http://mock:9502` | Passed to the backend; CRM API base URL |
| `CRM_API_STATIC_KEY` | `mySecretKey` | Passed to the backend; CRM API key prefix |
| `MOCK_SERVICE_URL` | `http://mock:9502` | Passed to the backend; set it to an **empty** value to load the modules dictionary from the CRM |

> **Warning**
> This repository's `./.env` currently points the backend to a local SuiteCRM 8 (`CRM_API_BASE_URL=http://suitecrm8/api/v9`,
> `MOCK_SERVICE_URL=` empty). To work with the mock, remove the three `CRM_*`/`MOCK_*` lines from `./.env` or override them on
> the command line. See [CRM integration](crm-integration.md).

## Backend variables

### Server and Swoole (`back/server.php`)

| Variable | Default | Description |
|---|---|---|
| `APP_DEBUG` | `false` (`config.json`) | `true` returns internal exception messages in `500` responses. Keep `false` in production |
| `SERVER_HOST` | `0.0.0.0` | Listen address |
| `SERVER_PORT` | `9501` | Listen port |
| `SERVER_MODE` | `PROCESS` | Swoole server mode (`PROCESS` / `BASE`) |
| `SWOOLE_WORKER_NUM` | `2` | HTTP workers; each is also a stream consumer |
| `SWOOLE_TASK_WORKER_NUM` | `1` | Task workers (cyclic jobs are skipped there) |
| `SWOOLE_MAX_COROUTINE` | `3000` | Max coroutines per worker |
| `LOG_LEVEL` | `info` | `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` |

### Redis — event bus

| Variable | Default | Description |
|---|---|---|
| `REDIS_STREAM_HOST` | `127.0.0.1` (compose: `redis-stream`) | Host |
| `REDIS_STREAM_PORT` | `6379` | Port |
| `REDIS_STREAM_PASSWORD` | empty | Password (`AUTH`) |
| `REDIS_STREAM_NAME` | `crm_events` | Stream name; the dead-letter stream is `<name>:dlq` |
| `REDIS_STREAM_CONSUMER_GROUP` | `wps_workers` | Consumer group (created automatically from id `0`) |
| `REDIS_STREAM_BATCH` | `10` | Messages per `XREADGROUP` |
| `REDIS_STREAM_BLOCK_MS` | `2000` | `XREADGROUP BLOCK` time |
| `REDIS_STREAM_CLAIM_IDLE_MS` | `300000` (min 1000) | Unacknowledged messages idle longer than this are re-claimed (`XAUTOCLAIM`) |

### Redis — data

| Variable | Default | Description |
|---|---|---|
| `REDIS_DATA_HOST` | `127.0.0.1` (compose: `redis-data`) | Host |
| `REDIS_DATA_PORT` | `6379` in code, `6380` in `.env.example` (compose: `6379`) | Port |
| `REDIS_DATA_PASSWORD` | empty | Password |
| `REDIS_DATA_DB` | `0` | Database number |

### PostgreSQL

| Variable | Default | Description |
|---|---|---|
| `DB_POSTGRES_HOST` (alias `DB_HOST`) | `127.0.0.1` (compose: `postgres`) | Host |
| `DB_POSTGRES_PORT` (alias `DB_PORT`) | `5432` | Port |
| `DB_POSTGRES_DB` (alias `DB_NAME`) | `wps` | Database |
| `DB_POSTGRES_USER` (alias `DB_USER`) | `wps` | User |
| `DB_POSTGRES_PASSWORD` (alias `DB_PASSWORD`) | empty in code, `secret` in `.env.example` and compose | Password |
| `DB_POOL_SIZE` | `16` (min 2) | Connection pool size per worker |
| `DB_WAIT_SECONDS` | `60` | How long `migrate.php` waits for the database on start |

### CRM

| Variable | Default | Description |
|---|---|---|
| `CRM_API_BASE_URL` | `http://127.0.0.1:9502` | CRM API base. `/api/v8` is appended unless the URL already ends with `/api/vN` |
| `CRM_API_STATIC_KEY` | empty | Key prefix of the `X-Auth-Key` header |
| `CRM_API_TIMEOUT` | `10` | HTTP timeout, seconds |
| `MOCK_SERVICE_URL` | not set | If set, the modules dictionary is loaded from `<url>/modules` first, then from the CRM |

### Execution

| Variable | Default | Description |
|---|---|---|
| `PROCESS_CACHE_TTL` | `60` s | Cache of active processes per object type (`proc_cache:<type>`); invalidated when a process is saved |
| `DICT_CACHE_TTL` | `300` s | Cache of the modules dictionary (`dict:modules`) |
| `ACTION_RETRY_MAX` | `3` | Retries of a step after a recoverable error |
| `ACTION_RETRY_BASE_DELAY_MS` | `1000` | First retry delay |
| `ACTION_RETRY_BACKOFF` | `2` (min 1) | Delay multiplier: `delay = base × backoff^(attempt−1)` |
| `EXECUTION_TTL_HOURS` | `24` | TTL of the live execution state `exec:<uuid>` in Redis |
| `EXECUTION_FINISHED_TTL_SECONDS` | `3600` | TTL of the state after the execution finished |
| `IDEMPOTENCY_TTL_HOURS` | `24` | TTL of `idem:<event_id>:<process_id>` keys |
| `EXECUTION_LOCK_TTL` | `30` s (min 5) | Lock of a running execution; refreshed before every step attempt |
| `MAX_CHILD_DEPTH` | `10` | Maximum nesting of child processes |
| `RECOVERY_STALE_SECONDS` | `300` (min 30) | Unfinished executions older than this are resumed or marked failed by `RecoveryJob` |

### Authentication and HTTP

| Variable | Default | Description |
|---|---|---|
| `JWT_SECRET` | `insecure-dev-secret` (compose sets `change-me-in-production-0123456789abcdef`) | HMAC-SHA256 key for tokens. **Must be changed** |
| `JWT_ACCESS_TTL` | `900` s (min 60) | Access token lifetime |
| `JWT_REFRESH_TTL` | `604800` s (min 300) | Refresh token lifetime (7 days) |
| `CORS_ALLOW_ORIGIN` | `*` | Value of `Access-Control-Allow-Origin` |
| `API_BASE_URL` | `http://localhost:9501` (compose) | Set by compose; not read by the backend code |

## Mock service variables

| Variable | Default | Description |
|---|---|---|
| `MOCK_REDIS_HOST` | `127.0.0.1` (compose: `redis-stream`) | Redis to publish events to and to store mock CRM objects in |
| `MOCK_REDIS_PORT` | `6379` | Port |
| `MOCK_REDIS_PASSWORD` | empty | Password |
| `MOCK_REDIS_STREAM` | `crm_events` | Stream name |
| `MOCK_CRM_STATIC_KEY` | empty (compose: `mySecretKey`) | Expected key prefix of `X-Auth-Key`; empty disables the check. Must equal `CRM_API_STATIC_KEY` |

## PostgreSQL container

Standard variables of the `postgres` image, set in `docker-compose.yml`. Keep them in sync with `DB_POSTGRES_*`.

| Variable | Compose value | Description |
|---|---|---|
| `POSTGRES_DB` | `wps` | Database created on first start (= `DB_POSTGRES_DB`) |
| `POSTGRES_USER` | `wps` | Owner user (= `DB_POSTGRES_USER`) |
| `POSTGRES_PASSWORD` | `secret` | Password (= `DB_POSTGRES_PASSWORD`) |

## `back/config/config.json`

SwooleApp configuration. `server.php` overrides `server.*` and `SWOOLE.*` from the environment and always sets
`SWOOLE.hook_flags = SWOOLE_HOOK_ALL` (PDO, phpredis, sockets and `sleep` become non-blocking inside coroutines).

| Key | Value | Meaning |
|---|---|---|
| `APP_DEBUG` | `false` | Default for `APP_DEBUG` |
| `server.host`, `server.port`, `server.mode` | `0.0.0.0`, `9501`, `PROCESS` | Defaults for `SERVER_*` |
| `SWOOLE.worker_num`, `task_worker_num`, `max_coroutine` | `2`, `1`, `3000` | Defaults for `SWOOLE_*` |
| `SWOOLE.enable_coroutine` | `true` | Coroutines in request handlers |
| `SWOOLE.package_max_length` | `4194304` | Max request size, 4 MB |
| `SWOOLE.http_parse_post` | `false` | Bodies are parsed as JSON by the controllers |
| `controllers` | `["Wps\\Controllers"]` | Namespace scanned for `#[Route]` controllers |
| `globalMiddlewares` | `["Wps\\Middleware\\AuthMiddleware"]` | JWT + role check for every request |
| `CyclicJobs` | `StreamConsumerJob`, `RecoveryJob` | Background loops (0.5 s and 30 s) |
| `cyclic_jobs.strategy` | `ALL_WORKERS` | Every HTTP worker runs the jobs |
| `cyclic_jobs.monitoring_interval` | `60` | Interval of the framework health check of cyclic jobs, seconds (with `APP_DEBUG` it also logs job statistics) |
| `StateContainerInitiation` | `["Wps\\State\\StateInitiation"]` | Builds `AppConfig` and the shared metrics table before workers fork |
| `events` | `[]` | Framework events (not used) |

## Example: production overrides

```yaml
# docker-compose.override.yml
services:
  backend:
    environment:
      JWT_SECRET: "<64 random characters>"
      CORS_ALLOW_ORIGIN: "https://wps.example.com"
      LOG_LEVEL: warning
      SWOOLE_WORKER_NUM: "4"
      CRM_API_TIMEOUT: "5"
```

<!-- footer-nav -->
---

← Previous: [Deployment](deployment.md) | [Contents](../README.md) | [CRM integration](crm-integration.md) Next →
<!-- /footer-nav -->
