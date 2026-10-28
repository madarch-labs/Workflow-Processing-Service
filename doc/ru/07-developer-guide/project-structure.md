# Структура проекта

Карта репозитория с пояснениями: где лежит каждая часть WPS и что менять в типичных задачах.

<!-- lang-switch -->
[🇬🇧 English](../../en/07-developer-guide/project-structure.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Верхний уровень

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

| Сервис Compose | Порт на хосте (по умолчанию) | Назначение |
|---|---|---|
| `backend` | `WPS_API_PORT` (9501) | API, обработчик событий, фоновые задачи |
| `frontend` | `WPS_UI_PORT` (3000) | production-сборка UI под nginx, `/api/` проксируется в backend |
| `postgres` | `WPS_PG_PORT` (5432) | процессы, выполнения, аудит, ожидания, секреты, журнал побочных эффектов |
| `redis-stream` | `WPS_REDIS_STREAM_PORT` (6379) | шина событий `crm_events` (+ `crm_events:dlq`), данные mock-CRM |
| `redis-data` | `WPS_REDIS_DATA_PORT` (6380) | состояние выполнения `exec:{uuid}`, блокировки, кэш справочников, идемпотентность, очереди `assign` |
| `mock` | `WPS_MOCK_PORT` (9502) | mock-CRM и тестовые цели |
| `mailpit` | `WPS_MAILPIT_PORT` (8025) | тестовый SMTP (`smtp://mailpit:1025`) и веб-интерфейс писем |

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

| Каталог | Что внутри |
|---|---|
| `Kernel/` | `WpsApplication` (маршрутизация, CORS, 404/500), `WpsRoutesCollectionBuilder` (точное сопоставление маршрутов), `Container` (сервисы и реестры одного воркера, `share()`), `Env` (`Env::get/int/bool`) |
| `Config/AppConfig.php` | неизменяемая конфигурация из окружения (с границами значений) |
| `State/` | `StateInitiation`, `WpsState`: `AppConfig` и общая таблица метрик, создаются до fork воркеров |
| `CyclicJobs/` | `StreamConsumerJob` (чтение `crm_events`), `RecoveryJob` (забытые сообщения, зависшие выполнения), `WaitResumeJob` (пробуждение ожидающих, `WAIT_POLL_INTERVAL_MS`) |
| `Controllers/` | один класс на маршрут с `#[Route]`, `BaseController`; подкаталоги `Auth/`, `Users/`, `Processes/`, `Executions/`, `Secrets/`, `Mail/`, `Notify/`, `Dictionaries/` (23 справочника), `Health/`, `Metrics/` |
| `Middleware/AuthMiddleware.php` | JWT + `AccessPolicy` для каждого запроса |
| `Auth/` | `JwtService` (access / refresh), `AccessPolicy` (правила доступа по маршрутам) |
| `Http/` | `ApiException`, `JsonResponder` (конверт ответа), `AuthContext`; `Outbound/` — исходящий HTTP: `OutboundHttpClient`, `EgressGuard` + `CidrList` (защита от SSRF), `JsonPathExtractor`, `Auth/` (`AuthApplier`, `OAuth2TokenProvider`) |
| `Services/` | бизнес-логика: приём событий (`StreamService`, `EventProcessorService`, `ProcessResolverService`), выполнение (`ExecutionEngineService`, `ExecutionStateStore`, `ExecutionHandle`, `AuditService`, `StepRecord`, `PreparedRun`), условия (`ConditionEngineService`, `ConditionResult`, `Conditions/ListOperand`), сохранение (`CrmObjectSaver`), API CRM (`ExternalApiAdapterService`, `DictionaryService`), процессы (`ProcessService`, `ProcessValidatorService`, `Validation/DefinitionScope`, `Validation/ValidationLog`), ошибки шагов (`Errors/ErrorClassifier`, `ErrorInfo`, `ErrorPolicy`, `RetryPolicy`), ожидания (`WaitService`), назначение (`Assign/AssignmentStrategies`, `UserPoolResolver`), `MetricsService`, `StreamInfoService` |
| `Program/` | компилятор определения в плоскую программу (`ProgramCompiler`, `ProgramEmitter`, `Program`, `Instruction`), `Interpreter`, `InstructionOutcome`, блоки (`BlockTypeInterface`, `BlockTypeRegistry`, `BlockValidationContext`, `Blocks/{Condition,StopProcess,Foreach}Block`), циклы (`LoopRunner`, `LoopItemsStore`) |
| `Context/` | `ExecutionContext` (событие, переменные, `meta`, изменённые поля, `sys.*`, `config.*`), `EventInfo` |
| `Events/EventEnvelope.php` | разбор события stream / ручного запуска (контракт 2.0: `event_type`, `before`, `changed_fields`) |
| `Actions/` | `ActionHandlerInterface`, `ResumableActionInterface`, `ActionHandlerRegistry`, `ActionResult` и обработчики: `Preload`, `Modify`, `Save`, `StartProcess`, `SetVariable`, `FindRecords`, `UpdateRecords`, `Delay`, `SendEmail`, `HttpRequest`, `Assign`, `Notify`, `LinkRecords` (и `unlink_records`); `ChangeTypes/` — `ChangeTypeInterface`, `ChangeTypeRegistry` и 13 типов изменения; `Support/RelatedIdsResolver` |
| `Comparators/` | `ComparatorInterface`, `ComparatorRegistry::default()`, базовые классы (`AbstractComparator`, `AbstractOrderComparator`, `AbstractChangeComparator`), 25 операторов, `OperandKind`, `PresenceValue`, `FieldChange` |
| `Types/` | `TypeCaster`, `CastType`, `CastOptions`, `CastResult`, `RelativeDate` |
| `Expression/` | язык выражений: `ExpressionLexer`, `ExpressionParser`, `ExpressionEngine` (реестр функций), `FunctionSpec`, `ValueComparator`, `Decimal`, `SafeRegex`; `Functions/` — `Logic`, `Number`, `Text`, `List`, `Date`, `Type` |
| `Template/` | шаблоны `{{ … }}`: `TemplateParser`, `TemplateEngine`, `TemplateValidator`, `EscapeMode`, `MissingPolicy` |
| `Time/` | `Clock` (`SystemClock`, `FrozenClock` для тестов), `DateMath`, `DateUnit`, `Duration`, `ParsedDate` |
| `Crm/` | контракт CRM: `CrmFilter`, `FilterTranslator`, `FilterRulesValidator`, `CrmPage`, `CrmCapabilities` + `CrmCapabilitiesService`, `CrmCallOrigin` |
| `LoadStrategies/` | `LoadStrategyInterface`, `LoadStrategyRegistry`, `ByIdStrategy`, `ByFieldStrategy` |
| `Notify/` | `ChannelInterface`, `ChannelRegistry`, `Channels/{CrmAlert,Telegram,Slack,Teams}Channel`, `Delivery`, `NotifyMessage`, `RecordLinks`, `HttpChannelSupport` |
| `Mail/` | `MailerFactory`, `RecipientResolver`, `RecipientList`, `SmtpErrorClassifier`, `TrackingSmtpTransport`, `MailRateLimiter` |
| `SideEffects/` | журнал внешних вызовов: `SideEffectLedger`, `SideEffectRepository`, `SideEffectStore`, `DeliveryPolicy`, `HasDeliveryPolicy`, `UnknownOutcomeException` |
| `Secrets/` | `SecretService`, `SecretRepository`, `SecretCipher`, `SecretResolver`, `SecretReference` (`secret://`), `SecretKind`, `SecretMasker`, `SecretUsageScanner` |
| `Persistence/` | репозитории PDO (`Process`, `Execution`, `ExecutionAction`, `ExecutionWait`, `ProcessObjectRun`, `User`) и сущности `Process`, `WaitRow` |
| `Exceptions/` | `RecoverableException`, `NonRecoverableException`, `ConfigurationException`, `ExecutionException`, `CrmApiException`, `ConditionCastException` |
| `Infrastructure/` | `Database` (пул PDO), `RedisConnection` (пул Redis) |
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

Редактор UI покрывает базовые шаги (условия, предзагрузка, `modify`, `start_process`); остальные действия и блоки хранятся
как `UnknownAction` и редактируются JSON-редактором — задачи этапа выполнялись на бэкенде.

## mock/

| Файл | Назначение |
|---|---|
| `mock_server.php` | Маршрутизатор (встроенный сервер PHP, порт 9502): `/test-event`, `/modules`, `/health`; API CRM контракта 2.0 под `/api/v8` и `/api/v9` (`X-Auth-Key` = `MOCK_CRM_STATIC_KEY` + `gmdate('Y.m.d H')`): `capabilities`, CRUD, фильтр `filter[f][op]`, сортировка, курсорная пагинация, `count`, связи; помощники без авторизации `/mock/objects/{module}/{id}`, `/mock/relationships/…`, `/mock/config`; цели `http_request` — `/mock/echo`, `/mock/echo-last`, `/mock/status/{code}`, `/mock/redirect`, `/mock/big`, `/mock/text`, `/mock/oauth/token`, `/mock/protected`; мессенджеры `/mock/slack/{id}`, `/mock/teams/{id}`, `/mock/telegram/bot{token}/sendMessage`, журнал `/mock/notify` |
| `MockEventService.php` | Генерирует событие, сохраняет объект в mock-CRM, `XADD crm_events * data '{json}'` |
| `MockCrmStorage.php` | Объекты и связи в Redis (чтение, запись, поиск с операторами фильтра, включая `nin`) |
| `MockModulesList.php` | 15 модулей с русскими названиями и сгенерированными полями |
| `Dockerfile` | образ сервиса `mock` |

Особые id в mock-CRM: `fail500…` — всегда HTTP 500, `fail<N>x500…` — первые N запросов 500, `slow…` — задержка 3 с.

## .tehnical/

Каталог в `.gitignore`, но есть в рабочей копии разработчика:

| Путь | Назначение |
|---|---|
| `suiteCRM8/` | Docker-стенд SuiteCRM 8.10.2 (`wps-suitecrm8-web-1`, `-db-1`, UI http://localhost:8801, API `/api/v9`), `.env` стенда (`WPS_API_STATIC_KEY`, `SUITECRM_ADMIN_*`, `WPS_EVENT_MODULES`) |
| `suiteCRM8/processes/cases/*.json` | исполняемые кейсы задач (`<id>-<name>.json`) |
| `suiteCRM8/scripts/run_case.php` | раннер кейсов (см. [Тестирование](testing.md#кейсы-suitecrm-8)) |
| `suiteCRM8/scripts/seed_case_users.sh` | фикстура: пользователи `wps-case-u1..u4` и группа `wps-case-sales` в БД стенда |
| `suiteCRM8/processes/suiteCRM8.processes.json` | демонстрационные процессы стенда |
| `suiteCRM7/` | стенд SuiteCRM 7 (порт 8701); держится остановленным, запускается для проверок плагина |

Плагин интеграции (logic hook публикует события в `crm_events`, точка входа `wpsApi` отдаёт API `/api/v9/...`) в
репозиторий не входит: пакеты `/home/sidalex/dev/Tasks/SuiteCRM8` и `/home/sidalex/dev/Tasks/suiteCRM7`, установка —
`bin/install.sh`, проверка контракта — `bin/contract_check.sh`. Подробнее — [Интеграция с CRM](../04-operations/crm-integration.md).

## md/

Каталог в `.gitignore`. Исходные требования и планирование на русском:

| Путь | Что |
|---|---|
| `md/tasks_list/README.md` | сводная таблица задач: id, приоритет, этап, зависимости, состояние, план, кейс |
| `md/tasks_list/<id>-*.md`, `done/`, `_excluded/` | планы невыполненных, выполненных и исключённых задач |
| `md/tasks_list/00-architecture.md`, `01-questions.md` | общие архитектурные решения и вопросы с рекомендациями |
| `md/tasks_list/cases/` | описания кейсов SuiteCRM 8 (`<id>-suitecrm8.md`) и их таблица |
| `md/tasks_list/tools/complete_task.py` | закрытие задачи (перенос плана в `done/`, ✅, ссылки) |
| `md/doc/` | ТЗ (`technical_specification.md`), ответы заказчика, концепция интеграционных тестов (`concept_apitest_back.md`) |
| `md/back_stap_*.md`, `front_stap_1.md`, `idempotentie_plan.md`, `multi_language_plan.md` … | ранние планы этапов |
| `md/SwooleApp/` | копия исходников фреймворка SwooleApp для справки |

В PHP-докблоках ссылаются на планы `md/tasks_list/done/<id>-….md`, но не на `doc/*`.

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

## Что где менять

| Задача | Файлы |
|---|---|
| Новый оператор сравнения | `back/src/Comparators/`, `ComparatorRegistry::default()`, `front/src/locales/*.json` → [инструкция](extending/new-comparator.md) |
| Новый тип действия | `back/src/Actions/`, `Container::actions()` → [инструкция](extending/new-action-type.md) |
| Новый блок с вложенными действиями | `back/src/Program/Blocks/`, `Container::blocks()` → [инструкция](extending/new-block-type.md) |
| Новый тип изменения | `back/src/Actions/ChangeTypes/`, `Container::changeTypes()`, `front/src/types/index.ts` → [инструкция](extending/new-change-type.md) |
| Новая стратегия загрузки | `back/src/LoadStrategies/`, `LoadStrategyRegistry::default()` → [инструкция](extending/new-load-strategy.md) |
| Новая функция выражений | `back/src/Expression/Functions/*`, `ExpressionEngine` / `Container::expressions()` → [инструкция](extending/expression-functions.md) |
| Новый эндпоинт | `back/src/Controllers/…`, `AccessPolicy`, `back/API.md`, [справочник API](../06-api-reference/endpoints.md) → [инструкция](extending/new-api-endpoint.md) |
| Другая CRM | `ExternalApiAdapterService` или API на стороне CRM → [инструкция](extending/new-crm-adapter.md) |
| Новый канал уведомлений | `back/src/Notify/Channels/`, `Container::notifyChannels()` → [инструкция](extending/new-notify-channel.md) |
| Новая системная переменная `sys.*` | `ExecutionContext::SYS_FIELDS` и `systemValues()`, подпись в `SystemVariablesController` |
| Новая переменная окружения | `Config/AppConfig.php` (с границами), `back/.env.example`, при необходимости `docker-compose.yml`, [Конфигурация](../04-operations/configuration.md) |
| Новая страница UI | `front/src/views/`, `router/index.ts`, `AppLayout.vue`, `api/` → [инструкция](extending/new-ui-screen.md) |
| Новый язык UI | `front/src/locales/`, `front/src/i18n/` → [инструкция](extending/new-language.md) |
| Изменение БД | новый `back/migrations/0NN_*.sql` (применяется при следующем старте бэкенда) |

<!-- footer-nav -->
---

← Назад: [Среда разработки](dev-environment.md) | [Оглавление](../README.md) | [Соглашения о коде](coding-conventions.md) Далее →
<!-- /footer-nav -->
