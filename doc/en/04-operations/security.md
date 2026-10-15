# Security

Authentication, roles and access rules of the API, secrets to change, and what is exposed without a token.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/04-operations/security.md)
<!-- /lang-switch -->

## Authentication: JWT

- `POST /api/auth/login` with e-mail and password (stored as a bcrypt hash, checked with `password_verify`) returns an
  **access token** and a **refresh token**.
- Tokens are HS256 JWTs signed with `JWT_SECRET`. Claims: `sub` (user id), `email`, `role`, `type` (`access`/`refresh`),
  `iat`, `exp`, `jti`.
- Every API request sends `Authorization: Bearer <access token>`. A refresh token is rejected as an access token
  (`INVALID_TOKEN`, "Wrong token type") and vice versa.
- Access token lifetime `JWT_ACCESS_TTL` (900 s), refresh token lifetime `JWT_REFRESH_TTL` (7 days). After `401` the UI calls
  `POST /api/auth/refresh` and repeats the request.
- On refresh the user is read from the database again, so a deleted user cannot refresh, and a changed role takes effect with
  the next token.

> **Note**
> Tokens are stateless: there is no server-side logout or revocation. An access token stays valid until it expires (at most
> `JWT_ACCESS_TTL`), even after the user is deleted or their role is changed. Keep `JWT_ACCESS_TTL` short. Changing
> `JWT_SECRET` invalidates all tokens at once.

The UI keeps both tokens in `localStorage` (`wps_access_token`, `wps_refresh_token`).

## Roles

| Role | Processes | Executions / audit | Manual run, clone | Delete processes | Reset run-once marks | Refresh modules | Users |
|---|---|---|---|---|---|---|---|
| `admin` | read, create, edit | yes | yes | yes | yes | yes | full management |
| `editor` | read, create, edit | yes | yes | no | yes | yes | no |
| `viewer` | read | yes | no | no | no | no | no |

User management for administrators is described in [Users and roles](../03-user-guide/users-and-roles.md).

## Access rules (`Wps\Auth\AccessPolicy`)

The global middleware `Wps\Middleware\AuthMiddleware` (configured in `config.json` → `globalMiddlewares`) runs for every
request. The first matching rule wins:

| Method | Path (regex) | Access |
|---|---|---|
| any | `^/api/health$` | public |
| any | `^/metrics$` | public |
| `POST` | `^/api/auth/(login\|refresh)$` | public |
| any | `^/api/users(/.*)?$` | admin |
| `DELETE` | `^/api/processes/[^/]+/runs/[^/]+/[^/]+$` | admin, editor |
| `DELETE` | `^/api/processes/[^/]+$` | admin |
| `POST` | `^/api/processes$` | admin, editor |
| `PUT` | `^/api/processes/[^/]+$` | admin, editor |
| `POST` | `^/api/processes/[^/]+/(clone\|run)$` | admin, editor |
| `POST` | `^/api/dictionaries/modules/refresh$` | admin, editor |
| `GET` | `^/api/.*$` | admin, editor, viewer |
| other | not listed | `GET` → any role, other methods → admin, editor |

Responses: no or malformed header → `401 UNAUTHORIZED`; bad signature or wrong type → `401 INVALID_TOKEN`; expired →
`401 TOKEN_EXPIRED`; role not allowed → `403 FORBIDDEN`. `OPTIONS` (CORS preflight) is answered before routing.
Routes are checked by URL only; a new endpoint is protected by the fallback rule until you add an explicit one
(see [New API endpoint](../07-developer-guide/extending/new-api-endpoint.md)).

## Public endpoints

| Endpoint | Discloses |
|---|---|
| `GET /api/health` | Status of PostgreSQL and Redis, error messages of failed checks, stream name, group, counters |
| `GET /metrics` | Event and execution counters |
| `POST /api/auth/login`, `POST /api/auth/refresh` | — |

If that is not acceptable, do not publish the backend port and restrict `/metrics` and `/api/health` at the reverse proxy.

## Secrets and defaults to change

| What | Default | Where to change |
|---|---|---|
| JWT signing key | `change-me-in-production-0123456789abcdef` in `docker-compose.yml` (code fallback `insecure-dev-secret`) | `JWT_SECRET` |
| Administrator | `admin@wps.local` / `admin` (migration `002_seed_admin.sql`) | Change the password in the UI ("Users") or create another admin and delete this one |
| PostgreSQL password | `secret` | `POSTGRES_PASSWORD` and `DB_POSTGRES_PASSWORD` |
| Redis | no password | `requirepass` in Redis + `REDIS_STREAM_PASSWORD`, `REDIS_DATA_PASSWORD`, `MOCK_REDIS_PASSWORD` |
| CRM API key | `mySecretKey` (mock) | `CRM_API_STATIC_KEY` in WPS and the same key in the CRM plugin (`WPS_API_STATIC_KEY`) |

Never commit real secrets: `./.env` and `.tehnical/*/.env` are local files.

> **Warning**
> Anyone who can write to `crm_events` can make WPS run processes and therefore change CRM records. Protect the stream Redis
> with a password and keep it in a private network.

## CRM key

WPS authenticates to the CRM with the header `X-Auth-Key = CRM_API_STATIC_KEY . gmdate("Y.m.d H")`. The value changes every
hour, but it is derived from a static secret: use HTTPS (`CRM_API_BASE_URL=https://…`) when the CRM is not in the same
private network. The CRM-side plugin performs every write as the user `WPS_API_USER_ID` — give that user only the rights the
processes need.

## CORS

Every API response has `Access-Control-Allow-Origin: <CORS_ALLOW_ORIGIN>` (default `*`), allowed methods
`GET, POST, PUT, DELETE, OPTIONS` and headers `Authorization, Content-Type`. The bundled UI calls the API through the nginx
proxy on the same origin and does not need CORS; set `CORS_ALLOW_ORIGIN` to your UI origin if other web apps must not call the API.

## Other notes

- `APP_DEBUG=true` returns internal exception messages in `500` responses; keep it `false`.
- Passwords of users must be at least 6 characters. The API never returns password hashes.
- The last administrator cannot be deleted or demoted (`409 LAST_ADMIN`); a user cannot delete themselves (`409 CANNOT_DELETE_SELF`).
- `calculate` expressions are evaluated by WPS's own parser (no `eval`), so process editors cannot run code on the server.
- Process editors can read and write any CRM record that the CRM API user may access. Give the `editor` role only to trusted people.

<!-- footer-nav -->
---

← Previous: [Reliability](reliability.md) | [Contents](../README.md) | [Troubleshooting](troubleshooting.md) Next →
<!-- /footer-nav -->
