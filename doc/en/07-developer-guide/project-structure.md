# Project structure

An annotated map of the repository: where each part of WPS lives and what to change for a typical task.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/07-developer-guide/project-structure.md)
<!-- /lang-switch -->

## Top level

```
.
├── back/                     Backend: PHP 8.3 + Swoole 6 + SwooleApp (sidalex/swoole-app)
├── front/                    Frontend: Vue 3 + TypeScript + Vite 5 + Element Plus + Pinia + vue-i18n
├── mock/                     Mock CRM (contract 2.0), event generator, test targets for http_request/notify
├── doc/                      This documentation (ru/ and en/ trees, images, _meta tools)
├── .tehnical/                SuiteCRM 7 / SuiteCRM 8 stands and the SuiteCRM 8 case runner (git-ignored)
├── md/                       Specification, task plans, case descriptions (git-ignored, Russian)
├── docker-compose.yml        postgres, redis-stream, redis-data, backend, mailpit, mock, frontend
├── .env                      Host ports (WPS_*_PORT) and the CRM the backend talks to
├── legend_project_agent.md   Project summary for a new developer / agent: state, stands, pitfalls
├── rules_agent.md            Working rules: task order, checks, documentation
└── README.md                 Short overview, run instructions, design decisions
```

| Compose service | Host port (default) | Purpose |
|---|---|---|
| `backend` | `WPS_API_PORT` (9501) | API, event processing, background jobs |
| `frontend` | `WPS_UI_PORT` (3000) | production UI build under nginx, `/api/` proxied to the backend |
| `postgres` | `WPS_PG_PORT` (5432) | processes, executions, audit, waits, secrets, side-effect journal |
| `redis-stream` | `WPS_REDIS_STREAM_PORT` (6379) | event bus `crm_events` (+ `crm_events:dlq`), mock-CRM data |
| `redis-data` | `WPS_REDIS_DATA_PORT` (6380) | execution state `exec:{uuid}`, locks, dictionary cache, idempotency, `assign` queues |
| `mock` | `WPS_MOCK_PORT` (9502) | mock CRM and test targets |
| `mailpit` | `WPS_MAILPIT_PORT` (8025) | test SMTP (`smtp://mailpit:1025`) and a web UI for letters |

## back/

```
back/
├── server.php                 Entry point: loads .env, maps env into config.json, starts the Swoole server
├── config/config.json         SwooleApp config: server, Swoole settings (2 workers + 1 task worker), controllers
│                              namespace Wps\Controllers, global middleware AuthMiddleware, CyclicJobs, StateContainerInitiation
├── composer.json / .lock      Dependencies (sidalex/swoole-app ^0.3, symfony/mailer ^7.1; dev: phpunit ^11.5);
│                              PSR-4 "Wps\\" → src/, "Wps\\Tests\\" → tests/
├── Dockerfile                 Stages base (swoole 6.0-php8.3 + bcmath, intl) → prod (service) / test (phpunit entrypoint)
├── docker/entrypoint.sh       .env from .env.example if missing → migrations → server
├── .env.example               All backend variables with defaults and comments
├── API.md                     REST API contract (short reference, Russian)
├── bin/secrets-key.php        Generates a WPS_SECRETS_KEY
├── phpunit.xml.dist           Test suite "unit" = tests/Unit, bootstrap tests/Unit/bootstrap.php
├── migrations/
│   ├── 001_create_tables.sql        users, processes, executions, execution_actions
│   ├── 002_seed_admin.sql           admin@wps.local / admin
│   ├── 003_run_once.sql             processes.run_once + process_object_runs
│   ├── 004_program_audit.sql        pc, step_path, iteration, step_id of audit records (flat program)
│   ├── 005_secrets.sql              secrets (encrypted values)
│   ├── 006_side_effects.sql         step_side_effects (external call journal)
│   ├── 007_trigger_on.sql           processes.trigger_on
│   ├── 008_step_error_handling.sql  error_code / handled of steps, handled_errors of executions
│   ├── 009_execution_waits.sql      execution_waits (parked executions), status waiting / cancelled
│   ├── 010_reserved_names_check.sql NOTICE report: processes that use sys / config / object_before as variable names
│   └── migrate.php                  Applies *.sql in name order once (schema_migrations)
├── tests/
│   ├── IntegrationTest.php    API integration tests (plain PHP, HTTP against a running server on the mock CRM)
│   └── Unit/                  PHPUnit unit tests (see Testing)
└── src/                       Code, namespace Wps\ (table below)
```

