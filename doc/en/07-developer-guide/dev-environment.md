# Development environment

How to run WPS locally while you change the code: rebuilding the backend, the frontend dev server, logs and debugging.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/07-developer-guide/dev-environment.md)
<!-- /lang-switch -->

## What you need

| Tool | Version | Used for |
|---|---|---|
| Docker + Docker Compose v2 | any recent | the whole stack (`docker-compose.yml`) |
| Node.js | ≥ 18 (22 recommended) | frontend dev server and build (`front/`) |
| PHP on the host | not required | PHP runs only inside the `backend` image (`phpswoole/swoole:6.0-php8.3`) |

> **Note**
> If your system Node.js is older than 18, switch it for the shell: `source ~/.nvm/nvm.sh && nvm use 22`.

## Start the stack

```bash
docker compose up -d --build
curl -s http://localhost:9501/api/health
```

Host ports come from `./.env` (`WPS_UI_PORT`, `WPS_API_PORT`, `WPS_MOCK_PORT`, `WPS_PG_PORT`, `WPS_REDIS_STREAM_PORT`,
`WPS_REDIS_DATA_PORT`). For development keep the backend on the mock CRM:

```dotenv
CRM_API_BASE_URL=http://mock:9502
CRM_API_STATIC_KEY=mySecretKey
MOCK_SERVICE_URL=http://mock:9502
```

See [Configuration](../04-operations/configuration.md) for every variable.

### A second, isolated stack

To try something without touching your main stack (database, Redis, containers), start another Compose project with
other host ports. Values on the command line take precedence over `./.env`:

```bash
WPS_UI_PORT=3091 WPS_API_PORT=9521 WPS_MOCK_PORT=9522 WPS_PG_PORT=55434 \
WPS_REDIS_STREAM_PORT=6399 WPS_REDIS_DATA_PORT=6400 \
CRM_API_BASE_URL=http://mock:9502 CRM_API_STATIC_KEY=mySecretKey MOCK_SERVICE_URL=http://mock:9502 \
docker compose -p wpsext up -d --build

docker compose -p wpsext down -v   # remove it together with its volumes
```

This is exactly how the extension examples in [Extending WPS](extending/new-action-type.md) were verified.

## Backend: change → rebuild

The backend code is **baked into the image** (`back/Dockerfile` copies `back/` and runs `composer install` and
`composer dump-autoload`). There is no bind mount, so a restart alone does not pick up your changes:

```bash
docker compose up -d --build backend
docker compose logs -f backend
```

On every start `back/docker/entrypoint.sh`:

1. copies `.env.example` to `.env` if `.env` is missing (real environment variables from Compose still win, see `Wps\Kernel\Env`);
2. runs `php migrations/migrate.php` — applies new `migrations/*.sql` in name order, once each (tracked in `schema_migrations`);
3. starts `php server.php`.

Registries (comparators, actions, change types, load strategies, expression functions) are built per worker in
`Wps\Kernel\Container`, so any extension needs a rebuild/restart of the backend.

### New classes and autoloading

`back/composer.json` maps `Wps\` to `back/src/` (PSR-4). A new class in the right folder is found automatically after the
rebuild (the image runs `composer dump-autoload --optimize`). Controllers do not need registration: SwooleApp scans the
`Wps\Controllers` namespace from `config/config.json` (sub-namespaces included) and reads the `#[Route]` attribute.

### Updating the SwooleApp framework

The framework is a normal Composer dependency `sidalex/swoole-app` (`^0.3.0`, pinned in `back/composer.lock`).
Update only its lock entry, inside the same image that builds the backend:

```bash
cd back
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app \
  phpswoole/swoole:6.0-php8.3 composer update sidalex/swoole-app
rm -rf vendor                          # vendor lives only in the image
cd .. && docker compose up -d --build backend
docker compose exec backend php tests/IntegrationTest.php

# unit tests (no stack needed)
docker build -f back/Dockerfile --target test -t wps-backend-test . && docker run --rm wps-backend-test --testsuite unit
```

A new major/minor version (0.4, 1.x) is set in `back/composer.json`. Check its changelog against the two workarounds WPS
applies by extension (exact route matching in `WpsRoutesCollectionBuilder`, global middlewares applied in `BaseController`).

## Frontend dev server

```bash
cd front
npm install
npm run dev            # http://localhost:3000, /api is proxied to http://localhost:9501
VITE_API_TARGET=http://localhost:9521 npm run dev   # another backend
```

Hot reload works for `.vue`, `.ts` and `locales/*.json`. The UI in Docker (`frontend` service) is a production build
served by nginx; rebuild it with `docker compose up -d --build frontend` when you need to check the container.

## Logs

The backend writes structured JSON lines to stdout/stderr (`Wps\Support\Logger`):

```json
{"timestamp":"2026-10-15T08:06:27.619Z","level":"info","message":"Process completed","context":{"execution_id":"…","process_id":13,"step":3},"pid":14}
```

```bash
docker compose logs -f backend | grep '"level":"error"'
docker compose logs -f backend | grep '<execution id>'
```

Execution logs carry `execution_id`, `process_id`, `event_id`, `step`, so one `grep` gives the whole run.

## Debugging tips

| Need | How |
|---|---|
| More log lines (CRM requests with duration, handled events) | `LOG_LEVEL=debug` in the backend environment |
| Real exception text in `500 INTERNAL_ERROR` responses | `APP_DEBUG=true` (never in production) |
| Generate an event | `curl -g 'http://localhost:9502/test-event?module=Deals&set[status]=Active'` |
| Look at a CRM object in the mock | `curl http://localhost:9502/mock/objects/Accounts/<id>` |
| Simulate CRM failures | ids starting with `fail500` → HTTP 500 (retries), `fail<N>x500` (e.g. `fail2x500-abc`) → the first N requests with this id get HTTP 500, then a normal answer (testing a `retry` profile), `slow` → 3 s delay |
| Check `http_request` without an external system | URL `http://mock:9502/mock/echo/...` (on the stand the mock is allowed by the compose SSRF settings); what arrived — `curl 'http://localhost:9502/mock/echo-last?key=<X-Mock-Key>'`; other targets — `/mock/status/{code}`, `/mock/redirect`, `/mock/big`, `/mock/oauth/token`, `/mock/protected` ([list](../04-operations/crm-integration.md)) |
| Look at the live execution context | `GET /api/executions/{id}` (while running it is read from Redis `exec:{uuid}`) |
| Inspect the stream | `docker compose exec redis-stream redis-cli XINFO GROUPS crm_events`, DLQ: `XRANGE crm_events:dlq - +` |
| Check a process definition without the UI | `POST /api/processes` returns `422` with `details[].field` paths |

> **Warning**
> `curl` without `-g` treats `set[status]` as a glob pattern and sends nothing.

## Related pages

- [Project structure](project-structure.md)
- [Testing](testing.md)
- [Troubleshooting](../04-operations/troubleshooting.md)

<!-- footer-nav -->
---

← Previous: [API reference: endpoints](../06-api-reference/endpoints.md) | [Contents](../README.md) | [Project structure](project-structure.md) Next →
<!-- /footer-nav -->
