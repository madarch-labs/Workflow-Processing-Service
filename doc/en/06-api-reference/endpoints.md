# API reference: endpoints

All 28 endpoints of the WPS API with roles, parameters, request and response examples.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/06-api-reference/endpoints.md)
<!-- /lang-switch -->

Examples use `http://localhost:9501` and `$TOKEN` from [the authentication flow](index.md#authentication-flow).
Responses were taken from a running instance and shortened (`…`). The envelope (`success`, `data`, `error`, `meta`) is described
in [API basics](index.md).

## Summary

| Method | Path | Role | Description |
|---|---|---|---|
| `POST` | [`/api/auth/login`](#post-apiauthlogin) | public | Log in, get tokens |
| `POST` | [`/api/auth/refresh`](#post-apiauthrefresh) | public | New token pair by refresh token |
| `GET` | [`/api/auth/me`](#get-apiauthme) | any | Current user |
| `GET` | [`/api/users`](#get-apiusers) | admin | List users |
| `GET` | [`/api/users/{id}`](#get-apiusersid) | admin | Get a user |
| `POST` | [`/api/users`](#post-apiusers) | admin | Create a user |
| `PUT` | [`/api/users/{id}`](#put-apiusersid) | admin | Update a user |
| `DELETE` | [`/api/users/{id}`](#delete-apiusersid) | admin | Delete a user |
| `GET` | [`/api/processes`](#get-apiprocesses) | any | List processes |
| `GET` | [`/api/processes/{id}`](#get-apiprocessesid) | any | Get a process with references |
| `POST` | [`/api/processes`](#post-apiprocesses) | editor+ | Create a process |
| `PUT` | [`/api/processes/{id}`](#put-apiprocessesid) | editor+ | Update a process |
| `DELETE` | [`/api/processes/{id}`](#delete-apiprocessesid) | admin | Delete a process (soft) |
| `POST` | [`/api/processes/{id}/clone`](#post-apiprocessesidclone) | editor+ | Clone a process |
| `POST` | [`/api/processes/{id}/run`](#post-apiprocessesidrun) | editor+ | Manual run with a test object |
| `GET` | [`/api/processes/{id}/executions`](#get-apiprocessesidexecutions) | any | Execution log of a process |
| `GET` | [`/api/processes/{id}/runs`](#get-apiprocessesidruns) | any | Run-once marks of a process |
| `DELETE` | [`/api/processes/{id}/runs/{object_type}/{object_id}`](#delete-apiprocessesidrunsobject_typeobject_id) | editor+ | Reset a run-once mark |
| `GET` | [`/api/executions`](#get-apiexecutions) | any | Executions of all processes (e.g. waiting ones) |
| `GET` | [`/api/executions/{id}`](#get-apiexecutionsid) | any | Execution with context, steps and children |
| `GET` | [`/api/executions/{id}/actions`](#get-apiexecutionsidactions) | any | Steps of an execution |
| `GET` | [`/api/executions/{id}/side-effects`](#get-apiexecutionsidside-effects) | any | External call journal of an execution |
| `GET` | [`/api/secrets`](#get-apisecrets) | admin, editor | List secrets (masks, no values) |
| `GET` | [`/api/secrets/{name}`](#get-apisecretsname) | admin, editor | A secret with its usages |
| `POST` | [`/api/secrets`](#post-apisecrets) | admin | Create a secret |
| `PUT` | [`/api/secrets/{name}`](#put-apisecretsname) | admin | Change the value, description, hosts |
| `DELETE` | [`/api/secrets/{name}`](#delete-apisecretsname) | admin | Delete an unused secret |
| `POST` | [`/api/secrets/rotate`](#post-apisecretsrotate) | admin | Re-encrypt all secrets with the current key |
| `POST` | [`/api/mail/test`](#post-apimailtest) | admin | Send a test letter (check the mail settings) |
| `POST` | [`/api/notify/test`](#post-apinotifytest) | admin, editor | Send a test notification to one channel (check the channel) |
| `GET` | [`/api/dictionaries/modules`](#get-apidictionariesmodules) | any | CRM modules (cached) |
| `GET` | [`/api/dictionaries/crm-capabilities`](#get-apidictionariescrm-capabilities) | any | CRM capabilities (contract version) |
| `POST` | [`/api/dictionaries/modules/refresh`](#post-apidictionariesmodulesrefresh) | editor+ | Reload CRM modules |
| `GET` | [`/api/dictionaries/operators`](#get-apidictionariesoperators) | any | Comparison operators |
| `GET` | [`/api/dictionaries/functions`](#get-apidictionariesfunctions) | any | Expression functions and operators |
| `GET` | [`/api/dictionaries/action-types`](#get-apidictionariesaction-types) | any | Action types |
| `GET` | [`/api/dictionaries/change-types`](#get-apidictionarieschange-types) | any | Change types |
| `GET` | [`/api/dictionaries/template-filters`](#get-apidictionariestemplate-filters) | any | Template filters |
| `GET` | [`/api/dictionaries/notify-channels`](#get-apidictionariesnotify-channels) | any | Notification channels and the suitable secret kinds |
| `GET` | [`/api/dictionaries/aggregate-functions`](#get-apidictionariesaggregate-functions) | any | Functions of the aggregate over records |
| `GET` | [`/api/dictionaries/assign-strategies`](#get-apidictionariesassign-strategies) | any | Owner assignment strategies |
| `GET` | [`/api/dictionaries/date-units`](#get-apidictionariesdate-units) | any | Date shift units |
| `GET` | [`/api/dictionaries/relative-dates`](#get-apidictionariesrelative-dates) | any | Anchors and units of relative dates, the business time zone |
| `GET` | [`/api/dictionaries/value-types`](#get-apidictionariesvalue-types) | any | Value types of the right side of a condition |
| `GET` | [`/api/dictionaries/error-policies`](#get-apidictionarieserror-policies) | any | `on_error` policies of a step |
| `GET` | [`/api/dictionaries/retry-classes`](#get-apidictionariesretry-classes) | any | Error classes for `retry.retry_on` |
| `GET` | [`/api/dictionaries/event-types`](#get-apidictionariesevent-types) | any | Event types for `trigger_on` |
| `GET` | [`/api/dictionaries/preload-sources`](#get-apidictionariespreload-sources) | any | Preload sources |
| `GET` | [`/api/dictionaries/casts`](#get-apidictionariescasts) | any | Cast types for conditions (`cast`) |
| `GET` | [`/api/dictionaries/delivery-policies`](#get-apidictionariesdelivery-policies) | any | Delivery policies of external actions (`delivery`) |
| `GET` | [`/api/dictionaries/secret-kinds`](#get-apidictionariessecret-kinds) | any | Secret kinds and their fields |
| `GET` | [`/api/dictionaries/mail-transports`](#get-apidictionariesmail-transports) | admin, editor | Mail transports for `send_email` |
| `GET` | [`/api/health`](#get-apihealth) | public | Health check |
| `GET` | [`/metrics`](#get-metrics) | public | Prometheus metrics |

"any" = any authenticated role, "editor+" = `editor` or `admin`.

## Objects

### `User`

```json
{ "id": 2, "email": "editor@example.com", "role": "editor", "created_at": "2026-10-15T07:49:47+00:00", "updated_at": "…" }
```

`role`: `admin` | `editor` | `viewer`. The password hash is never returned.

### `Process`

```json
{
  "id": 1,
  "name": "Active deal → mark account as VIP",
  "object_type": "Deals",
  "is_child": false,
  "is_active": true,
  "priority": 0,
  "run_once": false,
  "trigger_on": ["create", "update"],
  "version": 1,
  "definition": {
    "conditions": [ { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" } ],
    "preload": [ { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" } ],
    "actions": [ { "type": "modify", "target": "v1.rating", "change_type": "set_value", "value": "VIP", "save_to_crm": true } ]
  },
  "created_at": "2026-10-15T07:49:36+00:00",
  "updated_at": "2026-10-15T07:49:36+00:00"
}
```

| Field | Description |
|---|---|
| `object_type` | CRM module whose events start the process (`^[A-Za-z][A-Za-z0-9_]*$`) |
| `is_child` | `true` — child process: started only by `start_process` of another process |
| `is_active` | Inactive processes are not started by events and are skipped by `start_process` |
| `priority` | Integer; lower runs first among processes of one object type |
| `run_once` | At most one start per CRM record (`object_type` + object `id`) |
| `trigger_on` | CRM event types that start the process: a subset of `create`, `update`, `delete`, default `["create", "update"]`; `[]` — manual runs only. Ignored for child processes; a manual run does not check it. See [Event type](../03-user-guide/triggers-and-conditions.md#event-type-and-the-run-on-field-trigger_on) |
| `version` | Incremented by every `PUT`; executions store the version they ran |
| `definition` | `conditions` (a condition row: `field`, `operator`, `value_type`, `value`, optional `cast`, `cast_options`, `on_cast_error` — see [Comparison type (cast)](../03-user-guide/triggers-and-conditions.md#comparison-type-cast)), `preload`, `actions` — see [Triggers and conditions](../03-user-guide/triggers-and-conditions.md), [Actions](../03-user-guide/actions.md), [Change types and expressions](../03-user-guide/change-types-and-expressions.md). Elements of `actions` can be blocks with nested lists (`{"type":"condition","branches":[{"conditions":[…],"actions":[…]}],"else":[…]}`); any step has optional `id` and `name`, see [Nested blocks](../03-user-guide/actions.md#nested-blocks-and-step-fields), and `on_error` and `retry`, see [Step errors](../03-user-guide/actions.md#step-errors-on_error-and-retries) |

### `Execution` and `ExecutionAction`

```json
{
  "id": "7a0d6f22-aa92-4fef-ac54-5674b6b138eb", "process_id": 9, "process_name": "API doc test", "process_version": 2,
  "parent_execution_id": null, "trigger": "manual", "event_id": "manual-ce71f9d2-…",
  "object_type": "Tasks", "object_id": "T-1", "status": "completed", "retry_count": 0, "handled_errors": 0, "error_message": null,
  "started_at": "2026-10-15T08:02:39+00:00", "finished_at": "2026-10-15T08:02:39+00:00", "duration_ms": 3
}
```

- `trigger`: `stream` (CRM event) | `manual` | `child`.
- `status`: `running` | `retrying` | `waiting` | `completed` | `failed` | `cancelled`. A waiting execution has
  `waiting_until` (due time, UTC) and `wait_reason` (`delay` | `retry`), others have `null`. `handled_errors` — number of step errors handled by `on_error` or by a loop with `on_item_error: continue`.

```json
{
  "id": 27, "step_index": 0, "action_type": "modify", "status": "success", "attempts": 1,
  "started_at": "2026-10-15T08:02:39+00:00", "duration_ms": 0,
  "request_data": { "value": "closed by WPS", "target": "object.description", "change_type": "set_value" },
  "result_data": { "new_value": "closed by WPS", "old_value": null },
  "error_message": null,
  "pc": 0, "step_path": "actions[0]", "iteration": "", "step_id": null,
  "error_code": null, "handled": null
}
```

- `action_type`: `preload` | `modify` | `save_object` | `start_process` (or a custom type).
- `status`: `success` | `waiting` | `failed` | `skipped`. `attempts` = 1 + retries.
- `step_index` is the sequence number of the audit record (`seq`); `pc` — instruction number in the program;
  `step_path` — place of the step in the definition (`actions[1].branches[0].actions[0]`); `iteration` — the iteration
  number of a `foreach` loop, `"2/0"` in nested loops (`""` outside loops); `step_id` — the user `id` of the step. Records written before migration `004` have
  `pc = step_index`, `step_path = null`.
- `action_type: modify` with `change_type: template` — `result_data.missing` (paths without a value, if any) and
  `result_data.warnings`.
- `action_type: modify` with `change_type: date_shift` — `result_data.base` (the base in canonical form), `result_data.shifts`
  (`[{amount, unit}]`, amounts from fields already substituted) and `result_data.missing_base` (`true` when there was no base).
- `action_type: foreach` — the loop block record, one per loop: `request_data` — `source`, `as`, `max_items`,
  `on_overflow`, `on_item_error`, `has_where`; when the loop ends, `result_data` is overwritten with the totals (`count`,
  `processed`, `skipped_by_where`, `failed_items`, `failed_indexes`, `unsaved_items`, `truncated`, `source_missing`,
  `where_traces`, on failure `failed_at_index`), see [Executions and audit](../03-user-guide/executions-and-audit.md#the-record-of-a-foreach-loop).
- `action_type: condition` — a block record: `request_data` — `branches` (number of branches), `has_else`;
  `result_data.selected` (branch index, `"else"` or `"none"`) and `result_data.branches` — only the checked branches:
  `[{branch, name, result, conditions: [ConditionTrace]}]` (`name` if set). See
  [The record of a condition block](../03-user-guide/executions-and-audit.md#the-record-of-a-condition-block).
- `action_type: stop_process` — a block record: `request_data` — `status`, `code`, `message` (the template);
  `result_data` — `stopped: true`, `status`, `code`, `message` after substitution, `message_error` on a template error;
  the following steps are `skipped`. See [A stopped execution](../03-user-guide/executions-and-audit.md#a-stopped-execution).
- `action_type: delay` — `request_data`: `mode`, the due-time fields, `due_at` (UTC), `due_at_local`; `result_data`:
  `waited`, `waited_seconds`, `inline` (pause in the coroutine), `lateness_ms` (after parking), `past_due` (the moment has
  passed). While the execution waits, the step is `waiting`. See [Delay](../03-user-guide/actions.md#delay).

- A failed step has `error_code` (`HTTP_503`, `TIMEOUT`, `CONFIG`, `UNKNOWN_OUTCOME` …) and `handled` (`null` — the error
  stopped the execution, `continue`, `goto`, `item_continue` — a `foreach` loop went on to the next item); `result_data` has `error_class`, `recoverable`, `error_code`, `error`
  (`{code, message, transient, http_status?, retry_after?}`), `details`, with `goto` — `goto` (target id). External actions (`send_email`, `http_request`, `notify`) add `idempotency_key` and `replayed`
  (`true` — the response came from the side-effect journal without calling again).
### `ProcessObjectRun` (run-once mark)

```json
{
  "process_id": 9, "object_type": "Tasks", "object_id": "T-1",
  "execution_id": "7a0d6f22-aa92-4fef-ac54-5674b6b138eb", "event_id": "manual-ce71f9d2-…", "trigger": "manual",
  "created_at": "2026-10-15T08:02:39+00:00", "blocked_count": 1, "last_blocked_at": "2026-10-15T08:02:39+00:00"
}
```

### `ConditionTrace`

```json
{ "field": "object.status", "operator": "equals", "value_type": "static", "value": "Done",
  "actual": "Open", "expected": "Done", "result": false }
```

`result` is `null` for a row that was not evaluated. `actual` / `expected` are the original values of the sides.
When the row has `cast` (or `cast_options`), extra keys are added:

```json
{ "field": "object.amount", "operator": "equals", "value_type": "static", "value": "100",
  "cast": "decimal", "cast_options": { "scale": 2 },
  "actual": "100.00", "expected": "100", "actual_cast": "100", "expected_cast": "100", "result": true }
```

| Key | Present when | Value |
|---|---|---|
| `cast`, `cast_options` | a type is set | the type and its non-default options |
| `actual_cast`, `expected_cast` | a type is set and the side was cast | the cast value (date — `Y-m-d`, date and time — ISO 8601 in UTC, list — an array) |
| `missing` | the field is missing | `actual` or `expected` |
| `empty` | the side is empty (types other than `string` / `bool`) | `actual` or `expected` |
| `cast_error` | the value cannot be cast | `{"side": "actual", "message": "Value \"abc\" is not a valid decimal"}`; for an item of `in` / `not_in` — `side: "expected"`, `Item 1: …` |
| `found` | operators `is_empty`, `is_not_empty`, `is_null`, `is_not_null` | whether the field was found (`expected` is `null`) |
| `expected_cast` (list) | `in`, `not_in` | the cast list items |

## Authentication

### `POST /api/auth/login`

Public. Body: `{ "email", "password" }`.

```bash
curl -s -X POST http://localhost:9501/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@wps.local","password":"admin"}'
```

`200`: `{ access_token, refresh_token, token_type: "Bearer", expires_in: 900, user: User }`.

Errors: `401 INVALID_CREDENTIALS` ("Invalid email or password"); `422 VALIDATION_ERROR` (`email` / `password` is required).

### `POST /api/auth/refresh`

Public. Body: `{ "refresh_token": "…" }`. Response as for login (a new pair; the user is re-read from the database).

```bash
curl -s -X POST http://localhost:9501/api/auth/refresh -H 'Content-Type: application/json' \
  -d '{"refresh_token":"'"$REFRESH"'"}'
```

Errors: `401 INVALID_TOKEN` ("Refresh token is required", "Invalid or expired refresh token", "User no longer exists").

### `GET /api/auth/me`

Any role. Returns the current `User`.

```bash
curl -s http://localhost:9501/api/auth/me -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"id":1,"email":"admin@wps.local","role":"admin","created_at":"2026-10-15T07:48:53+00:00","updated_at":"2026-10-15T07:48:53+00:00"},"error":null}
```

## Users (admin only)

### `GET /api/users`

Query: `page`, `per_page` (default 50).

```bash
curl -s 'http://localhost:9501/api/users?per_page=2' -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"id":1,"email":"admin@wps.local","role":"admin",…},{"id":2,"email":"editor@example.com","role":"editor",…}],"error":null,"meta":{"page":1,"per_page":2,"total":3}}
```

### `GET /api/users/{id}`

```bash
curl -s http://localhost:9501/api/users/2 -H "Authorization: Bearer $TOKEN"
```

`200 User`; `404 USER_NOT_FOUND`.

### `POST /api/users`

Body: `{ "email", "password" (≥ 6 characters), "role" }`.

```bash
curl -s -X POST http://localhost:9501/api/users -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"email":"analyst@example.com","password":"secret1","role":"viewer"}'
```

```json
{"success":true,"data":{"id":4,"email":"analyst@example.com","role":"viewer","created_at":"…","updated_at":"…"},"error":null}
```

`201 User`. Errors: `409 EMAIL_EXISTS`; `422 VALIDATION_ERROR`:

```json
{"field":"email","message":"A valid email is required"},
{"field":"password","message":"Password must be at least 6 characters"},
{"field":"role","message":"Role must be one of: admin, editor, viewer"}
```

### `PUT /api/users/{id}`

Body: any of `{ "email", "password", "role" }`. An empty or missing `password` keeps the current one.

```bash
curl -s -X PUT http://localhost:9501/api/users/4 -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"role":"editor","password":""}'
```

`200 User`. Errors: `404 USER_NOT_FOUND`, `409 EMAIL_EXISTS`, `409 LAST_ADMIN` ("The last administrator cannot lose the admin role"), `422`.

### `DELETE /api/users/{id}`

```bash
curl -s -X DELETE http://localhost:9501/api/users/4 -H "Authorization: Bearer $TOKEN"
```

`200 {"deleted": true}`. Errors: `404 USER_NOT_FOUND`, `409 CANNOT_DELETE_SELF`, `409 LAST_ADMIN`.

## Processes

### `GET /api/processes`

Any role. Query parameters:

| Parameter | Description |
|---|---|
| `search` | Substring of the name, case-insensitive |
| `object_type` | Exact module, e.g. `Leads` |
| `is_child` | `true` / `false` (also `1` / `0`) |
| `is_active` | `true` / `false` |
| `trigger_on` | Processes whose `trigger_on` contains this type: `create`, `update` or `delete` |
| `page`, `per_page` | Pagination (default 20, max 1000) |

Sorted by `updated_at DESC`. Deleted processes are not returned.

```bash
curl -s 'http://localhost:9501/api/processes?object_type=Leads&per_page=2' -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"id":6,"name":"New lead → schedule a call (once per lead)","object_type":"Leads","is_child":false,"is_active":true,"priority":0,"run_once":true,"version":1,"definition":{…},…},{"id":4,"name":"Create follow-up task",…}],"error":null,"meta":{"page":1,"per_page":2,"total":2}}
```

### `GET /api/processes/{id}`

Any role. `Process` plus `references`: child processes started by this one and parents that start it.

```bash
curl -s http://localhost:9501/api/processes/6 -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"id":6,"name":"New lead → schedule a call (once per lead)",…,
 "definition":{"conditions":[…],"preload":[],"actions":[{"type":"start_process","process_id":4}]},
 "references":{"children":[{"id":4,"name":"Create follow-up task","is_active":true,"is_child":true}],"parents":[]}},"error":null}
```

`404 PROCESS_NOT_FOUND` ("Process with id 999 not found").

### `POST /api/processes`

Editor or admin. Body: `{ name, object_type, is_child, is_active?, priority?, run_once?, trigger_on?, definition }`
(`is_active` defaults to `true`, `priority` to `0`, `run_once` to `false`, `trigger_on` to `["create", "update"]`; duplicates in
`trigger_on` are dropped and the order becomes `create, update, delete`). `trigger_on` errors: `Must be an array of event types`,
`Unknown event type "link" (allowed: create, update, delete)`; a delete-only process that saves `object` —
`The process runs only on delete: the deleted record cannot be saved` (field `definition.actions[k]`). The definition is validated in execution order;
all errors are returned at once.

```bash
curl -s -X POST http://localhost:9501/api/processes -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "name": "API doc test", "object_type": "Tasks", "is_child": false, "is_active": false, "priority": 5,
  "definition": {
    "conditions": [{"field":"object.status","operator":"equals","value_type":"static","value":"Done"}],
    "preload": [],
    "actions": [{"type":"modify","target":"object.description","change_type":"set_value","value":"closed by WPS","save_to_crm":true}]
  }}'
```

```json
{"success":true,"data":{"id":9,"name":"API doc test","object_type":"Tasks","is_child":false,"is_active":false,"priority":5,"run_once":false,"version":1,"definition":{…},"created_at":"2026-10-15T08:02:29+00:00","updated_at":"2026-10-15T08:02:29+00:00"},"error":null}
```

`201 Process`. When the definition is saved but has a potential problem, an optional `warnings` array (same format as
the error `details`) is added to the process, e.g.
`"warnings":[{"field":"definition.actions[1].value","message":"Variable \"task\" may be undefined here: it is set only in some branches"}]`.
Errors:

```json
{"success":false,"data":null,"error":{"code":"VALIDATION_ERROR","message":"Validation failed","details":[
  {"field":"name","message":"Process name is required"},
  {"field":"definition.conditions[0].field","message":"Variable \"v9\" is not defined at this point"},
  {"field":"definition.actions[0].value","message":"Unknown function \"foo\""}]}}
```

- `422 VALIDATION_ERROR` — also `Process 6 "…" is not a child process`, `Process 42 not found`, `A process cannot start itself`.
- `422 CYCLE_DETECTED` — see `PUT`.
- `400 BAD_REQUEST` — body is not valid JSON or is a JSON scalar.

### `PUT /api/processes/{id}`

Editor or admin. Same body as `POST` (the whole process); when `trigger_on` is not sent, the current value is kept. `version` is incremented. The process cache of the old and the new
object type is invalidated, so the next event uses the new definition; running executions keep their version.

```bash
curl -s -X PUT http://localhost:9501/api/processes/9 -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "name": "API doc test", "object_type": "Tasks", "is_child": false, "is_active": true, "priority": 5, "run_once": true,
  "definition": {"conditions":[{"field":"object.status","operator":"equals","value_type":"static","value":"Done"}],
                 "preload":[],
                 "actions":[{"type":"modify","target":"object.description","change_type":"set_value","value":"closed by WPS","save_to_crm":false}]}}'
```

`200 Process` (`"version": 2`), with `warnings` when needed, as for `POST`. Errors: `404 PROCESS_NOT_FOUND`, `422 VALIDATION_ERROR` (also when a child process that is
started by others is turned into a top-level one), `422 CYCLE_DETECTED`:

```json
{"success":false,"data":null,"error":{"code":"CYCLE_DETECTED","message":"Process launch cycle detected: Doc cycle B → Doc cycle A → Doc cycle B",
 "details":{"path":[{"id":12,"name":"Doc cycle B"},{"id":13,"name":"Doc cycle A"},{"id":12,"name":"Doc cycle B"}]}}}
```

### `DELETE /api/processes/{id}`

Admin only. Soft delete (`deleted_at`): the process disappears from lists and stops running; its executions stay in the audit.

The `force=true` parameter is required when the process has waiting executions (`waiting`): they are cancelled — status
`cancelled`, `error_message: "Process deleted"`, the `execution_waits` rows are deleted. Deactivating a process
(`is_active: false`) does not stop waiting executions.

```bash
curl -s -X DELETE http://localhost:9501/api/processes/10 -H "Authorization: Bearer $TOKEN"
```

`200 {"deleted": true}`. Errors: `404 PROCESS_NOT_FOUND`, `409 PROCESS_HAS_WAITING_EXECUTIONS` (without `force=true`):

```json
{"success":false,"data":null,"error":{"code":"PROCESS_HAS_WAITING_EXECUTIONS",
 "message":"The process has 2 waiting executions; delete with force=true to cancel them","details":{"waiting":2}}}
```

`409 PROCESS_IN_USE`:

```json
{"success":false,"data":null,"error":{"code":"PROCESS_IN_USE","message":"The process is started by other processes",
 "details":[{"id":6,"name":"New lead → schedule a call (once per lead)"}]}}
```

### `POST /api/processes/{id}/clone`

Editor or admin. Creates an **inactive** copy named `<name> (копия)` (the suffix is always Russian). `run_once` and `trigger_on` are copied,
run-once marks are not. No body.

```bash
curl -s -X POST http://localhost:9501/api/processes/9/clone -H "Authorization: Bearer $TOKEN"
```

`201 Process` (`"name":"API doc test (копия)","is_active":false,"version":1`).

### `POST /api/processes/{id}/run`

Editor or admin. Manual run with a test object. Body: `{ "object": {…}, "force": false }`; if there is no `object` key, the whole
body is the object. `object.id` is required. `object.module` (or `object_type`) defaults to the process object type.
`force: true` skips the conditions but not run once. Conditions are checked synchronously; the steps run in the background.
The service fields of a contract 2.0 event — `event_type` (`create` | `update` | `delete`), `before` (old values of the changed
fields), `changed_fields` — are passed in `object` at the top level; they do not stay in the record but become the variable
`object_before` and `context.event_info` of the execution. Without them the run is an old-format event (`object_before` = `null`).

```bash
curl -s -X POST http://localhost:9501/api/processes/9/run -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"object":{"id":"T-1","module":"Tasks","status":"Done"}}'
```

| Result | HTTP | `data` |
|---|---|---|
| Conditions met, execution created | `201` | `{"conditions_met":true,"execution_id":"7a0d6f22-…","conditions":[ConditionTrace],"run_once_applied":true,"run_once_blocked":false,"previous_execution_id":null}` |
| Conditions not met | `200` | `{"conditions_met":false,"execution_id":null,"conditions":[{"field":"object.status",…,"actual":"Open","expected":"Done","result":false}],…}` |
| Blocked by run once | `200` | `{"conditions_met":true,"execution_id":null,"conditions":[],"run_once_applied":true,"run_once_blocked":true,"previous_execution_id":"7a0d6f22-…"}` |
| A strict condition could not cast a value (`on_cast_error: "error"`) | `200` | `{"conditions_met":false,"execution_id":null,"conditions":[{…,"cast_error":{"side":"actual","message":"…"}}],…,"error":"CAST_ERROR","error_message":"Condition on object.amount: Value \"12.5\" is not an integer"}` |

`run_once_applied` is `true` when the process has `run_once` and the object has an `id`. Follow the execution with
`GET /api/executions/{execution_id}`. Errors: `404 PROCESS_NOT_FOUND`, `422 VALIDATION_ERROR`
(`object`: "A JSON object is required"; `object.id`: "The object must have an \"id\"").

### `GET /api/processes/{id}/executions`

Any role. Query: `status` (`running` | `retrying` | `waiting` | `completed` | `failed` | `cancelled`), `date_from`, `date_to` (compared with `started_at`; ISO 8601
date-time, a time without zone is UTC; a bare date `YYYY-MM-DD` in `date_to` means "up to the end of that day"), `page`, `per_page`. Sorted by `started_at DESC`.

```bash
curl -s 'http://localhost:9501/api/processes/9/executions?status=completed' -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"id":"7a0d6f22-…","process_id":9,"process_name":"API doc test","process_version":2,"parent_execution_id":null,"trigger":"manual","event_id":"manual-ce71f9d2-…","object_type":"Tasks","object_id":"T-1","status":"completed","retry_count":0,"error_message":null,"started_at":"…","finished_at":"…","duration_ms":3}],"error":null,"meta":{"page":1,"per_page":20,"total":1}}
```

Errors: `404 PROCESS_NOT_FOUND`; `422` ("Status must be one of: running, retrying, waiting, completed, failed, cancelled", "Invalid date").

### `GET /api/processes/{id}/runs`

Any role. Run-once marks of the process, `created_at DESC`. Query: `object_id` (exact), `page`, `per_page`.

```bash
curl -s http://localhost:9501/api/processes/9/runs -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"process_id":9,"object_type":"Tasks","object_id":"T-1","execution_id":"7a0d6f22-…","event_id":"manual-ce71f9d2-…","trigger":"manual","created_at":"…","blocked_count":1,"last_blocked_at":"…"}],"error":null,"meta":{"page":1,"per_page":20,"total":1}}
```

### `DELETE /api/processes/{id}/runs/{object_type}/{object_id}`

Editor or admin. Removes the mark: the next event of this record starts the process again. URL-encode the segments.

```bash
curl -s -X DELETE http://localhost:9501/api/processes/9/runs/Tasks/T-1 -H "Authorization: Bearer $TOKEN"
```

`200 {"deleted": true}`. Errors: `404 PROCESS_NOT_FOUND`; `404 NOT_FOUND` ("Process 9 has no run mark for Tasks T-1").

## Executions

### `GET /api/executions`

Any role. Executions of all processes — paginated `Execution[]`. Query: `status` (`running` | `retrying` | `waiting` |
`completed` | `failed` | `cancelled`), `process_id`, `due_before` (`waiting_until` not later than this date-time — "what
wakes up before …"), `date_from`, `date_to` (by `started_at`, as in the process log), `page`, `per_page`.

```bash
curl -s 'http://localhost:9501/api/executions?status=waiting&due_before=2026-11-01T00:00:00Z' -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"id":"5c1e…","process_id":12,"process_name":"Call back in 2 days","status":"waiting",
 "waiting_until":"2026-10-22T08:15:03+00:00","wait_reason":"delay","started_at":"2026-10-20T08:15:03+00:00","finished_at":null,…}],
 "error":null,"meta":{"page":1,"per_page":20,"total":1}}
```

Errors: `422` ("Status must be one of: running, retrying, waiting, completed, failed, cancelled", `due_before` — "Invalid date").

### `GET /api/executions/{id}`

Any role. `Execution` plus:

| Field | Description |
|---|---|
| `context` | `{event, variables, meta, event_info, conditions, stop?}` — the final snapshot; while running, the live state from Redis. `event` and `variables.object` are the record without the event service keys; `variables.object_before` — old values of the changed fields or `null`; `event_info` — `{event_type, changed_fields, has_before, origin, legacy}` (`changed_fields: null` — unknown, `origin` — `wps_origin` of the event); `stop` — `{status, code, message}` when a `stop_process` step ended the execution |
| `current_step` | Only while running: `pc` of the next program instruction |
| `seq` | Only while running: number of the next audit record |
| `frames` | Only while running: frames of the open `foreach` loops (`[]` outside loops): `begin`, `next`, `end`, `as`, `loop_var`, `module`, `items_key`, `index`, `count`, `audit_seq`, `stats` … — without the items themselves (they are in the snapshot `exec:{id}:loop:{pc}`) |
| `wait` | Only for a waiting execution: `{due_at, reason, attempts, info}` — the `execution_waits` row (`info`: `pc`, `seq`, `mode`, `parked_at` …) |
| `actions` | `ExecutionAction[]`, by `step_index` |
| `children` | `Execution[]` started by `start_process` of this execution |

```bash
curl -s http://localhost:9501/api/executions/7a0d6f22-aa92-4fef-ac54-5674b6b138eb -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"id":"7a0d6f22-…","process_id":9,…,"status":"completed",
 "context":{"meta":{"object":{"module":"Tasks","source":"event"},"object_before":{"module":"Tasks","source":"event_before"}},
            "event":{"id":"T-1","module":"Tasks","status":"Done"},
            "variables":{"object":{"id":"T-1","module":"Tasks","status":"Done","description":"closed by WPS"},"object_before":null},
            "event_info":{"event_type":"update","changed_fields":null,"has_before":false,"origin":null,"legacy":true},
            "conditions":[{"field":"object.status","value":"Done","actual":"Done","result":true,"expected":"Done","operator":"equals","value_type":"static"}]},
 "actions":[{"id":27,"step_index":0,"action_type":"modify","status":"success","attempts":1,…}],
 "children":[]},"error":null}
```

`404 EXECUTION_NOT_FOUND` (also for an id that is not a UUID).

### `GET /api/executions/{id}/actions`

Any role. Only the steps (`ExecutionAction[]`).

```bash
curl -s http://localhost:9501/api/executions/7a0d6f22-aa92-4fef-ac54-5674b6b138eb/actions -H "Authorization: Bearer $TOKEN"
```

`404 EXECUTION_NOT_FOUND`.

### `GET /api/executions/{id}/side-effects`

Any role. The external call journal of an execution (table `step_side_effects`, without the responses of external
systems) — for analysing `UNKNOWN_OUTCOME` and repeats ([Reliability](../04-operations/reliability.md#side-effects-and-repeats)).

```bash
curl -s http://localhost:9501/api/executions/7a0d6f22-aa92-4fef-ac54-5674b6b138eb/side-effects -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
  {"effect_key":"3","action_type":"send_email","delivery":"at_most_once","status":"done","attempts":1,
   "started_at":"2026-10-19T19:40:02+00:00","finished_at":"2026-10-19T19:40:03+00:00","error_message":null}]}
```

- `effect_key` — `{pc}`, `{pc}@{iteration}` in loops, `#{sub}` for several calls in one step.
- `status`: `started` (the call started, outcome unknown), `done`, `failed`.
- An execution without external calls returns an empty list. `404 EXECUTION_NOT_FOUND`.

## Secrets

Credentials of external actions (see [Secrets](../03-user-guide/secrets.md)). A secret value is write-only: no endpoint
returns it. The `Secret` object:

```json
{ "name": "erp_token", "kind": "bearer", "description": "ERP API", "mask": "****cdef", "allowed_hosts": ["erp.example.com"],
  "version": 2, "created_at": "2026-10-19T19:29:33Z", "updated_at": "2026-10-19T19:40:00Z", "last_used_at": "2026-10-19T19:41:02Z" }
```

For composite kinds (`basic_auth`, `oauth2_client`) `mask` is an object: non-secret fields as is, the others masked
(`{"username":"erp","password":"********"}`).

Errors of this section: `503 SECRETS_KEY_NOT_CONFIGURED` (`WPS_SECRETS_KEY` is not set), `409 SECRET_EXISTS`,
`409 SECRET_IN_USE`, `404 NOT_FOUND`, `422 VALIDATION_ERROR`, `409 ROTATION_FAILED`.

### `GET /api/secrets`

admin, editor. `Secret[]` sorted by name, each with `usages_count` — the number of references from non-deleted processes.

```json
{"success":true,"data":[{"name":"erp_token","kind":"bearer","description":"ERP API","mask":"****cdef","allowed_hosts":[],
 "version":1,"created_at":"…","updated_at":"…","last_used_at":null,"usages_count":1}],"error":null}
```

### `GET /api/secrets/{name}`

admin, editor. `Secret` + `usages: [{process_id, process_name, path}]` (`path` — the field in the definition, e.g.
`definition.actions[1].auth.token`). No such secret — `404 NOT_FOUND`.

### `POST /api/secrets`

admin. Body `{ name, kind?, value, description?, allowed_hosts? }` → `201 Secret` (without the value).

```bash
curl -s -X POST http://localhost:9501/api/secrets -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"erp_basic","kind":"basic_auth","value":{"username":"erp","password":"p@ssw0rd"}}'
```

Validation errors (`422`, field → message): `name` — `Secret name must match ^[a-z][a-z0-9_]{1,63}$`; `kind` —
`Unknown secret kind (allowed: …)`; `value` — `Secret value is required`, `Secret value is too short (min 4 characters)`,
`Value must be a string`, `Value must be an object for kind "basic_auth"`, `Invalid mailer DSN (…)`,
`Value must be an http(s) URL`; `value.password` — `Field "password" is required for kind "basic_auth"`;
`value.token_url` — `Token URL must use https`; `allowed_hosts[0]` — `Invalid host "…"`. Name taken — `409 SECRET_EXISTS`.

### `PUT /api/secrets/{name}`

admin. Body `{ value?, description?, allowed_hosts? }` → `Secret`. A new value increments `version`. The name and the kind
cannot change (`422`: `A secret cannot be renamed (processes refer to it by name)`,
`The kind of a secret cannot be changed`). Workers pick up a new value within `SECRETS_CACHE_TTL`.

### `DELETE /api/secrets/{name}`

admin. `{ "deleted": true }`. If non-deleted processes refer to the secret — `409 SECRET_IN_USE` with
`{ "usages": [{process_id, process_name, path}] }` in `details`.

### `POST /api/secrets/rotate`

admin. Re-encrypts with the current key every secret encrypted with the previous one (`WPS_SECRETS_KEY_PREVIOUS`) →
`{ "reencrypted": N }`. A row that no key decrypts — `409 ROTATION_FAILED`.

## Mail

### `POST /api/mail/test`

admin. Sends a test letter to check the mail settings without a process. Body: `{ "to": "<address>", "transport"?:
"secret://smtp_main" }`; without `transport` — the default transport `MAILER_DSN`.

```bash
curl -s -X POST http://localhost:9501/api/mail/test -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"to":"you@company.ru"}'
```

```json
{"success":true,"data":{"sent":true,"to":"you@company.ru","message_id":"27xdaVLBnTJG95wPWc7fmU"},"error":null}
```

A failed send is also `200`: `{ "sent": false, "error": "<SMTP error text, password masked>", "error_code": "SMTP_AUTH" }`.
An invalid address — `422` (`to`: `Invalid email address`). Role editor or viewer — `403`.

## Notifications

### `POST /api/notify/test`

admin, editor. Sends a test notification to **one** channel of the [`notify`](../03-user-guide/actions.md#notification)
action to check the channel without a process. Body: `{ "channel": { …a channel as in channels… }, "message"?: "text" }`;
the title is "WPS", the default text is a Russian "this is a test WPS notification", `delivery: at_least_once`.

```bash
curl -s -X POST http://localhost:9501/api/notify/test -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"channel":{"channel":"telegram","connection":"secret://sales_bot","chat_id":"-1001234567890"}}'
```

```json
{"success":true,"data":{"sent":true,"result":{"channels":[{"channel":"telegram","status":"success","deliveries":[{"connection":"secret://sales_bot","chat_id":"-1001234567890","message_id":42,"replayed":false}]}]}},"error":null}
```

A send error is also `200`: `{ "sent": false, "error": "notify: 1 of 1 channels failed: telegram: HTTP 403 …" }`
(secrets masked). Channel configuration errors — `422` with `channel.…` fields (`channel.connection`: `Connection secret
is required (secret://name)` and others). The viewer role — `403`.

## Dictionaries

All return `[{ "value", "label" }]`; action types also have `description`. Labels are Russian; translate by `value`.

### `GET /api/dictionaries/modules`

Any role. CRM modules, cached in Redis for `DICT_CACHE_TTL` (300 s). Source: `MOCK_SERVICE_URL/modules`, then the CRM
`/modules` (see [CRM integration](../04-operations/crm-integration.md#modules-dictionary)).

```bash
curl -s http://localhost:9501/api/dictionaries/modules -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"Accounts","label":"Контрагенты"},{"value":"Contacts","label":"Контакты"},{"value":"Leads","label":"Лиды"},…],"error":null}
```

### `POST /api/dictionaries/modules/refresh`

Editor or admin. Drops the cache and loads the list again; returns it. It also reloads the CRM capabilities (`crm-capabilities`): after a CRM plugin update this request is enough.

```bash
curl -s -X POST http://localhost:9501/api/dictionaries/modules/refresh -H "Authorization: Bearer $TOKEN"
```

### `GET /api/dictionaries/crm-capabilities`

Any role. Version of the WPS ↔ CRM contract and capabilities of the CRM plugin ([CRM integration](../04-operations/crm-integration.md#capabilities-and-degradation)).
Cached in Redis `crm:capabilities` for `DICT_CACHE_TTL`, reset by `POST /api/dictionaries/modules/refresh`.

```bash
curl -s http://localhost:9501/api/dictionaries/crm-capabilities -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"contract_version":"2.0",
 "features":["filter_operators","sort","cursor_pagination","fields","count","delete","relationships","relationship_fields","event_v2","delete_events","origin_headers"],
 "limits":{"max_page_size":100,"max_link_batch":100,"max_in_values":100},
 "crm":{"name":"SuiteCRM","version":"8.10.2","plugin_version":"1.2.0"},
 "source":"crm","cached_at":"2026-10-20T09:00:00+00:00"},"error":null}
```

`source`: `crm` — the answer of `GET {CRM}/capabilities`; `fallback` — the CRM has no such endpoint (an old plugin):
`contract_version: "1.0"`, `features: []`. If the CRM is not reachable — `503 CRM_UNAVAILABLE`.

### `GET /api/dictionaries/operators`

```bash
curl -s http://localhost:9501/api/dictionaries/operators -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"equals","label":"Равен","casts":["string","int","decimal","date","datetime","bool","list"],"operands":"value"},
 {"value":"not_equals","label":"Не равен","casts":["string","int","decimal","date","datetime","bool","list"],"operands":"value"},
 {"value":"greater_than","label":"Больше","casts":["int","decimal","date","datetime"],"operands":"value"},
 {"value":"greater_or_equal","label":"Больше или равно","casts":["int","decimal","date","datetime"],"operands":"value"},
 {"value":"less_than","label":"Меньше","casts":["int","decimal","date","datetime"],"operands":"value"},
 {"value":"less_or_equal","label":"Меньше или равно","casts":["int","decimal","date","datetime"],"operands":"value"},
 {"value":"between","label":"В диапазоне","casts":["int","decimal","date","datetime"],"operands":"range"},
 {"value":"not_between","label":"Вне диапазона","casts":["int","decimal","date","datetime"],"operands":"range"},
 {"value":"in","label":"Входит в","casts":["string","int","decimal","date"],"operands":"list"},
 {"value":"not_in","label":"Не входит в","casts":["string","int","decimal","date"],"operands":"list"},
 {"value":"contains","label":"Включает","casts":["string","list"],"operands":"value"},
 {"value":"not_contains","label":"Не включает","casts":["string","list"],"operands":"value"},
 {"value":"starts_with","label":"Начинается с","casts":["string"],"operands":"value"},
 {"value":"ends_with","label":"Заканчивается на","casts":["string"],"operands":"value"},
 {"value":"contains_any","label":"Содержит любое из","casts":["list"],"operands":"list"},
 {"value":"contains_all","label":"Содержит все из","casts":["list"],"operands":"list"},
 {"value":"contains_none","label":"Не содержит ни одного из","casts":["list"],"operands":"list"},
 {"value":"is_empty","label":"Пусто","casts":["string","int","decimal","date","datetime","bool","list"],"operands":"none"},
 {"value":"is_not_empty","label":"Не пусто","casts":["…"],"operands":"none"},
 {"value":"is_null","label":"Данные отсутствуют","casts":["…"],"operands":"presence"},
 {"value":"is_not_null","label":"Данные есть","casts":["…"],"operands":"presence"},
 {"value":"changed","label":"Изменилось","casts":["string","int","decimal","date","datetime","bool","list"],"operands":"change"},
 {"value":"not_changed","label":"Не изменилось","casts":["…"],"operands":"change"},
 {"value":"changed_to","label":"Изменилось на","casts":["…"],"operands":"change_value"},
 {"value":"changed_from","label":"Изменилось с","casts":["…"],"operands":"change_value"}],"error":null}
```

`casts` — the `cast` types the operator works with; `operands` — the shape of the right-hand side: `value` — one value,
`list` — an array of values (`value` is a JSON array or a path to a list field; `value_list_options` splits a string),
`none` and `presence` — no value (`value` and `value_type` are not set), `range` — a pair `[from, to]` (`between`,
`not_between`: both bounds static, or both paths with `from_object_field`). Order operators require a `cast`.
`change` (`changed`, `not_changed`) and `change_value` (`changed_to`, `changed_from`) are change operators on the event object
(only `object.<field>`, every `cast`): `change` has no value, `change_value` requires `value`. A condition trace row gets the
keys `before`, `before_cast`, `changed`, `event_type`, and without change info — `change_info: "missing"` / `"delete"`. See
[Conditions on a field change](../03-user-guide/triggers-and-conditions.md#conditions-on-a-field-change).
See [Comparison type (cast)](../03-user-guide/triggers-and-conditions.md#comparison-type-cast).

### `GET /api/dictionaries/functions`

Functions and operators of the expression language (the **Calculate** change type). Available to any role.

```bash
curl -s http://localhost:9501/api/dictionaries/functions -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"name":"add_days","category":"date","signature":"add_days(d, n)","min_args":2,"max_args":2,"returns":"date|datetime","description":"Прибавляет календарные дни; местное время сохраняется","example":"add_days(today(), 3)","pure":true},
 {"name":"date_diff","category":"date","signature":"date_diff(from, to, unit)","min_args":3,"max_args":3,"returns":"number","description":"…","example":"date_diff(object.date_modified, now(), 'days')","pure":true},
 {"name":"now","category":"date","signature":"now()","min_args":0,"max_args":0,"returns":"datetime","description":"Текущий момент, UTC","example":"now()","pure":false},
 … other date functions …,
 {"name":"coalesce","category":"logic","signature":"coalesce(a, b, …)","min_args":1,"max_args":null,"returns":"any","description":"Первое непустое значение (не null и не пустая строка)","example":"coalesce(object.phone_mobile, object.phone_work, v1.phone_office)","pure":true},
 {"name":"if","category":"logic","signature":"if(cond, then[, else])","min_args":2,"max_args":3,"returns":"any","description":"Значение then, если cond истинно, иначе else (или пустая строка)","example":"if(object.amount > 100000, 'VIP', 'Regular')","pure":true},
 {"name":"ifempty","category":"logic","signature":"ifempty(a, default)","min_args":2,"max_args":2,"returns":"any","description":"…","example":"ifempty(object.title, 'Без названия')","pure":true},
 {"name":"ifs","category":"logic","signature":"ifs(cond1, value1, cond2, value2, …[, default])","min_args":2,"max_args":null,"returns":"any","description":"…","example":"…","pure":true},
 {"name":"switch","category":"logic","signature":"switch(x, key1, value1, key2, value2, …[, default])","min_args":3,"max_args":null,"returns":"any","description":"…","example":"…","pure":true},
 {"name":"abs","category":"number","signature":"abs(x)","min_args":1,"max_args":1,"returns":"number","description":"Модуль числа","example":"abs(object.balance)","pure":true},
 {"name":"max","category":"number","signature":"max(a, b, …)","min_args":1,"max_args":null,"returns":"number","description":"…","example":"…","pure":true},
 {"name":"min","category":"number","signature":"min(a, b, …)","min_args":1,"max_args":null,"returns":"number","description":"…","example":"…","pure":true},
 {"name":"round","category":"number","signature":"round(x[, digits])","min_args":1,"max_args":2,"returns":"number","description":"Округление до digits знаков","example":"round(object.amount * 0.9, 2)","pure":true},
 {"name":"concat","category":"string","signature":"concat(a, b, …)","min_args":1,"max_args":null,"returns":"string","description":"Склеивает значения в одну строку","example":"concat(v1.first_name, ' ', v1.last_name)","pure":true},
 {"name":"len","category":"string","signature":"len(s)","min_args":1,"max_args":1,"returns":"number","description":"…","example":"…","pure":true},
 {"name":"lower","category":"string","signature":"lower(s)","min_args":1,"max_args":1,"returns":"string","description":"…","example":"…","pure":true},
 {"name":"substr","category":"string","signature":"substr(s, start[, length])","min_args":2,"max_args":3,"returns":"string","description":"…","example":"…","pure":true},
 {"name":"trim","category":"string","signature":"trim(s)","min_args":1,"max_args":1,"returns":"string","description":"…","example":"…","pure":true},
 {"name":"upper","category":"string","signature":"upper(s)","min_args":1,"max_args":1,"returns":"string","description":"…","example":"…","pure":true}],
 "meta":{"operators":[
 {"op":"or","synonyms":["||"],"precedence":1},
 {"op":"and","synonyms":["&&"],"precedence":2},
 {"op":"not","synonyms":["!"],"precedence":3},
 {"op":"==","synonyms":["="],"precedence":4},
 {"op":"!=","synonyms":["<>"],"precedence":4},
 {"op":"<","synonyms":[],"precedence":4},
 {"op":"<=","synonyms":[],"precedence":4},
 {"op":">","synonyms":[],"precedence":4},
 {"op":">=","synonyms":[],"precedence":4},
 {"op":"+","synonyms":[],"precedence":5},
 {"op":"-","synonyms":[],"precedence":5},
 {"op":"*","synonyms":[],"precedence":6},
 {"op":"/","synonyms":[],"precedence":6},
 {"op":"%","synonyms":[],"precedence":6}]},"error":null}
```

The `data` items are sorted by `category`, then by `name`. Date functions (category `date`) are described in [Dates and time](../03-user-guide/change-types-and-expressions.md#dates-and-time); `format_date_locale` exists only when `intl` is installed in the image:

| Field | Description |
|---|---|
| `name` | function name (lower case) |
| `category` | `logic`, `string`, `number`, `date`, `list`, `type`, `system` or `custom` (a function registered without a description) |
| `signature` | the signature; `[…]` marks optional arguments, `…` a variable number |
| `min_args`, `max_args` | the allowed number of arguments; `max_args: null` — no upper bound. Checked when a process is saved: `Function "if" expects 2..3 arguments, got 1` → `422` |
| `returns` | result type: `string`, `number`, `bool`, `date`, `datetime`, `date\|datetime` (same as the argument), `any` |
| `description`, `example` | description (in Russian) and a sample call |
| `pure` | `true` — the result depends on the arguments only; `false` — on the current time (`now`, `today`): a retried step computes it again |

`meta.operators` lists the operators: `op`, its `synonyms` and `precedence` (1 is the weakest: `or`; 6 is the strongest:
`*` `/` `%`). Precedence-4 operators (comparisons) cannot be chained. Details —
[Change types and expressions](../03-user-guide/change-types-and-expressions.md#syntax).

### `GET /api/dictionaries/action-types`

```bash
curl -s http://localhost:9501/api/dictionaries/action-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"preload","label":"Предзагрузка объекта","description":"Загружает объект из CRM по ID или другому полю в переменную процесса","kind":"action"},
 {"value":"modify","label":"Изменить объект/переменную","description":"…","kind":"action"},
 {"value":"save_object","label":"Сохранить объект в CRM","description":"…","kind":"action"},
 {"value":"start_process","label":"Запустить следующий процесс","description":"…","kind":"action"},
 {"value":"send_email","label":"Отправить письмо","description":"Отправляет письмо через SMTP: получатели, тема и тело — шаблоны с подстановками","kind":"action"},
 {"value":"http_request","label":"HTTP-запрос","description":"Вызывает внешний API (вебхук): URL, заголовки и тело — шаблоны, ответ сохраняется в переменную","kind":"action"},
 {"value":"link_records","label":"Связать записи","description":"Связывает запись с другими записями через link-поле CRM (участники встречи, контакты контрагента), можно с полями связи","kind":"action"},
 {"value":"unlink_records","label":"Отвязать записи","description":"Удаляет связи записи с другими записями через link-поле CRM","kind":"action"},
 {"value":"set_variable","label":"Записать в переменную процесса","description":"Сохраняет промежуточное значение в переменную, которая не является записью CRM","kind":"action"},
 {"value":"find_records","label":"Найти записи","description":"Находит записи CRM по фильтру или связанные записи и сохраняет список в переменную","kind":"action"},
 {"value":"update_records","label":"Изменить записи списка","description":"Меняет поля у всех записей списка, найденного шагом «Найти записи», одним шагом","kind":"action"},
 {"value":"assign","label":"Назначить ответственного","description":"Назначает ответственного по правилу: по кругу, наименее загруженного, случайно, руководителя","kind":"action"},
 {"value":"notify","label":"Уведомление","description":"Отправляет короткое уведомление в колокольчик CRM, Telegram, Slack или Microsoft Teams","kind":"action"},
 {"value":"delay","label":"Пауза","description":"Приостанавливает выполнение на срок или до даты; длинная пауза не занимает воркер и переживает перезапуск","kind":"action"},
 {"value":"condition","label":"Условие (если / иначе)","description":"…","kind":"block"},
 {"value":"stop_process","label":"Завершить процесс","description":"…","kind":"block"},
 {"value":"foreach","label":"Для каждого элемента","description":"Выполняет вложенные действия для каждого элемента списка","kind":"block"}],"error":null}
```

The items are action types (`kind: "action"`) and blocks (`kind: "block"`): both are set by the `type` field of an `actions` row.

### `GET /api/dictionaries/change-types`

```bash
curl -s http://localhost:9501/api/dictionaries/change-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"set_value","label":"Равен значению"},{"value":"set_from_variable","label":"Равен переменной из поля"},{"value":"calculate","label":"Расчет"},{"value":"template","label":"Шаблон"},{"value":"date_shift","label":"Сдвиг даты"},{"value":"increment","label":"Увеличить на"},{"value":"decrement","label":"Уменьшить на"},{"value":"clear","label":"Очистить"},{"value":"append","label":"Дописать в конец"},{"value":"prepend","label":"Дописать в начало"},{"value":"remove","label":"Удалить из списка"},{"value":"map","label":"По таблице соответствия"},{"value":"aggregate","label":"Агрегат по записям"}],"error":null}
```

### `GET /api/dictionaries/error-policies`

Values of a step's `on_error` ([Step errors](../03-user-guide/actions.md#step-errors-on_error-and-retries)).

```json
{"success":true,"data":[{"value":"stop","label":"Остановить процесс"},{"value":"continue","label":"Продолжить"},{"value":"goto","label":"Перейти к шагу"}],"error":null}
```

### `GET /api/dictionaries/retry-classes`

Error classes for `retry.retry_on`; a 3-digit HTTP code (`"409"`) is allowed as well.

```json
{"success":true,"data":[
 {"value":"transient","label":"Временные ошибки: сеть, таймаут, 5xx, 408/425/429 (по умолчанию)"},
 {"value":"5xx","label":"Ответы HTTP 5xx"},
 {"value":"4xx","label":"Ответы HTTP 4xx"},
 {"value":"any","label":"Любая ошибка, кроме ошибок конфигурации и неизвестного исхода"}
],"error":null}
```

Labels are in Russian, like in the other dictionaries.

### `GET /api/dictionaries/notify-channels`

Any role. Channels of the `notify` action and the secret kinds suitable for their `connection` (an empty list — a
channel without a connection).

```bash
curl -s http://localhost:9501/api/dictionaries/notify-channels -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"crm_alert","label":"Уведомление в CRM","secret_kinds":[]},{"value":"telegram","label":"Telegram","secret_kinds":["telegram_bot","generic"]},{"value":"slack","label":"Slack","secret_kinds":["webhook_url","generic"]},{"value":"teams","label":"Microsoft Teams","secret_kinds":["webhook_url","generic"]}],"error":null}
```

### `GET /api/dictionaries/aggregate-functions`

Functions of the `aggregate` change type ([Aggregate over records](../03-user-guide/change-types-and-expressions.md#aggregate-over-records)).

```bash
curl -s http://localhost:9501/api/dictionaries/aggregate-functions -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"count","label":"Количество"},{"value":"count_distinct","label":"Количество различных"},{"value":"sum","label":"Сумма"},{"value":"avg","label":"Среднее"},{"value":"min","label":"Минимум"},{"value":"max","label":"Максимум"}],"error":null}
```

### `GET /api/dictionaries/assign-strategies`

Any role. Strategies of the `assign` action ([Assign an owner](../03-user-guide/actions.md#assign-an-owner)).

```bash
curl -s http://localhost:9501/api/dictionaries/assign-strategies -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"round_robin","label":"По кругу"},{"value":"least_busy","label":"Наименее загруженный"},{"value":"random","label":"Случайно"},{"value":"manager","label":"Руководитель"},{"value":"user","label":"Конкретный пользователь"}],"error":null}
```

### `GET /api/dictionaries/date-units`

Units of the `date_shift` change type ([Date shift](../03-user-guide/change-types-and-expressions.md#date-shift)). There are no working days.

```bash
curl -s http://localhost:9501/api/dictionaries/date-units -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"minute","label":"Минуты"},{"value":"hour","label":"Часы"},{"value":"day","label":"Дни"},{"value":"week","label":"Недели"},{"value":"month","label":"Месяцы"},{"value":"year","label":"Годы"}],"error":null}
```

### `GET /api/dictionaries/relative-dates`

Any role. Anchors and units of [relative dates](../03-user-guide/triggers-and-conditions.md#relative-dates) in conditions and filters and the time zone the anchors are computed in (`WPS_TIMEZONE`). The answer is an object, not a list.

```bash
curl -s http://localhost:9501/api/dictionaries/relative-dates -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"anchors":[{"value":"now","label":"Сейчас"},{"value":"today","label":"Сегодня"},{"value":"yesterday","label":"Вчера"},{"value":"tomorrow","label":"Завтра"},{"value":"start_of_day","label":"Начало дня"},"…",{"value":"end_of_year","label":"Конец года"}],"units":[{"value":"min","label":"минут"},{"value":"h","label":"часов"},{"value":"d","label":"дней"},{"value":"w","label":"недель"},{"value":"mo","label":"месяцев"},{"value":"y","label":"лет"}],"timezone":"Europe/Moscow"},"error":null}
```

### `GET /api/dictionaries/value-types`

Any role. Values of the `value_type` field of a condition row: `static`, `from_object_field` and `expression` — the right
side is computed by an expression ([Comparing with an expression](../03-user-guide/triggers-and-conditions.md#comparing-with-an-expression)).

```bash
curl -s http://localhost:9501/api/dictionaries/value-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"static","label":"Значение"},{"value":"from_object_field","label":"Из поля объекта/переменной"},{"value":"expression","label":"Выражение"}],"error":null}
```

### `GET /api/dictionaries/event-types`

CRM event types for the process field `trigger_on` ([Event type](../03-user-guide/triggers-and-conditions.md#event-type-and-the-run-on-field-trigger_on)). Labels are Russian.

```bash
curl -s http://localhost:9501/api/dictionaries/event-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"create","label":"Создание записи"},{"value":"update","label":"Изменение записи"},{"value":"delete","label":"Удаление записи"}],"error":null}
```

### `GET /api/dictionaries/template-filters`

Template filters ([Template](../03-user-guide/change-types-and-expressions.md#template)): `{{ x | f:a:b }}` calls the
expression function `f(x, a, b)`, so the list holds every function that takes the value as its first argument (no
`now()`) and the `raw` pseudo-filter.

```bash
curl -s http://localhost:9501/api/dictionaries/template-filters -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"truncate","label":"Обрезает текст до length символов вместе с suffix","category":"string","signature":"truncate(s, length[, suffix='…'])","min_args":1,"max_args":2,"example":"{{ topic.text | truncate:40 }}"},
 {"value":"upper","label":"Строка в верхнем регистре","category":"string","signature":"upper(s)","min_args":0,"max_args":0,"example":"{{ object.field | upper }}"},
 {"value":"raw","label":"Без HTML-экранирования (только там, где действие это разрешает)","category":"template","signature":"raw","min_args":0,"max_args":0,"example":"{{ v1.signature_html | raw }}"}
],"error":null}
```

| Field | Meaning |
|---|---|
| `value` | filter name |
| `label` | description (from the function description; Russian, like other dictionaries) |
| `category` | function category (`string`, `number`, `date`, `logic`) or `template` for `raw` |
| `signature` | function signature; the first argument is the value left of `\|` |
| `min_args`, `max_args` | number of **filter** arguments (one fewer than the function has); `max_args: null` — unlimited |
| `example` | usage in a template |

### `GET /api/dictionaries/preload-sources`

```bash
curl -s http://localhost:9501/api/dictionaries/preload-sources -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"object_field","label":"Из поля объекта"},{"value":"variable_field","label":"Из поля переменной"},{"value":"manual","label":"Значение вручную"}],"error":null}
```

### `GET /api/dictionaries/casts`

```bash
curl -s http://localhost:9501/api/dictionaries/casts -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"string","label":"Строка","orderable":false,"options":[{"name":"case_sensitive","type":"bool","default":true},{"name":"trim","type":"bool","default":false}]},
 {"value":"int","label":"Целое число","orderable":true,"options":[]},
 {"value":"decimal","label":"Десятичное число","orderable":true,"options":[{"name":"scale","type":"int","default":10,"min":0,"max":20}]},
 {"value":"date","label":"Дата","orderable":true,"options":[{"name":"format","type":"string|list","default":null},{"name":"timezone","type":"string","default":"UTC"}]},
 {"value":"datetime","label":"Дата и время","orderable":true,"options":[{"name":"format","type":"string|list","default":null},{"name":"timezone","type":"string","default":"UTC"}]},
 {"value":"bool","label":"Логическое","orderable":false,"options":[]},
 {"value":"list","label":"Список","orderable":false,"options":[{"name":"separator","type":"string","default":","},{"name":"wrapper","type":"string","default":null},{"name":"case_sensitive","type":"bool","default":true}]}],"error":null}
```

Types for the `cast` field of a condition row. `orderable` — the type supports "greater / less than"; `options` — the
allowed `cast_options` with their defaults. `case_sensitive: false` — Unicode case folding of both sides, `trim: true` —
trimming the edges including the no-break space and BOM (see [Case and spaces](../03-user-guide/triggers-and-conditions.md#case-and-spaces)).

### `GET /api/dictionaries/delivery-policies`

Any role. Values of the `delivery` field of actions with external effects.

```json
{"success":true,"data":[{"value":"at_most_once","label":"Не более одного раза"},{"value":"at_least_once","label":"Не менее одного раза"}]}
```

The `delivery` field is allowed only on actions with external effects (`send_email`, `http_request`, `notify`); on other
actions saving the process fails: `Action "modify" does not support "delivery"`. Defaults: `send_email` — `at_most_once`,
`http_request` — `at_most_once` for `POST`/`PATCH` and `at_least_once` for the other methods. A wrong value gives
`Delivery must be "at_most_once" or "at_least_once"`.

An `http_request` step (details — [Actions → HTTP request](../03-user-guide/actions.md#http-request)): `request_data` —
`method`, `url`, `headers` (names only), `body_size`, `body_preview`, `delivery`; `result_data` — `status`, `duration_ms`,
`var`, `replayed`, `response`. The `var` variable in `context.variables`: `status`, `ok`, `headers`, `body` / `body_text` /
`body_truncated` and the `response.extract` fields.

`link_records` / `unlink_records` steps (details — [Actions → Link / unlink records](../03-user-guide/actions.md#link--unlink-records)):
`request_data` — `record`, `link`, `module`, `record_id`, `related_module`, `related_ids` (the first 100), `related_count`,
for `link_records` also `fields`, `batches`; `result_data` — `linked`, `updated`, `not_found`, `warnings` (for
`unlink_records` — `removed`, `not_linked`), `missing_paths`. A record without `id` or no related ids → the step is
`skipped`. CRM contract 2.0 (`relationships`) is required, otherwise `CRM_CAPABILITY_MISSING`.

`find_records` step (details — [Actions → Find records](../03-user-guide/actions.md#find-records)): `request_data` — `var`,
`limit`, `module` / `related_to`, `filter`, `sort`, `fields`, in module mode — `crm_filter` (what was sent to the CRM) and
`crm_requests`; `result_data` — `count`, `has_more`, the first 100 `ids`, `reason`, `warnings`. The list variable
`{items, count, has_more, ids}` is in `context.variables`, `context.meta.<name>.source` is `list`.

`set_variable` step (details — [Actions → Set a process variable](../03-user-guide/actions.md#set-a-process-variable)):
`request_data` — `var`, `fields` (`name → {change_type, value}`); `result_data.fields.<name>` — `old_value`, `new_value`.
The variable is in `context.variables`, `context.meta.<name>.source` is `local`.

### `GET /api/dictionaries/mail-transports`

admin, editor. Whether the default transport (`MAILER_DSN`) is configured and the names of `smtp_dsn` secrets (no
values) — for the `transport` field of `send_email`.

```json
{"success":true,"data":{"default_configured":true,"secrets":["smtp_main","smtp_support"]},"error":null}
```

### `GET /api/dictionaries/secret-kinds`

```bash
curl -s http://localhost:9501/api/dictionaries/secret-kinds -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"generic","label":"Произвольный","composite":false,"fields":[]},
 {"value":"smtp_dsn","label":"SMTP (DSN)","composite":false,"fields":[]},
 {"value":"basic_auth","label":"Basic-авторизация","composite":true,"fields":[{"name":"username","required":true,"secret":false},{"name":"password","required":true,"secret":true}]},
 {"value":"bearer","label":"Bearer-токен","composite":false,"fields":[]},
 {"value":"api_key","label":"API-ключ","composite":false,"fields":[]},
 {"value":"oauth2_client","label":"OAuth2 client credentials","composite":true,"fields":[{"name":"token_url","required":true,"secret":false},{"name":"client_id","required":true,"secret":false},{"name":"client_secret","required":true,"secret":true},{"name":"scope","required":false,"secret":false}]},
 {"value":"webhook_url","label":"URL вебхука","composite":false,"fields":[]},
 {"value":"telegram_bot","label":"Токен Telegram-бота","composite":false,"fields":[]}],"error":null}
```

Secret kinds for the `kind` field; `composite` — the value is an object, `fields` — its fields (`secret: false` — the field is shown in the mask as is).

## Service

### `GET /api/health`

Public. `200` when PostgreSQL and both Redis instances answer, otherwise `503` with `"status": "degraded"`.

```bash
curl -s http://localhost:9501/api/health
```

```json
{"success":true,"data":{"status":"ok","checks":{"postgres":{"status":"ok","latency_ms":0},"redis_data":{"status":"ok","latency_ms":0},"redis_stream":{"status":"ok","latency_ms":0}},"stream":{"stream":"crm_events","group":"wps_workers","length":15,"lag":0,"pending":0,"consumers":2},"time":"2026-10-15T08:03:03+00:00"},"error":null}
```

Field meanings: [Monitoring](../04-operations/monitoring.md#health-check-get-apihealth).

### `GET /metrics`

Public. Prometheus text format, not wrapped in the envelope. Only on the backend port (the UI's nginx does not proxy it).

```bash
curl -s http://localhost:9501/metrics
```

```text
# HELP wps_events_received_total Всего получено событий
# TYPE wps_events_received_total counter
wps_events_received_total 15
…
# TYPE wps_redis_stream_lag gauge
wps_redis_stream_lag 0
```

The list of metrics is in [Monitoring](../04-operations/monitoring.md#metrics-get-metrics).

<!-- footer-nav -->
---

← Previous: [API reference: basics](index.md) | [Contents](../README.md) | [Development environment](../07-developer-guide/dev-environment.md) Next →
<!-- /footer-nav -->
