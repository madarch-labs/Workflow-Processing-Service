# Выполнения и аудит

Где посмотреть каждый запуск процесса, как читать страницу выполнения и как найти причину ошибки.

<!-- lang-switch -->
[🇬🇧 English](../../en/03-user-guide/executions-and-audit.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Что записывается

При каждом запуске процесса WPS создаёт **выполнение** (запуск для одного события) и пишет в аудит каждый **шаг**
(одну строку предзагрузки или одно действие):

- выполнение: процесс и его версия, источник запуска, id события, объект, статус, число повторов, ошибка, время, а также
  итоговый контекст (событие, переменные, результаты условий);
- каждый шаг: тип действия, статус, число попыток, время начала, длительность, `request_data` (что шаг сделал или
  отправил) и `result_data` (что он получил или изменил), текст ошибки, а также положение шага в программе:
  - `step_index` — порядковый номер записи в аудите выполнения;
  - `pc` — номер инструкции программы (для линейного процесса совпадает со `step_index`);
  - `step_path` — место шага в определении: `preload[0]`, `actions[2]`, `actions[1].branches[0].actions[0]`,
    `actions[1].else[0]`;
  - `iteration` — номер итерации цикла [`foreach`](actions.md#цикл-для-каждого-элемента) с нуля, во вложенных циклах —
    индексы от внешнего к внутреннему (`"2/0"`); пусто вне циклов;
  - `step_id` — пользовательский `id` шага, если он задан (см. [Действия](actions.md#вложенные-блоки-и-поля-шага));
  - `error_code` — код ошибки упавшего шага (`HTTP_503`, `TIMEOUT`, `CONFIG`, `UNKNOWN_OUTCOME` …);
  - `handled` — как обработана ошибка: `continue`, `goto` (по `on_error`), `item_continue` (цикл с
    `on_item_error: continue` перешёл к следующему элементу) или пусто (ошибка остановила выполнение), см.
    [Действия → Ошибки шага](actions.md#ошибки-шага-on_error-и-повторы);
- у выполнения также `handled_errors` — число ошибок шагов, обработанных `on_error` или `on_item_error: continue`.

> **Примечание**
> Событие, для которого условия процесса **не выполнены**, выполнения не создаёт и в журнал не попадает. То же
> относится к запуску, заблокированному **однократным запуском для записи**. Чтобы понять, почему процесс не
> запустился, используйте [ручной запуск](manual-run-and-reset.md): он показывает результат каждого условия.

## Журнал выполнения процесса

Откройте карточку процесса и прокрутите её до вкладки **Журнал выполнения**.

![Журнал выполнения с фильтрами по статусу и периоду](../../images/screenshots/ru/10-process-executions.png)

*Журнал процесса #1: два завершённых запуска по событиям CRM.*

| Колонка | Значение |
|---|---|
| **ID** | Короткий id выполнения; нажмите на строку, чтобы открыть выполнение |
| **ID события** | id сообщения Redis Stream (`1792050605348-0`) или `manual-<uuid>` для ручного запуска |
| **Объект** | id записи CRM из события |
| **Источник** | **Событие CRM** (`stream`), **Ручной запуск** (`manual`), **Дочерний** (`child`) |
| **Статус** | **Выполняется**, **Повтор**, **Ожидает**, **Завершено**, **Ошибка**, **Отменено** |
| **Начат** / **Завершён** / **Длительность** | Время выполнения |
| **Retry** | Сколько раз повторялись шаги этого выполнения (подпись колонки одинакова на обоих языках) |

Фильтры и кнопки:

- **Статус** — показать выполнения только с выбранным статусом: **Выполняется**, **Повтор**, **Ожидает**,
  **Отменено**, **Завершено**, **Ошибка**; крестик в поле сбрасывает фильтр;
- **Начало периода** — **Конец периода** — период по времени начала выполнения (дата и время; конец дня по умолчанию
  23:59:59);
- **Обновить** (над таблицей) — перезагрузить список: журнал не обновляется сам, новые запуски появляются после нажатия;
- внизу — общее число записей, размер страницы (10, 20, 50, 100) и переключатель страниц.

Нажатие на любую ячейку строки открывает страницу выполнения. Наведите курсор на короткий **ID**, чтобы увидеть полный
UUID.

![Журнал выполнения с ожидающим выполнением](../../images/screenshots/ru/19-executions-list-statuses.png)

*Процесс с действием `delay` (в карточке — JSON) и его журнал: выполнение в статусе «Ожидает».*

Как читать журнал:

| Видите | Значит |
|---|---|
| Много **Ошибка** подряд | Сломалось что-то общее: CRM, секрет, настройка шага. Откройте последнее выполнение |
| **Ожидает** | Процесс стоит на паузе `delay` или ждёт длинного повтора — это нормальное состояние, см. [ниже](#ожидающие-выполнения) |
| **Отменено** | Ожидающее выполнение отменено удалением процесса |
| **Завершено** с ненулевым **Retry** | Шаги падали временными ошибками, но повтор прошёл |
| Источник **Дочерний** | Выполнение запущено родительским процессом; ссылка на родителя — на странице выполнения |
| ID события `manual-…` | Ручной запуск |

Список отсортирован от новых к старым и разбит на страницы. Те же данные доступны через API:

```bash
curl -s "http://localhost:9501/api/processes/1/executions?status=failed&date_from=2026-10-15T00:00:00Z&page=1&per_page=20" \
  -H "Authorization: Bearer $TOKEN"
```

### Выполнения всех процессов (только API)

Общего журнала по всем процессам в интерфейсе нет — только у каждого процесса. Через API можно искать по всем процессам
сразу, например все упавшие за сутки или все ожидающие:

```bash
curl -s "http://localhost:9501/api/executions?status=failed&date_from=2026-10-27T00:00:00Z&per_page=50" -H "Authorization: Bearer $TOKEN"
curl -s "http://localhost:9501/api/executions?process_id=12&status=waiting" -H "Authorization: Bearer $TOKEN"
```

Параметры: `status`, `process_id`, `date_from`, `date_to`, `due_before` (для ожидающих — срок не позже даты), `page`,
`per_page`. Неверный статус или дата — `422`.

## Статусы выполнения

```mermaid
stateDiagram-v2
    [*] --> running: условия выполнены
    running --> retrying: восстановимая ошибка, повторы не исчерпаны
    retrying --> running: после задержки
    running --> waiting: пауза delay или длинная задержка повтора
    waiting --> running: срок наступил (WaitResumeJob)
    waiting --> cancelled: процесс удалён с force=true
    running --> completed: все шаги выполнены
    running --> failed: невосстановимая ошибка или повторы исчерпаны
    completed --> [*]
    failed --> [*]
    cancelled --> [*]
```

| Статус | В интерфейсе | Значение |
|---|---|---|
| `running` | **Выполняется** | Шаги выполняются |
| `retrying` | **Повтор** | Шаг завершился временной ошибкой (таймаут CRM, 5xx от CRM, сеть); WPS ждёт и повторяет его |
| `waiting` | **Ожидает** | Выполнение приостановлено до срока `waiting_until` (пауза `delay` или длинная задержка повтора); воркер свободен, состояние хранится в PostgreSQL |
| `completed` | **Завершено** | Все шаги выполнены; ошибки шагов, обработанные `on_error` (`continue` / `goto`) или циклом с `on_item_error: continue`, видны в `handled_errors` |
| `failed` | **Ошибка** | Шаг завершился ошибкой (с `on_error: stop`, по умолчанию), остальные шаги не выполнялись |
| `cancelled` | **Отменено** | Ожидающее выполнение отменено: процесс удалён с `?force=true` (`error_message` «Process deleted») |

Статусы шагов:

| Статус | В интерфейсе | Значение |
|---|---|---|
| `success` | **Успех** | Шаг выполнен |
| `failed` | **Ошибка** | Шаг завершился ошибкой, см. колонку **Ошибка** |
| `skipped` | **Пропущен** | Шаг не выполнялся: предыдущий шаг упал, сохранять было нечего или переменная для сохранения пуста |
| `waiting` | **Ожидает** | Шаг приостановил выполнение (`result_data.wait = {due_at, reason}`); после пробуждения та же запись получает итоговый статус |

### Ожидающие выполнения

У выполнения в статусе `waiting` есть поля `waiting_until` (срок, UTC) и `wait_reason` (`delay` — пауза, `retry` —
длинная задержка повтора). `GET /api/executions/{id}` для такого выполнения отдаёт ещё объект `wait`
(`due_at`, `reason`, `attempts` — попытки пробуждения, `info`), а контекст берёт из сохранённого снимка ожидания:

```json
{ "id": "…", "status": "waiting", "waiting_until": "2026-10-19T09:00:00+00:00", "wait_reason": "delay",
  "current_step": 1,
  "wait": { "due_at": "2026-10-19T09:00:00+00:00", "reason": "delay", "attempts": 0,
            "info": { "pc": 1, "seq": 1, "mode": "after_wait", "parked_at": "2026-10-16T09:00:00+00:00" } },
  "actions": [ { "step_index": 0, "action_type": "modify", "status": "success" },
               { "step_index": 1, "action_type": "delay", "status": "waiting", "result_data": { "wait": { "due_at": "…", "reason": "delay" } } } ] }
```

После пробуждения запись шага паузы получает статус `success` с `waited: true`, `waited_seconds` и `lateness_ms`
(насколько позже срока выполнение продолжилось), дальше идут следующие шаги. Шаг повтора (`retry`) после пробуждения
выполняется заново и перезаписывает ту же запись аудита.

![Ожидающее выполнение](../../images/screenshots/ru/20-execution-detail-waiting.png)

*Выполнение, остановленное на `delay`: статус «Ожидает», шаги до паузы выполнены.*

В интерфейсе ожидающее выполнение выглядит так: статус **Ожидает** в заголовке и свойствах, шаги до паузы —
**Успех**, шаг `delay` — **Ожидает** (срок — в его **Результате**: `wait.due_at`), следующих шагов ещё нет,
**Завершено** — «—». Страница ожидающего выполнения **сама не обновляется** (автообновление — только у
**Выполняется** и **Повтор**): после срока нажмите **Обновить**. Поля `waiting_until`, `wait_reason` и объект `wait`
на странице не показываются — они есть в ответе API.

Найти все ожидающие выполнения (по всем процессам):

```bash
curl -s "http://localhost:9501/api/executions?status=waiting&due_before=2026-10-20T00:00:00Z&page=1&per_page=50" \
  -H "Authorization: Bearer $TOKEN"
```

В журнале процесса фильтр **Статус** тоже принимает `waiting` и `cancelled`. Удаление процесса с ожидающими
выполнениями отклоняется (`409 PROCESS_HAS_WAITING_EXECUTIONS`), с `?force=true` (admin) они отменяются.

## Страница выполнения

Нажмите на строку журнала (или на ссылку в **Дочерних выполнениях**), чтобы открыть `/executions/<id>`.

![Завершённое выполнение: свойства, два успешных шага и контекст](../../images/screenshots/ru/11-execution-detail-success.png)

*Завершённое выполнение процесса #1: `preload` загрузил контрагента, `modify` выставил рейтинг и сохранил его в CRM.*

Страница состоит из четырёх частей.

1. **Заголовок** — короткий id, статус, кнопки **К процессу** и **Обновить**. Пока выполнение в статусе
   **Выполняется** или **Повтор**, страница обновляется сама каждые 2 секунды и показывает `live · обновлено <время>`.
2. **Свойства** — **ID выполнения**, **Процесс** (с версией на момент запуска), **Статус**, **Источник**,
   **Родительское выполнение** (для ведомого процесса), **Кол-во повторов (retry)**, **ID события**, **Объект**,
   **Длительность**, **Начато**, **Завершено**. Красный баннер **Ошибка выполнения** показывает текст ошибки.
3. **Шаги выполнения** — по строке на шаг: **#**, **Тип действия**, **Статус**, **Попыток**, **Начат**,
   **Длительность**, **Запрос**, **Результат**, **Ошибка**. Раскройте строку, чтобы увидеть полный JSON
   **Запрос (request_data)** и **Результат (result_data)**.
4. **Контекст выполнения** — три вкладки:
   - **Событие (event)** — объект в том виде, в каком он пришёл из CRM;
   - **Переменные (variables)** — `object` и все переменные после последнего шага (для идущего выполнения — живой
     контекст из Redis с пометкой **живой контекст из Redis**);
   - **Условия (conditions)** — каждое условие с фактическим и ожидаемым значением и результатом.

У родительского процесса таблица **Дочерние выполнения** перечисляет запущенные им ведомые процессы (см.
[Ведомые процессы](subprocesses.md)).

![Значения sys.* в результате шага](../../images/screenshots/ru/26-execution-context-system.png)

*Раскрытый шаг с шаблоном: подставленные значения `sys.*`; ниже — вкладки контекста (event, variables, conditions).*

Что есть в API, но не показывается на странице:

| Поле | Где взять | Зачем |
|---|---|---|
| `step_path`, `pc`, `iteration`, `step_id`, `error_code`, `handled` шага | `GET /api/executions/{id}/actions` | Найти шаг в определении, номер итерации цикла, код ошибки и как она обработана |
| `handled_errors` выполнения | `GET /api/executions/{id}` | Сколько ошибок шагов обработано `on_error` / циклом при статусе **Завершено** |
| `waiting_until`, `wait_reason`, `wait` | `GET /api/executions/{id}` | Срок и причина ожидания |
| `context.system` | `GET /api/executions/{id}` | Значения `sys.*` на момент снимка: `execution_id`, `trigger`, `event_type`, `changed_fields`, `user_id`, `errors_count` … |
| `context.event_info` | `GET /api/executions/{id}` | Тип события, изменённые поля, происхождение `wps_origin` |
| журнал побочных эффектов | `GET /api/executions/{id}/side-effects` | Какие письма, запросы и уведомления отправлены, см. [ниже](#журнал-побочных-эффектов) |

Строки шагов внутри цикла и веток на странице идут подряд по `#` (`step_index`) без отступов: номер итерации и место
шага видны только в API. Чтобы увидеть значения `sys.*` в интерфейсе, сохраните их в переменную (`set_variable`) —
она появится во вкладке **Переменные (variables)**.

```bash
curl -s http://localhost:9501/api/executions/<id> -H "Authorization: Bearer $TOKEN" \
  | python3 -c 'import sys,json; d=json.load(sys.stdin)["data"]; print(json.dumps(d["context"]["system"], indent=2, ensure_ascii=False))'
curl -s http://localhost:9501/api/executions/<id>/actions -H "Authorization: Bearer $TOKEN" \
  | python3 -c 'import sys,json; [print(a["step_index"], a["step_path"], a["iteration"], a["action_type"], a["status"], a["error_code"], a["handled"]) for a in json.load(sys.stdin)["data"]]'
```

### Что записывают шаги

| Действие | `request_data` | `result_data` |
|---|---|---|
| `preload` | `var`, `module`, `strategy` (`by_id`/`by_field`), `by_field`, `source`, `value`, `lookup_value` | `found`, `object` (загруженная запись или `null`) |
| `modify` | `target`, `change_type`, `value` (значение или выражение) | `old_value`, `new_value`; при `save_to_crm` ещё `save`; у `aggregate` — `function`, `module` / `link`, `records_scanned`, `pages`, `truncated`, `total`, `skipped_reason` (см. [Агрегат по записям](change-types-and-expressions.md#агрегат-по-записям)) |
| `save_object` | `method` (`PUT`/`POST`), `module`, `id`, отправленные `attributes` | `saved`, `response`, `created_id` для новой записи |
| `start_process` | `process_ids` | `children`: `started` / `skipped` для каждого ведомого процесса |
| `condition` (блок) | `branches` (число веток), `has_else` | `selected` — индекс выбранной ветки, `"else"` или `"none"` (ни одна ветка не подошла и `else` нет); `branches` — для каждой проверенной ветки `branch`, `name`, `result` и трасса условий `conditions` (см. [ниже](#запись-блока-condition)) |
| `stop_process` (блок) | `status`, `code`, `message` (шаблон сообщения) | `stopped: true`, `status`, `code`, `message` после подстановок; `message_error`, если шаблон не удалось вычислить (см. [ниже](#остановленное-выполнение)) |
| `assign` | `target`, `field`, `strategy`, `only_active`, `queue_key`, `save` | `chosen_user_id`, `previous_user_id`, `unchanged`, `reason`, `pool_size`, `pool`, `pool_source`, `inactive`, `excluded`, `counts`, `manager_of`, `fallback`, `reused_decision`, `save` (см. [Назначить ответственного](actions.md#назначить-ответственного)) |
| `notify` | `title`, `message_preview`, `link`, `channels` (без секретов), `delivery` | `channels`: по каналу `status` (`success` / `failed` / `skipped`), `deliveries` (`user`/`record`, `chat_id`/`message_id`, `status`, `replayed`), `error`, `error_code`; `warnings` (см. [Уведомление](actions.md#уведомление)) |
| `update_records` | `list`, `module`, `count`, `set`, `only_changed`, `max` | `updated`, `unchanged`, `skipped_no_id`, `errors`, `items` (до 200: `{id, status, fields, error}`); если записи упали — шаг `failed` («N of M records failed»), та же сводка в `details` (см. [Изменить записи списка](actions.md#изменить-записи-списка)) |
| `foreach` (блок) | `source`, `as`, `max_items`, `on_overflow`, `on_item_error`, `has_where` | в начале — `count`, `truncated`, `source_missing`; по окончании цикла запись перезаписывается итогами: `processed`, `skipped_by_where`, `failed_items`, `failed_indexes`, `unsaved_items`, `where_traces`, при ошибке `failed_at_index` (см. [ниже](#запись-цикла-foreach)) |
| `delay` | `mode` и поля срока, `due_at` (UTC), `due_at_local` | в корутине — `waited: true`, `waited_seconds`, `inline: true`; после парковки — `waited: true`, `lateness_ms`, `waited_seconds`; момент прошёл — `waited: false`, `past_due: true` (см. [Пауза](actions.md#пауза-delay)) |
| шаг с ошибкой | конфигурация шага | `error_class`, `recoverable`, `error_code`, `error` — классифицированная ошибка (`code`, `message`, `transient`, `http_status`, `retry_after`), `details` (например, ответ CRM); при `on_error: {goto}` — `goto` (id цели) |
| `set_variable` | `var`, `fields` (или `target`, `change_type`, `value`) | `fields.<имя>`: `old_value`, `new_value` |
| `find_records` | `var`, `module` / `related_to`, `filter`, `sort`, `limit`, `fields`, `crm_requests` | `count`, `has_more`, `ids` (первые 100), `reason` |
| `link_records` / `unlink_records` | `module`, `record_id`, `link`, `related_module`, `related_ids`, `related_count` | `linked` / `unlinked`, `updated`, `not_found`, `warnings` |
| `send_email` | `to`, `cc`, `bcc`, `from`, `subject`, `text_preview`, `html_size`, `transport` (`MAILER_DSN` или `secret://…`), `message_id` | `sent`, `recipients`, `message_id`, `replayed`; `warnings` (`invalid_recipients`), `crm` (история письма в CRM) |
| `http_request` | `method`, `url` (секреты замаскированы), имена заголовков, `body_size`, `body_preview` (500 символов), `delivery` | `status`, `duration_ms`, `var`, `replayed`, `response` (переменная ответа: `status`, `ok`, `headers`, `body` или `body_preview`, поля `extract`) |
| внешнее действие (`send_email`, `http_request`, `notify`) | что отправлено (секреты замаскированы) | ответ внешней системы, `idempotency_key` — внешний ключ идемпотентности; `replayed: true`, если шаг повторно выполнился после сбоя и ответ взят из журнала побочных эффектов без повторного вызова |

![Шаг http_request](../../images/screenshots/ru/22-execution-detail-http.png)

*Раскрытый шаг `http_request`: запрос и ответ с извлечёнными значениями.*

![Шаг send_email](../../images/screenshots/ru/23-execution-detail-email.png)

*Раскрытый шаг `send_email`: получатели, тема, Message-ID.*

Пример шага `send_email` (рецепт [59](recipes.md#59-крупная-сделка-уведомление-с-данными-выполнения-sys-config)):

```json
{ "action_type": "send_email", "status": "success",
  "request_data": { "to": ["sales-audit@company.ru"], "cc": [], "bcc": [], "from": "wps@wps.local",
    "subject": "[WPS] Deals doc59-17554: сумма 750000", "transport": "MAILER_DSN", "html_size": 0,
    "message_id": "<75d988ca-….1@wps.local>", "text_preview": "Сделка: Big deal\nСумма: 90000 → 750000…" },
  "result_data": { "sent": true, "recipients": 1, "replayed": false, "message_id": "<75d988ca-….1@wps.local>" } }
```

Само письмо на стенде видно в Mailpit (`http://localhost:8025`); `Message-ID` письма совпадает с `message_id` шага.

Шаги невыбранных веток в аудит не попадают. Если шаг упал (и `on_error` — `stop`), `skipped` получают следующие шаги
его списка (например, ветки) и следующие шаги всех объемлющих списков вплоть до верхнего уровня. При `on_error:
continue` упавший шаг остаётся `failed` с `handled: "continue"`, а выполнение идёт дальше; при `goto` шаги между
ошибкой и целью не выполняются и в аудит не пишутся.

### Запись блока condition

Блок `condition` пишет одну запись `condition` на каждое выполнение; шаги выбранной ветки идут следом обычными
записями (`step_path` вида `actions[0].branches[1].actions[0]`), шаги невыбранных веток не пишутся.

```json
{ "step_index": 0, "pc": 0, "step_path": "actions[0]", "step_id": "deal_size", "action_type": "condition", "status": "success",
  "request_data": { "branches": 3, "has_else": false },
  "result_data": { "selected": 1,
    "branches": [
      { "branch": 0, "name": "Больше 1 млн", "result": false,
        "conditions": [ { "field": "object.amount", "operator": "greater_than", "actual": "5000", "expected": "1000000", "result": false } ] },
      { "branch": 1, "name": "Больше 100 тыс. или VIP", "result": true, "conditions": [ … ] } ] } }
```

- `selected` — номер выбранной ветки, `"else"` или `"none"` (ни одна ветка не подошла, `else` нет).
- `branches` — только **проверенные** ветки по порядку: ветки после выбранной не вычисляются и в трассе отсутствуют.
  `name` — подпись ветки, если задана; `conditions` — та же трасса, что у условий запуска (`actual`, `expected`,
  `*_cast`, `missing`, `cast_error`).
- Ошибка в условии ветки (`on_cast_error: "error"`) — запись `condition` со статусом `failed` и трассой упавшей строки.

### Запись цикла foreach

Цикл [`foreach`](actions.md#цикл-для-каждого-элемента) пишет **одну** запись `foreach` (её `step_index` — перед шагами
тела) и обычные записи шагов тела с номером итерации. Когда цикл заканчивается, запись `foreach` перезаписывается
итогами, а `duration_ms` становится длительностью всего цикла:

```json
[ { "step_index": 2, "pc": 2, "step_path": "actions[2]", "step_id": "each_deal", "action_type": "foreach", "status": "success",
    "request_data": { "source": "deals.items", "as": "deal", "max_items": 500, "on_overflow": "fail", "on_item_error": "stop", "has_where": true },
    "result_data": { "count": 3, "processed": 2, "skipped_by_where": 1, "failed_items": 0, "failed_indexes": [],
                     "unsaved_items": 0, "truncated": false, "source_missing": false,
                     "where_traces": [ { "index": 2, "conditions": [ … ] } ] } },
  { "step_index": 3, "step_path": "actions[2].actions[0]", "iteration": "0", "action_type": "modify", "status": "success" },
  { "step_index": 4, "step_path": "actions[2].actions[1]", "iteration": "0", "action_type": "save_object", "status": "success" },
  { "step_index": 6, "step_path": "actions[2].actions[0]", "iteration": "1", "action_type": "modify", "status": "success" } ]
```

![Шаги цикла foreach](../../images/screenshots/ru/21-execution-detail-foreach.png)

*`find_records`, итоговая запись `foreach` и шаги тела цикла для каждого элемента.*

- Элементы, отсеянные `where`, записей не дают: только счётчик `skipped_by_where` и трасса первых 20 в `where_traces`.
- `unsaved_items` — итерации, после которых у переменной элемента остались несохранённые изменения (нет `save_object`).
- `on_item_error: continue`: упавший шаг — `failed` с `handled: "item_continue"`, остаток итерации не пишется, итог
  цикла — `failed_items` и `failed_indexes` (до 50), выполнение `completed`.
- `on_item_error: stop`: запись `foreach` — `failed` с `failed_at_index`, шаги после цикла — `skipped`.
- Цикл, прерванный `stop_process`, получает в итогах `stopped: true`; оставленный переходом `goto` — статус `failed` и
  `left_by: "goto"`.

### Остановленное выполнение

Шаг [`stop_process`](actions.md#завершить-процесс) пишет одну запись `stop_process` со статусом `success`, после неё
оставшиеся шаги получают `skipped` с причиной `Not executed: the process was stopped by stop_process`:

```json
[ { "step_index": 0, "action_type": "condition", "status": "success", "result_data": { "selected": 0, "branches": [ … ] } },
  { "step_index": 1, "step_path": "actions[0].branches[0].actions[0]", "action_type": "stop_process", "status": "success",
    "request_data": { "status": "failed", "code": "NO_CONTACTS", "message": "У лида {{ object.last_name }} нет email" },
    "result_data": { "stopped": true, "status": "failed", "code": "NO_CONTACTS", "message": "У лида Иванов нет email" } },
  { "step_index": 2, "step_path": "actions[1]", "action_type": "modify", "status": "skipped",
    "result_data": { "reason": "Not executed: the process was stopped by stop_process" } } ]
```

- `status: failed` — статус выполнения `failed`, `error_message = "[NO_CONTACTS] У лида Иванов нет email"`;
  `status: completed` — выполнение `completed`, `error_message` пуст.
- В снимке выполнения (`context`) — `stop: { status, code, message }`: так видно, что процесс завершён досрочно,
  даже если статус `completed`.

## Журнал побочных эффектов

Каждый внешний вызов шага — письмо, HTTP-запрос, сообщение в канал уведомления — до отправки записывается в журнал
`step_side_effects`, после отправки отмечается `done` или `failed`. Так повтор шага после падения воркера знает, что
уже отправлено. Журнал выполнения (любая роль; пример — выполнение рецепта [59](recipes.md#59-крупная-сделка-уведомление-с-данными-выполнения-sys-config)):

```bash
curl -s http://localhost:9501/api/executions/<id>/side-effects -H "Authorization: Bearer $TOKEN"
```

```json
[ { "effect_key": "0#0:crm_alert:1", "action_type": "notify", "delivery": "at_most_once", "status": "done",
    "attempts": 1, "started_at": "…", "finished_at": "…", "error_message": null },
  { "effect_key": "0#1:slack:e18739d6…", "action_type": "notify", "delivery": "at_most_once", "status": "done", "attempts": 1 },
  { "effect_key": "1", "action_type": "send_email", "delivery": "at_most_once", "status": "done", "attempts": 1 } ]
```

| Поле | Значение |
|---|---|
| `effect_key` | `{pc}` шага; в цикле — `{pc}@{iteration}`; у шага с несколькими вызовами — суффикс `#{sub}` (у `notify` — номер канала, канал и получатель или хеш адреса: `0#0:crm_alert:1`) |
| `status` | `started` — вызов начат, исход неизвестен (воркер упал посреди отправки); `done` — отправлено; `failed` — внешняя система ответила ошибкой |
| `delivery` | Политика шага: `at_most_once` — при неизвестном исходе не повторять (`UNKNOWN_OUTCOME`), `at_least_once` — повторить |

Ответ внешней системы в журнал не пишется — он в `result_data` шага. Пустой список — внешних вызовов не было.
Подробнее — [Надёжность → Побочные эффекты и повторы](../04-operations/reliability.md#побочные-эффекты-и-повторы).

## Разбор ошибки выполнения

![Выполнение с ошибкой: 4 попытки preload, следующий шаг пропущен](../../images/screenshots/ru/12-execution-detail-failed.png)

*Процесс #5 загружает запись, на которую мок CRM отвечает HTTP 500: три повтора, затем выполнение завершается ошибкой.*

1. Откройте выполнение со статусом **Ошибка**. В баннере **Ошибка выполнения** — текст, например
   `CRM GET http://mock:9502/api/v8/Accounts/fail500-demo returned HTTP 500`.
2. В **Шагах выполнения** найдите строку со статусом **Ошибка**. Шаги после неё **Пропущены** с причиной
   `Not executed: a previous step failed`.
3. Посмотрите на **Попыток**:
   - больше 1 (по умолчанию 4 = первая попытка + 3 повтора `ACTION_RETRY_MAX`) — ошибка временная
     (`recoverable: true`): CRM недоступна, таймаут или 5xx. Проверьте CRM, её адрес и ключ
     ([Интеграция с CRM](../04-operations/crm-integration.md));
   - 1 — ошибка постоянная (`recoverable: false`): 4xx от CRM, неверный модуль, ошибка настройки. Исправьте процесс.
4. Раскройте строку: в `result_data.details.response` — ответ CRM, в `request_data` — точная конфигурация шага.
5. Откройте **Контекст выполнения** → **Переменные**: проверьте, какие переменные равны `null` (предзагрузка ничего не
   нашла) и какие значения были у полей.
6. Исправьте процесс или данные и проверьте результат [ручным запуском](manual-run-and-reset.md).

Типичные ошибки:

| Ошибка | Причина | Что делать |
|---|---|---|
| `CRM GET … returned HTTP 500`, `connection failed`, `request timeout` | CRM недоступна или отвечает медленно | Проверить CRM, `CRM_API_BASE_URL`, `CRM_API_TIMEOUT` |
| `CRM … returned HTTP 401` | Неверный `CRM_API_STATIC_KEY` | Исправить ключ в конфигурации бэкенда |
| `Cannot save "v1": CRM module is unknown (set "module" in the save action)` | Сохраняется переменная, созданная через `modify`, без модуля | Заполнить **Модуль (для новых переменных, необязательно)** в строке «Сохранить в CRM» |
| `Variable "v1" is empty (object was not loaded) — nothing to save` (шаг **Пропущен**) | Предзагрузка ничего не нашла | Добавить условие на переменную (например, `v1.id` **Не равен** пустому значению) |
| `Child process 42 not found`, `… is not a child process` | Ведомый процесс удалён или сменил тип | Исправить строку **Запустить следующий процесс** |
| `Execution context expired or lost; it could not be resumed` | Воркер упал, а состояние в Redis истекло | Запустить процесс заново вручную |
| `Secret "x" not found`, `Secret "x" is not allowed for host "y"` | Секрет удалён или ограничен `allowed_hosts` | Восстановить секрет или поправить `allowed_hosts`, см. [Секреты](secrets.md) |
| `Destination address is not allowed (private network)`, `Host "x" is not allowed` | SSRF-защита отклонила адрес `http_request` | Разрешить хост в `HTTP_ALLOW_HOSTS` / `HTTP_ALLOW_PRIVATE_CIDRS`, см. [Безопасность](../04-operations/security.md#исходящие-http-запросы-ssrf-защита) |
| `HTTP 404 from host` (`error_code: HTTP_404`) | Внешний API ответил кодом вне `success_codes` | Тело ответа (до 2 КБ) — в `result_data.details`; поправить URL или добавить код в `success_codes` |
| `CRM_CAPABILITY_MISSING` | Плагину CRM не хватает возможности (фильтр `nin`, связи, количество) | Обновить плагин CRM, см. [Интеграция с CRM](../04-operations/crm-integration.md) |
| `[КОД] сообщение` при шаге `stop_process` | Процесс сам завершился со статусом `failed` | Это бизнес-правило процесса, см. [Остановленное выполнение](#остановленное-выполнение) |
| Выполнение долго **Ожидает** после срока | Задание пробуждения `WaitResumeJob` не успевает или backend остановлен | Проверить `docker compose ps backend`, метрику `wps_executions_waiting`, см. [Надёжность](../04-operations/reliability.md#ожидающие-выполнения) |
| `Outcome of the previous attempt is unknown …` (`error_code: UNKNOWN_OUTCOME`) | Воркер упал после отправки письма или запроса (`delivery: at_most_once`), шаг не повторён, чтобы не отправить дважды | Сверить с внешней системой; журнал вызовов — `GET /api/executions/{id}/side-effects`; подробно — [Надёжность](../04-operations/reliability.md#побочные-эффекты-и-повторы) |

> **Примечание**
> При каждом сохранении процесса его версия увеличивается. Выполнение хранит версию, с которой оно запускалось
> (**(версия N)** рядом с названием процесса), поэтому аудит остаётся согласованным после правок процесса.

См. также: [Надёжность](../04-operations/reliability.md), [Мониторинг](../04-operations/monitoring.md),
[Решение проблем](../04-operations/troubleshooting.md), [Эндпоинты API](../06-api-reference/endpoints.md).

<!-- footer-nav -->
---

← Назад: [Секреты](secrets.md) | [Оглавление](../README.md) | [Ручной запуск и сброс однократного запуска](manual-run-and-reset.md) Далее →
<!-- /footer-nav -->
