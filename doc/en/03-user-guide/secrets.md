# Secrets

How an administrator stores credentials of external systems in WPS and how a process refers to them without seeing
the value.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/03-user-guide/secrets.md)
<!-- /lang-switch -->

## Why

External actions need credentials: an SMTP password, an API token, a Telegram bot key, an OAuth2 client secret. They
must not be put into a process definition: every role can read definitions, and they get into the audit and exports.
So the value is stored in WPS separately and encrypted, and the process holds only a reference `secret://name`.

- The administrator creates a secret and changes its value when needed — processes do not change.
- An editor sees the name, kind and mask of a secret and refers to it in a process, but never sees the value.
- The execution audit shows `***` instead of the value.

How encryption, the key and key rotation work — [Security → Process secrets](../04-operations/security.md#process-secrets).
There is no UI for secrets yet: they are managed through the API (`/api/secrets`, see
[API reference](../06-api-reference/endpoints.md#secrets)).

## A secret

| Field | Type | Description |
|---|---|---|
| `name` | string | Name used in references: `^[a-z][a-z0-9_]{1,63}$` (lower-case Latin letters, digits, `_`, at least 2 characters), unique. It cannot be renamed — processes refer to it by name |
| `kind` | string | Kind (table below), set on creation and never changes. Default `generic` |
| `value` | string or object | The value: a string for simple kinds, an object for composite ones (`basic_auth`, `oauth2_client`). Write-only — the API never returns it |
| `description` | string | Free text |
| `allowed_hosts` | list of strings | Optional list of hosts the secret may be sent to: `api.example.com`, `*.example.com`, with or without a port (`api.example.com:8443`), no scheme. Checked by the `auth` field of the [`http_request`](actions.md#http-request) action: a request to another host fails — so an editor cannot send a token to their own server. An empty list means no restriction |
| `mask` | string or object | Read-only. `****` + the last 4 characters for values of 12+ characters, otherwise `********`; for composite kinds an object where non-secret fields are shown as is |
| `version` | integer | Read-only. Grows with every value change |
| `created_at`, `updated_at` | date-time | Read-only |
| `last_used_at` | date-time | Read-only. When the secret was last decrypted for a step (updated at most once per 5 minutes per worker) |

### Secret kinds

Dictionary — `GET /api/dictionaries/secret-kinds` (`value`, `label`, `composite`, `fields: [{name, required, secret}]`).

| `kind` | Value | Check on save | Used by |
|---|---|---|---|
| `generic` | string | at least 4 characters | any credential field that accepts a "generic" secret: e-mail `transport`, HTTP request `url`, notification `connection`, HTTP request `auth` |
| `smtp_dsn` | DSN string: `smtp://user:pass@smtp.example.com:587`, `smtps://…` | `scheme://…` format (`Invalid mailer DSN (expected e.g. smtp://user:pass@smtp.example.com:587)`) | `transport` of the [`send_email`](actions.md#send-an-e-mail) action |
| `basic_auth` | `{ "username", "password" }` | both fields are required, `password` at least 4 characters; `username` is not secret and is shown in the mask | `auth: {"type": "basic", "credentials": …}` of `http_request` |
| `bearer` | token string | at least 4 characters | `auth: {"type": "bearer", "token": …}` |
| `api_key` | string | at least 4 characters | `auth: {"type": "api_key", "key": …}` |
| `oauth2_client` | `{ "token_url", "client_id", "client_secret", "scope"? }` | `token_url`, `client_id`, `client_secret` are required; `token_url` must be `https://`; `token_url`, `client_id`, `scope` are shown in the mask | `auth: {"type": "oauth2_client_credentials", "client": …}` |
| `webhook_url` | string — an `http(s)://` URL | a valid URL (`Value must be an http(s) URL`) | the whole `url` of `http_request`; `connection` of the `slack` and `teams` channels of the [`notify`](actions.md#notification) action |
| `telegram_bot` | string — a bot token | at least 4 characters | `connection` of the `telegram` channel of `notify` (the token goes into the URL path, so the whole URL is masked) |

Secret strings shorter than 4 characters are rejected: such values cannot be reliably cut out of audit text. A composite
`value` accepts only the fields of its kind; an extra field is an error `Unknown field "x" for kind "basic_auth"`.

### Creating

`admin` only:

```bash
curl -s -X POST http://localhost:9501/api/secrets -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"erp_token","kind":"bearer","value":"tok-0123456789abcdef","description":"ERP API"}'
```

```json
{"success":true,"data":{"name":"erp_token","kind":"bearer","description":"ERP API","mask":"****cdef","allowed_hosts":[],
 "version":1,"created_at":"2026-10-19T19:29:33Z","updated_at":"2026-10-19T19:29:33Z","last_used_at":null},"error":null}
```

A composite OAuth2 secret restricted to one host:

```bash
curl -s -X POST http://localhost:9501/api/secrets -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"erp_oauth","kind":"oauth2_client","description":"ERP OAuth2","allowed_hosts":["erp.example.com"],
       "value":{"token_url":"https://auth.example.com/oauth/token","client_id":"wps-crm",
                "client_secret":"s3cr3t-client-secret-123","scope":"orders.write"}}'
```

```json
{"success":true,"data":{"name":"erp_oauth","kind":"oauth2_client","description":"ERP OAuth2",
 "mask":{"token_url":"https://auth.example.com/oauth/token","client_id":"wps-crm","client_secret":"****-123","scope":"orders.write"},
 "allowed_hosts":["erp.example.com"],"version":1,"created_at":"…","updated_at":"…","last_used_at":null},"error":null}
```

A Basic authorization secret: `{"name":"erp_basic","kind":"basic_auth","value":{"username":"wps","password":"p@ssw0rd-2026"}}`
→ mask `{"username":"wps","password":"****2026"}`.

### List and card

- `GET /api/secrets` — all secrets (`admin`, `editor`) with `usages_count`, the number of references from processes.
- `GET /api/secrets/{name}` — the card with `usages: [{process_id, process_name, path}]`: which processes and which
  fields refer to the secret. They show which processes a value change affects. An unknown name — `404 NOT_FOUND`.

```json
{ "name": "erp_basic", "kind": "basic_auth", "mask": { "username": "wps", "password": "****2026" }, "version": 1,
  "usages": [ { "process_id": 12, "process_name": "Lead to ERP", "path": "definition.actions[0].auth.credentials" } ] }
```

References are searched in the definitions of processes that are not deleted (active and inactive).

## Referring from a process

| Form | What is substituted |
|---|---|
| `secret://erp_token` | the whole value (a string or the object of a composite secret) |
| `secret://erp_oauth.client_id` | one field of a composite secret |

A reference is the **whole** value of a credential field of an action, with no text around it. Credential fields:

| Action | Field | Allowed kinds |
|---|---|---|
| [`send_email`](actions.md#send-an-e-mail) | `transport` | `smtp_dsn`, `generic` |
| [`http_request`](actions.md#http-request) | `url` (the whole URL from a secret) | `webhook_url`, `generic` |
| `http_request` | `auth.token` (`bearer`) | any string secret (usually `bearer`) or a field of a composite one |
| `http_request` | `auth.credentials` (`basic`) | `basic_auth` |
| `http_request` | `auth.key` (`api_key`) | a string secret or a field of a composite one |
| `http_request` | `auth.client` (`oauth2_client_credentials`) | `oauth2_client` |
| [`notify`](actions.md#notification), channel `telegram` | `connection` | `telegram_bot`, `generic` |
| `notify`, channels `slack`, `teams` | `connection` | `webhook_url`, `generic` |

The kind is checked on save for `transport`, `url` and `connection`; for `auth` — at run time (for example,
`Secret secret://erp_token must be of kind basic_auth`, `Secret does not hold a token`).

Example — one HTTP request with OAuth2 and another with Basic authorization:

```json
{ "type": "http_request", "id": "erp_order", "method": "POST",
  "url": "https://erp.example.com/api/orders",
  "auth": { "type": "oauth2_client_credentials", "client": "secret://erp_oauth" },
  "body": { "type": "json", "value": { "crm_id": "{{ object.id }}", "name": "{{ object.name }}" } } }
{ "type": "http_request", "id": "legacy_sync", "method": "PUT",
  "url": "https://legacy.example.com/sync/{{ object.id }}",
  "auth": { "type": "basic", "credentials": "secret://erp_basic" } }
```

The value is substituted in memory at the moment of the step and is written nowhere: not into process variables, not
into Redis, not into the audit.

### Where a reference is not allowed

In templates and step values (`value`, `expression`, `template`, `subject`, `text`, `html`, `body`), in `field` /
`target` and in conditions the secret would end up in process data, so saving the process is rejected:
`Secrets can only be used in credential fields`.

```json
{ "type": "modify", "target": "object.description", "change_type": "template", "value": "Token: secret://erp_token" }
```

→ `422`, `definition.actions[0].value: Secrets can only be used in credential fields`.

> **Attention**
> In other fields that are not credential fields (for example the values of `headers` and `query` of an HTTP request),
> the text `secret://…` is not substituted: it is sent to the external system as a plain string. Pass tokens only through
> `auth` — for a header with a custom name use `{"type": "api_key", "name": "X-Token", "key": "secret://…"}`.

Secrets cannot be kept in [process constants](processes.md#process-constants) either: a constant whose name contains
`password`, `secret`, `token` or `api_key` (case-insensitive) is rejected (`Constant "api_token" looks like a secret; use a secret reference (secret://name)`).

## Masking

Every value a step decrypts is registered in the masker of that step. Before anything is written to the audit and the
logs, WPS replaces with `***`:

- the value itself and its derived forms: URL-encoded, JSON-escaped, base64, and for Basic authorization the base64 of
  `username:password`;
- the OAuth2 token obtained with `client_credentials`;
- the values of the `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `X-Auth-Key` headers
  and of the `api_key`, `token`, `access_token`, `client_secret`, `password` fields / parameters — always, even if they
  are not WPS secrets.

What the external system returns is masked as well: if an echo webhook sends the authorization header back in the
response body, the `http_request` response variable and the audit show `"authorization": "***"`. The step error text
(`sys.last_error.message`) is masked too.

## Errors

| Situation | Response |
|---|---|
| Reference to a secret that does not exist | `422`, `definition.actions[2].auth.token: Secret "erp_token" does not exist` |
| A literal instead of a reference in a credential field (`"token": "tok-123"`) | `422`, `…auth.token: Credentials must reference a secret (secret://name)` (for `transport` — `Transport must be a secret reference (secret://name)`, for `connection` — `Connection secret is required (secret://name)`) |
| Reference in a template, a step value or a condition | `422`, `… : Secrets can only be used in credential fields` |
| Wrong reference form (`secret://Bad`) in a field the action does not check itself | `422`, `Invalid secret reference (expected secret://name or secret://name.field)` |
| A secret of the wrong kind | `422`, `Secret must be of kind smtp_dsn, got bearer`, `Secret must be of kind webhook_url, got bearer`, `Secret "erp_token" has kind "bearer", expected webhook_url or generic` |
| A field of a composite secret does not exist (`secret://erp_basic.nofield`) | not checked on save; the step `failed`: `Secret "erp_basic" has no field "nofield"` |
| The secret was deleted or the key does not fit at run time | the step `failed`: `Secret "erp_token" not found`, `Secret "erp_token": …` |
| The request host is not in `allowed_hosts` | the step `failed`: `Secret "erp_oauth" is not allowed for host "mock"` (`error_code: SECRET_HOST`) |
| The store key is not configured at run time | the step `failed`: `Secrets are not available: WPS_SECRETS_KEY is not configured` |

Run-time secret errors are not retried; the step's `on_error` applies
([Step errors](actions.md#step-errors-on_error-and-retries)).

Secrets API errors:

| Situation | Response |
|---|---|
| Invalid name | `422`, `name: Secret name must match ^[a-z][a-z0-9_]{1,63}$` |
| Unknown kind | `422`, `kind: Unknown secret kind (allowed: generic, smtp_dsn, basic_auth, bearer, api_key, oauth2_client, webhook_url, telegram_bot)` |
| No value | `422`, `value: Secret value is required` |
| A short value | `422`, `value: Secret value is too short (min 4 characters)` (for composite kinds — `value.password` etc.) |
| A string instead of an object or vice versa | `422`, `Value must be an object for kind "basic_auth"`, `Value must be a string` |
| A required field of a composite secret is missing | `422`, `value.client_id: Field "client_id" is required for kind "oauth2_client"` |
| `token_url` is not `https` | `422`, `value.token_url: Token URL must use https` |
| An invalid host in `allowed_hosts` | `422`, `allowed_hosts[0]: Invalid host "https://x.y" (a host name without scheme, e.g. api.example.com)` |
| Attempt to rename or change the kind | `422`, `A secret cannot be renamed (processes refer to it by name)`, `The kind of a secret cannot be changed` |
| A secret with this name already exists | `409 SECRET_EXISTS` |
| Deleting a secret in use | `409 SECRET_IN_USE` with the list of usages `details.usages: [{process_id, process_name, path}]` |
| No such secret | `404 NOT_FOUND`: `Secret "x" not found` |
| The store key is not configured | `503 SECRETS_KEY_NOT_CONFIGURED` |

A secret can be deleted only when no process that is not deleted refers to it.

## Changing the value

```bash
curl -s -X PUT http://localhost:9501/api/secrets/erp_token -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"value":"tok-new-0123456789"}'
```

- `value`, `description` and `allowed_hosts` can be changed; the name and the kind cannot: create a new secret and
  switch the processes to it.
- The value of a composite secret is replaced **as a whole**: send all fields, including optional ones (`scope`),
  otherwise they are lost.
- `version` grows by 1 and the mask is recalculated. A request without `value` (only the description or the hosts)
  does not change the version.
- Workers cache the decrypted value in memory for `SECRETS_CACHE_TTL` seconds (60 by default, see
  [Configuration](../04-operations/configuration.md#secrets)) — the new value is picked up within that time. To switch
  at once, lower `SECRETS_CACHE_TTL` or restart the backend.

**Rotating credentials without downtime.** If the external system allows two valid keys at the same time: issue a new
key, update the secret value (`PUT`), wait `SECRETS_CACHE_TTL`, and only then revoke the old key in the external system.
If two keys at once are not possible, keep in mind that during `SECRETS_CACHE_TTL` some steps may still use the old
value and fail with an authorization error; repeat such steps or handle the error with `on_error`.

**Changing the store encryption key** (`WPS_SECRETS_KEY`) is a separate operation: the old key moves to
`WPS_SECRETS_KEY_PREVIOUS`, then `POST /api/secrets/rotate` (`admin`) re-encrypts all secrets with the new key and
returns `{"reencrypted": N}`; a secret that cannot be decrypted gives `409 ROTATION_FAILED`. The procedure —
[Security → Key rotation](../04-operations/security.md#key-rotation).

## Roles

| Action | admin | editor | viewer |
|---|---|---|---|
| List secrets and see a secret with its usages | yes | yes | no |
| Create, change, delete, re-encrypt | yes | no | no |
| Read the value | no | no | no |
| Refer to a secret in a process | yes | yes | — |

Creating, changing and deleting secrets is written to the backend log (`Secret created` / `Secret updated` /
`Secret deleted`, the name and `user_id`, no value). Metrics: `wps_secret_resolve_total` and
`wps_secret_resolve_errors_total` ([Monitoring](../04-operations/monitoring.md)).

<!-- footer-nav -->
---

← Previous: [Child processes](subprocesses.md) | [Contents](../README.md) | [Executions and audit](executions-and-audit.md) Next →
<!-- /footer-nav -->
