# Architecture decisions

The key design decisions of WPS in a short ADR format: context, decision, consequences. The reasons are taken from code comments, `md/doc/maiInfo.md` (customer answers, numbered) and the plans in `md/`.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/05-architecture/decisions.md)
<!-- /lang-switch -->

## ADR-1. PHP + Swoole + SwooleApp

**Context.** The task requires a PHP service built on Swoole and the SwooleApp framework (`md/doc/maiInfo.md`,
introduction). The service has to read an event stream continuously, call a slow external API and serve a REST API at
the same time; the expected load is up to a hundred events per second per object type and hundreds of active processes
per type (answer #21).

**Decision.** A long-running Swoole HTTP server built with SwooleApp (`sidalex/swoole-app` from Packagist):
controllers by attribute, global middlewares, cyclic jobs and a state container from `config/config.json`.
`SWOOLE_HOOK_ALL` makes PDO, phpredis and sockets coroutine-friendly; the CRM is called with the coroutine HTTP client.

**Consequences.** One worker handles many events and requests concurrently, and waiting for the CRM or for a retry delay
costs no thread. Services and connections are kept in memory, so every worker needs its own instances after the fork
(see ADR-10). Code changes require a restart, which is also how new action types are deployed (answers #25, #26).

## ADR-2. Redis Stream with a consumer group as the event bus

**Context.** The CRM publishes every save of an object (answer #2). Processing must survive a crash and handle each event
at least once (answer #22); events that match no process are simply consumed (answer #23).

**Decision.** Stream `crm_events`, consumer group `wps_workers`, one consumer per HTTP worker. A message is acknowledged
only after all its processes have been handled. Unacknowledged messages are taken over with `XAUTOCLAIM` by
`RecoveryJob`; invalid messages and messages delivered more than five times go to `crm_events:dlq`.

**Consequences.** Horizontal scaling by adding workers or backend instances to the same group. Delivery is
at-least-once, so the processing must be idempotent (ADR-7). A poison message cannot block the stream forever.

## ADR-3. PostgreSQL for consistent data, a separate Redis for cache and state

**Context.** The customer proposed PostgreSQL as the consistent storage and Redis for cache and temporary data,
**a separate instance, not the one used as the bus** (answer #4).

**Decision.** PostgreSQL stores users, processes (definition as JSONB), executions, step audit and run-once marks.
`redis-data` stores the process and modules caches, the execution context, locks and idempotency keys. `redis-stream`
is used only for the bus (and by the mock as its storage).

**Consequences.** Cache traffic and stream traffic do not compete, and each Redis can be sized and persisted on its own.
Losing `redis-data` only drops caches and the state of running executions (they are then marked as lost); the audit in
PostgreSQL stays.

## ADR-4. One controller class per endpoint

**Context.** SwooleApp routes requests by a `#[Route]` attribute on a controller class.

**Decision.** Every endpoint is its own class in `Wps\Controllers\…` extending `BaseController`, which adds the response
envelope, error rendering, request helpers and the global middlewares.

**Consequences.** Endpoints are small and easy to find by URI. Shared logic lives in services, not in controllers.
Adding an endpoint is a new class; the controllers namespace from `config.json` is scanned automatically
(see [New API endpoint](../07-developer-guide/extending/new-api-endpoint.md)).

## ADR-5. Registries as extension points

**Context.** New typical actions (sending a message, calling a custom API, loading objects in a new way) are written by
developers, while the business logic is configured in the UI (answers #1, #12). Conditions must be ready for new
operators.

**Decision.** Four interfaces with registries — `ActionHandlerInterface`, `ComparatorInterface`,
`ChangeTypeInterface`, `LoadStrategyInterface` — plus `ExpressionEngine::registerFunction()`. The process definition stores
only codes (`type`, `operator`, `change_type`, `strategy`). Each handler also validates its own configuration when a
process is saved. The dictionary endpoints are built from the registries.

**Consequences.** A new capability is one class plus one registration line in `Container`, and it appears in the UI
dictionaries without changes to the engine. Adding one requires a restart (answers #25, #26). Labels returned by the
registries are Russian; the UI translates them by code.

## ADR-6. Lazy preload in conditions

**Context.** Conditions may compare fields of a related object loaded from the CRM (answer #12). Loading it for every
event would call the CRM even when a cheap check on the event object already fails.

**Decision.** Three-valued condition evaluation (`ConditionEngineService`): rows on `object.*` are checked first; a row
that refers to a variable that is not loaded yet gives "unknown". Only then is the `preload` block executed and the
conditions are evaluated again. The flat list of rows is joined by AND; the engine already accepts a tree with
`logical_operator` `AND`/`OR`, so OR and brackets can be added in the UI later without changing the engine (answer #8).

**Consequences.** Most events that do not match never reach the CRM. The preload steps executed for the conditions
become the first steps of the execution and are not repeated.

## ADR-7. Idempotency per event and run once per record

**Context.** At-least-once delivery means that the same message may be processed again. Separately, a CRM record is
saved many times, and a process such as "create a call for a new lead" must not run on every save; a condition on a
marker field in the CRM turned out to be unreliable (`md/idempotentie_plan.md`, §1).

**Decision.** Two independent mechanisms:
- `idem:{event_id}:{process_id}` in Redis (`SET NX`, 24 h): a re-delivered event resumes or skips the existing execution
  instead of starting a new one; child processes use a key built from the parent execution and step;
- the process flag `run_once` with the table `process_object_runs`: the mark "process × record" is taken atomically when
  the conditions are met, before the execution starts, and also blocks after `failed`, because actions are not
  transactional (`md/idempotentie_plan.md`, FR-3). A mark is removed explicitly through the API or the UI.

**Consequences.** Double delivery does not produce duplicate side effects. Run once works for every trigger (`stream`,
`child`, `manual`); `force` in a manual run skips only the conditions. Without an `id` in the object run once is not
applied, which is logged and counted (`wps_run_once_no_id_total`).

## ADR-8. Soft delete of processes

**Context.** Executions reference processes by a foreign key, and the audit must remain readable after a process is
removed.

**Decision.** `DELETE /api/processes/{id}` sets `deleted_at` and `is_active = false` (`ProcessRepository::softDelete`); the
migration comment states "soft delete keeps the execution audit consistent". A process referenced by `start_process` of
another process cannot be deleted (`409 PROCESS_IN_USE`).

**Consequences.** Old executions still show the process name and version. Deleted processes disappear from the lists and
from the resolver but stay in the database.

## ADR-9. Execution state in Redis for resumable executions

**Context.** The result of each step should be kept in Redis until the process is finished and then written with the whole
result into PostgreSQL (answer #14); a process interrupted by a crash should be retried and brought to the end
(answer #22).

**Decision.** `exec:{uuid}` holds the context and `current_step`, updated after every step; a lock `lock:exec:{uuid}` with a
short TTL marks the owner. Every step is also written to `execution_actions` at once, so the audit is visible while the
execution runs. `RecoveryJob` resumes stale executions from `current_step`, or marks them failed when the state is gone.

**Consequences.** After a crash at most the interrupted step is repeated. The UI can show the live context of a running
execution. Steps should tolerate being executed again (the context snapshot is restored before every retry).

## ADR-10. Extend the framework instead of patching it

**Context.** Some framework behaviour did not fit: the stock route matcher compares only the length of the pattern
(`/api/processes/5` would be served by `/api/processes`), `AbstractController` parses global middlewares but never runs
them, there was no CORS preflight and no JSON 404/500.

**Decision.** Subclass instead of modifying the vendor code: `WpsApplication extends Application` (preflight, JSON 404,
last-resort 500), `WpsRoutesCollectionBuilder extends RoutesCollectionBuilder` (exact segment-count matching),
`BaseController` (runs the global middlewares). The framework is a normal Composer dependency pinned in `composer.lock`.

**Consequences.** The framework can be updated with `composer update sidalex/swoole-app` without re-applying patches; the
overridden methods have to be checked when it is updated.

## ADR-11. Configuration: `.env`, then the environment

**Context.** The service is configured with environment variables and `.env` files; the file is read first, then real
environment variables override or extend it (answer #24).

**Decision.** `Wps\Kernel\Env::load()` puts values from `back/.env` into the environment only when a variable is not set
yet; `AppConfig::fromEnv()` reads everything once in the master process.

**Consequences.** Docker Compose and Kubernetes can override any value without editing files. A change of configuration
requires a restart. The full list is in [Configuration](../04-operations/configuration.md).

## ADR-12. Waiting executions are stored in PostgreSQL

**Context.** A `delay` pause can last up to 400 days, long retry delays take minutes or hours. Keeping such an
execution in a worker coroutine is not possible (a restart loses it), and Redis is unreliable for it: the
`exec:{uuid}` key has a TTL, Redis can lose data, and `RecoveryJob` would mark the execution failed. Options: A — a
delayed queue in Redis (sorted set), B — a PostgreSQL table, C — an external scheduler.

**Decision.** Option B. The execution is parked: the state snapshot and the due time are written to `execution_waits`
in the same transaction that sets the status to `waiting`, then the Redis key is deleted. `WaitResumeJob` in every
worker claims due rows with `FOR UPDATE SKIP LOCKED` and a lease and resumes the execution after a CAS
`waiting → running`. Deleting a process with waiting executions requires `force=true` and cancels them (`cancelled`);
deactivating a process does not stop them.

**Consequences.** A wait survives a backend restart and a Redis loss (verified with `SIGKILL` during a pause), each
execution wakes up exactly once, waiting executions are visible via `GET /api/executions?status=waiting`. Wake-up
precision is limited by `WAIT_POLL_INTERVAL_MS`; the snapshot size by `WAIT_STATE_MAX_BYTES`. See
[Reliability → Waiting executions](../04-operations/reliability.md#waiting-executions).

## ADR-13. A flat program instead of walking the tree

**Context.** Branches, loops, early stop and `on_error: goto` do not fit a linear step list, while crash recovery and the
audit were built on one integer cursor `current_step` (`md/tasks_list/00-architecture.md`, section A).

**Decision.** The definition stays a tree (as the user writes it) and is compiled before execution into a flat list of
instructions (`action`, `branch`, `jump`, `jump_if_false`, `loop_begin`, `loop_next`, `halt`) with `step_path` paths.
The cursor is the integer `pc`; the loop stack is `frames`; the audit record number is `seq`. `if / else-if / else` is
one `branch` instruction (one audit record per block, Q3). Blocks have their own registry `BlockTypeRegistry`.

**Consequences.** A linear process compiles one-to-one (`seq == pc == the old step_index`) — old executions and the audit
stay compatible. Crash recovery is the same for any block. Compilation is deterministic and cached by the definition
hash; if the program hash differs after an upgrade, the execution is not continued (`Program changed after an upgrade`).
See [Execution engine](execution-engine.md#program-and-instructions).

## ADR-14. Our own CRM contract 2.0 and two separate plugins

**Context.** New actions need filter operators, sorting, a cursor, counting, delete, relationships and old field values
in the event (section F, Q1). The built-in SuiteCRM V8 API uses OAuth2 and another format.

**Decision.** WPS defines **its own** contract (`/api/v9`, [CRM integration](../04-operations/crm-integration.md)) — an
extension of contract 1.0 without breaking it; the CRM declares its capabilities at `GET /capabilities`, WPS checks them
at run time (`CRM_CAPABILITY_MISSING`). The SuiteCRM 7 and SuiteCRM 8 plugins are two separate packages outside the
repository, each changed and tested separately (`bin/contract_check.sh`). Event v2: `event_type`, `before` of the
**changed** fields only (after type normalisation), `changed_fields`, `wps_origin` (section C). Filters are AND only
(`in` / `nin` for "or", Q28), sorting — up to 2 fields + `id` (Q27), aggregates are computed by WPS (Q29).

**Consequences.** Any CRM can be connected by implementing the contract (or a bridge adapter). An old plugin keeps
working within contract 1.0. An event without `event_type` is handled as before (`legacy`). No aggregate endpoint means
reading records page by page (see `AGGREGATE_MAX_RECORDS`).

## ADR-15. Side-effect journal and delivery policy

**Context.** A step runs "at least once": after a worker crash `resume()` repeats it. That is safe for a CRM `PUT`, not
for a letter or a webhook (section H).

**Decision.** Table `step_side_effects` keyed by the call position (`{pc}@{iteration}#{sub}`): `started` before the call,
`done` with the answer after it. A repeated step with a `done` record returns the stored answer; a "foreign" `started`
record is an unknown outcome which, by the `delivery` policy, either fails with `UNKNOWN_OUTCOME` (`at_most_once`, the
default for letters, notifications, `POST`/`PATCH`) or repeats the call with the same `Idempotency-Key` / `Message-ID`
(`at_least_once`).

**Consequences.** Duplicate letters and orders after crashes are excluded at the price of rare `UNKNOWN_OUTCOME` errors
that an operator checks by hand. CRM actions (relationships, saves) are idempotent and do not use the journal.

## ADR-16. Secrets: application-level encryption and `secret://` references

**Context.** Actions need SMTP passwords, API and bot tokens. Keeping them in the process definition exposes them in the
JSON, the audit, Redis and history.

**Decision.** Table `secrets` encrypted with XChaCha20-Poly1305 (libsodium) and the key `WPS_SECRETS_KEY`, associated
data `wps-secret|name|version`, `kek_id` for rotation (`WPS_SECRETS_KEY_PREVIOUS` + `POST /api/secrets/rotate`). The
definition holds only a reference `secret://name[.field]`, and only in credential fields; values are masked by
`SecretMasker` in the audit and logs; a decrypted value lives in worker memory for `SECRETS_CACHE_TTL`.

**Consequences.** No API returns a secret value. Losing the key means re-entering the secrets (processes need no change).
Without the key the service runs, but actions using secrets fail.

## ADR-17. Outgoing requests: open outwards, closed inwards

**Context.** `http_request` calls a URL from the process definition; a process editor must not reach the internal
network (databases, Redis, cloud metadata) — Q20, Q21.

**Decision.** `EgressGuard`: any public host is allowed, private and service networks are closed by checking **all** DNS
addresses, the connection goes to the already checked IP (protection against DNS rebinding), redirects are checked again;
the URL host is a literal only; `http://` is allowed by default (`HTTP_ALLOW_PLAIN_HTTP=false` forbids it). User regular
expressions are limited by `SafeRegex` (length, PCRE backtracking limits).

**Consequences.** Internal systems are opened explicitly (`HTTP_ALLOW_PRIVATE_CIDRS`); the development stand opens the
docker network and is not copied to production. See [Security](../04-operations/security.md#outgoing-http-requests-ssrf-protection).

## ADR-18. Comparisons: explicit type, a missing field is always "false"

**Context.** All CRM values arrive as strings; the engine does not read CRM field metadata (section E). Customer rule #10:
a missing field never satisfies a condition.

**Decision.** The comparison type is set by the condition row (`cast`: `string`, `int`, `decimal`, `date`, `datetime`,
`bool`, `list`); one `TypeCaster` serves conditions, expressions and templates; a cast error is "false" with a trace (or
a `failed` execution with a `conditions` step under `on_cast_error: error`, Q8). `is_empty` of a missing field is false;
absence is checked by the separate operators `is_null` / `is_not_null` (Q7, customer decision). `decimal` and
`calculate` arithmetic use `bcmath` (Q15).

**Consequences.** Conditions behave predictably and independently of the CRM; an empty string and a missing field differ.

## ADR-19. Time: business time zone and calendar units only

**Context.** "Today", the start of a month, `date_shift` and pauses depend on the zone; the customer excluded working days
and a production calendar from the scope (sections J, O).

**Decision.** `WPS_TIMEZONE` (IANA, default UTC) for calendar operations; CRM dates are UTC; hours and minutes are exact
time (`+1d` ≠ `+24h` across a DST change). Time is taken only through `Wps\Time\Clock` (`FrozenClock` in tests). New
tables use `TIMESTAMPTZ`, wait deadlines are compared with PostgreSQL `now()`.

**Consequences.** No `workday` / `add_workdays`; the extension point is a new unit in `DateMath` and expression functions.
A wrong zone stops the service at start.

## ADR-20. Own template engine with escaping chosen by the field

**Context.** Letter, HTTP body and notification texts are built from CRM data; Twig is too broad for business users
(section G).

**Decision.** `TemplateEngine`: `{{ path | filter }}`, `{{= expression }}`; filters are the expression functions. The
escaping mode is chosen by the **consumer field** (`text`, `header`, `html`, `url`, `json`), not by the template author;
the URL host cannot be templated; a missing value follows the field policy (`empty`, `error`, `keep`).

**Consequences.** One function set for expressions and templates; header injection and host substitution are excluded by
construction.

## ADR-21. Handled errors keep the `completed` status

**Context.** `on_error: continue / goto` and `on_item_error: continue` let a process reach the end after a step error
(Q4); a process-level error handler block is postponed (Q5).

**Decision.** An execution with handled errors is `completed`; the error count is `executions.handled_errors`, the metric
`wps_step_errors_handled_total`, the last error — `sys.last_error`. `goto` goes only forward to a top-level step; steps
it skips are not audited (Q6). `stop_process` with `failed` is not a step error and `on_error` does not catch it.

**Consequences.** Statuses stay simple; "successful with errors" executions are visible through the counter and metric.

## ADR-22. Reserved names and ordered lists in the definition

**Context.** System values (`sys`), constants (`config`) and old values (`object_before`) are read as variables
(section I); `definition` is stored in `jsonb`, which does not keep the order of object keys (L.14).

**Decision.** The names `object`, `object_before`, `sys`, `config` are reserved (`ExecutionContext::RESERVED`), the last
three are read-only, `sys` and `config` are computed on read. Migration 010 reports processes that used these names as
variables. Wherever order matters (`actions`, `branches`, `set_variable` fields, `update_records` rules, `map` entries),
the format uses lists.

**Consequences.** The evaluation order does not depend on storage. Processes with the old names keep running after the
upgrade but cannot be saved until the variables are renamed.

## ADR-23. What is deliberately not done

| What | Decision | Where |
|---|---|---|
| Scheduled start (cron), trigger by a date in a record | Outside P1/P2 (customer decision) | `md/tasks_list/_excluded/` |
| Working days, working hours, production calendar | Excluded | ADR-19 |
| Outbox in the CRM plugin | None: when Redis is down the event is lost, the CRM save succeeds | [Reliability](../04-operations/reliability.md#what-is-not-guaranteed) |
| OR and brackets of conditions in the UI | The engine accepts a tree, the UI a flat list | ADR-6 |
| E-mail channel of `notify` | Not done — there is `send_email` (Q23) | — |

> **Note**
> Deviation from the plan: section B of `00-architecture.md` proposed an `executions.updated_at` column as the
> `RecoveryJob` criterion. The implementation kept the `started_at` criterion: a freshly woken execution is protected by the
> `lock:exec:{uuid}` lock, so the column was not needed.

<!-- footer-nav -->
---

← Previous: [Frontend architecture](frontend.md) | [Contents](../README.md) | [API reference: basics](../06-api-reference/index.md) Next →
<!-- /footer-nav -->
