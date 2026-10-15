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
| `GET` | [`/api/executions/{id}`](#get-apiexecutionsid) | any | Execution with context, steps and children |
| `GET` | [`/api/executions/{id}/actions`](#get-apiexecutionsidactions) | any | Steps of an execution |
| `GET` | [`/api/dictionaries/modules`](#get-apidictionariesmodules) | any | CRM modules (cached) |
| `POST` | [`/api/dictionaries/modules/refresh`](#post-apidictionariesmodulesrefresh) | editor+ | Reload CRM modules |
| `GET` | [`/api/dictionaries/operators`](#get-apidictionariesoperators) | any | Comparison operators |
| `GET` | [`/api/dictionaries/action-types`](#get-apidictionariesaction-types) | any | Action types |
| `GET` | [`/api/dictionaries/change-types`](#get-apidictionarieschange-types) | any | Change types |
| `GET` | [`/api/dictionaries/preload-sources`](#get-apidictionariespreload-sources) | any | Preload sources |
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
| `version` | Incremented by every `PUT`; executions store the version they ran |
| `definition` | `conditions`, `preload`, `actions` — see [Triggers and conditions](../03-user-guide/triggers-and-conditions.md), [Actions](../03-user-guide/actions.md), [Change types and expressions](../03-user-guide/change-types-and-expressions.md) |

### `Execution` and `ExecutionAction`

```json
{
  "id": "7a0d6f22-aa92-4fef-ac54-5674b6b138eb", "process_id": 9, "process_name": "API doc test", "process_version": 2,
  "parent_execution_id": null, "trigger": "manual", "event_id": "manual-ce71f9d2-…",
  "object_type": "Tasks", "object_id": "T-1", "status": "completed", "retry_count": 0, "error_message": null,
  "started_at": "2026-10-15T08:02:39+00:00", "finished_at": "2026-10-15T08:02:39+00:00", "duration_ms": 3
}
```

- `trigger`: `stream` (CRM event) | `manual` | `child`.
- `status`: `running` | `retrying` | `completed` | `failed`.

```json
{
  "id": 27, "step_index": 0, "action_type": "modify", "status": "success", "attempts": 1,
  "started_at": "2026-10-15T08:02:39+00:00", "duration_ms": 0,
  "request_data": { "value": "closed by WPS", "target": "object.description", "change_type": "set_value" },
  "result_data": { "new_value": "closed by WPS", "old_value": null },
  "error_message": null
}
```

- `action_type`: `preload` | `modify` | `save_object` | `start_process` (or a custom type).
- `status`: `success` | `failed` | `skipped`. `attempts` = 1 + retries.

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

`result` is `null` for a row that was not evaluated.

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

Editor or admin. Body: `{ name, object_type, is_child, is_active?, priority?, run_once?, definition }`
(`is_active` defaults to `true`, `priority` to `0`, `run_once` to `false`). The definition is validated in execution order;
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

`201 Process`. Errors:

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

Editor or admin. Same body as `POST` (the whole process). `version` is incremented. The process cache of the old and the new
object type is invalidated, so the next event uses the new definition; running executions keep their version.

```bash
curl -s -X PUT http://localhost:9501/api/processes/9 -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "name": "API doc test", "object_type": "Tasks", "is_child": false, "is_active": true, "priority": 5, "run_once": true,
  "definition": {"conditions":[{"field":"object.status","operator":"equals","value_type":"static","value":"Done"}],
                 "preload":[],
                 "actions":[{"type":"modify","target":"object.description","change_type":"set_value","value":"closed by WPS","save_to_crm":false}]}}'
```

`200 Process` (`"version": 2`). Errors: `404 PROCESS_NOT_FOUND`, `422 VALIDATION_ERROR` (also when a child process that is
started by others is turned into a top-level one), `422 CYCLE_DETECTED`:

```json
{"success":false,"data":null,"error":{"code":"CYCLE_DETECTED","message":"Process launch cycle detected: Doc cycle B → Doc cycle A → Doc cycle B",
 "details":{"path":[{"id":12,"name":"Doc cycle B"},{"id":13,"name":"Doc cycle A"},{"id":12,"name":"Doc cycle B"}]}}}
```

### `DELETE /api/processes/{id}`

Admin only. Soft delete (`deleted_at`): the process disappears from lists and stops running; its executions stay in the audit.

```bash
curl -s -X DELETE http://localhost:9501/api/processes/10 -H "Authorization: Bearer $TOKEN"
```

`200 {"deleted": true}`. Errors: `404 PROCESS_NOT_FOUND`, `409 PROCESS_IN_USE`:

```json
{"success":false,"data":null,"error":{"code":"PROCESS_IN_USE","message":"The process is started by other processes",
 "details":[{"id":6,"name":"New lead → schedule a call (once per lead)"}]}}
```

### `POST /api/processes/{id}/clone`

Editor or admin. Creates an **inactive** copy named `<name> (копия)` (the suffix is always Russian). `run_once` is copied,
run-once marks are not. No body.

```bash
curl -s -X POST http://localhost:9501/api/processes/9/clone -H "Authorization: Bearer $TOKEN"
```

`201 Process` (`"name":"API doc test (копия)","is_active":false,"version":1`).

### `POST /api/processes/{id}/run`

Editor or admin. Manual run with a test object. Body: `{ "object": {…}, "force": false }`; if there is no `object` key, the whole
body is the object. `object.id` is required. `object.module` (or `object_type`) defaults to the process object type.
`force: true` skips the conditions but not run once. Conditions are checked synchronously; the steps run in the background.

```bash
curl -s -X POST http://localhost:9501/api/processes/9/run -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"object":{"id":"T-1","module":"Tasks","status":"Done"}}'
```

| Result | HTTP | `data` |
|---|---|---|
| Conditions met, execution created | `201` | `{"conditions_met":true,"execution_id":"7a0d6f22-…","conditions":[ConditionTrace],"run_once_applied":true,"run_once_blocked":false,"previous_execution_id":null}` |
| Conditions not met | `200` | `{"conditions_met":false,"execution_id":null,"conditions":[{"field":"object.status",…,"actual":"Open","expected":"Done","result":false}],…}` |
| Blocked by run once | `200` | `{"conditions_met":true,"execution_id":null,"conditions":[],"run_once_applied":true,"run_once_blocked":true,"previous_execution_id":"7a0d6f22-…"}` |

`run_once_applied` is `true` when the process has `run_once` and the object has an `id`. Follow the execution with
`GET /api/executions/{execution_id}`. Errors: `404 PROCESS_NOT_FOUND`, `422 VALIDATION_ERROR`
(`object`: "A JSON object is required"; `object.id`: "The object must have an \"id\"").

### `GET /api/processes/{id}/executions`

Any role. Query: `status` (`running` | `retrying` | `completed` | `failed`), `date_from`, `date_to` (compared with `started_at`; ISO 8601
date-time, a time without zone is UTC; a bare date `YYYY-MM-DD` in `date_to` means "up to the end of that day"), `page`, `per_page`. Sorted by `started_at DESC`.

```bash
curl -s 'http://localhost:9501/api/processes/9/executions?status=completed' -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"id":"7a0d6f22-…","process_id":9,"process_name":"API doc test","process_version":2,"parent_execution_id":null,"trigger":"manual","event_id":"manual-ce71f9d2-…","object_type":"Tasks","object_id":"T-1","status":"completed","retry_count":0,"error_message":null,"started_at":"…","finished_at":"…","duration_ms":3}],"error":null,"meta":{"page":1,"per_page":20,"total":1}}
```

Errors: `404 PROCESS_NOT_FOUND`; `422` ("Status must be one of: running, retrying, completed, failed", "Invalid date").

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

### `GET /api/executions/{id}`

Any role. `Execution` plus:

| Field | Description |
|---|---|
| `context` | `{event, variables, meta, conditions}` — the final snapshot; while running, the live state from Redis |
| `current_step` | Only while running: index of the next step |
| `actions` | `ExecutionAction[]`, by `step_index` |
| `children` | `Execution[]` started by `start_process` of this execution |

```bash
curl -s http://localhost:9501/api/executions/7a0d6f22-aa92-4fef-ac54-5674b6b138eb -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"id":"7a0d6f22-…","process_id":9,…,"status":"completed",
 "context":{"meta":{"object":{"module":"Tasks","source":"event"}},
            "event":{"id":"T-1","module":"Tasks","status":"Done"},
            "variables":{"object":{"id":"T-1","module":"Tasks","status":"Done","description":"closed by WPS"}},
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

Editor or admin. Drops the cache and loads the list again; returns it.

```bash
curl -s -X POST http://localhost:9501/api/dictionaries/modules/refresh -H "Authorization: Bearer $TOKEN"
```

### `GET /api/dictionaries/operators`

```bash
curl -s http://localhost:9501/api/dictionaries/operators -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"equals","label":"Равен"},{"value":"not_equals","label":"Не равен"},{"value":"contains","label":"Включает"},{"value":"not_contains","label":"Не включает"}],"error":null}
```

### `GET /api/dictionaries/action-types`

```bash
curl -s http://localhost:9501/api/dictionaries/action-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"preload","label":"Предзагрузка объекта","description":"Загружает объект из CRM по ID или другому полю в переменную процесса"},
 {"value":"modify","label":"Изменить объект/переменную","description":"…"},
 {"value":"save_object","label":"Сохранить объект в CRM","description":"…"},
 {"value":"start_process","label":"Запустить следующий процесс","description":"…"}],"error":null}
```

### `GET /api/dictionaries/change-types`

```bash
curl -s http://localhost:9501/api/dictionaries/change-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"set_value","label":"Равен значению"},{"value":"set_from_variable","label":"Равен переменной из поля"},{"value":"calculate","label":"Расчет"}],"error":null}
```

### `GET /api/dictionaries/preload-sources`

```bash
curl -s http://localhost:9501/api/dictionaries/preload-sources -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"object_field","label":"Из поля объекта"},{"value":"variable_field","label":"Из поля переменной"},{"value":"manual","label":"Значение вручную"}],"error":null}
```

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