### back/src

| Directory | Contents |
|---|---|
| `Kernel/` | `WpsApplication` (routing, CORS, 404/500), `WpsRoutesCollectionBuilder` (exact route matching), `Container` (services and registries of one worker, `share()`), `Env` (`Env::get/int/bool`) |
| `Config/AppConfig.php` | immutable configuration from the environment (with value bounds) |
| `State/` | `StateInitiation`, `WpsState`: `AppConfig` and the shared metrics table, created before the workers fork |
| `CyclicJobs/` | `StreamConsumerJob` (reads `crm_events`), `RecoveryJob` (abandoned messages, stuck executions), `WaitResumeJob` (wakes waiting executions, `WAIT_POLL_INTERVAL_MS`) |
| `Controllers/` | one class per route with `#[Route]`, `BaseController`; subdirectories `Auth/`, `Users/`, `Processes/`, `Executions/`, `Secrets/`, `Mail/`, `Notify/`, `Dictionaries/` (23 dictionaries), `Health/`, `Metrics/` |
| `Middleware/AuthMiddleware.php` | JWT + `AccessPolicy` for every request |
| `Auth/` | `JwtService` (access / refresh), `AccessPolicy` (route access rules) |
| `Http/` | `ApiException`, `JsonResponder` (response envelope), `AuthContext`; `Outbound/` — outgoing HTTP: `OutboundHttpClient`, `EgressGuard` + `CidrList` (SSRF protection), `JsonPathExtractor`, `Auth/` (`AuthApplier`, `OAuth2TokenProvider`) |
| `Services/` | business logic: event intake (`StreamService`, `EventProcessorService`, `ProcessResolverService`), execution (`ExecutionEngineService`, `ExecutionStateStore`, `ExecutionHandle`, `AuditService`, `StepRecord`, `PreparedRun`), conditions (`ConditionEngineService`, `ConditionResult`, `Conditions/ListOperand`), saving (`CrmObjectSaver`), CRM API (`ExternalApiAdapterService`, `DictionaryService`), processes (`ProcessService`, `ProcessValidatorService`, `Validation/DefinitionScope`, `Validation/ValidationLog`), step errors (`Errors/ErrorClassifier`, `ErrorInfo`, `ErrorPolicy`, `RetryPolicy`), waits (`WaitService`), assignment (`Assign/AssignmentStrategies`, `UserPoolResolver`), `MetricsService`, `StreamInfoService` |
| `Program/` | compiler of a definition into a flat program (`ProgramCompiler`, `ProgramEmitter`, `Program`, `Instruction`), `Interpreter`, `InstructionOutcome`, blocks (`BlockTypeInterface`, `BlockTypeRegistry`, `BlockValidationContext`, `Blocks/{Condition,StopProcess,Foreach}Block`), loops (`LoopRunner`, `LoopItemsStore`) |
| `Context/` | `ExecutionContext` (event, variables, `meta`, changed fields, `sys.*`, `config.*`), `EventInfo` |
| `Events/EventEnvelope.php` | parsing of a stream event / manual run (contract 2.0: `event_type`, `before`, `changed_fields`) |
| `Actions/` | `ActionHandlerInterface`, `ResumableActionInterface`, `ActionHandlerRegistry`, `ActionResult` and the handlers: `Preload`, `Modify`, `Save`, `StartProcess`, `SetVariable`, `FindRecords`, `UpdateRecords`, `Delay`, `SendEmail`, `HttpRequest`, `Assign`, `Notify`, `LinkRecords` (also `unlink_records`); `ChangeTypes/` — `ChangeTypeInterface`, `ChangeTypeRegistry` and 13 change types; `Support/RelatedIdsResolver` |
| `Comparators/` | `ComparatorInterface`, `ComparatorRegistry::default()`, base classes (`AbstractComparator`, `AbstractOrderComparator`, `AbstractChangeComparator`), 25 operators, `OperandKind`, `PresenceValue`, `FieldChange` |
| `Types/` | `TypeCaster`, `CastType`, `CastOptions`, `CastResult`, `RelativeDate` |
| `Expression/` | the expression language: `ExpressionLexer`, `ExpressionParser`, `ExpressionEngine` (function registry), `FunctionSpec`, `ValueComparator`, `Decimal`, `SafeRegex`; `Functions/` — `Logic`, `Number`, `Text`, `List`, `Date`, `Type` |
| `Template/` | `{{ … }}` templates: `TemplateParser`, `TemplateEngine`, `TemplateValidator`, `EscapeMode`, `MissingPolicy` |
| `Time/` | `Clock` (`SystemClock`, `FrozenClock` for tests), `DateMath`, `DateUnit`, `Duration`, `ParsedDate` |
| `Crm/` | the CRM contract: `CrmFilter`, `FilterTranslator`, `FilterRulesValidator`, `CrmPage`, `CrmCapabilities` + `CrmCapabilitiesService`, `CrmCallOrigin` |
| `LoadStrategies/` | `LoadStrategyInterface`, `LoadStrategyRegistry`, `ByIdStrategy`, `ByFieldStrategy` |
| `Notify/` | `ChannelInterface`, `ChannelRegistry`, `Channels/{CrmAlert,Telegram,Slack,Teams}Channel`, `Delivery`, `NotifyMessage`, `RecordLinks`, `HttpChannelSupport` |
| `Mail/` | `MailerFactory`, `RecipientResolver`, `RecipientList`, `SmtpErrorClassifier`, `TrackingSmtpTransport`, `MailRateLimiter` |
| `SideEffects/` | the external call journal: `SideEffectLedger`, `SideEffectRepository`, `SideEffectStore`, `DeliveryPolicy`, `HasDeliveryPolicy`, `UnknownOutcomeException` |
| `Secrets/` | `SecretService`, `SecretRepository`, `SecretCipher`, `SecretResolver`, `SecretReference` (`secret://`), `SecretKind`, `SecretMasker`, `SecretUsageScanner` |
| `Persistence/` | PDO repositories (`Process`, `Execution`, `ExecutionAction`, `ExecutionWait`, `ProcessObjectRun`, `User`) and the entities `Process`, `WaitRow` |
| `Exceptions/` | `RecoverableException`, `NonRecoverableException`, `ConfigurationException`, `ExecutionException`, `CrmApiException`, `ConditionCastException` |
| `Infrastructure/` | `Database` (PDO pool), `RedisConnection` (Redis pool) |
| `Support/` | `Logger` (JSON), `Json`, `Uuid`, `JsonPath`, `Transliterator` |

