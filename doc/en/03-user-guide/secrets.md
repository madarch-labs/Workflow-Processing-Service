# Secrets

How an administrator stores credentials of external systems in WPS and how a process refers to them without seeing the value.

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

| Field | Description |
|---|---|
| `name` | Name for references: `^[a-z][a-z0-9_]{1,63}$`, unique. Cannot be renamed — processes refer to it by name |
| `kind` | Kind (table below), set on creation and cannot change. Default `generic` |
| `value` | The value: a string or an object (composite kinds). Write-only — nobody can read it |
| `description` | Free text |
| `allowed_hosts` | Optional list of hosts the secret may be sent to (`api.example.com`, `*.example.com`, with or without a port). Actions that send secrets to external systems check this list — so an editor cannot send a token to their own server |
| `mask` | Mask for the list: `****` + the last 4 characters for values of 12+ characters, otherwise `********` |
| `version` | Grows with every value change |

Kinds (dictionary `GET /api/dictionaries/secret-kinds`):

| `kind` | Value | Check |
|---|---|---|
| `generic` | string | at least 4 characters |
| `smtp_dsn` | DSN string, e.g. `smtp://user:pass@smtp.example.com:587` | DSN format; used by the `transport` field of [`send_email`](actions.md#send-an-e-mail) |
| `basic_auth` | `{ "username", "password" }` | both required; `username` is not secret and shown in the mask |
| `bearer` | token string | at least 4 characters |
| `api_key` | string | at least 4 characters |
| `oauth2_client` | `{ "token_url", "client_id", "client_secret", "scope"? }` | `token_url` — `https://` only; `token_url`, `client_id`, `scope` are shown in the mask |
| `webhook_url` | string — an `http(s)://` URL | valid URL; `connection` of the `slack` and `teams` channels of the [`notify`](actions.md#notification) action |
| `telegram_bot` | string — bot token | at least 4 characters; `connection` of the `telegram` channel of the [`notify`](actions.md#notification) action (the token goes into the URL path, so the whole URL is masked) |

Secret strings shorter than 4 characters are rejected: such values cannot be reliably cut out of audit text.

Creating (`admin` only):

```bash
curl -s -X POST http://localhost:9501/api/secrets -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"erp_token","kind":"bearer","value":"tok-0123456789abcdef","description":"ERP API"}'
```

```json
{"success":true,"data":{"name":"erp_token","kind":"bearer","description":"ERP API","mask":"****cdef","allowed_hosts":[],
 "version":1,"created_at":"2026-10-19T19:29:33Z","updated_at":"2026-10-19T19:29:33Z","last_used_at":null},"error":null}
```

## Referring from a process

| Form | What is substituted |
|---|---|
| `secret://erp_token` | the whole value (a string or the object of a composite secret) |
| `secret://erp_oauth.client_id` | one field of a composite secret |

The reference is the **whole** value of a credential field of an action. Such fields are declared by actions that call
external systems (sending email, HTTP requests, notifications), for example:

```json
{ "type": "http_request", "url": "https://erp.example.com/api/orders",
  "auth": { "type": "bearer", "token": "secret://erp_token" } }
```

The value is substituted in memory when the step runs and is not written anywhere: not into process variables, not into
Redis, not into the audit.

References are forbidden in templates, `modify` values (`value`, `expression`), `field` / `target` and conditions. There
the secret would end up in process data, so saving the process is rejected.

## Errors

| Situation | Response |
|---|---|
| Reference to a secret that does not exist | `422`, `definition.actions[2].auth.token: Secret "erp_token" does not exist` |
| Reference in a template, a step value or a condition | `422`, `… : Secrets can only be used in credential fields` |
| Malformed reference (`secret://Bad`) | `422`, `Invalid secret reference (expected secret://name or secret://name.field)` |
| The secret was deleted or the key does not match at run time | the step fails: `Secret "erp_token" not found` / `Secret "erp_token" has no field "x"` |
| Deleting a secret in use | `409 SECRET_IN_USE` with the list of usages `usages: [{process_id, process_name, path}]` |
| A secret with this name exists | `409 SECRET_EXISTS` |
| The store key is not configured | `503 SECRETS_KEY_NOT_CONFIGURED` |

`GET /api/secrets/{name}` shows where the secret is used (`usages`) — the processes a value change affects. A secret can
be deleted only when no non-deleted process refers to it.

## Changing the value

```bash
curl -s -X PUT http://localhost:9501/api/secrets/erp_token -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"value":"tok-new-0123456789"}'
```

`version` grows by 1, the mask is recomputed. Workers cache the decrypted value in memory for `SECRETS_CACHE_TTL` seconds
(default 60) — the new value is used within that time. The name and the kind cannot change: create a new secret and
switch the processes to it.

## Roles

| Action | admin | editor | viewer |
|---|---|---|---|
| List secrets and see a secret with its usages | yes | yes | no |
| Create, change, delete, re-encrypt | yes | no | no |
| Read the value | no | no | no |
| Refer to a secret in a process | yes | yes | — |

<!-- footer-nav -->
---

← Previous: [Child processes](subprocesses.md) | [Contents](../README.md) | [Executions and audit](executions-and-audit.md) Next →
<!-- /footer-nav -->
