# API reference: basics

Base URL, authentication flow, response envelope, pagination, roles and error codes of the WPS REST API.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/06-api-reference/index.md)
<!-- /lang-switch -->

## Base URL

| Access | URL |
|---|---|
| Directly to the backend | `http://localhost:9501` (`WPS_API_PORT`) |
| Through the UI's nginx | `http://localhost:<WPS_UI_PORT>/api/...` (only `/api/`; `/metrics` is not proxied) |

All endpoints except `/metrics` start with `/api`. Request and response bodies are JSON
(`Content-Type: application/json`); request bodies must be JSON objects. A trailing slash is ignored
(`/api/processes/` = `/api/processes`). Times are UTC in ISO 8601 (`2026-10-15T08:02:39+00:00`).

The full list of endpoints with examples is in [Endpoints](endpoints.md).

## Authentication flow

```bash
# 1. log in
curl -s -X POST http://localhost:9501/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@wps.local","password":"admin"}'
```

```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…",
    "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…",
    "token_type": "Bearer",
    "expires_in": 900,
    "user": { "id": 1, "email": "admin@wps.local", "role": "admin", "created_at": "…", "updated_at": "…" }
  },
  "error": null
}
```

```bash
# 2. call the API with the access token
TOKEN=$(curl -s -X POST http://localhost:9501/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@wps.local","password":"admin"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["access_token"])')
curl -s http://localhost:9501/api/processes -H "Authorization: Bearer $TOKEN"

# 3. when you get 401 TOKEN_EXPIRED, exchange the refresh token for a new pair
curl -s -X POST http://localhost:9501/api/auth/refresh -H 'Content-Type: application/json' \
  -d '{"refresh_token":"<refresh token>"}'
```

The access token lives `JWT_ACCESS_TTL` seconds (900), the refresh token `JWT_REFRESH_TTL` (7 days). Details:
[Security](../04-operations/security.md).

## Response envelope

Success:

```json
{ "success": true, "data": { }, "error": null, "meta": { "page": 1, "per_page": 20, "total": 100 } }
```

`meta` is present only in paginated lists.