## front/

```
front/
├── package.json             dev / build / typecheck / i18n:check / test scripts
├── vite.config.ts           dev server on 3000, /api proxy (VITE_API_TARGET), vue-i18n plugin
├── vitest.config.ts         unit tests: src/**/*.spec.ts (npm test)
├── scripts/i18n-check.mjs   Same keys in all locales, used keys exist, no Cyrillic in .vue/.ts
├── Dockerfile, nginx.conf   Production build served by nginx, /api/ → backend:9501
└── src/
    ├── main.ts, App.vue     App bootstrap, Element Plus, icons, i18n
    ├── router/index.ts      Routes, role guard (meta.roles), page titles (meta.titleKey)
    ├── api/                 client.ts (Axios, envelope unwrap, token refresh) + auth, processes, executions,
    │                        dictionaries, users
    ├── stores/              auth.ts (tokens, roles), processes.ts (form ↔ definition conversion)
    ├── views/               LoginView, ProcessesView, ProcessDetailView, ProcessEditView,
    │                        ExecutionDetailView, UsersView
    ├── components/          AppLayout, LanguageSwitcher, ProcessHeader, ConditionsPanel, PreloadPanel,
    │                        ModifyPanel, StartProcessPanel, VariableSidebar, JsonEditor, JsonView, PathInput
    ├── i18n/                index.ts (setLocale, detectLocale), locales.ts (registry), element-plus.ts
    ├── locales/             ru.json (reference), en.json
    ├── utils/               format.ts (labels, dictionary localisation), errors.ts, variables.ts, keys.ts,
    │                        processForm.ts (editor form ⇄ definition), __tests__/ (vitest)
    ├── types/index.ts       API and form types
    └── styles/main.css
```

