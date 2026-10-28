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

Process secrets: `admin` creates, changes, deletes and re-encrypts them; `editor` sees names, kinds and masks (to pick a
secret in a process); `viewer` has no access to `/api/secrets`. Nobody can read a secret value — see
[Process secrets](#process-secrets).

## Access rules (`Wps\Auth\AccessPolicy`)

The global middleware `Wps\Middleware\AuthMiddleware` (configured in `config.json` → `globalMiddlewares`) runs for every
request. The first matching rule wins:

| Method | Path (regex) | Access |
|---|---|---|
| any | `^/api/health$` | public |
| any | `^/metrics$` | public |
| `POST` | `^/api/auth/(login\|refresh)$` | public |
| any | `^/api/users(/.*)?$` | admin |
| `GET` | `^/api/secrets(/[^/]+)?$` | admin, editor |
| any | `^/api/secrets(/.*)?$` | admin |
| any | `^/api/mail(/.*)?$` | admin (test letter `POST /api/mail/test`) |
| `GET` | `^/api/dictionaries/mail-transports$` | admin, editor (names of mail secrets) |
| `DELETE` | `^/api/processes/[^/]+/runs/[^/]+/[^/]+$` | admin, editor |
| `DELETE` | `^/api/processes/[^/]+$` | admin |
| `POST` | `^/api/processes$` | admin, editor |
| `PUT` | `^/api/processes/[^/]+$` | admin, editor |
| `POST` | `^/api/processes/[^/]+/(clone\|run)$` | admin, editor |
| `POST` | `^/api/dictionaries/modules/refresh$` | admin, editor |
| `GET` | `^/api/.*$` | admin, editor, viewer |
| other | not listed | `GET` → any role, other methods → admin, editor |

The default rule covers, for example, `POST /api/notify/test` (test notification: admin, editor) and
`GET /api/auth/me`, `GET /api/dictionaries/*` (any role).

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
| Secrets store key | test key in `docker-compose.yml` (`WPS_SECRETS_KEY`) | `WPS_SECRETS_KEY` — generate your own, see [Process secrets](#process-secrets) |

Never commit real secrets: `./.env` and `.tehnical/*/.env` are local files.

> **Warning**
> Anyone who can write to `crm_events` can make WPS run processes and therefore change CRM records. Protect the stream Redis
> with a password and keep it in a private network.

## Process secrets

Credentials of external actions (SMTP password, API token, bot key, OAuth2 client secret) are stored in WPS separately
from processes. A process definition holds only a reference `secret://name` — the value itself never gets into the
definition, the audit or Redis. How to create secrets and refer to them — [Secrets](../03-user-guide/secrets.md).

### Encryption

- The value is encrypted by the application: AEAD **XChaCha20-Poly1305** (libsodium), a fresh random nonce for every
  encryption.
- Associated data is `wps-secret|<name>|<version>`: a ciphertext copied into another table row does not decrypt.
- Every row has a `kek_id` — the first 8 hex characters of `sha256(key)`; it selects the key on decryption.
- The `secrets` table (PostgreSQL) stores the ciphertext and the nonce (base64) and a ready mask; there is no plaintext
  in the database.

### Key

`WPS_SECRETS_KEY` is 32 random bytes in base64:

```bash
docker compose exec backend php bin/secrets-key.php     # or php back/bin/secrets-key.php
# hWC+YI6MCicl6PHwI1aK/61wwBnFOmhJeekBdj96TNQ=
```

- No key — the service starts, processes without secrets work, the secrets API answers `503 SECRETS_KEY_NOT_CONFIGURED`;
  a step that refers to a secret fails.
- A key of the wrong format — the service does not start, the log says:
  `WPS_SECRETS_KEY: expected base64 of 32 bytes (generate with: php bin/secrets-key.php)`.
- Keep the key where the other deployment secrets live (a secrets manager, CI environment variables) and back it up.
  **Losing the key = losing all values**: the secrets have to be entered again (processes do not change — they refer to
  names).

> **Warning**
> `docker-compose.yml` sets a default test key — for development and tests only. Set your own `WPS_SECRETS_KEY` in
> production.

### Key rotation

1. Generate a new key.
2. Move the old key to `WPS_SECRETS_KEY_PREVIOUS`, put the new one into `WPS_SECRETS_KEY`, restart the backend. Rows with
   the old `kek_id` still decrypt, new writes use the new key.
3. Call `POST /api/secrets/rotate` (admin) — every secret is re-encrypted with the new key, the response is
   `{"reencrypted": N}`.
4. Remove `WPS_SECRETS_KEY_PREVIOUS` and restart the backend.

### Access and masking

- The value is write-only: no endpoint returns it, a mask is returned instead (`****` + the last 4 characters for values
  of 12+ characters, otherwise `********`; composite secrets show their non-secret fields — `username`, `client_id`,
  `token_url`, `scope` — as is).
- Secrets cannot be used in templates, `modify` values, expressions or conditions — only in credential fields of
  actions, so a value never ends up in a process variable or an email body.
- For every step the engine creates a masker: each secret the step resolves registers its value and derived forms
  (URL-encoded, JSON-escaped, base64, for Basic — base64 of `user:password`). The step request, result and error text go
  through the masker before they are written to the audit; occurrences become `***`.
- Regardless of secrets, the headers `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`,
  `X-Auth-Key` and the fields `api_key`, `token`, `access_token`, `client_secret`, `password` are masked by name.
- Decrypted values are cached only in the worker memory for `SECRETS_CACHE_TTL` seconds (default 60) and are never
  written to Redis. After a value changes, workers pick it up within that time.
- Changes of secrets are logged (`Secret created/updated/deleted`, the name and the user id, no value).

## Outgoing HTTP requests (SSRF protection)

The [`http_request`](../03-user-guide/actions.md#http-request) action calls external systems by a URL from the process
definition. So that a process editor cannot make WPS reach the internal network (databases, Redis, the CRM, cloud
metadata), every request is checked by `EgressGuard`:

1. **Scheme** — `http` / `https` only; `http` is forbidden entirely with `HTTP_ALLOW_PLAIN_HTTP=false` (recommended in
   production).
2. **Port** — ports from `HTTP_DENY_PORTS` are forbidden (default `22,25,3306,5432,6379,6380,9200,11211`); when
   `HTTP_ALLOW_PORTS` is set, only those are allowed.
3. **Host** — `HTTP_DENY_HOSTS` are always forbidden (default `localhost`, `metadata.google.internal`, `postgres`,
   `redis-stream`, `redis-data`, `mock` and the CRM host from `CRM_API_BASE_URL`); when `HTTP_ALLOW_HOSTS` is set
   (`*.company.ru` is supported), the host must be in it.
4. **Addresses** — the host is resolved to **all** A and AAAA records and every address is checked against private,
   special and documentation networks (`10/8`, `127/8`, `169.254/16`, `172.16/12`, `192.168/16`, `100.64/10`, `::1`,
   `fc00::/7`, `fe80::/10`, IPv4-mapped IPv6 and others). A match fails with
   `Destination address is not allowed (private network)`. Internal systems that may be called are listed in
   `HTTP_ALLOW_PRIVATE_CIDRS` (e.g. `10.20.0.0/16`); the forbidden hosts of item 3 take precedence.
5. **Connection to the checked address** — WPS connects to the already checked IP and sends `Host` and TLS SNI with the
   host name; the certificate is verified against the name (`HTTP_TLS_VERIFY=true`, own CA — `HTTP_CA_FILE`). There is
   no second DNS lookup, so changing DNS after the check (DNS rebinding) does not work.
6. **Redirects** are checked again at every hop; authorization headers, cookies and the API key are not sent to another
   origin (scheme + host + port).

Also: the scheme and host of the URL in a definition are literal only (the path and query may be substituted);
credentials come only from secrets, and a secret with `allowed_hosts` is not sent to other hosts; the response size is
limited by `HTTP_MAX_RESPONSE_BYTES` (counted while reading, without loading the whole body into memory), the request
body by `HTTP_MAX_REQUEST_BYTES`.

> **Development stand.** `docker-compose.yml` sets `HTTP_ALLOW_PRIVATE_CIDRS=172.16.0.0/12,192.168.0.0/16` and
> `HTTP_DENY_HOSTS` without `mock` for the stand — so that tests can call the mock CRM inside the docker network. Do not
> override these variables in production (or set only the subnets of your internal systems).

## Regular expressions (`SafeRegex`)

The expression functions `regex_match`, `regex_extract`, `regex_replace` take a regular expression from the process
editor. So that one expression cannot occupy a worker (catastrophic backtracking, ReDoS), `Wps\Expression\SafeRegex`:

- wraps the pattern itself (`~…~u`, `~` inside is escaped) — do not write delimiters or modifiers in the pattern; flags
  are only `i`, `m`, `s` (the third/fourth argument of the function);
- limits the pattern to 500 characters and the subject string to 100 000 characters;
- lowers `pcre.backtrack_limit` to 100 000 and `pcre.recursion_limit` to 10 000 for a single `preg_*` call and restores
  the previous values (the worker is shared by coroutines);
- checks a literal pattern when the process is saved (`422` with the PCRE message) and turns a run-time failure into a
  non-recoverable step error.

| Message | When |
|---|---|
| `Invalid regular expression in regex_match(): missing closing parenthesis` | The pattern does not compile (at save or run time) |
| `Regular expression in regex_replace() is too long (max 500 characters)` | Pattern longer than 500 characters |
| `Regular expression input in regex_extract() is too long (max 100000 characters)` | Subject longer than 100 000 characters |
| `Function "regex_match": flags may contain only i, m, s, got "x"` | Invalid flag |
| `Regular expression failed: backtrack limit exceeded` | The pattern is too "expensive" for this subject (also `recursion limit exceeded`, `JIT stack limit exceeded`) |

## Built-in limits

Besides the configurable variables ([Configuration](configuration.md)), the code has fixed limits. They protect the
worker from oversized definitions and data; exceeding one gives a save error (`422 VALIDATION_ERROR`) or a
non-recoverable step error.

| What | Limit | Message / behaviour |
|---|---|---|
| Expression length (`calculate`, `{{= … }}`, functions) | 4000 characters; in conditions (`value_type: expression`) — 1000 | `Expression is too long (max 4000 characters)` |
| Expression complexity | 500 nodes, nesting 32 levels | `Expression is too complex (max 500 nodes)`, `Expression is nested too deeply (max 32 levels)` |
| Result of an expression / template | 1 MB | step error |
| Template length | 65 536 characters | `Template is too long (max 65536 characters)` |
| Lists in expression functions | 10 000 items | `List is too large (max 10000 items)` |
| Count and length of `repeat`, `pad_left`, `pad_right` | 0..1000 | `Function "repeat": count must be an integer between 0 and 1000, got "5000"` |
| `json_decode`, `json_get` | 1 MB, depth 64 | step error |
| `decimal` precision | 100 digits, `cast_options.scale` ≤ 20 | cast error |
| Process constants (`definition.constants`) | 100 items, 16 KB as JSON; scalars and lists of scalars only | `Too many constants (max 100)`, `Constants are too large (max 16 KB)` |
| Constant name that looks like a secret (`password`, `secret`, `token`, `api_key`) | forbidden | `Constant "api_token" looks like a secret; use a secret reference (secret://name)` |
| Branches of a `condition` block | 20 | `Too many branches (max 20)` |
| List on the right side of a condition (`in`, `contains_any` …) | 500 values | `List is too long (max 500 items)` |
| Shifts of a relative date (`today+1d-2h…`) | 5 | `Too many shifts in relative date (max 5)` |
| Rules of `update_records` | 1..20 | `1..20 field rules are required: …` |
| Entries of `map` | 500 | `Too many entries (max 500)` |
| `notify` channels | 1..10 | `From 1 to 10 channels are required` |
| Recipients of the `crm_alert` bell | 50 users (the rest are dropped) | — |
| Notification text | 4000 characters (cut) | warning `Message was cut to 4000 characters` |
| E-mail subject | 998 characters | step error |
| `assign` pool | 200 ids | `assign: pool is too large (N > 200)` |
| `date_shift` amount | 100 000 units | step error |
| Step retries | 20 attempts, delay ≤ 7 days | save error |
| Deliveries of one stream message | 5, then DLQ | `Delivered N times without success` |

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