Error:

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "details": [ { "field": "definition.actions[0].value", "message": "Unknown function \"foo\"" } ]
  }
}
```

The `details` key is present only when the error has details (otherwise `error` has no such key): a list of `{field, message}` for validation errors, an object for
`CYCLE_DETECTED` (`{"path": [{id, name}, …]}`), a list of processes `[{id, name}]` for `PROCESS_IN_USE`,
`{"waiting": N}` for `PROCESS_HAS_WAITING_EXECUTIONS`, `{"usages": [...]}` for `SECRET_IN_USE`. `field` is a path in the request body,
such as `name`, `definition.conditions[0].field` or `definition.preload[1].module`.

Exceptions: `GET /metrics` returns Prometheus text; `GET /api/health` uses the envelope but answers `503` when degraded.

## Pagination

Lists accept `page` (from 1) and `per_page` (1…1000; default 20, users 50) and return `meta`:

```bash
curl -s 'http://localhost:9501/api/processes?page=2&per_page=10' -H "Authorization: Bearer $TOKEN"
```

```json
"meta": { "page": 2, "per_page": 10, "total": 23 }
```

Invalid values are clamped (`page=0` → 1, `per_page=0` → 1, `per_page=5000` → 1000).

Pagination is supported by `GET /api/users`, `/api/processes`, `/api/processes/{id}/executions`, `/api/processes/{id}/runs` and
`/api/executions`. The other lists (`/api/secrets`, `/api/executions/{id}/actions`, `/api/executions/{id}/side-effects`,
dictionaries) are returned in full, without `meta`.

Boolean query parameters (`is_child`, `is_active`, `force`) accept `true` / `1` and `false` / `0`; any other value is treated as
if the parameter were absent.

## Roles

| Role | Can |
|---|---|
| `viewer` | Read processes, executions, the external call journal, run-once marks, dictionaries (except `mail-transports`) |
| `editor` | Everything `viewer` can + create, edit, clone and manually run processes, reset run-once marks, refresh the modules dictionary, see secrets (names and masks, no values) and the `mail-transports` dictionary, send a test notification (`POST /api/notify/test`) |
| `admin` | Everything `editor` can + delete processes, manage users, create, change, delete and re-encrypt secrets, send a test letter (`POST /api/mail/test`) |

Rules are checked in order, the first match wins (`Wps\Auth\AccessPolicy::RULES`):

| Method | Path | Roles |
|---|---|---|
| any | `/api/health`, `/metrics` | public |
| `POST` | `/api/auth/login`, `/api/auth/refresh` | public |
| any | `/api/users`, `/api/users/…` | admin |
| `GET` | `/api/secrets`, `/api/secrets/{name}` | admin, editor |
| any | other `/api/secrets/…` | admin |
| any | `/api/mail/…` | admin |
| `GET` | `/api/dictionaries/mail-transports` | admin, editor |
| `DELETE` | `/api/processes/{id}/runs/{object_type}/{object_id}` | admin, editor |
| `DELETE` | `/api/processes/{id}` | admin |
| `POST`, `PUT` | `/api/processes`, `/api/processes/{id}`, `…/clone`, `…/run` | admin, editor |
| `POST` | `/api/dictionaries/modules/refresh` | admin, editor |
| `GET` | any other `/api/…` | any |
| other methods | everything else (e.g. `POST /api/notify/test`) | admin, editor |

The role required by each endpoint is listed in [Endpoints](endpoints.md); the rule table is in
[Security](../04-operations/security.md#access-rules-wpsauthaccesspolicy).

## Error codes

| HTTP | Code | When |
|---|---|---|
| 400 | `BAD_REQUEST` | Body is not valid JSON or is a JSON scalar such as `"text"` ("Request body must be a JSON object"). An empty body is treated as `{}`; a JSON array is not rejected here and fails field validation instead |
| 401 | `UNAUTHORIZED` | No `Authorization: Bearer` header |
| 401 | `INVALID_TOKEN` | Bad signature, malformed token, wrong token type, invalid refresh token, user no longer exists |
| 401 | `TOKEN_EXPIRED` | Access token expired |
| 401 | `INVALID_CREDENTIALS` | Wrong e-mail or password on login |
| 403 | `FORBIDDEN` | The role does not allow the operation |
| 404 | `NOT_FOUND` | Unknown route or method ("Route not found": e.g. `PATCH`, there is no `405`); a non-numeric id in the URL ("Invalid id"); no run-once mark to reset; no such secret |
| 404 | `PROCESS_NOT_FOUND` | Process does not exist or was deleted |
| 404 | `EXECUTION_NOT_FOUND` | Execution does not exist or the id is not a UUID |
| 404 | `USER_NOT_FOUND` | User does not exist |
| 409 | `EMAIL_EXISTS` | A user with this e-mail exists |
| 409 | `PROCESS_IN_USE` | The process is started by other processes (`details` lists them) |
| 409 | `PROCESS_HAS_WAITING_EXECUTIONS` | The process being deleted has waiting executions and `force=true` was not passed |
| 409 | `SECRET_EXISTS` | A secret with this name exists |
| 409 | `SECRET_IN_USE` | Non-deleted processes use the secret (`details.usages`) |
| 409 | `ROTATION_FAILED` | During rotation a secret decrypts with neither the current nor the previous key |
| 409 | `CANNOT_DELETE_SELF` | A user tries to delete their own account |
| 409 | `LAST_ADMIN` | Deleting or demoting the last administrator |
| 422 | `VALIDATION_ERROR` | Invalid input; `details` lists the fields |
| 422 | `CYCLE_DETECTED` | `start_process` would create a launch cycle; `details.path` lists the chain |
| 500 | `INTERNAL_ERROR` | Unexpected error ("Internal server error"; the real message only with `APP_DEBUG=true`) |
| 503 | `SECRETS_KEY_NOT_CONFIGURED` | `WPS_SECRETS_KEY` is not set and a secrets endpoint needs the key |
| 503 | `CRM_UNAVAILABLE` | `GET /api/dictionaries/crm-capabilities`: the CRM does not answer |
| 503 | — | `GET /api/health`: a dependency is down (`"status": "degraded"`, regular envelope) |

`401` messages: "Authentication required" (`UNAUTHORIZED`), "Token expired" (`TOKEN_EXPIRED`), "Malformed token", "Invalid token
signature", "Unsupported token", "Wrong token type", "Invalid token payload", "Invalid or expired refresh token", "User no longer
exists" (`INVALID_TOKEN`), "Invalid email or password" (`INVALID_CREDENTIALS`). The `403` message is "Insufficient permissions".

Errors of process steps (`HTTP_503`, `TIMEOUT`, `CRM_CAPABILITY_MISSING`, `CAST_ERROR` …) are not API response codes: they are
written to the execution audit (the step's `error_code`), see [Endpoints → Execution](endpoints.md#execution-and-executionaction).

## CORS

`OPTIONS` requests get `204` with `Access-Control-Allow-Origin` (`CORS_ALLOW_ORIGIN`, default `*`),
`Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`, `Access-Control-Allow-Headers: Authorization, Content-Type`,
`Access-Control-Max-Age: 600`.

## Language

The UI sends `Accept-Language`; the API does not use it. API messages and dictionary labels (`label` fields) are fixed:
messages in English, dictionary labels in Russian. Clients translate dictionaries by `value`.

<!-- footer-nav -->
---

← Previous: [Architecture decisions](../05-architecture/decisions.md) | [Contents](../README.md) | [API reference: endpoints](endpoints.md) Next →
<!-- /footer-nav -->
