# Project structure

An annotated map of the repository: where each part of WPS lives and what to change for a typical task.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/07-developer-guide/project-structure.md)
<!-- /lang-switch -->

## Top level

```
.
├── back/                  Backend: PHP 8.3 + Swoole 6 + SwooleApp (sidalex/swoole-app)
├── front/                 Frontend: Vue 3 + TypeScript + Vite 5 + Element Plus
├── mock/                  Mock Event Service + CRM API v8 emulation (PHP built-in server)
├── .tehnical/             SuiteCRM 7 and SuiteCRM 8 stacks with the WPS integration plugin
├── md/                    Specification, customer answers, implementation plans (Russian)
├── doc/                   This documentation (en/ and ru/ trees, images, _meta)
├── docker-compose.yml     postgres, redis-stream, redis-data, backend, mock, frontend
├── .env                   Host ports (WPS_*_PORT) and the CRM used by the backend
└── README.md              Short overview, run instructions, design decisions
```

## back/

```
back/
├── server.php                 Entry point: loads .env, maps env into config.json, starts the Swoole server
├── config/config.json         SwooleApp config: server, Swoole settings, controllers namespace,
│                              global middlewares, CyclicJobs, StateContainerInitiation
├── composer.json / .lock      Dependencies; PSR-4 "Wps\\" → src/
├── Dockerfile                 phpswoole/swoole:6.0-php8.3, composer install, code copied into the image
├── docker/entrypoint.sh       .env from .env.example if missing → migrations → server
├── .env.example               All backend variables with defaults
├── API.md                     REST API contract
├── migrations/
│   ├── 001_create_tables.sql  users, processes, executions, execution_actions
│   ├── 002_seed_admin.sql     admin@wps.local / admin
│   ├── 003_run_once.sql       processes.run_once + process_object_runs
│   └── migrate.php            Applies *.sql in name order once (schema_migrations)
├── tests/IntegrationTest.php  API integration tests (plain PHP, HTTP against a running server)
└── src/
    ├── Kernel/                WpsApplication (routing, CORS, 404/500), WpsRoutesCollectionBuilder,
    │                          Container (per-worker services and registries), Env
    ├── Config/AppConfig.php   Immutable configuration built from the environment
    ├── State/                 StateInitiation: AppConfig + shared metrics table, built before fork
    ├── CyclicJobs/            StreamConsumerJob (every 0.5 s), RecoveryJob (every 30 s)
    ├── Controllers/           One class per endpoint (#[Route]); BaseController
    │   ├── Auth/  Users/  Processes/  Executions/  Dictionaries/  Health/  Metrics/
    ├── Middleware/            AuthMiddleware (JWT + AccessPolicy)
    ├── Auth/                  JwtService, AccessPolicy (route-level RBAC)
    ├── Http/                  ApiException, JsonResponder (response envelope), AuthContext
    ├── Services/              Business logic:
    │   ├── StreamService, EventProcessorService, ProcessResolverService   event intake
    │   ├── ExecutionEngineService, ExecutionStateStore, AuditService       execution
    │   ├── ConditionEngineService, CrmObjectSaver                          conditions, saving
    │   ├── ExternalApiAdapterService, DictionaryService                    CRM API
    │   ├── ProcessService, ProcessValidatorService, Validation/            process CRUD + validation
    │   └── MetricsService, StreamInfoService                               /metrics, /api/health
    ├── Context/ExecutionContext.php   Event, variables, dirty fields of one execution
    ├── Actions/               ActionHandlerInterface + Preload/Modify/Save/StartProcess handlers,
    │   └── ChangeTypes/       ChangeTypeInterface + set_value / set_from_variable / calculate
    ├── Comparators/           ComparatorInterface + equals / not_equals / contains / not_contains
    ├── LoadStrategies/        LoadStrategyInterface + by_id / by_field
    ├── Expression/            Lexer, parser and evaluator of "calculate" expressions
    ├── Exceptions/            Recoverable / NonRecoverable / Configuration / CrmApi exceptions
    ├── Persistence/           Repositories (PDO) and the Process entity
    ├── Infrastructure/        Database (PDO pool), RedisConnection (pool)
    └── Support/               Logger (JSON), Json, Uuid
```

## front/