The UI editor covers the basic steps (conditions, preload, `modify`, `start_process`); the other actions and blocks are kept as
`UnknownAction` and edited in the JSON editor — the stage tasks were done in the backend.

## mock/

| File | Purpose |
|---|---|
| `mock_server.php` | Router (PHP built-in server, port 9502): `/test-event`, `/modules`, `/health`; the CRM API of contract 2.0 under `/api/v8` and `/api/v9` (`X-Auth-Key` = `MOCK_CRM_STATIC_KEY` + `gmdate('Y.m.d H')`): `capabilities`, CRUD, filter `filter[f][op]`, sorting, cursor pagination, `count`, relationships; helpers without auth `/mock/objects/{module}/{id}`, `/mock/relationships/…`, `/mock/config`; `http_request` targets — `/mock/echo`, `/mock/echo-last`, `/mock/status/{code}`, `/mock/redirect`, `/mock/big`, `/mock/text`, `/mock/oauth/token`, `/mock/protected`; messengers `/mock/slack/{id}`, `/mock/teams/{id}`, `/mock/telegram/bot{token}/sendMessage`, the log `/mock/notify` |
| `MockEventService.php` | Generates an event, stores the object in the mock CRM, `XADD crm_events * data '{json}'` |
| `MockCrmStorage.php` | Objects and relationships in Redis (read, write, search with the filter operators, including `nin`) |
| `MockModulesList.php` | 15 modules with Russian names and generated fields |
| `Dockerfile` | image of the `mock` service |

Special ids in the mock CRM: `fail500…` — always HTTP 500, `fail<N>x500…` — the first N requests return 500, `slow…` — a 3 s delay.

## .tehnical/

The directory is git-ignored but present in a developer's working copy:

