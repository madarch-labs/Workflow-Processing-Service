# Движок выполнения

Как `ExecutionEngineService` выполняет один процесс для одного события: контекст выполнения, статусы, повторы, идемпотентность и восстановление после сбоя.

<!-- lang-switch -->
[🇬🇧 English](../../en/05-architecture/execution-engine.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Контекст выполнения

`Wps\Context\ExecutionContext` — память одного выполнения. Каждый шаг читает из неё и пишет в неё.

| Часть | Содержимое |
|---|---|
| `eventId` | `event_id` события (или id сообщения стрима; `manual-{uuid}` для ручного запуска) |
| `event` | Исходный объект из события, не меняется |
| `variables` | `object` (рабочая копия объекта события) и переменные, созданные предзагрузкой или действием `modify` (`v1`, `task`, …). Значение — ассоциативный массив или `null` («загружали, но в CRM не нашли») |
| `meta` | Для каждой переменной: `module` CRM и источник `source` (`event`, `preload`, `created`) |
| `dirty` | Для каждой переменной: поля, изменённые в памяти и ещё не сохранённые в CRM — `save_object` отправляет только их (если не указан `mode: "full"`) |
| `depth` | Уровень вложенности ведомых процессов, ограничен `MAX_CHILD_DEPTH` |

Поля адресуются путями `<переменная>.<поле>`: `object.status`, `v1.rating`, `task.name`. Путь без поля (`v1`)
обозначает переменную целиком и допустим только там, где ожидается объект (`save_object.target`). Для неплоских
объектов работают и вложенные пути, например `v1.address.city`. Путь к отсутствующей переменной или полю даёт
«не найдено»: условие с ним ложно (ответ заказчика №10), выражение получает пустую строку. Запись в переменную,
которой ещё нет, создаёт её как новый объект с источником `created`; при последующем сохранении для неё отправляется
`POST`, а новый `id` записывается в переменную.

Ведомый процесс получает `forChild()`: то же событие и переменные, пустой набор `dirty` и `depth + 1`.

## Жизненный цикл выполнения

```mermaid
flowchart TB
    start(["handle(process, context, trigger)"]) --> idem{"SET idem:{event}:{process} NX"}
    idem -- "ключ уже есть" --> dup["продолжить существующее выполнение<br/>или вернуть duplicate"]
    idem -- "ключ захвачен" --> cond["Проверка условий по object.*"]
    cond -- "неизвестно: строка ссылается на переменную" --> lazy["Выполнить блок предзагрузки,<br/>проверить условия ещё раз"]
    cond -- "ложь" --> skip["idem = skipped, выполнения нет"]
    lazy -- "ложь" --> skip
    lazy -- "шаг предзагрузки упал" --> begin
    cond -- "истина" --> once
    lazy -- "истина" --> once{"run_once?"}
    once -- "отметка уже есть" --> blocked["blocked_count + 1, выполнения нет"]
    once -- "флага нет или отметка захвачена" --> begin["begin: INSERT executions,<br/>exec:{uuid} в Redis, блокировка"]
    begin --> steps["Шаги по порядку:<br/>строки предзагрузки, затем действия"]
    steps -- "все шаги success или skipped" --> done["finish: completed"]
    steps -- "шаг упал" --> rest["skipRemaining: следующие шаги skipped"] --> fail["finish: failed"]
```

- Условия проверяются в два прохода (**ленивая предзагрузка**). Сначала проверяются строки по `object.*`, без обращений
  к CRM. Если результат зависит от строки, которая ссылается на переменную предзагрузки, выполняется блок `preload`, и
  условия проверяются заново. Выполненные здесь шаги предзагрузки записываются как первые шаги выполнения и повторно не
  выполняются.
- Если шаг предзагрузки падает во время проверки условий, выполнение всё равно создаётся и сразу завершается со
  статусом `failed` — так проблема видна в аудите.
- Отметка `run_once` захватывается, только когда условия выполнены, и до записи строки выполнения. Если записать
  выполнение не удалось, отметка снимается.
- Шаги — это `Process::steps()`: все строки `definition.preload` (как `type: preload`), за ними `definition.actions`.
  Они выполняются строго друг за другом (ответ №13).
- `finish()` записывает в PostgreSQL статус, счётчик повторов и итоговый `context_snapshot` (событие, переменные, meta,
  трассировку условий), а хеш в Redis оставляет на `EXECUTION_FINISHED_TTL_SECONDS` (1 ч).

## Статусы выполнения

```mermaid
stateDiagram-v2
    [*] --> running: begin
    running --> retrying: восстановимая ошибка, попытки есть
    retrying --> running: после паузы
    running --> completed: все шаги выполнены
    running --> failed: шаг упал
    retrying --> failed: попытки исчерпаны
    running --> failed: RecoveryJob, состояние в Redis потеряно
    completed --> [*]
    failed --> [*]
```

| Статус | Смысл | В интерфейсе (`front/src/utils/format.ts`) |
|---|---|---|
| `running` | Шаги выполняются | синий, обновляется в реальном времени |
| `retrying` | Шаг упал с восстановимой ошибкой и ждёт следующей попытки | оранжевый, обновляется в реальном времени |
| `completed` | Все шаги завершились со статусом `success` или `skipped` | зелёный |
| `failed` | Шаг упал, или выполнение потеряно и не может быть продолжено | красный |

## Статусы шагов

```mermaid
stateDiagram-v2
    [*] --> executing
    executing --> success: обработчик вернул success
    executing --> skipped: обработчик вернул skipped
    executing --> executing: восстановимая ошибка, повтор
    executing --> failed: невосстановимая ошибка или попытки исчерпаны
    [*] --> skipped: предыдущий шаг упал
```

| Статус | Когда |
|---|---|
| `success` | Обработчик отработал штатно |
| `skipped` | Обработчик решил, что делать нечего (`save_object` для пустой переменной или без изменённых полей), либо предыдущий шаг упал и этот не выполнялся |
| `failed` | Ошибка невосстановимая или все повторы использованы |

Неактивный ведомый процесс не делает шаг `start_process` пропущенным: шаг получает `success`, а ведомый процесс
указывается со `status: skipped` в `result_data.children`.

Каждый шаг — одна строка в `execution_actions` с полями `attempts`, `duration_ms`, `request_data` (что шаг отправил),
`result_data` (что получил: старое и новое значение, ответ CRM, класс ошибки) и `error_message`.

## Выполнение шага, повторы и ошибки

```mermaid
flowchart TB
    s(["executeStep(index, step)"]) --> lock["Продлить lock:exec:{uuid}"]
    lock --> snap["Снимок variables, meta, dirty"]
    snap --> exec["ActionHandlerRegistry.get(type).execute()"]
    exec -- "успех" --> rec["StepRecord success / skipped"]
    exec -- "исключение" --> restore["Восстановить снимок"]
    restore --> kind{"восстановимая?"}
    kind -- "да и attempts <= ACTION_RETRY_MAX" --> wait["статус retrying,<br/>пауза base x backoff ^ (attempt - 1)"]
    wait --> lock
    kind -- "нет или попытки исчерпаны" --> failrec["StepRecord failed<br/>error_class, recoverable, details"]
    rec --> audit["INSERT execution_actions,<br/>current_step и контекст в Redis"]
    failrec --> audit
```

- **Восстановимые**: `RecoverableException`, `CrmApiException` при транспортных ошибках (нет соединения, таймаут,
  сброс) и HTTP ≥ 500, `RedisException`, ошибки соединения PDO.
- **Невосстановимые**: `NonRecoverableException`, `ConfigurationException`, `CrmApiException` при HTTP 4xx (кроме
  404 на `GET` — это «не найдено», результат `null`) и любые другие исключения.
- Со значениями по умолчанию (`ACTION_RETRY_MAX=3`, `ACTION_RETRY_BASE_DELAY_MS=1000`, `ACTION_RETRY_BACKOFF=2`) шаг
  пробуется 4 раза с паузами 1, 2 и 4 с. На демонстрационном скриншоте упавшего выполнения видно именно это:
  4 попытки примерно за 7 с.
- Перед каждым повтором восстанавливается снимок, поэтому неидемпотентное изменение вроде `x = x * 2` не применится
  дважды.

## Восстановление после сбоя

```mermaid
flowchart TB
    job(["RecoveryJob каждые 30 с"]) --> claim["XAUTOCLAIM неподтверждённых сообщений<br/>простой > REDIS_STREAM_CLAIM_IDLE_MS"]
    job --> stale["выполнения running или retrying,<br/>начатые раньше RECOVERY_STALE_SECONDS назад"]
    stale --> state{"exec:{uuid} есть в Redis?"}
    state -- "нет" --> lost["markLost: failed,<br/>'Execution context expired or lost'"]
    state -- "да" --> lk{"SET lock:exec:{uuid} NX"}
    lk -- "занята другим воркером" --> later["пропустить до следующего раза"]
    lk -- "захвачена" --> resume["resume(): восстановить контекст,<br/>продолжить с current_step"]
```

Контекст и `current_step` записываются в Redis после каждого шага, поэтому выполнение, прерванное падением воркера,
продолжается с того шага, на котором остановилось (ответы №14, №22). Блокировка `lock:exec:{uuid}` (TTL
`EXECUTION_LOCK_TTL`, 30 с, продлевается перед каждой попыткой) гарантирует, что выполнением занимается только один
воркер. Выполнения, запущенные сообщением стрима, дополнительно восстанавливаются при повторной доставке самого
сообщения: `handle()` находит ключ идемпотентности и вызывает `resume()` вместо нового запуска процесса. У ручных и
ведомых выполнений сообщения стрима нет, их восстанавливает только `RecoveryJob`.

## Ключи Redis, которые использует движок

Все ключи хранятся в **redis-data**.

| Ключ | Тип | Содержимое | TTL |
|---|---|---|---|
| `exec:{uuid}` | Hash | `status`, `current_step`, `retry_count`, `trigger`, `parent_execution_id`, `process`, `definition`, `conditions`, `event`, `variables`, `meta`, `dirty`, `depth`, `started_at`, `finished_at` | `EXECUTION_TTL_HOURS` (24 ч) во время выполнения, `EXECUTION_FINISHED_TTL_SECONDS` (1 ч) после завершения |
| `lock:exec:{uuid}` | String | токен владельца | `EXECUTION_LOCK_TTL` (30 с), продлевается на каждом шаге |
| `idem:{event_id}:{process_id}` | String | id выполнения или `skipped` | `IDEMPOTENCY_TTL_HOURS` (24 ч) |
| `idem:{parent_execution_id}.{step}:{child_id}` | String | то же для ведомых процессов | `IDEMPOTENCY_TTL_HOURS` |

См. также [Модель данных](data-model.md) — таблицы PostgreSQL — и [Надёжность](../04-operations/reliability.md) —
эксплуатационная сторона.

<!-- footer-nav -->
---

← Назад: [Поток событий](event-flow.md) | [Оглавление](../README.md) | [Модель данных](data-model.md) Далее →
<!-- /footer-nav -->
