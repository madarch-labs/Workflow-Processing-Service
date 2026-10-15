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

<!-- footer-nav -->
---

← Previous: [Frontend architecture](frontend.md) | [Contents](../README.md) | [API reference: basics](../06-api-reference/index.md) Next →
<!-- /footer-nav -->
