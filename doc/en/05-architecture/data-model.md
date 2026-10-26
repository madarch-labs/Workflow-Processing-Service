# Data model

PostgreSQL tables, the structure of a process definition and the Redis keys used by WPS.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/05-architecture/data-model.md)
<!-- /lang-switch -->

The schema is created by the migrations in `back/migrations/` (`001_create_tables.sql` … `009_execution_waits.sql`), which `back/docker/entrypoint.sh` applies with `migrations/migrate.php` on every start of the
backend. All `TIMESTAMP` columns are stored in UTC.

## ER diagram

```mermaid
erDiagram
    users {
        BIGSERIAL id PK
        TEXT email UK
        TEXT password_hash
        TEXT role "admin, editor, viewer"
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }
    processes {
        BIGSERIAL id PK
        TEXT name
        TEXT object_type "CRM module"
        BOOLEAN is_child
        BOOLEAN is_active
        INT priority "lower runs first"
        BOOLEAN run_once
        TEXT[] trigger_on "create, update, delete"
        INT version
        JSONB definition "conditions, preload, actions"
        TIMESTAMP created_at
        TIMESTAMP updated_at
        TIMESTAMP deleted_at "soft delete"
    }
    executions {
        UUID id PK
        BIGINT process_id FK
        INT process_version
        UUID parent_execution_id FK
        TEXT trigger "stream, manual, child"
        TEXT event_id
        TEXT object_type
        TEXT object_id
        TEXT status "running, retrying, waiting, completed, failed, cancelled"
        INT retry_count
        INT handled_errors
        TEXT error_message
        TIMESTAMP started_at
        TIMESTAMP finished_at
        TIMESTAMPTZ waiting_until
        TEXT wait_reason
        JSONB context_snapshot
    }
    execution_waits {
        UUID execution_id PK
        BIGINT process_id FK
        TIMESTAMPTZ due_at
        TEXT reason
        JSONB info
        JSONB state
        INT attempts
        TIMESTAMPTZ locked_until
    }
    execution_actions {
        BIGSERIAL id PK
        UUID execution_id FK
        INT step_index
        TEXT action_type
        TEXT status "success, failed, skipped"
        INT attempts
        TIMESTAMP started_at
        INT duration_ms
        JSONB request_data
        JSONB result_data
        TEXT error_message
        INT pc
        TEXT step_path
        TEXT iteration
        TEXT step_id
        TEXT error_code
        TEXT handled "continue, goto, item_continue"
    }
    process_object_runs {
        BIGINT process_id PK, FK
        TEXT object_type PK
        TEXT object_id PK
        UUID execution_id
        TEXT event_id
        TEXT trigger
        TIMESTAMP created_at
        INT blocked_count
        TIMESTAMP last_blocked_at
    }
    step_side_effects {
        UUID execution_id PK, FK
        TEXT effect_key PK
        TEXT action_type
        TEXT delivery "at_most_once, at_least_once"
        TEXT status "started, done, failed"
        UUID attempt_token
        TEXT request_hash
        JSONB response
        TEXT error_message
        INT attempts
        TIMESTAMPTZ started_at
        TIMESTAMPTZ finished_at
    }

    processes ||--o{ executions : "is run as"
    executions ||--o{ execution_actions : "has steps"
    executions |o--o{ executions : "parent of"
    processes ||--o{ process_object_runs : "run-once marks"
    executions ||--o{ step_side_effects : "external call journal"
    executions ||--o| execution_waits : "wait"
```

`users` is independent of the other tables: the audit does not store who changed a process. A process has many
executions; an execution has one row per step and may have a parent execution (child processes). `process_object_runs`
holds at most one mark per process and CRM record. Its `execution_id` has no foreign key on purpose, because the mark is
written before the `executions` row exists.

## Tables

### `users`

| Column | Description |
|---|---|
| `id` | Primary key |
| `email` | Login, unique |
| `password_hash` | `password_hash()` (bcrypt); never returned by the API |
| `role` | `admin`, `editor` or `viewer` (CHECK constraint) |
| `created_at`, `updated_at` | Timestamps |

The migration `002_seed_admin.sql` creates `admin@wps.local` with the password `admin`.

### `processes`

