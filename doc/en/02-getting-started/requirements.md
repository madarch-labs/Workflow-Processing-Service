# Requirements

What you need on your machine to run WPS, and which ports it uses.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/02-getting-started/requirements.md)
<!-- /lang-switch -->

## To run WPS (Docker)

| Requirement | Notes |
|---|---|
| Docker Engine with the Compose plugin | The whole stack is described in `docker-compose.yml` and started with `docker compose up -d --build`. |
| Internet access on the first build | Images are pulled from Docker Hub (`postgres:15-alpine`, `redis:7-alpine`, `phpswoole/swoole:6.0-php8.3`, `node:22-alpine` and `nginx:1.27-alpine` for the frontend, `axllent/mailpit` for test mail). Composer downloads PHP packages from Packagist, npm downloads frontend packages. |
| Resources | For the stack with the mock, 2 cores and 2–3 GB of free memory are enough: six small containers plus the image build. |
| Free host ports | See the table below. Every port can be changed in `./.env`. |
| A modern browser | Chrome, Firefox, Edge or Safari for the web UI (Vue 3 + Element Plus). |
| `curl` | Used in the examples to call the API and to send test events. |

PHP, Composer, PostgreSQL and Redis do **not** need to be installed on the host: they run inside the containers.

## Ports

| Service | Variable in `./.env` | Default | Purpose |
|---|---|---|---|
| `frontend` | `WPS_UI_PORT` | `3000` | Web UI (nginx), proxies `/api/` to the backend |
| `backend` | `WPS_API_PORT` | `9501` | REST API `/api/*`, health `/api/health`, metrics `/metrics` |
| `mock` | `WPS_MOCK_PORT` | `9502` | Mock CRM API and the test event generator `/test-event` |
| `postgres` | `WPS_PG_PORT` | `5432` | PostgreSQL (`wps` / `wps` / `secret`) |
| `redis-stream` | `WPS_REDIS_STREAM_PORT` | `6379` | Redis with the event stream `crm_events` |
| `redis-data` | `WPS_REDIS_DATA_PORT` | `6380` | Redis for caches and execution state |
| `mailpit` | `WPS_MAILPIT_PORT` | `8025` | Test SMTP server: catches the letters of the "Send an e-mail" action and shows them in the web UI `http://localhost:8025`. The backend sends to `mailpit:1025` (`MAILER_DSN`) |

Check that the ports are free before starting:

```bash
for p in 3000 9501 9502 5432 6379 6380 8025; do (ss -ltn | grep -q ":$p ") && echo "port $p is busy"; done
```

A busy port makes `docker compose up` fail with `Bind for 0.0.0.0:5432 failed: port is already allocated` — change the
matching variable in `./.env`.

> **Note**
> The `./.env` shipped in the repository sets `WPS_UI_PORT=3080` and `WPS_PG_PORT=55432`, because ports 3000 and 5432
> were taken on the author's machine. Then the UI is at `http://localhost:3080`. Adjust these values to your machine.

## For development

| Requirement | Needed for |
|---|---|
| Node.js ≥ 18 (22 recommended) and npm | Running the frontend outside Docker: `cd front && npm install && npm run dev` (port 3000, `/api` proxied to `localhost:9501`). |
| Git | Working with the repository. |
| PHP 8.3 with Swoole 6 (optional) | Only if you want to run the backend outside Docker. The supported way is the `backend` image: rebuild it after code changes (`docker compose up -d --build backend`). |

Details: [Development environment](../07-developer-guide/dev-environment.md).

## Network for process actions (optional)

Actions that reach outside need network access **from the `backend` container**:

| Action | Where | Settings |
|---|---|---|
| **Send an e-mail** (`send_email`) | SMTP server | `MAILER_DSN` (by default Mailpit of the stack) or a secret of kind `smtp_dsn` |
| **HTTP request** (`http_request`) | external API or webhook | SSRF protection: `HTTP_ALLOW_HOSTS`, `HTTP_DENY_HOSTS`, `HTTP_ALLOW_PRIVATE_CIDRS`, `HTTP_ALLOW_PLAIN_HTTP` — see [Security](../04-operations/security.md#outgoing-http-requests-ssrf-protection) |
| **Notification** (`notify`) | Telegram API, Slack / Teams webhooks | `telegram_bot` / `webhook_url` secrets; `TELEGRAM_API_BASE` |

With the mock all three work without internet: Mailpit catches the letters, the mock emulates messengers and "external
APIs" (`/mock/slack/…`, `/mock/telegram/…`, `/mock/echo/…`).

## For a real CRM (optional)

WPS works with the bundled mock out of the box. To connect SuiteCRM 7 or 8, use the ready-made Docker setups and the
WPS plugin in `.tehnical/suiteCRM7` and `.tehnical/suiteCRM8`. See [CRM integration](../04-operations/crm-integration.md).

<!-- footer-nav -->
---

← Previous: [Glossary](../01-overview/glossary.md) | [Contents](../README.md) | [Quick start](quick-start.md) Next →
<!-- /footer-nav -->