| Path | Purpose |
|---|---|
| `suiteCRM8/` | Docker stand of SuiteCRM 8.10.2 (`wps-suitecrm8-web-1`, `-db-1`, UI http://localhost:8801, API `/api/v9`), the stand's `.env` (`WPS_API_STATIC_KEY`, `SUITECRM_ADMIN_*`, `WPS_EVENT_MODULES`) |
| `suiteCRM8/processes/cases/*.json` | executable task cases (`<id>-<name>.json`) |
| `suiteCRM8/scripts/run_case.php` | the case runner (see [Testing](testing.md#suitecrm-8-cases)) |
| `suiteCRM8/scripts/seed_case_users.sh` | fixture: users `wps-case-u1..u4` and the group `wps-case-sales` in the stand's database |
| `suiteCRM8/processes/suiteCRM8.processes.json` | demo processes of the stand |
| `suiteCRM7/` | SuiteCRM 7 stand (port 8701); kept stopped, started to check the plugin |

The integration plugin (a logic hook publishes events to `crm_events`, the `wpsApi` entry point serves the `/api/v9/...` API) is
not part of the repository: the packages `/home/sidalex/dev/Tasks/SuiteCRM8` and `/home/sidalex/dev/Tasks/suiteCRM7`, installed
with `bin/install.sh`, contract check — `bin/contract_check.sh`. Details — [CRM integration](../04-operations/crm-integration.md).

## md/

The directory is git-ignored. Source requirements and planning, in Russian:

| Path | What |
|---|---|
| `md/tasks_list/README.md` | summary table of tasks: id, priority, stage, dependencies, state, plan, case |
| `md/tasks_list/<id>-*.md`, `done/`, `_excluded/` | plans of open, finished and excluded tasks |
| `md/tasks_list/00-architecture.md`, `01-questions.md` | shared architecture decisions and questions with recommendations |
| `md/tasks_list/cases/` | SuiteCRM 8 case descriptions (`<id>-suitecrm8.md`) and their table |
| `md/tasks_list/tools/complete_task.py` | closes a task (moves the plan to `done/`, ✅, links) |
| `md/doc/` | specification (`technical_specification.md`), customer answers, the integration test concept (`concept_apitest_back.md`) |
| `md/back_stap_*.md`, `front_stap_1.md`, `idempotentie_plan.md`, `multi_language_plan.md` … | early stage plans |
| `md/SwooleApp/` | a copy of the SwooleApp framework sources for reference |

PHP docblocks refer to the plans `md/tasks_list/done/<id>-….md`, never to `doc/*`.

## doc/

```
doc/
├── README.md                Language choice
├── ru/ , en/                Same tree, same file names and headings
├── images/screenshots/{ru,en}/
└── _meta/
    ├── research_notes.md, open_questions.md, screenshots_manifest.md, demo_processes/
    └── tools/
        ├── check_links.py   ru/en trees identical, no broken links or anchors (run before every commit)
        ├── nav.py           regenerates the language switch and the footer navigation from the page list
        └── shoot.mjs        documentation screenshots via puppeteer-core (UI / API / MAILPIT env)
```

## Where to change what

| Task | Files |
|---|---|
| New comparison operator | `back/src/Comparators/`, `ComparatorRegistry::default()`, `front/src/locales/*.json` → [guide](extending/new-comparator.md) |
| New action type | `back/src/Actions/`, `Container::actions()` → [guide](extending/new-action-type.md) |
| New block with nested actions | `back/src/Program/Blocks/`, `Container::blocks()` → [guide](extending/new-block-type.md) |
| New change type | `back/src/Actions/ChangeTypes/`, `Container::changeTypes()`, `front/src/types/index.ts` → [guide](extending/new-change-type.md) |
| New load strategy | `back/src/LoadStrategies/`, `LoadStrategyRegistry::default()` → [guide](extending/new-load-strategy.md) |
| New expression function | `back/src/Expression/Functions/*`, `ExpressionEngine` / `Container::expressions()` → [guide](extending/expression-functions.md) |
| New endpoint | `back/src/Controllers/…`, `AccessPolicy`, `back/API.md`, [API reference](../06-api-reference/endpoints.md) → [guide](extending/new-api-endpoint.md) |
| Another CRM | `ExternalApiAdapterService` or an API on the CRM side → [guide](extending/new-crm-adapter.md) |
| New notification channel | `back/src/Notify/Channels/`, `Container::notifyChannels()` → [guide](extending/new-notify-channel.md) |
| New system variable `sys.*` | `ExecutionContext::SYS_FIELDS` and `systemValues()`, the label in `SystemVariablesController` |
| New environment variable | `Config/AppConfig.php` (with bounds), `back/.env.example`, `docker-compose.yml` if needed, [Configuration](../04-operations/configuration.md) |
| New UI page | `front/src/views/`, `router/index.ts`, `AppLayout.vue`, `api/` → [guide](extending/new-ui-screen.md) |
| New UI language | `front/src/locales/`, `front/src/i18n/` → [guide](extending/new-language.md) |
| Database change | a new `back/migrations/0NN_*.sql` (applied on the next backend start) |

<!-- footer-nav -->
---

← Previous: [Development environment](dev-environment.md) | [Contents](../README.md) | [Coding conventions](coding-conventions.md) Next →
<!-- /footer-nav -->
