# Research notes (internal, not part of the navigation)

Collected from the code on 2026-10-15 (commit `c88cb64`). Facts here were checked against the sources listed in each section.
Used as the shared brief for all documentation sections.

## 1. What WPS is

Workflow Processing Service — a business-process automation service for a CRM (SuiteCRM 7/8 or the bundled mock).
The CRM publishes "object saved" events into the Redis Stream `crm_events`. WPS picks active **top-level** processes for
the object type (`module`), checks their **conditions** (AND), runs **preload** of related objects from the CRM, then
**actions** strictly in order (`preload`, `modify`, `save_object`, `start_process`). Child processes run asynchronously.
Every execution and every step is written to the audit tables.

## 2. Components (docker-compose.yml)

| Service | Image | Host port (env, default) | Role |
|---|---|---|---|
| `postgres` | postgres:15-alpine | `WPS_PG_PORT` (5432) | processes, executions, audit, users, run-once marks |
| `redis-stream` | redis:7-alpine | `WPS_REDIS_STREAM_PORT` (6379) | event bus, stream `crm_events`, group `wps_workers`, DLQ `crm_events:dlq` |
| `redis-data` | redis:7-alpine | `WPS_REDIS_DATA_PORT` (6380) | caches (`proc_cache:{type}`, `dict:modules`), execution state `exec:{uuid}`, locks, idempotency `idem:*` |
| `backend` | back/Dockerfile (phpswoole/swoole:6.0-php8.3) | `WPS_API_PORT` (9501) | API + StreamConsumerJob + RecoveryJob |
| `mock` | mock/Dockerfile | `WPS_MOCK_PORT` (9502) | Mock Event Service + CRM API v8 emulation |
| `frontend` | front/Dockerfile (nginx) | `WPS_UI_PORT` (3000; this repo's `.env` sets 3080) | SPA, proxies `/api/` to `backend:9501` |

Login: `admin@wps.local` / `admin` (migration `002_seed_admin.sql`).

## 3. Backend facts

- Entry `back/server.php`: loads `.env` (real env wins), maps env into `config/config.json` (SwooleApp), `SWOOLE_HOOK_ALL`.
- `config.json`: controllers namespace `Wps\Controllers`, global middleware `Wps\Middleware\AuthMiddleware`,
  CyclicJobs `StreamConsumerJob` (0.5 s, start after 1 s) and `RecoveryJob` (30 s, start after 15 s), strategy `ALL_WORKERS`,
  `worker_num` 2, `task_worker_num` 1, `StateContainerInitiation` → `Wps\State\StateInitiation` (AppConfig + shared metrics `Swoole\Table`, built before fork).
- `Wps\Kernel\WpsApplication` extends SwooleApp `Application`: OPTIONS preflight, JSON 404, last-resort 500, uses
  `WpsRoutesCollectionBuilder` (exact segment-count route matching; tolerates trailing slash).
- `Wps\Kernel\Container` — per-worker lazy service locator (rebuilt after fork by pid). **Registries are built here** →
  this is where extensions are registered: `comparators()` (`ComparatorRegistry::default()`), `loadStrategies()`
  (`LoadStrategyRegistry::default()`), `changeTypes()`, `actions()`, `expressions()`. Restart required after changes.
- Controllers: one class per endpoint, `#[Route(uri: '/api/...', method: 'GET')]`, extend `Wps\Controllers\BaseController`
  (`handle()`, `ok()`, `body()`, `query()`, `pagination()`, `intParam()`, `user()`; `ApiException` → error envelope).
- RBAC: `Wps\Auth\AccessPolicy::RULES` (regex per method; first match wins; unlisted GET = all roles, unlisted write = editors).
- JWT: `Wps\Auth\JwtService`, access TTL `JWT_ACCESS_TTL` 900 s, refresh `JWT_REFRESH_TTL` 604800 s.
- Execution pipeline: `StreamConsumerJob` → `StreamService::consumeBatch` (XREADGROUP, batch `REDIS_STREAM_BATCH`, block
  `REDIS_STREAM_BLOCK_MS`) → `EventProcessorService::process` (parse: field `data` JSON or flat fields; `module`/`object_type`
  required, `id` required, else DLQ; `event_id` from object or message id) → `ProcessResolverService::resolve(module)` (active,
  top-level, not deleted, ordered by `priority` asc; Redis cache `proc_cache:{type}` TTL `PROCESS_CACHE_TTL` 60 s, invalidated on save)
  → for each process `ExecutionEngineService::handle` with idempotency key `idem:{event_id}:{process_id}` → ack.
  Exception during processing → message not acked → re-claimed by `RecoveryJob` via XAUTOCLAIM after `REDIS_STREAM_CLAIM_IDLE_MS`
  (300000); more than 5 deliveries → DLQ.
- Engine `handle()`: idempotency claim → `prepare()` (conditions on `object.*` first; if a row references a variable → result
  "unknown" → run `definition.preload` → evaluate again = **lazy preload**) → `claimRunOnce` → `begin()` (row in `executions`,
  Redis hash `exec:{uuid}`, lock) → `run()` steps sequentially (`Process::steps()` = preload rows then actions), after each step
  audit row + progress in Redis → `finish()` (status, `context_snapshot`; Redis state kept `EXECUTION_FINISHED_TTL_SECONDS` 3600).
- Retry: recoverable errors (`RecoverableException`, `CrmApiException` with 5xx/transport, `RedisException`, DB connection
  errors) retried `ACTION_RETRY_MAX` (3) times, delay `ACTION_RETRY_BASE_DELAY_MS` (1000) × `ACTION_RETRY_BACKOFF` (2)^(n-1);
  status `retrying` while waiting. Non-recoverable (CRM 4xx, config errors) → step `failed`, remaining steps `skipped`,
  execution `failed`. Context is restored to the snapshot before each retry.
- Recovery: `RecoveryJob` also resumes executions stale > `RECOVERY_STALE_SECONDS` (300) from Redis `current_step`
  (lock TTL `EXECUTION_LOCK_TTL` 30 s); if Redis state is gone → `failed` ("Execution context expired or lost").
- `start_process`: `StartProcessHandler` checks child exists, `is_child`, depth ≤ `MAX_CHILD_DEPTH` (10); inactive child →
  `skipped` entry; `spawnChild()` runs `handle(..., 'child', parentId, key 'idem:{parentExecId}.{step}:{childId}')` in a new
  coroutine, not awaited. Child gets the parent's event and all variables (`forChild()`, depth+1).
- Run once (`run_once`): table `process_object_runs` PK (process_id, object_type, object_id). Mark taken atomically only when
  conditions are met; blocked start → no execution, `blocked_count++`, metric `wps_run_once_skipped_total`. Without `id` →
  not applied, metric `wps_run_once_no_id_total`. Manual `force` skips conditions but not run_once. Reset via
  `DELETE /api/processes/{id}/runs/{object_type}/{object_id}`.
- CRM adapter `Wps\Services\ExternalApiAdapterService` (coroutine HTTP client): header `X-Auth-Key` = `CRM_API_STATIC_KEY` .
  `gmdate('Y.m.d H')`; `getById` GET `/{module}/{id}` (404 → null), `findOneByField` GET `/{module}?filter[field]=value&size=1`,
  `update` PUT, `create` POST, `getModules` (`MOCK_SERVICE_URL/modules` first, then `{crm}/modules`). `crmUrl()` appends
  `/api/v8` unless the base already ends with `/api/vN`. JSON:API `{id, attributes}` is flattened. Timeout `CRM_API_TIMEOUT` 10 s.
  Status ≤0 or ≥500 → recoverable `CrmApiException`; other ≥400 → non-recoverable.
- `CrmObjectSaver::save`: variable null → `skipped`; module from action `module` or the variable meta; with `id` → PUT only
  dirty fields (or all with `mode: "full"`; no changes → `skipped`); without `id` → POST, new id stored in the variable.
- Validation `ProcessValidatorService` + `Services/Validation/DefinitionScope` (errors `{field, message}`; variables known in
  execution order; child processes inherit variables → unknown variables allowed; cycle detection → `422 CYCLE_DETECTED`).
- Expression engine `Wps\Expression\ExpressionEngine` (lexer/parser/evaluator): numbers, quoted strings, paths, `+ - * / %`,
  parentheses; `+` concatenates if an operand is not numeric; functions `concat upper lower trim len substr round abs min max`;
  `registerFunction(name, callable)` is the extension point (register it in `Container::expressions()`).
- Metrics (`/metrics`, Prometheus text): `wps_events_received_total`, `wps_events_processed_total`, `wps_events_skipped_total`,
  `wps_events_invalid_total`, `wps_processes_started_total`, `wps_executions_completed_total`, `wps_executions_failed_total`,
  `wps_execution_duration_seconds`, `wps_action_duration_seconds`, `wps_retry_count_total`, `wps_run_once_skipped_total`,
  `wps_run_once_no_id_total`.
- Backend dictionary labels (`label()` in comparators, change types, actions, `PreloadHandler::SOURCES`) are Russian; the UI
  translates by `value` via `front/src/locales/*.json` → `dict.*`. Module labels come from the CRM/mock (Russian in the mock).

## 4. Frontend facts

- Vue 3.5 + TS + Vite 5 + Element Plus 2.8 + Pinia + Vue Router 4 + Axios + vue-i18n 11.
- Routes (`front/src/router/index.ts`): `/login` (public), `/processes`, `/processes/create` (admin, editor),
  `/processes/:id`, `/processes/:id/edit` (admin, editor), `/executions/:id`, `/users` (admin).
- Tokens in `localStorage` `wps_access_token`, `wps_refresh_token`; 401 → refresh.
- i18n: `src/locales/ru.json` (reference) + `en.json`; `?lang=en` → `localStorage['wps_locale']` → browser language → `ru`.
  `npm run i18n:check` (part of `npm run build`) checks keys and forbids Cyrillic in `.vue/.ts`.
- Edit form components: `ProcessHeader.vue` (name, type, status, object type, priority, run once), `ConditionsPanel.vue`,
  `PreloadPanel.vue`, `ModifyPanel.vue` (modify + save rows), `StartProcessPanel.vue`, `VariableSidebar.vue`, `JsonEditor.vue`.
- Dev: `cd front && npm install && npm run dev` → port 3000, `/api` proxied to `localhost:9501` (`VITE_API_TARGET`). Node ≥ 18.

## 5. Mock (mock/)

- `GET|POST /test-event?module=Deals&set[status]=Active&count=N` — generates an event, stores the object in the mock CRM,
  XADDs `data` JSON into `crm_events`. Use `curl -g` (brackets!). Statuses: Active, Inactive, New, In Progress, Closed.
- Objects of Accounts/Contacts/Deals/Invoices/Orders/Payments/Contracts/Tickets get `account_id`; the account is created in the mock CRM.
- CRM API v8 emulation `/api/v8/{module}[/{id}]` (needs `X-Auth-Key`); ids starting with `fail500` → HTTP 500, `slow` → 3 s delay.
- `/mock/objects/{module}/{id}` GET/PUT test helper; `/modules` dictionary; `/health`.

## 6. Verified demo stack used for screenshots

Isolated compose project (does not touch the main stack):

```bash
WPS_UI_PORT=3081 WPS_API_PORT=9511 WPS_MOCK_PORT=9512 WPS_PG_PORT=55433 WPS_REDIS_STREAM_PORT=6389 WPS_REDIS_DATA_PORT=6390 \
CRM_API_BASE_URL=http://mock:9502 CRM_API_STATIC_KEY=mySecretKey MOCK_SERVICE_URL=http://mock:9502 \
docker compose -p wpsdoc up -d --build
```

Demo processes (all created via `POST /api/processes` and verified with mock events — every one completed as expected):

| id | name | type | what it shows |
|---|---|---|---|
| 1 | Active deal → mark account as VIP | Deals | condition + preload by id + modify `set_value` with `save_to_crm` |
| 2 | Invoice discount 10% | Invoices, priority 10 | `calculate` (`round(object.amount * 0.9, 2)`, `concat(...)`) + `save_object` |
| 3 | Contact: copy account name | Contacts | condition on a preloaded variable (lazy preload) + `set_from_variable` |
| 4 | Create follow-up task | Leads, child | creates a new variable `task` and saves it as a new `Tasks` record (POST) |
| 5 | Payment sync (CRM failure demo) | Payments | preload of `fail500-demo` → 3 retries → failed, next step skipped |
| 6 | New lead → schedule a call (once per lead) | Leads, run_once | `contains`, `start_process` of #4, run-once mark + blocked manual run |

Exact JSON of each is in `doc/_meta/demo_processes/` (copied from the scratch files used to create them).

## 7. Glossary (EN ↔ RU) — use these terms everywhere

| EN | RU | code |
|---|---|---|
| process | процесс | `processes` |
| top-level process | процесс верхнего уровня | `is_child: false` |
| child process | ведомый процесс | `is_child: true` |
| object type (CRM module) | тип объекта (модуль CRM) | `object_type` |
| event | событие | stream message |
| event object / source object | объект события / исходный объект | `object` |
| condition | условие | `conditions[]` |
| operator (comparison) | оператор сравнения | `operator` |
| preload | предзагрузка | `preload` |
| variable | переменная | `v1`, `task` |
| action | действие | `actions[]` |
| modify (change) | изменение | `modify` |
| change type | тип изменения | `change_type` |
| expression | выражение | `calculate` |
| save to CRM | сохранение в CRM | `save_object`, `save_to_crm` |
| start next process | запуск следующего процесса | `start_process` |
| execution (run) | выполнение (запуск) | `executions` |
| step | шаг | `execution_actions` |
| trigger (source of a run) | источник запуска | `trigger`: stream/manual/child |
| manual run | ручной запуск | `POST /run` |
| run once per record | однократный запуск для записи | `run_once` |
| run-once mark | отметка однократного запуска | `process_object_runs` |
| retry | повтор | `retry_count` |
| recoverable / non-recoverable error | восстановимая / невосстановимая ошибка | |
| dead-letter queue | очередь недоставленных сообщений (DLQ) | `crm_events:dlq` |
| priority | приоритет | `priority` |
| audit | аудит | |

## 8. Documentation conventions (all sections)

- File tree is identical in `doc/en/` and `doc/ru/`. Each page: `# H1`, then one line "what the page is about". The language
  switch line and the footer navigation are inserted by `doc/_meta/tools/nav.py` — do not write them by hand.
- Screenshots: `../../images/screenshots/<lang>/NN-name.png` from `doc/<lang>/<section>/page.md`
  (from `07-developer-guide/extending/*` it is `../../../images/...`). Below the image: one italic caption line.
- Mermaid for diagrams; labels translated, technical names unchanged.
- UI labels quoted verbatim from `front/src/locales/{en,ru}.json`.
- Callouts: `> **Note**` / `> **Warning**` (RU: `> **Примечание**` / `> **Внимание**`).