```
front/
├── package.json             dev / build / typecheck / i18n:check scripts
├── vite.config.ts           dev server on 3000, /api proxy (VITE_API_TARGET), vue-i18n plugin
├── scripts/i18n-check.mjs   Same keys in all locales, used keys exist, no Cyrillic in .vue/.ts
├── Dockerfile, nginx.conf   Production build served by nginx, /api/ → backend:9501
└── src/
    ├── main.ts, App.vue     App bootstrap, Element Plus, icons, i18n
    ├── router/index.ts      Routes, role guard (meta.roles), page titles (meta.titleKey)
    ├── api/                 client.ts (Axios, envelope unwrap, token refresh) + one module per resource
    ├── stores/              auth.ts (tokens, roles), processes.ts (form ↔ definition conversion)
    ├── views/               LoginView, ProcessesView, ProcessDetailView, ProcessEditView,
    │                        ExecutionDetailView, UsersView
    ├── components/          AppLayout, LanguageSwitcher, ProcessHeader, ConditionsPanel, PreloadPanel,
    │                        ModifyPanel, StartProcessPanel, VariableSidebar, JsonEditor, JsonView, PathInput
    ├── i18n/                index.ts (setLocale, detectLocale), locales.ts (registry), element-plus.ts
    ├── locales/             ru.json (reference), en.json
    ├── utils/               format.ts (labels, dictionary localisation), errors.ts, variables.ts, keys.ts
    ├── types/index.ts       API and form types
    └── styles/main.css
```

## mock/

| File | Role |
|---|---|
| `mock_server.php` | Router: `/test-event`, `/modules`, `/health`, `/mock/objects/{module}/{id}`, `/api/v8/{module}[/{id}]` |
| `MockEventService.php` | Generates an event, stores the object in the mock CRM, `XADD crm_events * data '{json}'` |
| `MockCrmStorage.php` | Objects in Redis (get / put / find by field) |
| `MockModulesList.php` | 15 modules with Russian labels and generated fields |

## .tehnical/

`suiteCRM7/` and `suiteCRM8/`: Docker stacks of a clean SuiteCRM. The integration plugin (an `after_save` logic hook
publishes events into `crm_events`, the `wpsApi` entry point serves `/api/v9/...` for WPS) is not part of this repository:
it is installed from the separate packages `../suiteCRM7` and `../SuiteCRM8` (`bin/install.sh`).
`scripts/wps_processes.php` imports/exports process sets (`processes/*.processes.json`). See
[CRM integration](../04-operations/crm-integration.md).

## md/

Source requirements in Russian: `doc/technical_specification.md` (specification), `doc/maiInfo.md` (customer answers),
stage plans `back_stap_*.md`, `front_stap_1.md`, `idempotentie_plan.md` (run once), `multi_language_plan.md`,
`moc_eventService.md`, `suite_generate_plugin.md`, and a copy of the SwooleApp framework sources in `SwooleApp/` for reference.

## doc/

```
doc/
├── README.md               Language choice
├── en/ , ru/               Same tree, same file names
├── images/screenshots/{en,ru}/
└── _meta/                  research_notes.md, screenshots_manifest.md, demo_processes/, tools/ (nav.py, shoot.mjs)
```

## Where to change what

| Task | Files |
|---|---|
| New comparison operator | `back/src/Comparators/`, `ComparatorRegistry::default()`, `front/src/locales/*.json` → [guide](extending/new-comparator.md) |
| New action type | `back/src/Actions/`, `Container::actions()` → [guide](extending/new-action-type.md) |
| New change type | `back/src/Actions/ChangeTypes/`, `Container::changeTypes()`, `front/src/types/index.ts` → [guide](extending/new-change-type.md) |
| New load strategy | `back/src/LoadStrategies/`, `LoadStrategyRegistry::default()` → [guide](extending/new-load-strategy.md) |
| New expression function | `Container::expressions()` → [guide](extending/expression-functions.md) |
| New endpoint | `back/src/Controllers/…`, `AccessPolicy`, `back/API.md` → [guide](extending/new-api-endpoint.md) |
| Another CRM | `ExternalApiAdapterService`, or a CRM-side API → [guide](extending/new-crm-adapter.md) |
| New page in the UI | `front/src/views/`, `router/index.ts`, `AppLayout.vue`, `api/` → [guide](extending/new-ui-screen.md) |
| New UI language | `front/src/locales/`, `front/src/i18n/` → [guide](extending/new-language.md) |
| Database change | new `back/migrations/00N_*.sql` (applied on the next backend start) |

<!-- footer-nav -->
---

← Previous: [Development environment](dev-environment.md) | [Contents](../README.md) | [Coding conventions](coding-conventions.md) Next →
<!-- /footer-nav -->