| Column | Description |
|---|---|
| `id` | Primary key, used in `start_process.process_id` |
| `name` | Display name, up to 255 characters |
| `object_type` | CRM module the process belongs to (`Accounts`, `Leads`, …). For a top-level process it selects the events |
| `is_child` | `true` — the process is started only by `start_process` of another process and never directly by an event |
| `is_active` | Inactive processes are ignored by the resolver and skipped by `start_process` |
| `priority` | Order among top-level processes of the same object type, ascending |
| `run_once` | Run at most once per CRM record (migration `003`) |
| `trigger_on` | `TEXT[] NOT NULL DEFAULT ARRAY['create','update']`, `CHECK (trigger_on <@ ARRAY['create','update','delete'])` — CRM event types that start the process (migration `007_trigger_on.sql`). It is filtered in PHP among the processes of the module, no index. See [Event type](../03-user-guide/triggers-and-conditions.md#event-type-and-the-run-on-field-trigger_on) |
| `version` | Incremented on every update; an execution stores the version it ran with |
| `definition` | JSONB, see below |
| `deleted_at` | Soft delete: the row stays so that old executions still point to it |

Indexes: `object_type`, partial indexes on `is_child = false` and `is_active = true`, and the resolver index
`(object_type, priority) WHERE deleted_at IS NULL AND is_active AND NOT is_child`.

### `executions`

| Column | Description |
|---|---|
| `id` | UUID generated by the engine |
| `process_id`, `process_version` | Process and the version of its definition |
| `parent_execution_id` | Execution that started this one by `start_process`; `ON DELETE SET NULL` |
| `trigger` | `stream` (CRM event), `manual` (manual run), `child` (started by another process) |
| `event_id` | `event_id` of the object, the stream message id, or `manual-{uuid}` |
| `object_type`, `object_id` | Module and `id` of the event object |
| `status` | `running`, `retrying`, `waiting`, `completed`, `failed`, `cancelled` |
| `retry_count` | Number of retries over all steps |
| `handled_errors` | Number of step errors handled by `on_error` (`continue` / `goto`) or by a loop with `on_item_error: continue`; the execution may still be `completed` |
| `error_message` | Error of the failed step |
| `started_at`, `finished_at` | `finished_at` is `NULL` while the execution is not finished |
| `waiting_until`, `wait_reason` | `TIMESTAMPTZ` and `TEXT`: due time and reason (`delay`, `retry`) of the wait; set while the execution is `waiting` |
| `context_snapshot` | Final `{event, variables, meta, conditions}`; `{lost: true}` for an execution that could not be recovered |

Indexes: `process_id`, `event_id`, `status`, `started_at DESC`, `parent_execution_id`, partial
`idx_executions_waiting (waiting_until) WHERE status = 'waiting'`.

### `execution_actions`

| Column | Description |
|---|---|
| `execution_id`, `step_index` | Unique pair; `step_index` is the sequence number of the audit record (`seq`). For a linear process it equals the step number: 0 is the first preload row or the first action |
| `action_type` | `preload`, `modify`, `save_object`, `start_process` or a custom type |
| `status` | `success`, `waiting`, `failed`, `skipped` |
| `attempts` | 1 + number of retries; 0 for a step skipped because a previous step failed |
| `started_at`, `duration_ms` | Timing, including the retry delays |
| `request_data` | What the step did or sent (for a failed step — the step configuration) |
| `result_data` | What it got: old/new value, found object, CRM response, `error_class`, `recoverable` |
| `error_message` | Error text |
| `pc` | Instruction number in the execution program ([Execution engine](execution-engine.md#program-and-instructions)) |
| `step_path` | Place of the step in the definition tree: `preload[0]`, `actions[2]`, `actions[1].branches[0].actions[0]`, `actions[1].else[0]` |
| `iteration` | Iteration number of a `foreach` loop from zero, in nested loops the indexes joined with `/` (`"2/0"`), empty outside loops |
| `step_id` | User `id` of the step, if set |
| `error_code` | Code of the classified error of a failed step (`HTTP_503`, `TIMEOUT`, `CONFIG`, `UNKNOWN_OUTCOME` …) |
| `handled` | `NULL` (the error stopped the execution), `continue` or `goto` — how `on_error` handled the error; `item_continue` — a loop with `on_item_error: continue` went on to the next item |

Migration `008_step_error_handling.sql` added `executions.handled_errors`, `execution_actions.error_code` and
`execution_actions.handled` ([Actions → Step errors](../03-user-guide/actions.md#step-errors-on_error-and-retries)).

Migration `004_program_audit.sql` added `pc`, `step_path`, `iteration`, `step_id` and filled `pc = step_index` for the
existing rows (they come from linear processes); index `(execution_id, pc)`.

The rows are deleted together with the execution (`ON DELETE CASCADE`).

### `process_object_runs`

| Column | Description |
|---|---|
| `process_id`, `object_type`, `object_id` | Primary key: one mark per process and CRM record |
| `execution_id` | The first (blocking) execution |
| `event_id`, `trigger` | Where the first run came from |
| `created_at` | When the mark was taken |
| `blocked_count`, `last_blocked_at` | How many later starts were blocked and when the last one was |

Indexes: `(object_type, object_id)`, `(process_id, created_at DESC)`. A mark is removed with
`DELETE /api/processes/{id}/runs/{object_type}/{object_id}`.

### `secrets`

Secrets of external actions (see [Security → Process secrets](../04-operations/security.md#process-secrets)).

| Column | Description |
|---|---|
| `id` | Primary key |
| `name` | Unique name for `secret://name` references |
| `kind` | Kind: `generic`, `smtp_dsn`, `basic_auth`, `bearer`, `api_key`, `oauth2_client`, `webhook_url`, `telegram_bot` |
| `description` | Description |
| `ciphertext`, `nonce` | Encrypted value (JSON of a string or an object) and the nonce, base64; XChaCha20-Poly1305, associated data `wps-secret\|name\|version` |
| `kek_id` | Which key encrypted the row (first 8 hex of `sha256(key)`) — for rotation |
| `version` | Grows with every value change (part of the associated data) |
| `mask` | Ready mask for the list (a string or a JSON object for composite kinds) |
| `allowed_hosts` | `JSONB`, hosts the secret may be sent to |
| `created_by`, `updated_by` | Users (`ON DELETE SET NULL`) |
| `created_at`, `updated_at`, `last_used_at` | `TIMESTAMPTZ`; `last_used_at` is updated at most once per 5 minutes per worker |

### `step_side_effects`

Journal of external calls (e-mails, webhooks, notifications), so that a step repeated after a worker crash does not send
them a second time (see [Reliability → Side effects and repeats](../04-operations/reliability.md#side-effects-and-repeats)).
Migration `006_side_effects.sql`.

| Column | Description |
|---|---|
| `execution_id`, `effect_key` | Primary key. `effect_key` is the position of the call in the program: `{pc}`, `{pc}@{iteration}` in loops, `#{sub}` for several calls in one step |
| `action_type` | Type of the action that made the call |
| `delivery` | `at_most_once` or `at_least_once` |
| `status` | `started` (before the call or outcome unknown), `done` (response stored), `failed` (final error) |
| `attempt_token` | Step attempt that wrote the row: tells a retry inside the step from the trace of a crashed worker |
| `request_hash` | `sha256` of the sent request (the request itself is not stored) |
| `response` | Response of the external system, secrets masked |
| `error_message` | Text of the final error |
| `attempts` | How many times the call was started |
| `started_at`, `finished_at` | `TIMESTAMPTZ`; index on `started_at` for the purge |

Rows are deleted together with their execution (`ON DELETE CASCADE`) and hourly when older than
`SIDE_EFFECTS_RETENTION_DAYS`.

### `execution_waits`

Waiting executions ([Execution engine → Waiting](execution-engine.md#waiting-and-waking-up-waitresumejob)): while an
execution waits, its state lives here instead of Redis. Migration `009_execution_waits.sql`.

| Column | Description |
|---|---|
| `execution_id` | Primary key, `REFERENCES executions(id) ON DELETE CASCADE` — one row per execution |
| `process_id` | `BIGINT NOT NULL REFERENCES processes(id)` — checked when the process is deleted |
| `due_at` | `TIMESTAMPTZ` — when to wake up |
| `reason` | `delay` (pause) or `retry` (long retry delay) |
| `info` | `JSONB`: `pc`, `seq`, `mode` (`after_wait`, `retry`), `parked_at`, `attempts` and action data for `resume()` |
| `state` | `JSONB` — the full `exec:{uuid}` snapshot (at most `WAIT_STATE_MAX_BYTES`); when waiting inside a `foreach` loop also `loop_items`: the contents of the item snapshots `exec:{uuid}:loop:{pc}` |
| `attempts` | How many wake-ups were tried (`WAIT_MAX_ATTEMPTS`) |
| `locked_until`, `locked_by` | Lease of the worker that took the row (`WAIT_LEASE_SECONDS`) |
| `last_error` | Error of the last failed attempt |
| `created_at` | When the execution was parked |

Indexes: `idx_execution_waits_due (due_at)`, `idx_execution_waits_process (process_id)`. The row is deleted on wake-up,
on cancellation (`DELETE /api/processes/{id}?force=true`) and together with the execution.

## Process definition (`processes.definition`)

```mermaid
flowchart LR
    def["definition"] --> c["conditions[]<br/>field, operator, value_type, value"]
    def --> p["preload[]<br/>var, source, module, by_field, value, strategy?"]
    def --> a["actions[]"]
    a --> m["modify<br/>target, change_type, value or expression, save_to_crm, module?"]
    a --> s["save_object<br/>target, module?, mode?"]
    a --> sp["start_process<br/>process_id or process_ids"]
    a --> pa["preload<br/>same fields as preload[]"]
```

```json
{
  "conditions": [
    { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }
  ],
  "preload": [
    { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" }
  ],
  "actions": [
    { "type": "modify", "target": "v1.rating", "change_type": "set_value", "value": "VIP", "save_to_crm": true },
    { "type": "save_object", "target": "object" },
    { "type": "start_process", "process_id": 4 }
  ]
}
```

- `conditions` is a flat list joined by AND. The engine also accepts a tree
  `{ "logical_operator": "AND" | "OR", "conditions": [...], "groups": [...] }`, prepared for OR and brackets (customer
  answer #8); the current UI does not edit it.
- `preload` rows are executed before the actions; `preload` may also appear inside `actions`.
- `Process::normalizeDefinition()` always produces the three keys, so a missing block is an empty list.
- The `definition` column is `jsonb`: PostgreSQL **does not keep the order of object keys** (shorter keys come first),
  the order of array items is kept. Where the order matters the format uses lists: `actions`, `branches`, `fields` of
  `set_variable` (an object `fields` is allowed only for independent fields — otherwise a warning). The keys of an
  `http_request` JSON body may go out in a different order — irrelevant for JSON.

The field-by-field meaning is described in the user guide: [Conditions](../03-user-guide/triggers-and-conditions.md),
[Actions](../03-user-guide/actions.md), [Change types and expressions](../03-user-guide/change-types-and-expressions.md).

## Redis keys

**redis-data** (cache and state):

| Key | Type | Content | TTL |
|---|---|---|---|
| `proc_cache:{object_type}` | String (JSON) | Active top-level processes of the type, sorted by priority | `PROCESS_CACHE_TTL` (60 s); deleted when a process of the type is saved |
| `dict:modules` | String (JSON) | Modules dictionary from the CRM | `DICT_CACHE_TTL` (300 s); `POST /api/dictionaries/modules/refresh` reloads it |
| `exec:{uuid}` | Hash | Live execution context | 24 h while running, 1 h after finish |
| `lock:exec:{uuid}` | String | Owner token of the worker that runs the execution | `EXECUTION_LOCK_TTL` (30 s) |
| `idem:{event_id}:{process_id}` | String | Execution id or `skipped` | `IDEMPOTENCY_TTL_HOURS` (24 h) |
| `idem:{parent_execution_id}.{step}:{child_id}` | String | Same for a child process | 24 h |

**redis-stream** (event bus):

| Key | Type | Content |
|---|---|---|
| `crm_events` (`REDIS_STREAM_NAME`) | Stream | CRM events; consumer group `wps_workers` (`REDIS_STREAM_CONSUMER_GROUP`) |
| `crm_events:dlq` | Stream | Invalid and poison messages: `original_id`, `reason`, `fields`, `at`; trimmed to about 10 000 entries |
| `mock_crm:{module}` | Hash | Objects of the mock CRM (only when the `mock` container is used) |

<!-- footer-nav -->
---

← Previous: [Execution engine](execution-engine.md) | [Contents](../README.md) | [Frontend architecture](frontend.md) Next →
<!-- /footer-nav -->
