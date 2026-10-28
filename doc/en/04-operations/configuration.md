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

### How values are parsed

`Wps\Kernel\Env` and `AppConfig::fromEnv()` parse environment strings with the same rules:

| Kind of value | Rule | Example |
|---|---|---|
| Line of `back/.env` | `KEY=value`; lines starting with `#` and lines without `=` are skipped; `"…"` / `'…'` quotes around the value are removed | `MAILER_FROM_NAME="WPS bot"` |
| Integer (`Env::int`) | not a number or empty — the default; then the bounds (`max(…)`, `min(…)`) of `AppConfig` are applied | `WAIT_POLL_INTERVAL_MS=50` → `100` |
| Float (`Env::float`) | the same | `CRM_API_TIMEOUT=2.5` |
| Boolean (`Env::bool`) | `1`, `true`, `yes`, `on` (case-insensitive) — true, any other non-empty value — false | `APP_DEBUG=on` |
| Flags `HTTP_ALLOW_PLAIN_HTTP`, `HTTP_TLS_VERIFY` | switched off **only** by the string `false`; any other value keeps them on | `HTTP_TLS_VERIFY=0` — verification **stays on** |
| Lists (`HTTP_*_HOSTS`, `HTTP_*_PORTS`, `HTTP_ALLOW_PRIVATE_CIDRS`, `MAILER_ALLOWED_FROM`) | comma separated, trimmed, lower-cased, empty items dropped; non-numeric items of port lists are ignored | `HTTP_ALLOW_HOSTS=api.example.com, *.erp.local` |
| Enumerations (`ACTION_RETRY_JITTER`, `CRM_ALERT_PROFILE`) | an unknown value is silently replaced by the default | `ACTION_RETRY_JITTER=random` → `full` |
| Durations (`ACTION_RETRY_MAX_DELAY`, `DELAY_MAX`) | short form (`500ms`, `30s`, `10m`, `1h`, `2d`, `1w`, combinable: `1h30m`) or ISO 8601 (`PT5M`, `P400D`); for `ACTION_RETRY_MAX_DELAY` a bare number means milliseconds, months and years are not allowed, an unparsable value gives `1h` | `ACTION_RETRY_MAX_DELAY=15m` |
| `DB_POSTGRES_*` and the `DB_*` synonyms | the first **non-empty** value of the pair is taken | `DB_HOST=db` works when `DB_POSTGRES_HOST` is not set |

