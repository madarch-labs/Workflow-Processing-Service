# Поток событий

Путь одного события CRM: от сохранения записи до Redis Stream, выполнения подходящих процессов, аудита и подтверждения сообщения.

<!-- lang-switch -->
[🇬🇧 English](../../en/05-architecture/event-flow.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Основной поток

```mermaid
sequenceDiagram
    autonumber
    participant CRM as CRM / мок
    participant RS as redis-stream<br/>crm_events
    participant Job as StreamConsumerJob<br/>StreamService
    participant EP as EventProcessorService
    participant PR as ProcessResolverService
    participant RD as redis-data
    participant EE as ExecutionEngineService
    participant DB as PostgreSQL
    participant AR as ActionHandlerRegistry<br/>обработчики
    participant API as REST API CRM

    CRM->>RS: XADD crm_events * data={"id","module",...}
    loop каждые 0,5 с, в каждом HTTP-воркере
        Job->>RS: XREADGROUP wps_workers {consumer} COUNT 10 BLOCK 2000
    end
    RS-->>Job: сообщения
    Job->>EP: process(messageId, fields)
    EP->>EP: parse(): JSON в data, module или object_type, id
    EP->>PR: resolve(module)
    PR->>RD: GET proc_cache:{module}
    alt нет в кэше
        PR->>DB: активные процессы верхнего уровня ORDER BY priority
        PR->>RD: SET proc_cache:{module} EX 60
    end
    loop каждый процесс по приоритету, со своим контекстом
        EP->>EE: handle(process, context, "stream", ключ idem:{event_id}:{process_id})
        EE->>RD: SET idem:... NX EX 24h
        EE->>EE: условия по object.* (ConditionEngineService)
        opt условие ссылается на переменную предзагрузки
            EE->>AR: строки предзагрузки
            AR->>API: GET /{module}/{id} или поиск по полю
            EE->>EE: повторная проверка условий
        end
        opt у процесса run_once
            EE->>DB: INSERT process_object_runs (захват отметки)
        end
        EE->>DB: INSERT executions (status running)
        EE->>RD: HMSET exec:{uuid}, SET lock:exec:{uuid} NX
        loop каждый шаг: строки предзагрузки, затем действия
            EE->>AR: execute(context, step)
            AR->>API: GET / PUT / POST, если шагу нужна CRM
            EE->>DB: INSERT execution_actions (аудит)
            EE->>RD: HMSET exec:{uuid} current_step, контекст
        end
        EE->>DB: UPDATE executions status, finished_at, context_snapshot
        EE->>RD: статус в exec:{uuid}, EXPIRE 1h, снятие блокировки
    end
    EP-->>Job: status ok
    Job->>RS: XACK crm_events wps_workers id
```

1. CRM публикует весь сохранённый объект (ответ заказчика №2). Плагин SuiteCRM делает это в logic hook `after_save`,
   мок — по запросу `/test-event`. Принимаются два формата: поле `data` с JSON-объектом или поля объекта напрямую.
2. Каждый HTTP-воркер — потребитель группы `wps_workers`; Redis отдаёт каждое сообщение только одному потребителю.
3. `EventProcessorService::parse()` требует `module` (или `object_type`) и `id`. `event_id` берётся из объекта,
   если он там есть, иначе используется id сообщения стрима.
4. `ProcessResolverService` возвращает активные, не удалённые процессы **верхнего уровня** этого типа объекта,
   отсортированные по `priority` по возрастанию. Список кэшируется на `PROCESS_CACHE_TTL` секунд и сбрасывается при
   сохранении процесса. Затем `EventProcessorService` оставляет процессы, у которых `trigger_on` содержит тип события
   (`Process::triggersOn()`; ключ кэша от типа не зависит). Событие с неподдерживаемым `event_type` (`link`, `unlink`)
   подтверждается сразу со статусом `unsupported_event_type`. Лог `Event handled` (debug) пишет `processes_total` и
   `processes_matched`.
5. Для каждого процесса движок строит собственный `ExecutionContext` из события и вызывает `handle()` — подробности в
   разделе [Движок выполнения](execution-engine.md). Если условия процесса не выполнены, выполнение не создаётся,
   остаётся только ключ идемпотентности со значением `skipped`.
6. Сообщение подтверждается после обработки всех процессов — и тогда, когда ни один процесс не подошёл (ответ №23).

### Итог обработки сообщения

`EventProcessorService::process()` возвращает статус, по которому `StreamService::handle()` решает судьбу сообщения:

```mermaid
flowchart TB
    m["сообщение"] --> p{"parse()"}
    p -- "ошибка" --> inv["invalid<br/>wps_events_invalid_total"] --> dlq["XADD crm_events:dlq"] --> ack["XACK"]
    p -- "ок" --> t{"event_type<br/>create / update / delete?"}
    t -- "нет (link, unlink, опечатка)" --> uns["unsupported_event_type<br/>wps_events_unsupported_type_total"] --> ack
    t -- "да" --> r["resolve(module):<br/>активные процессы верхнего уровня"]
    r --> f{"есть процессы с<br/>trigger_on ∋ event_type?"}
    f -- "процессов модуля нет" --> np1["no_processes<br/>wps_events_skipped_total"] --> ack
    f -- "есть, но не на этот тип" --> np2["no_processes<br/>wps_events_skipped_by_type_total"] --> ack
    f -- "да" --> run["handle() каждого процесса"] --> ok["ok<br/>processed_total, если started > 0,<br/>иначе skipped_total"] --> ack
    run -- "исключение" --> pend["нет XACK: pending,<br/>Stream message processing failed"]
```

| Статус | Когда | Сообщение |
|---|---|---|
| `invalid` | Нет `module`/`object_type` или `id`; `data` — не JSON-объект | В DLQ, подтверждается |
| `unsupported_event_type` | `event_type` не `create`/`update`/`delete` | Подтверждается без запуска процессов |
| `no_processes` | У модуля нет активных процессов верхнего уровня или ни один не подписан на тип события | Подтверждается |
| `ok` | Процессы запущены (или пропущены условиями, `run_once`, идемпотентностью); `started` — сколько выполнений создано | Подтверждается |
| — (исключение) | Недоступны Redis / PostgreSQL, ошибка вне выполнения | Остаётся в pending, через `REDIS_STREAM_CLAIM_IDLE_MS` забирается `XAUTOCLAIM` |

`trigger_on` фильтрует только события **стрима**. Ручной запуск (`POST /api/processes/{id}/run`) и `start_process`
запускают процесс независимо от `trigger_on`; тип события ручного запуска берётся из объекта (`event_type`, иначе
`update`, а при `wps_is_update = "0"` — `create`) и виден в `sys.event_type`.

### Конверт события и сведения о событии

`EventProcessorService::parse()` превращает сообщение в `Wps\Events\EventEnvelope` — неизменяемый конверт, общий для стрима
и ручного запуска (будущий вебхук 6.5 использует его же):

| Поле конверта | Что содержит |
|---|---|
| `module`, `object` | модуль и запись **без** служебных ключей `event_type`, `before`, `changed_fields`, `wps_origin` |
| `eventType` | `create` / `update` / `delete`; без `event_type` в событии — `create` при `wps_is_update = "0"`, иначе `update` |
| `unsupportedType` | `event_type` не из `create`, `update`, `delete` — процессы не запускаются |
| `before` | старые значения изменённых полей; `null` — неизвестны, `[]` — известно, что их нет |
| `changedFields` | список изменённых полей; `null` — неизвестно |
| `origin` | `wps_origin` (`execution_id`, `process_id`, `depth`) — для защиты от каскадов (7.5) |
| `legacy` | в событии нет `event_type` (издатель контракта 1.0) |
| `warnings` | отброшенные служебные поля неверного типа — пишутся в лог, считаются в `wps_events_malformed_fields_total` |

Правила вывода недостающих полей — в [Интеграции с CRM](../04-operations/crm-integration.md#как-wps-разбирает-событие).

`ExecutionContext::fromEnvelope()` строит контекст процесса:

- переменная `object` — запись конверта, `object_before` — `before` (метаданные `source: event_before`); `object_before`
  **только для чтения**: `set()`, `setVariable()`, `setClean()`, `setModule()` бросают `ConfigurationException`, а валидатор
  отклоняет такие цели при сохранении процесса (`DefinitionScope::checkWritePath()`, предзагрузка, `save_object`);
- `ExecutionContext::$eventInfo` — неизменяемый `Wps\Context\EventInfo` (`eventType`, `changedFields`, `hasBefore`, `origin`,
  `legacy`, метод `isChanged(field)`: `true` / `false` / `null` — неизвестно). Его используют операторы изменения (3.3),
  системные переменные (5.2.9) и защита от каскадов (7.5).

`event_info` сериализуется вместе с контекстом: в Redis-состоянии выполнения (поле `event_info` хеша, поэтому переживает
`resume()` после падения воркера) и в `executions.context_snapshot`. `forChild()` передаёт ведомому процессу и `object_before`,
и `event_info`. Состояние, сохранённое до 6.1, восстанавливается с `EventInfo` по умолчанию (`legacy: true`).

## Ведомые процессы

```mermaid
sequenceDiagram
    participant EE as ExecutionEngineService<br/>родительское выполнение
    participant SP as StartProcessHandler
    participant DB as PostgreSQL
    participant CO as новая корутина
    participant EC as ExecutionEngineService<br/>выполнение ведомого

    EE->>SP: execute(context, шаг start_process)
    SP->>DB: найти ведомые процессы
    SP->>SP: проверить is_child и depth + 1 <= MAX_CHILD_DEPTH
    alt ведомый процесс неактивен
        SP-->>EE: children: status skipped
    else
        SP->>CO: spawnChild(child, context.forChild())
        SP-->>EE: children: status started
        Note over EE: родитель сразу переходит к следующему шагу
        CO->>EC: handle(child, childContext, "child", parentExecutionId, idem:{parentExec}.{step}:{childId})
        EC->>EC: условия, run_once, шаги и аудит — как обычно
    end
```

Шаг `start_process` завершается, как только ведомые процессы запущены, и никогда их не ждёт (ответ №13). Ведомый
процесс получает копию события родителя и **все** переменные, глубина вложенности увеличивается на единицу (ответ №20).
Ключ идемпотентности включает родительское выполнение и номер шага, поэтому шаг родителя, повторённый после сбоя, не
запустит тот же ведомый процесс дважды. Выполнение ведомого процесса хранит `parent_execution_id` — по нему интерфейс
строит таблицу «Дочерние выполнения».

## Некорректные события и сбои

```mermaid
flowchart TB
    msg["Сообщение стрима"] --> parse{"parse(): data — JSON-объект,<br/>есть module и id?"}
    parse -- "нет" --> dlq["XADD crm_events:dlq<br/>original_id, reason, fields"] --> ack1["XACK"]
    parse -- "да" --> run["Подбор и запуск процессов"]
    run -- "обработано, даже если выполнения упали" --> ack2["XACK"]
    run -- "исключение: Redis или БД недоступны" --> pending["Не подтверждено:<br/>остаётся в списке pending"]
    pending --> claim["RecoveryJob каждые 30 с:<br/>XAUTOCLAIM, простой > REDIS_STREAM_CLAIM_IDLE_MS"]
    claim --> deliveries{"доставлено больше 5 раз?"}
    deliveries -- "нет" --> run
    deliveries -- "да" --> dlq2["XADD crm_events:dlq"] --> ack3["XACK"]
```

Упавшее **выполнение** (например, CRM вернула HTTP 500 после всех повторов) — нормальный исход: оно записано в аудит, а
сообщение подтверждается. Неподтверждённым сообщение остаётся только при **инфраструктурной** ошибке, которая помешала
обработке, — тогда оно будет доставлено ещё раз. Это гарантия «хотя бы один раз» (at-least-once, ответ №22). Повторная
доставка безопасна, потому что каждый запуск процесса защищён ключом идемпотентности `idem:{event_id}:{process_id}`.
Сообщения, которые не удалось разобрать, и сообщения, доставленные больше пяти раз, попадают в стрим недоставленных
сообщений `crm_events:dlq` (он обрезается примерно до 10 000 записей).

## Ручной запуск

`POST /api/processes/{id}/run` входит в движок иначе: `ExecutionEngineService::runManual()` проверяет условия
синхронно (или пропускает их при `force: true`), учитывает `run_once`, создаёт выполнение и запускает шаги в фоновой
корутине. HTTP-ответ с `execution_id` приходит сразу; дальше интерфейс опрашивает `GET /api/executions/{id}`, который,
пока выполнение идёт, отдаёт живой контекст из Redis. См. [Ручной запуск и сброс](../03-user-guide/manual-run-and-reset.md).

<!-- footer-nav -->
---

← Назад: [Архитектура backend](backend.md) | [Оглавление](../README.md) | [Движок выполнения](execution-engine.md) Далее →
<!-- /footer-nav -->
