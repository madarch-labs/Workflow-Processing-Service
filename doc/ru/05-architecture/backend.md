# Архитектура backend

Как устроен backend на PHP/Swoole: слои, компоненты, модель процессов и точки расширения.

<!-- lang-switch -->
[🇬🇧 English](../../en/05-architecture/backend.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Слои

| Слой | Пространство имён / файл | Назначение |
|---|---|---|
| Запуск | `back/server.php`, `Wps\Kernel\Env` | Читает `.env` (настоящие переменные окружения важнее), переносит переменные в `config/config.json`, включает `SWOOLE_HOOK_ALL`, запускает сервер |
| Ядро | `Wps\Kernel\WpsApplication`, `WpsRoutesCollectionBuilder`, `Container` | Диспетчеризация запросов, точное сопоставление маршрутов, CORS preflight, JSON-ответы 404/500; контейнер сервисов воркера |
| HTTP | `Wps\Controllers\*`, `BaseController`, `Wps\Http\*` | Один класс контроллера на эндпоинт, конверт ответа, `ApiException` |
| Безопасность | `Wps\Middleware\AuthMiddleware`, `Wps\Auth\AccessPolicy`, `JwtService` | Проверка JWT и правила ролей для каждого маршрута |
| Сервисы | `Wps\Services\*` | Обработка событий, проверка условий, движок выполнения, валидация, адаптер CRM, справочники, метрики |
| Реестры расширений | `Wps\Actions`, `Wps\Comparators`, `Wps\Actions\ChangeTypes`, `Wps\LoadStrategies`, `Wps\Expression` | Подключаемые типы действий, операторы, типы изменений, стратегии загрузки, функции выражений |
| Фоновые задачи | `Wps\CyclicJobs\StreamConsumerJob`, `RecoveryJob` | Чтение стрима; восстановление неподтверждённых сообщений и прерванных выполнений |
| Хранение | `Wps\Persistence\*Repository`, `Process` | SQL-доступ к PostgreSQL |
| Инфраструктура | `Wps\Infrastructure\Database`, `RedisConnection` | Корутинные пулы соединений (PDO pgsql, phpredis) |
| Общее состояние | `Wps\State\StateInitiation`, `WpsState` | `AppConfig` и общая таблица метрик, создаются один раз в мастер-процессе |

## Компоненты

```mermaid
flowchart TB
    subgraph kernel["Ядро"]
        server["server.php + Env"]
        app["WpsApplication"]
        routes["WpsRoutesCollectionBuilder"]
        container["Container<br/>на каждый воркер"]
    end
    subgraph http["HTTP"]
        mw["AuthMiddleware + AccessPolicy"]
        ctrl["Контроллеры<br/>наследники BaseController"]
    end
    subgraph jobs["CyclicJobs"]
        consumer["StreamConsumerJob<br/>каждые 0,5 с"]
        recovery["RecoveryJob<br/>каждые 30 с"]
    end
    subgraph services["Сервисы"]
        stream["StreamService"]
        events["EventProcessorService"]
        resolver["ProcessResolverService"]
        engine["ExecutionEngineService"]
        cond["ConditionEngineService"]
        audit["AuditService"]
        statestore["ExecutionStateStore"]
        crm["ExternalApiAdapterService"]
        procsvc["ProcessService + ProcessValidatorService"]
        dict["DictionaryService"]
        metrics["MetricsService"]
    end
    subgraph registries["Реестры — точки расширения"]
        actions["ActionHandlerRegistry"]
        comparators["ComparatorRegistry"]
        changes["ChangeTypeRegistry"]
        strategies["LoadStrategyRegistry"]
        expr["ExpressionEngine"]
    end
    subgraph persistence["Хранение + инфраструктура"]
        repos["Репозитории"]
        db[("Пул Database<br/>PostgreSQL")]
        redis[("Пулы RedisConnection<br/>redis-stream, redis-data")]
    end

    server --> app --> routes --> ctrl
    app --> mw
    ctrl --> container
    consumer --> stream --> events --> resolver
    events --> engine
    recovery --> stream
    recovery --> engine
    engine --> cond --> comparators
    engine --> actions
    engine --> audit
    engine --> statestore
    actions --> changes --> expr
    actions --> strategies
    actions --> crm
    ctrl --> procsvc --> actions
    ctrl --> dict --> crm
    resolver --> repos
    audit --> repos
    procsvc --> repos
    repos --> db
    statestore --> redis
    stream --> redis
    engine --> metrics
```

В бизнес-логику ведут два входа: HTTP-запросы (интерфейс и клиенты API) и циклические задачи, которые читают стрим
событий. Оба получают сервисы из `Wps\Kernel\Container` — ленивого локатора, который создаёт каждый сервис один раз на
воркер. Движок выполнения не знает ни одного конкретного действия: он запрашивает у `ActionHandlerRegistry` обработчик
по `type` шага, а обработчики пользуются остальными реестрами. С PostgreSQL и Redis работают только репозитории и два
класса соединений.

### Путь запроса

1. Swoole вызывает `WpsApplication::execute()`. На запрос `OPTIONS` сразу отдаётся ответ CORS preflight.
2. `WpsRoutesCollectionBuilder` ищет контроллер, шаблон `#[Route]` которого содержит **столько же сегментов URI**
   (штатный механизм SwooleApp сравнивает только длину шаблона, и `/api/processes/5` попал бы в `/api/processes`).
   Совпадения нет — `404 NOT_FOUND` в JSON-конверте.
3. `BaseController::getMiddlewares()` ставит впереди глобальные middleware из `config.json` (`AuthMiddleware`).
   Middleware получает роли через `AccessPolicy::rolesFor(method, path)`, проверяет Bearer JWT и кладёт пользователя
   в `AuthContext`.
4. `BaseController::execute()` вызывает `handle()`; `ApiException` превращается в конверт ошибки, любое другое
   исключение — в `500 INTERNAL_ERROR` (текст ошибки виден только при `APP_DEBUG=true`).

## Модель процессов Swoole

```mermaid
flowchart TB
    master["Мастер-процесс<br/>server.php, StateInitiation:<br/>AppConfig + Swoole Table метрик"]
    master -- "fork" --> w0["HTTP-воркер 0"]
    master -- "fork" --> w1["HTTP-воркер 1"]
    master -- "fork" --> tw["Task-воркер<br/>без циклических задач"]
    subgraph w0box["Внутри каждого HTTP-воркера"]
        c["Container, создаётся после fork<br/>свои пулы БД и Redis"]
        r["Корутины: HTTP-запросы"]
        j["Корутины: StreamConsumerJob, RecoveryJob"]
        e["Корутины: фоновые выполнения,<br/>ведомые процессы"]
    end
    w0 --- w0box
```

- В `config.json` заданы `worker_num: 2` и `task_worker_num: 1` (переменные `SWOOLE_WORKER_NUM`,
  `SWOOLE_TASK_WORKER_NUM`). Циклические задачи работают со стратегией `ALL_WORKERS`: каждый HTTP-воркер запускает
  свой `StreamConsumerJob` и является отдельным потребителем (`{hostname}-{pid}`) группы `wps_workers`. Task-воркер
  циклические задачи не запускает.
- `StateInitiation` выполняется один раз в мастер-процессе до fork: `Swoole\Table` (общие метрики) нужно создать
  раньше, чем появятся воркеры. Так же разделяется разобранный `AppConfig`.
- `Container::get()` сравнивает текущий pid с тем, в котором контейнер был создан, поэтому каждый воркер строит свои
  сервисы и свои пулы соединений (`DB_POOL_SIZE` — 16 соединений PDO, 32 соединения с redis-data, 8 — с redis-stream).
- `SWOOLE_HOOK_ALL` делает PDO, phpredis, сокеты и `sleep` неблокирующими внутри корутин. Медленный вызов CRM или
  пауза перед повтором (`Coroutine::sleep`) приостанавливают только свою корутину; воркер продолжает обслуживать
  HTTP-запросы и другие события.
- Ручной запуск и каждый ведомый процесс выполняются в своей корутине (`Coroutine::create`), их завершения никто не ждёт.

## Точки расширения

```mermaid
classDiagram
    class ActionHandlerInterface {
        <<interface>>
        +type() string
        +label() string
        +description() string
        +execute(ExecutionContext, array) ActionResult
        +validate(array, DefinitionScope, string) void
    }
    class ActionHandlerRegistry {
        +register(ActionHandlerInterface)
        +get(type) ActionHandlerInterface
        +dictionary() array
    }
    ActionHandlerInterface <|.. PreloadHandler
    ActionHandlerInterface <|.. ModifyHandler
    ActionHandlerInterface <|.. SaveHandler
    ActionHandlerInterface <|.. StartProcessHandler
    ActionHandlerRegistry o-- ActionHandlerInterface

    class ComparatorInterface {
        <<interface>>
        +name() string
        +label() string
        +compare(actual, expected) bool
    }
    class ComparatorRegistry {
        +default() ComparatorRegistry
        +register(ComparatorInterface)
        +get(name) ComparatorInterface
    }
    ComparatorInterface <|.. EqualsComparator
    ComparatorInterface <|.. NotEqualsComparator
    ComparatorInterface <|.. ContainsComparator
    ComparatorInterface <|.. NotContainsComparator
    ComparatorRegistry o-- ComparatorInterface

    class ChangeTypeInterface {
        <<interface>>
        +name() string
        +label() string
        +compute(ExecutionContext, array) mixed
        +validate(array, DefinitionScope, string) void
    }
    class ChangeTypeRegistry
    ChangeTypeInterface <|.. SetValueChangeType
    ChangeTypeInterface <|.. SetFromVariableChangeType
    ChangeTypeInterface <|.. CalculateChangeType
    ChangeTypeRegistry o-- ChangeTypeInterface
    CalculateChangeType --> ExpressionEngine

    class LoadStrategyInterface {
        <<interface>>
        +name() string
        +label() string
        +load(api, module, config, lookupValue) array
    }
    class LoadStrategyRegistry {
        +resolve(config) LoadStrategyInterface
    }
    LoadStrategyInterface <|.. ByIdStrategy
    LoadStrategyInterface <|.. ByFieldStrategy
    LoadStrategyRegistry o-- LoadStrategyInterface

    class ExpressionEngine {
        +registerFunction(name, callable)
        +evaluate(expression, resolver) mixed
    }

    ModifyHandler --> ChangeTypeRegistry
    PreloadHandler --> LoadStrategyRegistry
```

Каждая точка расширения — это интерфейс, набор встроенных реализаций и реестр, где ключом служит код из определения
процесса (`type`, `operator`, `change_type`, `strategy`). Реестры заполняются в `Container` (`actions()`,
`comparators()`, `changeTypes()`, `loadStrategies()`, `expressions()`), а эндпоинты справочников
(`/api/dictionaries/*`) читают их, поэтому зарегистрированная реализация сама появляется в списках интерфейса. После
добавления сервис нужно перезапустить (ответы заказчика №25, №26). Пошаговые инструкции — в руководстве разработчика,
например [Новый тип действия](../07-developer-guide/extending/new-action-type.md) и
[Новый оператор сравнения](../07-developer-guide/extending/new-comparator.md).

| Точка расширения | Интерфейс | Встроенные | Где регистрируется |
|---|---|---|---|
| Тип действия | `ActionHandlerInterface` | `preload`, `modify`, `save_object`, `start_process` | `Container::actions()` |
| Оператор сравнения | `ComparatorInterface` | `equals`, `not_equals`, `contains`, `not_contains` | `ComparatorRegistry::default()` |
| Тип изменения | `ChangeTypeInterface` | `set_value`, `set_from_variable`, `calculate` | `Container::changeTypes()` |
| Стратегия загрузки | `LoadStrategyInterface` | `by_id`, `by_field` | `LoadStrategyRegistry::default()` |
| Функция выражения | `callable` | `concat`, `upper`, `lower`, `trim`, `len`, `substr`, `round`, `abs`, `min`, `max` | `ExpressionEngine::registerDefaultFunctions()` / `Container::expressions()` |

## Ошибки

Исключение, брошенное во время выполнения шага, определяет дальнейшие действия движка:

| Класс | Смысл | Поведение движка |
|---|---|---|
| `RecoverableException` | Временный сбой (таймаут CRM, 5xx, сеть) | Повтор с экспоненциальной паузой |
| `CrmApiException` | HTTP-ошибка CRM; восстановимая для транспортных ошибок и 5xx, иначе нет | Зависит от флага |
| `NonRecoverableException` | Постоянная ошибка (неверный модуль, 4xx от CRM, предел вложенности) | Шаг `failed`, выполнение `failed` |
| `ConfigurationException` | Неверная настройка (неизвестный тип, плохой путь) | Как невосстановимая |
| `RedisException`, ошибки соединения PDO | Потеряно соединение с инфраструктурой | Считается восстановимой |

Подробности — в разделе [Движок выполнения](execution-engine.md).

<!-- footer-nav -->
---

← Назад: [Обзор архитектуры](overview.md) | [Оглавление](../README.md) | [Поток событий](event-flow.md) Далее →
<!-- /footer-nav -->