Values validated at start that stop the service: `WPS_TIMEZONE` (unknown zone) and `WPS_SECRETS_KEY` /
`WPS_SECRETS_KEY_PREVIOUS` (not base64 of 32 bytes). Other mistakes are silently corrected by the bounds — check the
effective values with the commands of [Checking the effective configuration](#checking-the-effective-configuration).

## Root `./.env` (Docker Compose)

| Variable | Default in `docker-compose.yml` | Description |
|---|---|---|
| `WPS_UI_PORT` | `3000` | Host port of the UI (this repo sets `3080`) |
| `WPS_API_PORT` | `9501` | Host port of the backend API |
| `WPS_MOCK_PORT` | `9502` | Host port of the mock service |
| `WPS_PG_PORT` | `5432` | Host port of PostgreSQL (this repo sets `55432`) |
| `WPS_REDIS_STREAM_PORT` | `6379` | Host port of the event-bus Redis |
| `WPS_REDIS_DATA_PORT` | `6380` | Host port of the data Redis |
| `WPS_MAILPIT_PORT` | `8025` | Host port of the Mailpit web UI and API |
| `CRM_API_BASE_URL` | `http://mock:9502` | Passed to the backend; CRM API base URL |
| `CRM_API_STATIC_KEY` | `mySecretKey` | Passed to the backend; CRM API key prefix |
| `MOCK_SERVICE_URL` | `http://mock:9502` | Passed to the backend; set it to an **empty** value to load the modules dictionary from the CRM |

Besides these, `docker-compose.yml` passes the following to the backend from `./.env` or the command line (default after
`:-`): `WPS_TIMEZONE` (`UTC`), `WPS_SECRETS_KEY` (a test key), `WPS_SECRETS_KEY_PREVIOUS` (empty), `MAILER_DSN`
(`smtp://mailpit:1025`), `MAILER_FROM` (`wps@wps.local`), `MAILER_FROM_NAME` (`WPS`), `HTTP_ALLOW_PRIVATE_CIDRS`
(`172.16.0.0/12,192.168.0.0/16`), `HTTP_DENY_HOSTS` (`localhost,metadata.google.internal,postgres,redis-stream,redis-data`),
`RECOVERY_STALE_SECONDS` (`300`), `TELEGRAM_API_BASE` (`https://api.telegram.org`), `CRM_UI_BASE_URL` (empty),
`CRM_ALERT_PROFILE` (`suitecrm8`). Add any other backend variable to the `environment:` section (or to
`docker-compose.override.yml`) — a variable of `./.env` does not reach the container by itself.

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
| `WPS_TIMEZONE` | `UTC` | Business time zone (IANA name, e.g. `Europe/Moscow`): values with a time are moved into it before a `cast: date` comparison (see [Comparison type (cast)](../03-user-guide/triggers-and-conditions.md#comparison-type-cast)); the date functions of expressions work in it — `today()`, `date()`, calendar days in `add_days`/`add_months`, `date_diff(…, 'days')`, period boundaries `start_of_*`/`end_of_*`, date parts, `is_weekend`, `format_date` by default; the `date_shift` change type — base `today`, calendar shifts (days, weeks, months), `set_time` and `result: date` (see [Date shift](../03-user-guide/change-types-and-expressions.md#date-shift)); anchors of [relative dates](../03-user-guide/triggers-and-conditions.md#relative-dates) in conditions and filters (`today`, `start_of_month`, …); a date without a time where a moment is needed is midnight in this zone (see [Dates and time](../03-user-guide/change-types-and-expressions.md#dates-and-time)). CRM dates are treated as UTC and the functions return datetimes in UTC too. A wrong name stops the service (`WPS_TIMEZONE: unknown time zone …`); at startup the value is logged (`Business time zone`, level info). Working days and holidays are not taken into account. `docker-compose.yml` passes it as `${WPS_TIMEZONE:-UTC}` |

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
| `ACTION_RETRY_MAX_DELAY` | `1h` | Upper bound of one retry delay (duration: `30s`, `10m`, `1h`, `PT5M`); the step's `retry.max_delay` overrides it |
| `ACTION_RETRY_JITTER` | `full` | Random spread of delays: `none` (as before, no spread), `full` (in `[0, d]`), `equal` (`d/2 + [0, d/2]`); see [Reliability](reliability.md#retries-of-a-step) |
| `EXECUTION_TTL_HOURS` | `24` | TTL of the live execution state `exec:<uuid>` in Redis |
| `EXECUTION_FINISHED_TTL_SECONDS` | `3600` | TTL of the state after the execution finished |
| `IDEMPOTENCY_TTL_HOURS` | `24` | TTL of `idem:<event_id>:<process_id>` keys |
| `EXECUTION_LOCK_TTL` | `30` s (min 5) | Lock of a running execution; refreshed before every step attempt |
| `MAX_CHILD_DEPTH` | `10` | Maximum nesting of child processes |
| `RECOVERY_STALE_SECONDS` | `300` (min 30; in compose — `${RECOVERY_STALE_SECONDS:-300}`) | Unfinished executions older than this are resumed or marked failed by `RecoveryJob`. In compose the value can be overridden at start (`RECOVERY_STALE_SECONDS=30 docker compose up -d backend`) — this is how case 4.2 checks recovery after a worker crash |
| `PROGRAM_MAX_DEPTH` | `5` (min 1) | Maximum nesting depth of blocks (`condition` etc.) in a process definition; deeper — save error `Nesting is too deep` |
| `EXECUTION_MAX_INSTRUCTIONS` | `5000` (min 10) | Maximum number of program instructions in one execution; more — the execution fails with `LIMIT_EXCEEDED` (guard against endless jumps) |

### Secrets

| Variable | Default | Description |
|---|---|---|
| `WPS_SECRETS_KEY` | empty (a test key in `docker-compose.yml`) | Key of the secrets store: base64 of 32 bytes (`php bin/secrets-key.php`). Empty — secrets are unavailable, the service runs; wrong format — the service does not start. See [Security → Process secrets](security.md#process-secrets) |
| `WPS_SECRETS_KEY_PREVIOUS` | empty | The previous key during rotation: rows encrypted with it still decrypt; remove it after `POST /api/secrets/rotate` |
| `SECRETS_CACHE_TTL` | `60` s (min 0) | How long a decrypted value stays in the worker memory; also how long it takes to switch to a changed value |

### Side-effect journal

| Variable | Default | Description |
|---|---|---|
| `SIDE_EFFECTS_RETENTION_DAYS` | `30` (at least 1) | After how many days rows of the external call journal `step_side_effects` are deleted (hourly purge in `RecoveryJob`). See [Reliability → Side effects and repeats](reliability.md#side-effects-and-repeats) |
| `WPS_MAIL_MESSAGE_ID_DOMAIN` | `wps.local` | Domain of the e-mail `Message-ID`: `<{execution_id}.{effect_key}@domain>` — mail systems use it to drop a repeated send |

### Mail (`send_email`)

| Variable | Default | Description |
|---|---|---|
| `MAILER_DSN` | empty (`smtp://mailpit:1025` in `docker-compose.yml`) | Default transport of [`send_email`](../03-user-guide/actions.md#send-an-e-mail): `smtp://user:pass@host:587`, `smtps://user:pass@host:465`, parameter `?timeout=`. Empty — a process with `send_email` without `transport` cannot be saved. The password is masked in the audit and logs |
| `MAILER_FROM` | empty (compose: `wps@wps.local`) | Default sender; empty — `from` is required in the action |
| `MAILER_FROM_NAME` | empty (compose: `WPS`) | Default sender name |
| `MAILER_ALLOWED_FROM` | empty (any) | Allowed senders, comma-separated: addresses or domains `@company.ru`. Another `from` is rejected (`Sender address is not allowed`) |
| `MAILER_TIMEOUT` | `15` s (min 1) | SMTP connection timeout; keep it below `EXECUTION_LOCK_TTL` |
| `MAILER_MAX_RECIPIENTS` | `50` (min 1) | Recipients of one letter (`to` + `cc` + `bcc`); more — the step fails |
| `MAILER_RATE_PER_MINUTE` | `60` (`0` — no limit) | Letters a minute per transport; above it the step is retried (`MAIL_RATE_LIMIT`) |
| `MAILER_RATE_PER_RECIPIENT_HOUR` | `10` (`0` — no limit) | Letters an hour to one recipient; above it the recipient is skipped (`rate_limited`) |
| `MAILPIT_API_URL` | `http://mailpit:8025` (compose) | Mailpit API address for the integration tests; not used by the service |

### Outgoing HTTP requests (`http_request`)

| Variable | Default | Description |
|---|---|---|
| `HTTP_ALLOW_PLAIN_HTTP` | `true` | `false` — `http://` requests are forbidden (`https` only); recommended in production |
| `HTTP_DENY_PORTS` | `22,25,3306,5432,6379,6380,9200,11211` | Forbidden ports |
| `HTTP_ALLOW_PORTS` | empty (any except the forbidden ones) | When set — only these ports are allowed |
| `HTTP_ALLOW_HOSTS` | empty (any public host) | Allow list of hosts, comma-separated, `*.company.ru` — subdomains |
| `HTTP_DENY_HOSTS` | `localhost, metadata.google.internal, postgres, redis-stream, redis-data, mock` + the CRM host (stand compose: without `mock` and the CRM host) | Always forbidden hosts; a set value replaces the whole list |
| `HTTP_ALLOW_PRIVATE_CIDRS` | empty (stand compose: `172.16.0.0/12,192.168.0.0/16`) | Private networks that may be called (internal ERP etc.). On the stand — so that tests can call the mock in the docker network; **do not copy to production** |
| `HTTP_TLS_VERIFY` | `true` | Certificate verification against the host name; `false` — for debugging only |
| `HTTP_CA_FILE` | empty (system CAs) | Own root certificate file |
| `HTTP_MAX_TIMEOUT` | `30` s | Upper bound of the action's `timeout`; keep it below `EXECUTION_LOCK_TTL` |
| `HTTP_MAX_RESPONSE_BYTES` | `5242880` (5 MB) | Response limit; counted while reading, above it — `Response is too large` |
| `HTTP_MAX_REQUEST_BYTES` | `1048576` (1 MB) | Request body limit |
| `HTTP_RETRY_AFTER_MAX` | `60` s | Upper bound of a `Retry-After` delay |
| `HTTP_MAX_STORED_BODY_BYTES` | `65536` (64 KB) | A larger response body is not put into the variable (`body_truncated: true`) |

Details — [Security → Outgoing HTTP requests](security.md#outgoing-http-requests-ssrf-protection).

### Notifications (`notify`)

| Variable | Default | Description |
|---|---|---|
| `CRM_ALERT_PROFILE` | `suitecrm8` | The set of fields of the `Alerts` record of the `crm_alert` channel and the default record link template: `suitecrm8` (SuiteCRM 8 fields, link `#/{module_kebab}/record/{id}`), `suitecrm7` (link `index.php?module={module}&action=DetailView&record={id}`), `custom` (fields only from the channel `fields`, link only from `CRM_RECORD_URL_TEMPLATE`) |
| `CRM_RECORD_URL_TEMPLATE` | empty — by the profile | Record link template: `{module}`, `{module_lower}` (`Leads` → `leads`), `{module_kebab}` (`AOS_Contracts` → `aos-contracts`), `{id}`. Relative (`#/…`) or absolute (`https://crm.company.com/…`) |
| `CRM_UI_BASE_URL` | empty — `CRM_API_BASE_URL` without `/api/…` | Address of the CRM UI for absolute "Open" links in messengers (the CRM bell gets a relative link). Set it when the CRM API and its UI live at different addresses |
| `TELEGRAM_API_BASE` | `https://api.telegram.org` | The Bot API address. Changed for tests (`http://mock:9502/mock/telegram`) or a self-hosted Bot API server |

Requests to Telegram, Slack and Teams pass the outgoing HTTP protection (the table above): with `HTTP_ALLOW_HOSTS` set,
add `api.telegram.org`, `hooks.slack.com` and the Teams webhook host. See
[Actions → Notification](../03-user-guide/actions.md#notification).

### Owner assignment (`assign`)

| Variable | Default | Description |
|---|---|---|
| `ASSIGN_POOL_CACHE_TTL` | `60` s (`0` — no cache) | How long the members of a security group (`pool.security_group`) are cached. A new group member joins the distribution within this time |
| `ASSIGN_LEAST_BUSY_MAX_POOL` | `50` (1..200) | The largest pool of the `least_busy` strategy: one CRM count request per user. More — step error `Pool is too large for least_busy` |

The `round_robin` and `least_busy` queues live in redis-data: `assign:rr:{queue_key}` — the last assigned,
`assign:last:{queue_key}:{user}` — when the user was assigned (30 days), `assign:decided:{execution}:{pc}:{iteration}` —
the step decision for repeats (a day). To reset a queue so that the distribution starts from the first id:

```bash
docker compose exec redis-data redis-cli DEL assign:rr:<queue_key>
```

The default queue key is `p{process id}:{step id or pc}` (visible in `request_data.queue_key` of the step). See
[Actions → Assign an owner](../03-user-guide/actions.md#assign-an-owner).

### Record links (`link_records`, `unlink_records`)

| Variable | Default | Description |
|---|---|---|
| `LINK_RECORDS_MAX` | `500` (at least 1) | How many related record ids one step may take; more is a step error `Too many related ids (N > 500)`. The size of a CRM request batch is `max_link_batch` from the plugin capabilities. See [Actions → Link / unlink records](../03-user-guide/actions.md#link--unlink-records) |

### Record search, update and aggregates (`find_records`, `update_records`, `aggregate`)

| Variable | Default | Description |
|---|---|---|
| `FIND_RECORDS_MAX` | `500` (at least 1) | Upper bound of the step `limit`; a larger `limit` is rejected when the process is saved |
| `FIND_RECORDS_SCAN_MAX` | `1000` (at least 1) | Relationship mode (`related_to`): how many related records the step reads and checks with the local filter; beyond that — `has_more: true` and a warning in the audit |
| `FIND_RECORDS_MAX_BYTES` | `1048576` (1 MB, at least 10000) | Limit of the found list size in JSON; larger is a step error `result is too large`, narrow `fields` or `limit` |
| `UPDATE_RECORDS_MAX` | `200` (1..5000) | [Update list records](../03-user-guide/actions.md#update-list-records): most records of one `update_records` step and the upper bound of its `max`; more fail the step before any change |
| `AGGREGATE_MAX_RECORDS` | `5000` (1..50000) | [Aggregate over records](../03-user-guide/change-types-and-expressions.md#aggregate-over-records): most records one aggregate reads and the upper bound of its `limit` (`limit` = 1000 by default) |

See [Actions → Find records](../03-user-guide/actions.md#find-records).

### Loops (`foreach`)

| Variable | Default | Description |
|---|---|---|
| `LOOP_MAX_ITEMS` | `500` (1..5000) | [`foreach` loop](../03-user-guide/actions.md#loop-for-each-item): most items of one loop and the upper bound of `max_items`. The item snapshot is kept in a separate Redis key `exec:{id}:loop:{pc}`, not in the execution hash |

### Waiting executions and pause (`delay`)

| Variable | Default | Description |
|---|---|---|
| `WAIT_POLL_INTERVAL_MS` | `1000` (at least 100) | Period of `WaitResumeJob`: how often a worker looks for due waits |
| `WAIT_BATCH` | `20` | How many waits a worker claims per iteration |
| `WAIT_LEASE_SECONDS` | `120` (at least 10) | Lease of an `execution_waits` row while it is being woken; when it expires another worker may take it again |
| `WAIT_MAX_ATTEMPTS` | `10` | Wake attempts after which the execution ends `failed` |
| `WAIT_INLINE_MAX_SECONDS` | `5` | Pauses and retry delays up to this wait in the coroutine, longer ones park the execution (`waiting`) |
| `WAIT_STATE_MAX_BYTES` | `1048576` | Largest state snapshot that can be parked; larger fails the step |
| `DELAY_MAX` | `P400D` | Longest single `delay` pause (ISO 8601 or a short form); the step field `max_wait` can only lower it |

Details — [Reliability → Waiting executions](reliability.md#waiting-executions).

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
| `MOCK_CRM_CONTRACT` | `2.0` (compose) | API contract version of the mock: `2.0` or `1.0` (emulates an old plugin: `404` on `capabilities`, equality search only, no relationships or delete). Switchable at runtime: `PUT http://localhost:9502/mock/config {"contract": "1.0"}` |
| `MOCK_PUBLISH_ON_WRITE` | `0` (compose) | `1` — the mock publishes a v2 event (with `before` and `wps_origin`) on every `POST`, `PUT`, `DELETE` through the API, like a CRM plugin. At runtime: `PUT /mock/config {"publish_on_write": true}` |

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
| `CyclicJobs` | `StreamConsumerJob`, `RecoveryJob`, `WaitResumeJob` | Background loops (0.5 s, 30 s and `WAIT_POLL_INTERVAL_MS`) |
| `cyclic_jobs.strategy` | `ALL_WORKERS` | Every HTTP worker runs the jobs |
| `cyclic_jobs.monitoring_interval` | `60` | Interval of the framework health check of cyclic jobs, seconds (with `APP_DEBUG` it also logs job statistics) |
| `StateContainerInitiation` | `["Wps\\State\\StateInitiation"]` | Builds `AppConfig` and the shared metrics table before workers fork |
| `events` | `[]` | Framework events (not used) |

## Checking the effective configuration

```bash
# what compose will pass to the containers (after ./.env and command-line variables)
docker compose config | sed -n '/backend:/,/ports:/p'

# environment of the running backend
docker compose exec backend env | sort | grep -E '^(CRM_|MOCK_|WPS_|HTTP_|MAILER_|REDIS_|DB_)'

# business time zone logged at start
docker compose logs backend | grep 'Business time zone'
# {"level":"info","message":"Business time zone","context":{"WPS_TIMEZONE":"UTC"},...}

# capabilities of the CRM as the backend sees them (token of any role)
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:9501/api/dictionaries/crm-capabilities
```

Configuration errors at start (the service does not come up; the text is in `docker compose logs backend`):

| Message | Cause |
|---|---|
| `WPS_TIMEZONE: unknown time zone "MSK" (use an IANA name, e.g. Europe/Moscow)` | Unknown time-zone name |
| `WPS_SECRETS_KEY: expected base64 of 32 bytes (generate with: php bin/secrets-key.php)` | The secrets key is not base64 or not 32 bytes (the same for `WPS_SECRETS_KEY_PREVIOUS`) |
| `[migrate] database is not reachable: …` | PostgreSQL unreachable for longer than `DB_WAIT_SECONDS` or wrong `DB_POSTGRES_*` |
| `[migrate] 0NN_… failed: …` | A migration failed; its transaction is rolled back, the next start tries again |

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
