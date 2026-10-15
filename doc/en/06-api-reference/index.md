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

`details` is present only when the error has details: a list of `{field, message}` for validation errors, an object for
`CYCLE_DETECTED` (`{"path": [{id, name}, …]}`), a list of processes for `PROCESS_IN_USE`. `field` is a path in the request body,
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

Invalid values are clamped (`per_page=0` → 1, `per_page=5000` → 1000).

## Roles

| Role | Can |
|---|---|
| `viewer` | Read processes, executions, run-once marks, dictionaries |
| `editor` | Everything `viewer` can + create, edit, clone and manually run processes, reset run-once marks, refresh the modules dictionary |
| `admin` | Everything `editor` can + delete processes, manage users |

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
| 404 | `NOT_FOUND` | Unknown route; invalid numeric id in the URL; no run-once mark to reset |
| 404 | `PROCESS_NOT_FOUND` | Process does not exist or was deleted |
| 404 | `EXECUTION_NOT_FOUND` | Execution does not exist or the id is not a UUID |
| 404 | `USER_NOT_FOUND` | User does not exist |
| 409 | `EMAIL_EXISTS` | A user with this e-mail exists |
| 409 | `PROCESS_IN_USE` | The process is started by other processes (`details` lists them) |
| 409 | `CANNOT_DELETE_SELF` | A user tries to delete their own account |
| 409 | `LAST_ADMIN` | Deleting or demoting the last administrator |
| 422 | `VALIDATION_ERROR` | Invalid input; `details` lists the fields |
| 422 | `CYCLE_DETECTED` | `start_process` would create a launch cycle; `details.path` lists the chain |
| 500 | `INTERNAL_ERROR` | Unexpected error ("Internal server error"; the real message only with `APP_DEBUG=true`) |
| 503 | — | `GET /api/health`: a dependency is down |

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
