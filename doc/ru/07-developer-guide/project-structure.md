# Структура проекта

Карта репозитория с пояснениями: где лежит каждая часть WPS и что менять в типичных задачах.

<!-- lang-switch -->
[🇬🇧 English](../../en/07-developer-guide/project-structure.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Верхний уровень

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

| Файл | Назначение |
|---|---|
| `mock_server.php` | Маршрутизатор: `/test-event`, `/modules`, `/health`, `/mock/objects/{module}/{id}`, `/api/v8/{module}[/{id}]` |
| `MockEventService.php` | Генерирует событие, сохраняет объект в мок-CRM, `XADD crm_events * data '{json}'` |
| `MockCrmStorage.php` | Объекты в Redis (чтение, запись, поиск по полю) |
| `MockModulesList.php` | 15 модулей с русскими названиями и сгенерированными полями |

## .tehnical/

`suiteCRM7/` и `suiteCRM8/` — Docker-стенды чистой SuiteCRM. Плагин интеграции (logic hook `after_save` публикует события
в `crm_events`, точка входа `wpsApi` отдаёт WPS API `/api/v9/...`) в репозиторий не входит: он ставится из отдельных пакетов
`../suiteCRM7` и `../SuiteCRM8` (`bin/install.sh`).
`scripts/wps_processes.php` импортирует и экспортирует наборы процессов (`processes/*.processes.json`). Подробнее —
в разделе [Интеграция с CRM](../04-operations/crm-integration.md).

## md/

Исходные требования на русском: `doc/technical_specification.md` (ТЗ), `doc/maiInfo.md` (ответы заказчика),
планы этапов `back_stap_*.md`, `front_stap_1.md`, `idempotentie_plan.md` (однократный запуск), `multi_language_plan.md`,
`moc_eventService.md`, `suite_generate_plugin.md`, а также копия исходников фреймворка SwooleApp в `SwooleApp/` для справки.

## doc/

```
doc/
├── README.md               Language choice
├── en/ , ru/               Same tree, same file names
├── images/screenshots/{en,ru}/
└── _meta/                  research_notes.md, screenshots_manifest.md, demo_processes/, tools/ (nav.py, shoot.mjs)
```

## Что где менять

| Задача | Файлы |
|---|---|
| Новый оператор сравнения | `back/src/Comparators/`, `ComparatorRegistry::default()`, `front/src/locales/*.json` → [инструкция](extending/new-comparator.md) |
| Новый тип действия | `back/src/Actions/`, `Container::actions()` → [инструкция](extending/new-action-type.md) |
| Новый тип изменения | `back/src/Actions/ChangeTypes/`, `Container::changeTypes()`, `front/src/types/index.ts` → [инструкция](extending/new-change-type.md) |
| Новая стратегия загрузки | `back/src/LoadStrategies/`, `LoadStrategyRegistry::default()` → [инструкция](extending/new-load-strategy.md) |
| Новая функция выражений | `Container::expressions()` → [инструкция](extending/expression-functions.md) |
| Новый эндпоинт | `back/src/Controllers/…`, `AccessPolicy`, `back/API.md` → [инструкция](extending/new-api-endpoint.md) |
| Другая CRM | `ExternalApiAdapterService` или API на стороне CRM → [инструкция](extending/new-crm-adapter.md) |
| Новая страница UI | `front/src/views/`, `router/index.ts`, `AppLayout.vue`, `api/` → [инструкция](extending/new-ui-screen.md) |
| Новый язык UI | `front/src/locales/`, `front/src/i18n/` → [инструкция](extending/new-language.md) |
| Изменение БД | новый `back/migrations/00N_*.sql` (применяется при следующем старте бэкенда) |

<!-- footer-nav -->
---

← Назад: [Среда разработки](dev-environment.md) | [Оглавление](../README.md) | [Соглашения о коде](coding-conventions.md) Далее →
<!-- /footer-nav -->
