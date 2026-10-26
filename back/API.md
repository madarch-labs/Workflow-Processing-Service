# WPS REST API — контракт

Базовый префикс: `/api`. Все тела запросов и ответов — JSON (`Content-Type: application/json`).

## Общий формат ответа

```json
{ "success": true, "data": {}, "error": null, "meta": { "page": 1, "per_page": 20, "total": 100 } }
```

`meta` присутствует только у списков с пагинацией.

Ошибка:

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "details": [ { "field": "definition.actions[2].process_id", "message": "Process 42 is not a child process" } ]
  }
}
```

`details` — необязательное поле (массив или объект).

| HTTP | Когда |
|---|---|
| 200 | успех |
| 201 | создано (POST create / clone / run с запуском) |
| 400 | неверный запрос (битый JSON и т.п.) — `BAD_REQUEST` |
| 401 | нет токена / токен невалиден / истёк — `UNAUTHORIZED`, `TOKEN_EXPIRED`, `INVALID_CREDENTIALS`, `INVALID_TOKEN` |
| 403 | недостаточно прав — `FORBIDDEN` |
| 404 | не найдено — `NOT_FOUND`, `PROCESS_NOT_FOUND`, `EXECUTION_NOT_FOUND`, `USER_NOT_FOUND` |
| 409 | конфликт — `EMAIL_EXISTS`, `PROCESS_IN_USE`, `CANNOT_DELETE_SELF`, `LAST_ADMIN` |
| 422 | ошибка валидации — `VALIDATION_ERROR`, `CYCLE_DETECTED` |
| 500 | внутренняя ошибка — `INTERNAL_ERROR` |

## Аутентификация

Заголовок `Authorization: Bearer <access_token>`. Access-токен живёт `JWT_ACCESS_TTL` секунд (по умолчанию 900),
refresh — `JWT_REFRESH_TTL` (по умолчанию 7 дней). При `401` клиент вызывает `/api/auth/refresh`.

### Роли

| Роль | Процессы | Аудит | Ручной запуск | Удаление процессов | Пользователи |
|---|---|---|---|---|---|
| `admin` | чтение, создание, изменение, клонирование | да | да | да | CRUD |
| `editor` | чтение, создание, изменение, клонирование | да | да | нет | нет |
| `viewer` | только чтение | да | нет | нет | нет |

### `POST /api/auth/login` (публичный)

Запрос: `{ "email": "admin@wps.local", "password": "admin" }`

Ответ `200`:
```json
{
  "access_token": "…", "refresh_token": "…", "token_type": "Bearer", "expires_in": 900,
  "user": { "id": 1, "email": "admin@wps.local", "role": "admin", "created_at": "…", "updated_at": "…" }
}
```
Ошибки: `401 INVALID_CREDENTIALS`, `422 VALIDATION_ERROR`.

### `POST /api/auth/refresh` (публичный)

Запрос: `{ "refresh_token": "…" }` → ответ как у login. Ошибка: `401 INVALID_TOKEN`.

### `GET /api/auth/me`

Ответ: объект `User`.

## Пользователи (только admin)

`User`: `{ id, email, role: "admin"|"editor"|"viewer", created_at, updated_at }` (хеш пароля никогда не отдаётся).

| Метод | URI | Тело | Ответ |
|---|---|---|---|
| GET | `/api/users?page=&per_page=` | — | `User[]` + `meta` |
| GET | `/api/users/{id}` | — | `User` |
| POST | `/api/users` | `{ email, password (≥ 6 симв.), role }` | `201 User`; `409 EMAIL_EXISTS` |
| PUT | `/api/users/{id}` | `{ email?, password? (пусто = не менять), role? }` | `User` |
| DELETE | `/api/users/{id}` | — | `{ "deleted": true }`; `409 CANNOT_DELETE_SELF` / `LAST_ADMIN` |

## Секреты (`/api/secrets`)

Учётные данные внешних действий; в определении процесса — только ссылка `secret://name` или `secret://name.field`
в поле учётных данных действия. Значение только на запись: ни один ответ его не содержит.

`Secret`: `{ name, kind, description, mask, allowed_hosts: string[], version, created_at, updated_at, last_used_at }`;
`kind` ∈ `generic | smtp_dsn | basic_auth | bearer | api_key | oauth2_client | webhook_url | telegram_bot`
(составные `basic_auth` — `{username, password}`, `oauth2_client` — `{token_url (https), client_id, client_secret, scope?}`;
у них `mask` — объект с несекретными полями как есть).

| Метод | URI | Роль | Описание |
|---|---|---|---|
| GET | `/api/secrets` | admin, editor | `Secret[]` + `usages_count` |
| GET | `/api/secrets/{name}` | admin, editor | `Secret` + `usages: [{process_id, process_name, path}]`; `404 NOT_FOUND` |
| POST | `/api/secrets` | admin | `{ name (^[a-z][a-z0-9_]{1,63}$), kind?, value, description?, allowed_hosts? }` → `201 Secret`; `409 SECRET_EXISTS` |
| PUT | `/api/secrets/{name}` | admin | `{ value?, description?, allowed_hosts? }` → `Secret` (`version` + 1 при новом значении); имя и вид не меняются (`422`) |
| DELETE | `/api/secrets/{name}` | admin | `{ "deleted": true }`; `409 SECRET_IN_USE` с `details.usages` |
| POST | `/api/secrets/rotate` | admin | перешифровать текущим ключом → `{ "reencrypted": N }`; `409 ROTATION_FAILED` |

Без `WPS_SECRETS_KEY` эндпоинты, которым нужен ключ, отвечают `503 SECRETS_KEY_NOT_CONFIGURED`. Значение строк — не короче
4 символов. При сохранении процесса: ссылка на несуществующий секрет — `422 … Secret "x" does not exist`; ссылка в шаблоне,
`value`/`expression`/`field`/`target` или в условии — `422 … Secrets can only be used in credential fields`.

## Почта

| Метод | URI | Роль | Описание |
|---|---|---|---|
| POST | `/api/mail/test` | admin | `{ "to": "<адрес>", "transport"?: "secret://name" }` → `{ sent: true, to, message_id }` или `{ sent: false, error, error_code }` (тоже `200`); неверный адрес — `422` |

## Уведомления

| Метод | URI | Роль | Описание |
|---|---|---|---|
| POST | `/api/notify/test` | admin, editor | `{ "channel": {…канал notify…}, "message"?: "текст" }` — тестовое уведомление в один канал (`delivery: at_least_once`) → `{ sent: true, result: { channels: [...] } }` или `{ sent: false, error }` (тоже `200`, секреты замаскированы); ошибки канала — `422` с полями `channel.…` |

## Процессы

`Process`:
```json
{
  "id": 1,
  "name": "VIP-контрагенты",
  "object_type": "Accounts",
  "is_child": false,
  "is_active": true,
  "priority": 0,
  "run_once": false,
  "trigger_on": ["create", "update"],
  "version": 3,
  "definition": { "conditions": [], "preload": [], "actions": [] },
  "created_at": "2026-10-14T10:00:00+00:00",
  "updated_at": "2026-10-14T10:00:00+00:00"
}
```

`priority`: чем меньше, тем раньше выполняется среди процессов одного типа объекта.

`run_once` — однократный запуск, по умолчанию `false`. Если флаг включён, процесс запускается для одной записи
CRM (`object_type` + `id` объекта события) не больше одного раза. Источник запуска не важен: `stream`, `child`
или `manual`. Как работает отметка:
- она ставится атомарно в момент старта, и только если условия выполнены;
- после `failed` запись остаётся заблокированной;
- снять блокировку можно через `DELETE …/runs/…`.

**`id` объекта — это id сохранённой записи CRM**, а не id события или сообщения стрима. Без `id` однократный
запуск не применяется. Это принятое ограничение, но на практике `id` есть всегда:
- событие стрима без `id` уходит в `crm_events:dlq`;
- ручной запуск без `object.id` отклоняется с `422`;
- ведомый процесс берёт запись из события родителя.

Заблокированный запуск не создаёт выполнения. Он увеличивает `blocked_count` отметки и метрику
`wps_run_once_skipped_total`. Подробно — `md/idempotentie_plan.md`.

`trigger_on` — типы событий CRM, на которые запускается процесс (колонка `processes.trigger_on TEXT[]`, миграция `007`):
подмножество `create`, `update`, `delete`, по умолчанию `["create", "update"]` (прежнее поведение); `[]` — только ручной запуск.
- Фильтр применяется до условий: процессы модуля, не подписанные на `event_type` события, не проверяются
  (метрика `wps_events_skipped_by_type_total`, если не подошёл ни один).
- Ведомые процессы его игнорируют; ручной запуск его не проверяет (тип — из `object.event_type`, по умолчанию `update`).
- Событие без `event_type`: `wps_is_update = "0"` → `create`, иначе `update`. Неподдерживаемый тип (`link`, `unlink`) — событие
  подтверждается без запуска (`wps_events_unsupported_type_total`).
- На `delete` `save_object` / `save_to_crm` для `object` пропускается (`skipped`, «Record was deleted in the CRM — nothing to
  save»). Процесс с `trigger_on: ["delete"]`, сохраняющий `object`, не сохраняется: `The process runs only on delete: the deleted
  record cannot be saved`.
- Ошибки поля: `Must be an array of event types`, `Unknown event type "link" (allowed: create, update, delete)`; повторы
  убираются. `PUT` без `trigger_on` сохраняет текущее значение, `clone` копирует его.

### Структура `definition`

**Условия** (`conditions[]`, все строки объединяются по И; пустой массив — запуск всегда):
```json
{ "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }
```
- `field` — путь `object.<поле>` или `<переменная>.<поле>` (например `v1.rating`).
- `operator` — справочник `/api/dictionaries/operators` (`value`, `label`, `casts`, `operands`):
  `equals` | `not_equals` | `contains` | `not_contains` (одно значение);
  `value_type: "expression"` (md/tasks_list/3.10-expression-value.md, справочник `/api/dictionaries/value-types`): правая
  часть вычисляется выражением (до 1000 символов) и приводится `cast` строки; `between` — пара выражений, списочные
  операторы — список или строка; `null` — правой части нет (ложь), ошибка вычисления — ложь, `expression_error` в трассе,
  метрика `wps_condition_expression_errors_total` (с `on_cast_error: "error"` — ошибка проверки); пути выражения
  участвуют в ленивой предзагрузке; относительные даты внутри не распознаются; операторы изменения — `422 Operator
  "changed_to" does not support value_type "expression"`; неверный тип — `422 Must be "static", "from_object_field" or
  "expression"`.
  `contains_any` | `contains_all` | `contains_none` (md/tasks_list/3.9-list-operators.md; `operands: list`, только
  `cast: list`): поле — список (мультисписок `^a^,^b^` с `cast_options.wrapper: "^"`), правая часть — массив или строка,
  разобранная так же, как поле (из поля — по `cast_options` поля, если нет `value_list_options`); операции над
  множествами; пустой список в поле — `contains_none` истина, остальные ложь; отсутствующее поле или пустая правая часть —
  ложь; трасса — `actual_cast`, `expected_cast`, `matched`; `422 List of values must not be empty`.
  Регистр и пробелы (md/tasks_list/3.8-case-insensitive.md): `cast_options` типа `string` — `case_sensitive` (`true`;
  `false` — складывание регистра Unicode обеих сторон, `ё` ≠ `е`, `ß` не раскрывается) и `trim` (`false`; `true` — края с
  неразрывным пробелом и BOM), у `list` — `case_sensitive`; действуют на все строковые и списочные операторы, включая
  `changed*` (смена регистра при `case_sensitive: false` — не изменение); плоский ключ в строке условия —
  `422 Use "cast_options": {"case_sensitive": ...}`.
  Относительные даты (md/tasks_list/3.7-relative-dates.md): статическое значение с `cast: date` / `datetime` (и границы
  `between`) может быть `якорь (±N единица)*` — якоря `now`, `today`, `yesterday`, `tomorrow`, `start_of_` / `end_of_` +
  `day` | `week` | `month` | `quarter` | `year`; единицы `min`, `h`, `d`, `w`, `mo`, `y` (справочник
  `/api/dictionaries/relative-dates`); до 5 сдвигов; якоря в `WPS_TIMEZONE`, неделя с понедельника, `end_of_*` — последняя
  секунда; `d`/`w`/`mo`/`y` календарные, `h`/`min` — точное время; рабочих дней нет; значения из полей не
  интерпретируются; одно «сейчас» на проверку условий (оба прохода ленивой предзагрузки); трасса — `expected_resolved`,
  `evaluated_at`, `timezone`; ошибка разбора — `422 Invalid relative date "today+10wd": unknown unit "wd" at position 8
  (use min, h, d, w, mo, y)`. Работают и в фильтрах `find_records` / `aggregate` / `least_busy`.
  `between` | `not_between` (`operands: range`: `value` — пара `[от, до]`, обе статические или обе пути при
  `from_object_field`; `cast` обязателен: `int`, `decimal`, `date`, `datetime`; границы включаются; пустое или
  отсутствующее поле / граница — ложь для обоих; перевёрнутый диапазон из данных — ложь и `range_inverted` в трассе;
  `422`: `Operator "between" expects a pair [from, to]`, `Range bound is required`, `Range start must not be greater than
  range end`);
  `starts_with` | `ends_with` (одно значение, только `cast: string` или без `cast`; регистр — `cast_options.case_sensitive`;
  пустое или пробельное статическое значение — `422 Value is required for operator "starts_with"`; значение из пустого
  поля — истина, из отсутствующего — ложь);
  `greater_than` | `greater_or_equal` | `less_than` | `less_or_equal` (одно значение, `cast` обязателен: `int`, `decimal`,
  `date`, `datetime`; иначе `422 Operator "greater_than" requires cast: int, decimal, date or datetime`);
  `in` | `not_in` (`operands: list`: `value` — непустой JSON-массив скаляров до 500 элементов, приводимых к `cast`
  (`string`, `int`, `decimal`, `date`), или при `from_object_field` — путь к полю-списку; `value_list_options`
  `{separator, wrapper}` — разбор строки, только для поля; пустой список из поля: `in` — ложь, `not_in` — истина);
  `is_empty` | `is_not_empty` (`operands: none`) и `is_null` | `is_not_null` (`operands: presence`) — без `value` и
  `value_type` (иначе `422 Operator "is_null" does not take a value`), `cast` игнорируется; пустота проверяется у
  присутствующего поля (отсутствующее — ложь для обоих), `is_null` истинно для отсутствующего поля, не найденной
  переменной и `null`.
  `changed` | `not_changed` (`operands: change`, без `value`) и `changed_to` | `changed_from` (`operands: change_value`,
  `value` обязательно) — изменение поля в этом сохранении CRM; только `object.<поле>` (иначе `422 Operator "changed" works
  only with event object fields (object.<field>)`), все `cast`; новое значение — из события (не из изменённой `object`),
  старое — `object_before`; известно только для событий формата 2.0 (`changed_fields`) и не для `delete` — иначе все
  четыре ложны, в трассе `change_info: "missing"` / `"delete"`; в трассе также `before`, `before_cast`, `changed`,
  `event_type`. Ручной запуск принимает `event_type` / `before` / `changed_fields` в `object`.
- `value_type` — `static` (значение) | `from_object_field` (`value` — путь к полю объекта или переменной).
- Если поле отсутствует в объекте — условие ложно (кроме `is_null`).
- `cast` (необязательно) — тип сравнения: `string` (по умолчанию) | `int` | `decimal` | `date` | `datetime` | `bool` | `list`
  (справочник `/api/dictionaries/casts`). Без `cast` обе стороны сравниваются как текст, регистр учитывается.
- `cast_options` (необязательно) — параметры типа: `string` — `case_sensitive` (true), `trim` (false); `decimal` — `scale`
  (10, 0–20); `date`/`datetime` — `format` (строка или список форматов `DateTime::createFromFormat`), `timezone` (пояс
  значений без смещения, `UTC`); `list` — `separator` (`,`), `wrapper` (например `^` для мультисписков SuiteCRM),
  `case_sensitive` (true).
- `on_cast_error` — `"false"` (по умолчанию: значение, не приводимое к типу, делает условие ложным) | `"error"` (строгий
  режим: в условиях запуска создаётся выполнение `failed` с шагом `conditions`, при ручном запуске — `error: "CAST_ERROR"`).
- Для типов, кроме `string` и `bool`, пустое значение (`null`, пустая строка, `[]`) делает условие ложным. Даты со
  временем переводятся в пояс `WPS_TIMEZONE` перед взятием календарной даты.
- Валидация: неизвестный `cast`, параметр не того типа, оператор без поддержки типа (`contains` — только `string`, `list`),
  статическое значение, не приводимое к типу, — `422` с полем `definition.conditions[i].cast` / `.cast_options.<имя>` /
  `.value` / `.on_cast_error`.
- Если условие ссылается на переменную из блока предзагрузки, предзагрузка выполняется лениво:
  сначала проверяются условия по `object.*`, затем выполняется `preload`, затем остальные условия.
- Для будущей OR-логики движок уже принимает вложенную форму
  `{ "logical_operator": "AND"|"OR", "conditions": [...], "groups": [...] }` вместо массива.

**Предзагрузка** (`preload[]`, выполняется перед действиями, по порядку):
```json
{ "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" }
```
- `var` — имя переменной: латиница/цифры/`_`, не `object`, уникально в процессе.
- `source` — `object_field` (`value` = `object.<поле>`) | `variable_field` (`value` = `<ранее объявленная переменная>.<поле>`) | `manual` (`value` = введённое значение).
- `module` — модуль CRM объекта для загрузки.
- `by_field` — поле поиска; `id` → `GET /{module}/{id}`, иное → поиск по полю (берётся первая запись).
- Объект не найден / значение для поиска пустое → в переменную записывается `null`.

**Действия** (`actions[]`, выполняются последовательно; ошибка шага останавливает процесс):
```json
{ "type": "modify", "target": "object.phone", "change_type": "set_value", "value": "+7999888", "save_to_crm": false }
{ "type": "modify", "target": "object.sale", "change_type": "set_from_variable", "value": "v2.sale", "save_to_crm": false }
{ "type": "modify", "target": "v1.total", "change_type": "calculate", "value": "object.amount * 0.9 + 10", "save_to_crm": true }
{ "type": "modify", "target": "call.name", "change_type": "template", "value": "Позвонить: {{ object.first_name }} {{ object.last_name | upper | truncate:25 }}", "missing": "empty" }
{ "type": "modify", "target": "task.date_due", "change_type": "date_shift", "base": "object.date_closed", "shift": "-3 day", "set_time": "18:00" }
{ "type": "save_object", "target": "v1" }
{ "type": "start_process", "process_id": 42 }
{ "type": "send_email", "to": ["{{ object.email1 }}"], "cc": [{ "path": "owner.email1" }], "subject": "Сделка {{ object.name }}", "html": "<p>{{ object.name }}</p>", "save_to_crm": { "module": "Emails", "parent": "object" } }
{ "type": "http_request", "var": "erp", "method": "POST", "url": "https://erp.company.ru/api/orders/{{ object.id }}", "auth": { "type": "bearer", "token": "secret://erp_token" }, "body": { "type": "json", "value": { "amount": "{{= to_number(object.amount) }}" } }, "response": { "extract": { "order_id": "$.data.order_id" } } }
{ "type": "link_records", "record": "meeting", "link": "users", "related": [{ "path": "object.assigned_user_id" }], "fields": { "accept_status": "accept" } }
{ "type": "unlink_records", "record": "m", "link": "leads", "related": [{ "path": "object.id" }] }
{ "type": "find_records", "var": "tasks", "module": "Tasks", "filter": [{ "field": "parent_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" }, { "field": "status", "operator": "not_in", "value": ["Completed", "Deferred"] }], "sort": [{ "field": "date_due", "direction": "asc" }], "fields": ["name", "date_due"], "limit": 2 }
{ "type": "update_records", "list": "tasks", "set": [{ "field": "status", "change_type": "set_value", "value": "Deferred" }, { "field": "description", "change_type": "template", "value": "{{ item.description }} Отложено." }] }
{ "type": "assign", "target": "object", "strategy": "round_robin", "pool": { "security_group": "<id группы>" }, "queue_key": "leads_sales", "save_to_crm": true }
{ "type": "notify", "title": "Новый лид", "message": "Вам назначен лид {{ object.last_name }}", "link": { "record": "object" }, "channels": [{ "channel": "crm_alert", "users": ["{{ object.assigned_user_id }}"] }, { "channel": "telegram", "connection": "secret://sales_bot", "chat_id": "-1001234567890" }, { "channel": "slack", "connection": "secret://slack_sales" }] }
{ "type": "set_variable", "var": "calc", "fields": [{ "name": "rate", "change_type": "set_value", "value": "10" }, { "name": "net", "change_type": "calculate", "value": "object.amount * (100 - calc.rate) / 100" }] }
```
- `modify.target` — `object.<поле>` или `<переменная>.<поле>`; переменная, которой нет в предзагрузке, создаётся как новый объект.
- `change_type` — `set_value` | `set_from_variable` | `calculate` | `template` | `date_shift` | `increment` | `decrement` |
  `clear` | `append` | `prepend` | `remove` | `map` | `aggregate` (справочник `/api/dictionaries/change-types`).
- `template` — шаблон в `value` (синоним — поле `template`): `{{ path }}`, фильтры `{{ path | f:a:b }}` = функция `f(path, a, b)`,
  `{{= выражение }}`, `{{! комментарий }}`, `\{{` — литерал. `missing`: `empty` (по умолчанию; пустая строка, путь в
  `result_data.missing` шага) | `error` (шаг падает: `Template references missing value "…"`) | `keep` (тег остаётся).
  Ошибки сохранения: `Unclosed "{{" at position N` (позиция в символах), `Empty template tag …`, `Unknown template filter "foo"`,
  `Template filter "truncate" expects 1..2 arguments, got 0`, `Template filter "raw" is not allowed here`, `Template is too long (max 65536 characters)`,
  `Must be one of: empty, error, keep` (поле `missing`). Результат ≤ 1 МБ (`Template result is too long`).
- `date_shift` — дата от базы со сдвигом: `base` (`now` | `today` | путь к полю; по умолчанию `now`), `base_cast` (`date` | `datetime`
  или `{type, format, timezone}`), `shift` (список `{amount, unit}`, один объект или строка `"+2 day"`, `"+1 week -2 day"`;
  `unit`: `minute` | `hour` | `day` | `week` | `month` | `year` — справочник `/api/dictionaries/date-units`; `amount` — целое или путь,
  `|amount| ≤ 100000`), `set_time` (`HH:MM` по `WPS_TIMEZONE`), `result` (`date` → `Y-m-d` | `datetime` → `Y-m-d H:i:s` UTC;
  по умолчанию — вид получившегося значения), `on_missing` (`null` — поле очищается | `error` | `now`). Минуты и часы — абсолютное
  время, дни/недели/месяцы — по местному времени, месяцы обрезаются по концу месяца (31.01 + 1 month = 28.02). Рабочих дней нет.
  `result_data`: `base`, `shifts`, `missing_base`. Ошибки сохранения: `Unit must be one of: minute, hour, day, week, month, year`,
  `Shift must be a list of {amount, unit} or a string like "+2 day"`, `Amount must be an integer or a field path`, `Amount is out of range`,
  `Time must be HH:MM`, `Result must be "date" or "datetime"`, `Must be one of: null, error, now`; выполнения: `date_shift: cannot parse "…" as datetime`,
  `date_shift: amount must be an integer, got "…"`, `date_shift: base "…" has no value`.
- `increment` / `decrement` (5.1.3): текущее значение `target` ± `value` (по умолчанию 1; путь при `value_type:
  from_object_field`), отсутствующее / пустое = 0; `cast` `int` | `decimal` (по умолчанию целое, если обе части целые),
  `scale` 0..10, `on_invalid` `error` | `reset`; считается от значения в контексте, в CRM не атомарно. `clear` (5.1.4):
  `empty` `""` (по умолчанию) | `"null"`. `append` / `prepend` / `remove` (5.1.5): `mode: text` (по умолчанию; `value` —
  шаблон, `separator` только между непустыми, пустое новое — без изменений, `max_length`: append хранит конец, prepend —
  начало) или `mode: list` (`list: {separator, wrapper}`, `unique` по умолчанию `true`, результат в том же формате, пустой
  список → `''`); `remove` — только `mode: list` (`422 "remove" works only with mode "list"`). `map` (5.1.6): `source`,
  `mode: exact` (`map` — ключи приводятся `cast` / `cast_options` источника, ключ `""` — пустое значение, ≤ 500 записей) или
  `mode: ranges` (`ranges: [{lt | lte, value}]` по возрастанию, `cast` обязателен), `value_template`, `default`,
  `on_no_match` `default` | `keep` | `null` | `error`.
- `aggregate` — агрегат по записям CRM (md/tasks_list/5.1.7-aggregate.md): `function` (`count` | `count_distinct` | `sum` |
  `avg` | `min` | `max`, справочник `/api/dictionaries/aggregate-functions`), `field` (кроме `count`), `cast` (`sum`/`avg`:
  `int` | `decimal`, по умолчанию `decimal`; `min`/`max` обязательно: `int` | `decimal` | `date` | `datetime` | `string`),
  `scale` (0..10, у `avg` по умолчанию 2), `source` — ровно одно: `{module, filter}` (фильтр в формате `find_records`,
  обязателен) или `{relationship: {module, id (путь), link}}`; `limit` (1..`AGGREGATE_MAX_RECORDS`, по умолчанию 1000),
  `on_limit` (`error` | `partial`), `empty_result` (по умолчанию 0 для `count`/`count_distinct`/`sum`, иначе `null`).
  `count` без `field` по фильтру — один запрос с `meta.total`; иначе страницы по 100 и свёртка в WPS (bcmath; даты в формате
  CRM). Пустое значение фильтра или пустой `id` связи — без запроса, `empty_result`. Не транзакционно.
  `result_data`: `function`, `module`/`link`, `records_scanned`, `pages`, `truncated`, `total`, `skipped_reason`. Метрика
  `wps_aggregate_records_scanned`. Ошибки сохранения: `Function must be one of: …`, `Field is required for "sum"`,
  `Function "sum" requires cast: int or decimal`, `Function "max" requires cast: int, decimal, date, datetime or string`,
  `Scale must be an integer between 0 and 10`, `Specify either "module" with "filter" or "relationship"`,
  `Filter is required (aggregating a whole module is not allowed)`, `Link name is required (e.g. contacts)`,
  `Limit must be between 1 and 5000`, `Must be "error" or "partial"`; выполнения: `aggregate: more than N records of M;
  narrow the filter or set "on_limit": "partial"`, `aggregate: value "…" of M.f (id …) is not a decimal`.
- `save_to_crm: true` — сразу после изменения переменная сохраняется в CRM (как `save_object`).
- `save_object` — отправляет изменённые поля переменной в CRM (`PUT`, при отсутствии `id` — `POST`);
  необязательное поле `module` задаёт модуль для новых переменных.
- `start_process` — асинхронно запускает ведомый (`is_child = true`) процесс, передавая ему событие и все переменные; не ждёт завершения.
  Допустима форма `{ "type": "start_process", "process_ids": [42, 43] }`.
- `preload` допустим и в `actions[]` (загрузка посреди процесса) с теми же полями, что в блоке предзагрузки.
- `http_request` — вызов внешнего API, подробно — `doc/{ru,en}/03-user-guide/actions.md` (раздел «HTTP-запрос»).
  Поля: `var` (переменная ответа; не `object`/`object_before`/`sys`), `method` (`GET HEAD POST PUT PATCH DELETE`), `url` (схема и хост —
  литерал, подстановки в путь и query кодируются; либо `secret://name` вида `webhook_url` целиком), `query`, `headers` (шаблоны;
  нельзя `Host`, `Content-Length`, `Transfer-Encoding`, `Connection`), `auth` (`none` | `bearer` `{token}` | `basic` `{credentials}` |
  `api_key` `{key, in: header|query, name}` | `oauth2_client_credentials` `{client, auth_style: header|body}` — значения только
  `secret://…`, учитывается `allowed_hosts` секрета), `body` (`{type: json|form|raw, value, content_type}`; одиночная подстановка в
  листе `json` сохраняет тип; запрещено для `GET`/`HEAD`/`DELETE`), `response` (`{type: auto|json|text|none, extract: {поле: JSONPath}}`),
  `success_codes` (`"2xx"` или список), `timeout` (1…`HTTP_MAX_TIMEOUT`), `connect_timeout` (1…30), `follow_redirects` (0…5),
  `delivery` (по умолчанию `at_most_once` для `POST`/`PATCH`), `idempotency_header` (по умолчанию `Idempotency-Key` для `POST`/`PATCH`,
  значение `{execution_id}:{effect_key}`; `null` — не отправлять). Переменная: `status`, `ok`, `headers` (без `set-cookie`), `body` /
  `body_text` / `body_truncated`, поля `extract`; секреты в ней маскируются. `408/425/429/503` повторяются (с `Retry-After`,
  не больше `HTTP_RETRY_AFTER_MAX`), `500/502/504` и обрыв после отправки — только с ключом идемпотентности или `at_least_once`,
  иначе `UNKNOWN_OUTCOME`. SSRF-защита (`HTTP_*`): частные сети и служебные хосты закрыты, подключение — к проверенному IP.
  Ошибки выполнения: `Destination address is not allowed (private network)`, `Host "x" is not allowed`, `Port N is not allowed`,
  `Secret "x" is not allowed for host "y"`, `Response is too large`, `Response is not valid JSON`, `HTTP 404 from host`.
- `set_variable` — промежуточное значение в локальной переменной (не запись CRM), подробно — `doc/{ru,en}/03-user-guide/actions.md`
  (раздел «Записать в переменную процесса»). Формы: `target` + `change_type` + `value` (как `modify`) или `var` + `fields`;
  `fields` — список `[{name, change_type, value}]` (по порядку, следующее поле видит предыдущее) или объект (порядок ключей
  не сохраняется — `jsonb`; если поле ссылается на другое поле той же переменной — предупреждение). Любые типы изменения.
  Переменная `local`: не помечается изменённой, `save_object` / `save_to_crm` для неё — ошибка
  (`Variable "calc" is local (set_variable) and cannot be saved to the CRM`); в переменную-запись CRM —
  `Variable "acc" is a CRM record variable; use modify`; имена `object`, `object_before`, `sys`, `config` зарезервированы.
  Результат шага: `result_data.fields.<имя> = {old_value, new_value}`. Накопление — инициализируйте значением `0`
  (`'' + 500` склеивает строку).
- `link_records` / `unlink_records` — связи записи через link-поле её модуля CRM, подробно — `doc/{ru,en}/03-user-guide/actions.md`
  (раздел «Связать / отвязать записи»). Поля: `record` (переменная-запись с `id`: `object`, предзагрузка, сохранённая созданная),
  `module` (по умолчанию — модуль переменной), `link` (имя link-поля: `users`, `contacts`, `leads` …), `related` (список
  `{"path": "<переменная>.<поле>"}` — значение, список или список записей с `id`, либо `{"value": "<id>"}`), `related_module`,
  `fields` (только `link_records`, поля связи: `accept_status`, `required`, `contact_role`), `on_not_found` (`warn` | `error`),
  `on_missing_record` (`skip` | `error`). Id дедуплицируются, пустые → шаг `skipped`; больше `LINK_RECORDS_MAX` (500) — ошибка;
  пакеты по `max_link_batch`. Связывание идемпотентно (повтор обновляет поля, `updated`). Результат: `linked`, `updated`,
  `not_found`, `warnings` / `removed`, `not_linked`; `missing_paths`. Нужна возможность `relationships` (контракт 2.0).
- `send_email` — одно письмо всем получателям через SMTP WPS (`symfony/mailer`), подробно — `doc/{ru,en}/03-user-guide/actions.md`.
  Поля: `transport` (`secret://name` вида `smtp_dsn`; по умолчанию `MAILER_DSN`), `from` (строка или `{email, name}`, по умолчанию
  `MAILER_FROM`/`MAILER_FROM_NAME`, ограничение `MAILER_ALLOWED_FROM`), `to` (обязательно), `cc`, `bcc`, `reply_to` — элементы:
  адрес, шаблон `"{{ object.email1 }}"` (несколько адресов через `,`/`;`), `{ "path": "owner.email1", "name": "<шаблон>" }`
  (путь может указывать на список); `subject` (обязательно), `text` и/или `html` (обязательно одно; значения в `html` экранируются,
  текст без `text` строится из `html`), `headers` (`{ "X-Имя": "<шаблон>" }`, служебные заголовки запрещены), `on_empty_recipients`
  (`skip` — шаг `skipped` | `fail`), `save_to_crm` (`{module, parent}` — поля SuiteCRM `Emails` по умолчанию, или `{module, fields}` —
  карта поле → шаблон, `mail.*` — данные письма), `delivery` (`at_most_once` по умолчанию). Неверный/пустой адрес из данных —
  предупреждение `result_data.invalid_recipients`, дубли убираются, больше `MAILER_MAX_RECIPIENTS` — ошибка. `Message-ID` —
  `<{execution_id}.{позиция шага}@WPS_MAIL_MESSAGE_ID_DOMAIN>`, повтор шага письмо не дублирует (журнал внешних вызовов).
  Ошибки SMTP: соединение и `4xx` — повтор; `5xx` — без повторов (`SMTP_5xx`); `535` — `SMTP_AUTH`; обрыв после `DATA` при
  `at_most_once` — `UNKNOWN_OUTCOME`. Лимиты: `MAILER_RATE_PER_MINUTE` (повтор, `MAIL_RATE_LIMIT`), `MAILER_RATE_PER_RECIPIENT_HOUR`
  (получатель пропускается, `result_data.rate_limited`). `request_data`: `bcc` — только количество, `text_preview`, `html_size`,
  `message_id`; `result_data`: `sent`, `message_id`, `recipients`, `replayed`, `crm_history_id` / `crm_history_error`.
  Не поддерживаются `attachments`, `template`. Ошибки сохранения: `At least one recipient is required`, `Invalid email address "…"`,
  `Recipient must be an email, a template or {"path": ...}`, `Subject is required`, `Email body is required (text or html)`,
  `Sender address is not allowed`, `Transport must be a secret reference (secret://name)`,
  `No mail transport configured (set MAILER_DSN or "transport")`, `Header "From" cannot be set here`, `Must be "skip" or "fail"`,
  `Attachments are not supported yet`.
- `delay` — пауза (label «Пауза»), подробно — `doc/{ru,en}/03-user-guide/actions.md` (раздел «Пауза»). Поля: `mode`
  (`duration` | `until`); `duration` (ISO 8601 `P3D`/`PT30M` или `30s`/`15m`/`2h`/`3d`/`1w`; `P1M`/`P1Y` — календарно с
  обрезкой конца месяца) или `duration_expression`; `until` (путь поля — значение UTC, или литерал даты — в `WPS_TIMEZONE`
  / `timezone`) или `until_expression`; `cast` (`date` | `datetime`), `time` (`HH:MM` в `WPS_TIMEZONE`), `offset` (со знаком:
  `-P30D`, `-3d`), `if_past` (`continue` по умолчанию → `success`, `waited: false`, `past_due: true` | `fail`), `on_invalid`
  (`fail` по умолчанию | `continue` → `skipped` «Delay skipped: …»), `max_wait` (≤ `DELAY_MAX`, `P400D`). Пауза ≤
  `WAIT_INLINE_MAX_SECONDS` (5 с) — в корутине (`result_data: { waited: true, waited_seconds, inline: true }`), длиннее —
  парковка (`ActionResult::wait`, выполнение `waiting`, `wait_reason: delay`); после пробуждения шаг `success`
  (`waited: true`, `lateness_ms`, `waited_seconds`). `request_data` содержит `due_at` (UTC) и `due_at_local`. После паузы
  `object` — снимок события. Ошибки сохранения: `Delay mode must be one of: duration, until`, `Duration is required`,
  `Use either duration or duration_expression`, `Invalid duration "3x" (use ISO 8601 like P3D or 3d, 2h, 30m)`,
  `Delay exceeds the limit P400D`, `Date is required`, `Invalid date "31.02.2026"`, `Invalid offset "-3x"`,
  `Time must be HH:MM`, `Cast must be "date" or "datetime"`, `Unknown timezone "…"`, `Must be "continue" or "fail"`,
  `Must not exceed P400D` (`max_wait`). Ошибки выполнения: `delay: cannot resolve a date from object.date_end (value "")`,
  `delay: wait of N days exceeds the limit P400D`, `delay: the moment … has already passed`, `delay: invalid duration "soon"`.
- `find_records` — поиск записей CRM в переменную-список (label «Найти записи»), подробно — `doc/{ru,en}/03-user-guide/actions.md`
  (раздел «Найти записи»). Режимы: `module` (поиск в CRM по фильтру) или `related_to: {record, link[, module]}` (связанные записи,
  фильтр применяется в WPS; страницы связи читаются до `FIND_RECORDS_SCAN_MAX`). Поля: `var` (не `object`/`object_before`/`sys`/
  `event_info`/`config`), `filter` (список условий: `field` — поле найденных записей, `operator`, `value`, `value_type`
  `static`|`from_object_field`, `cast`; AND), `sort` (только `module`, ≤ 2 `{field, direction: asc|desc}`), `fields` (`id` всегда),
  `limit` (1…`FIND_RECORDS_MAX`, по умолчанию 100), `on_limit` (`truncate` | `error`), `on_empty_value` (`empty_result` — пустой
  список без запроса, `reason` | `ignore_condition` | `error`). Операторы в режиме `module`: `equals`→`eq`, `not_equals`→`neq`,
  `greater_*`/`less_*`→`gt gte lt lte` (нужен `cast` `int|decimal|date|datetime`), `contains`→`like` (экранирование `% _ \`), `starts_with`/`ends_with`→`like 'x%'`/`like '%x'`, `between`→`gte`+`lte` (статические границы; `not_between` недоступен),
  `in`→`in`, `not_in`→`nin` (1..100 значений; возможность `filter_nin`), `is_empty`/`is_not_empty`→`null`/`notnull`; значения — в
  формате хранения CRM (дата `Y-m-d`, дата-время UTC, decimal с точкой, bool `1/0`). В режиме связи — любые операторы условий.
  Переменная: `{items, count, has_more, ids}`, источник `list`, только чтение: `modify`/`save_object` по ней —
  `Variable "deals" is a record list; use foreach to change its records` (записи меняются по одной в `foreach`). `request_data`: `crm_filter`, `crm_requests`;
  `result_data`: `count`, `has_more`, первые 100 `ids`, `reason`, `warnings`. Больше `FIND_RECORDS_MAX_BYTES` —
  `find_records: result is too large …`; `on_limit: error` — `find_records: more than N records match (on_limit=error)`.
  Без нужной возможности CRM (`filter_operators`, `sort`, `cursor_pagination`, `filter_nin`, `relationships`) —
  `CRM_CAPABILITY_MISSING`.
- `assign` — назначить ответственного (label «Назначить ответственного», md/tasks_list/4.9-assign.md): `target` (запись),
  `field` (`assigned_user_id`), `strategy` (`round_robin` | `least_busy` | `random` | `manager` | `user`, справочник
  `/api/dictionaries/assign-strategies`), `pool` для первых трёх — `{users: [{path}|{value}]}` или
  `{security_group: id | {path}}` (участники `SecurityGroups/{id}/relationships/users`, кэш `ASSIGN_POOL_CACHE_TTL`),
  `only_active` (`true`: `status`/`employee_status` Active, не `is_group`, не `portal_only`), `exclude`, `queue_key`
  (по умолчанию `p{процесс}:{id шага | pc}`; одинаковый — общая очередь), `least_busy` `{module, user_field, filter}`
  (фильтр обязателен; пул ≤ `ASSIGN_LEAST_BUSY_MAX_POOL`), `manager_of`, `user`, `fallback` (`{mode: keep}` — шаг
  `skipped` | `error` | `{mode: user, user}`), `save_to_crm`, `module`. Round-robin: в Redis последний назначенный
  `assign:rr:{queue_key}`, следующий — первый id после него в отсортированном пуле (атомарно, Lua). Решение шага
  запоминается на сутки (`assign:decided:{execution}:{pc}:{iteration}`): повтор назначает того же, очередь не сдвигается
  (`reused_decision`). `result_data`: `chosen_user_id`, `previous_user_id`, `unchanged`, `reason`, `pool_size`, `pool`,
  `pool_source`, `inactive`, `excluded`, `counts`, `manager_of`, `fallback`, `save`. Метрика `wps_assign_total`. Ошибки
  `422`: `Record variable is required (e.g. object)`, `Variable "x" is not a CRM record`, `Strategy must be one of: …`,
  `Pool is required: {"users": [...]} or {"security_group": ...}`, `Use {"path": ...} or {"value": ...}`,
  `CRM module of the counted records is required (e.g. Cases)`, `Filter of "open" records is required for least_busy`,
  `Invalid queue key`, `Fallback must be keep, error or user`, `Must be true or false`; выполнения: `assign: <причина>`
  (`fallback: error`), `Security group "x" not found in CRM`, `Pool is too large for least_busy (N > 50)`.
- `notify` — уведомление (label «Уведомление», md/tasks_list/4.8-notify.md): `message` (шаблон, обязательно, ≤ 4000 символов —
  длиннее обрезается с предупреждением), `title` (шаблон), `link` (`{record: var}` — ссылка по `CRM_RECORD_URL_TEMPLATE` /
  профилю `CRM_ALERT_PROFILE`, или `{url: шаблон}`), `channels` (1..10), `fail_on` (`any` по умолчанию | `all`), `delivery`
  (`at_most_once` по умолчанию | `at_least_once`). Каналы (справочник `/api/dictionaries/notify-channels`):
  `crm_alert` — `users` (id, шаблон, `{path}`; до 50), `module` (`Alerts`), `profile` (`suitecrm8` | `suitecrm7` | `custom`),
  `fields` (поле → шаблон); `telegram` — `connection` (секрет `telegram_bot`), `chat_id`; `slack` — `connection`
  (`webhook_url`); `teams` — `connection` (`webhook_url`), `format` (`adaptive_card` | `message_card`). Каждая доставка —
  отдельная запись журнала побочных эффектов (повтор шага не дублирует, `replayed: true`). Ответы: 408/425/429/503 —
  повтор шага после всех каналов (`Retry-After`); 500/502/504 и обрыв после отправки — `UNKNOWN_OUTCOME` при
  `at_most_once`, повтор при `at_least_once`; прочие — ошибка канала. Шаг `failed` при `any` и упавшем канале, при `all` —
  если не доставлено никуда: `notify: N of M channels failed: …`, сводка в `details`. `request_data`: `title`,
  `message_preview`, `link`, `channels` (без секретов), `delivery`; `result_data.channels[]`: `channel`, `status`
  (`success` | `failed` | `skipped`), `deliveries`, `error`, `error_code`; `warnings`. Метрики `wps_notifications_sent_total`,
  `wps_notifications_failed_total`. Ошибки `422`: `Message is required`, `From 1 to 10 channels are required`,
  `Unknown channel "x"`, `At least one user is required`, `Connection secret is required (secret://name)`,
  `Secret "x" has kind "…", expected telegram_bot or generic`, `Chat id is required`, `Must be adaptive_card or
  message_card`, `Profile "custom" needs "fields"`, `Use {"record": "<variable>"} or {"url": "<template>"}`,
  `Must be "any" or "all"`.
- `update_records` — одинаковые поля у всех записей переменной-списка `find_records` одним шагом (label «Изменить записи
  списка», md/tasks_list/4.6-update-related.md). Поля: `list` (обязательно, переменная-список), `set` (1..20 правил
  `{field, change_type, …}` — типы изменения `modify`, значение считается для каждой записи), `item_var` (по умолчанию
  `item` — текущая запись в правилах, существует только на время вычисления), `only_changed` (по умолчанию `true`:
  отправляются только отличающиеся поля, запись без изменений не отправляется), `max` (1..`UPDATE_RECORDS_MAX`, 200;
  больше записей — ошибка до изменений), `on_item_error` (`continue` | `stop`), `module` (если модуль списка
  неизвестен). Пустой список → `skipped`; запись без `id` → `skipped_no_id`. Ошибка вычисления или 4xx/404 записи
  собирается, остальные записи обрабатываются, в конце шаг `failed` (`update_records: N of M records failed`, сводка в
  `result_data.details`) → дальше `on_error`; 5xx и сеть повторяют весь шаг. Список в контексте получает новые значения.
  `request_data`: `list`, `module`, `count`, `set`, `only_changed`, `max`; `result_data`: `updated`, `unchanged`,
  `skipped_no_id`, `errors`, `items` (до 200: `{id, status, fields, error}`). Метрика `wps_update_records_items_total`.
  Ошибки `422`: `Record list variable is required (e.g. contacts)`, `Variable "x" is not a record list (use find_records)`
  (`…list`), `Variable "item" already exists; set "item_var"` (`…item_var`), `1..20 field rules are required: …`
  (`…set`), `Field name is required`, `Field "id" cannot be changed`, `Field "x" is set twice` (`…set[k].field`),
  `Change type must be one of: …`, `Invalid CRM module`, `Max must be between 1 and 200`,
  `Must be one of: continue, stop`, `Must be true or false` (`only_changed`).

**Блоки** — элементы `actions[]` с вложенными списками действий (справочник `/api/dictionaries/action-types`,
`kind: "block"`). Определение компилируется в плоскую программу, курсор выполнения — номер инструкции `pc`.
```json
{ "type": "condition", "id": "deal_size", "name": "Крупная сделка?",
  "branches": [ { "conditions": [ { "field": "object.amount", "operator": "equals", "cast": "decimal", "value": "1000" } ],
                  "actions": [ { "type": "modify", "target": "object.priority", "change_type": "set_value", "value": "High" } ] } ],
  "else": [ { "type": "modify", "target": "object.priority", "change_type": "set_value", "value": "Normal" } ] }
```
- `condition` — `branches` (1–20 веток, несколько = «иначе если»; у ветки `conditions` — формат условий запуска,
  плоский список или группы AND/OR, `actions`, необязательный `name` до 255 символов), необязательный `else`.
  Выполняется первая ветка с выполненными условиями; ветка без условий (`[]` или нет поля) истинна всегда.
  Условия веток видят переменные, объявленные до блока. Ошибка приведения (`on_cast_error: "error"`) — шаг
  `condition` `failed`, дальше `on_error` блока (`continue` — к шагу после блока).
- Аудит блока — одна запись `action_type: condition`: `request_data: { branches, has_else }`,
  `result_data: { selected: <номер> | "else" | "none", branches: [{ branch, name?, result, conditions: [ConditionTrace] }] }`
  — только проверенные ветки; шаги невыбранных веток не пишутся.
- Ошибки блока: `422` `At least one branch is required`, `Too many branches (max 20)`, `Branch must be an object`,
  `Must be an array or a condition group` (`…branches[k].conditions`), `Must be an array` (`actions` ветки, `else`),
  `Branch name must be a string up to 255 characters`; ошибки строк условий — с путём
  `…branches[k].conditions[n].…`. Предупреждения (`warnings`, не блокируют): `Branch k has no conditions: the following
  branches are never reached`, `Branch k has no conditions: "else" is never reached`.
- `stop_process` — досрочно завершает всё выполнение из любого места:
  `{ "type": "stop_process", "status": "completed" | "failed", "code": "NO_CONTACTS", "message": "шаблон {{ object.x }}" }`.
  `status` по умолчанию `completed`; `message` (до 1000 символов, шаблон) обязателен для `failed`; `code` —
  `^[A-Z][A-Z0-9_]{0,63}$`. Запись аудита `action_type: stop_process` (`success`): `request_data: { status, code, message }`,
  `result_data: { stopped: true, status, code, message, message_error? }`; оставшиеся шаги — `skipped`
  (`Not executed: the process was stopped by stop_process`). `failed` → выполнение `failed`,
  `error_message = "[CODE] message"`; `completed` → `completed`. Снимок выполнения: `context.stop = { status, code, message }`.
  `on_error` не перехватывает `stop_process`. Ошибки: `Status must be "completed" or "failed"`, `Message is required for
  status "failed"`, `Message must be a string up to 1000 characters`, `Code must match ^[A-Z][A-Z0-9_]{0,63}$`,
  `on_error is not allowed for stop_process`; предупреждение `Steps after stop_process in the same list are never executed`.
- `foreach` — цикл по списку (md/tasks_list/4.11-foreach.md):
  `{ "type": "foreach", "source": "deals.items" | { "expression": "…" }, "as": "deal", "loop_var": "deal_loop",
  "module": "Opportunities", "where": [ConditionRow], "max_items": 200, "on_overflow": "fail" | "truncate",
  "on_item_error": "stop" | "continue", "actions": [ … ] }`. Обязательны `source`, `as`, `actions`; `loop_var` по
  умолчанию `<as>_loop`; `module` по умолчанию — модуль переменной-источника; `max_items` 1..`LOOP_MAX_ITEMS` (500).
  В теле `<as>` — копия элемента (объект; скаляр → `{value}`), изменения сохраняет `save_object` элемента;
  `<loop_var>` = `{index, number, count, first, last}` только для чтения. Переменные тела сохраняются между
  итерациями и после цикла (после цикла — `conditional`); `<as>`/`<loop_var>` после цикла удаляются. Источник
  `null`/не найден — пустой цикл (`source_missing`); не список — `foreach: source "x" is not a list`; больше `max_items`
  при `fail` — `foreach: N items exceed max_items (M)`. Снимок элементов — Redis-список `exec:{id}:loop:{pc}`; при
  ожидании внутри цикла — в `execution_waits.state.loop_items`. Ошибка шага тела (после `on_error` шага): `stop` —
  выполнение `failed`, запись `foreach` `failed` с `failed_at_index`; `continue` — остаток итерации пропускается
  (не пишется), шаг `handled: item_continue`, `sys.last_error.iteration`, выполнение `completed`.
  Аудит — одна запись `action_type: foreach`: `request_data: { source, as, max_items, on_overflow, on_item_error,
  has_where }`, `result_data` в начале `{ count, truncated, source_missing }`, по окончании перезаписывается итогами
  `{ count, processed, skipped_by_where, failed_items, failed_indexes (≤50), unsaved_items, truncated, source_missing,
  where_traces? (≤20), failed_at_index?, stopped?, left_by? }`; шаги тела — с `iteration` (`"0"`, `"2/0"`).
  Ошибки `422`: `List source is required (e.g. deals.items)`, `Variable "v1" is not defined at this point` (`…source`),
  ошибка выражения (`…source.expression`), `Variable name is required (letters, digits, "_"; e.g. item)`,
  `Name "sys" is reserved`, `Variable "deal" is already defined` (`…as`, `…loop_var`), `"loop_var" must differ from
  "as"`, `max_items must be an integer between 1 and 500`, `Must be one of: fail, truncate` / `stop, continue`,
  `Invalid CRM module`, `Must be an array` (`…actions`), `Variable "deal_loop" is read-only` (запись в `loop_var`).
- Любой шаг на любом уровне может иметь `id` (`^[a-z][a-z0-9_]{0,63}$`, уникален в процессе) и `name` (до 255 символов).
- Вложенность — до `PROGRAM_MAX_DEPTH` (5); глубже — `422 Nesting is too deep (max 5)`.
- Переменная, объявленная не во всех ветках (или в блоке без `else`), после блока читается с предупреждением: ответ
  `POST`/`PUT` содержит `warnings: [{ field, message }]` (`Variable "x" may be undefined here: it is set only in some
  branches`); сохранение не блокируется.
- Цикл `start_process` через вложенные блоки обнаруживается (`422 CYCLE_DETECTED`), процесс, запускаемый из ветки,
  нельзя удалить (`409 PROCESS_IN_USE`).
- Если шаг упал, `skipped` получают следующие шаги его списка и всех объемлющих списков; невыбранные ветки в аудит не
  пишутся. Больше `EXECUTION_MAX_INSTRUCTIONS` (5000) инструкций в одном выполнении — `failed` с `LIMIT_EXCEEDED`.

**Общие поля шага: ошибки и повторы** (md/tasks_list/done/7.1-on-error.md). У любого шага в `actions` (действие или
блок `condition`) можно задать:
- `on_error`: `"stop"` (по умолчанию — шаг `failed`, выполнение `failed`) | `"continue"` (выполнение идёт к следующему
  шагу; для `condition` — после всего блока) | `{"goto": "<id>"}` (переход к шагу верхнего уровня **после** шага
  верхнего уровня, содержащего ошибку; выход из веток; пропущенные шаги в аудит не пишутся). Обработанная ошибка —
  статус шага `failed`, `handled: continue|goto`, `sys.last_error` (`step_id`, `step_path`, `action_type`, `code`,
  `message`, `http_status`, `attempts`, `at`) и `sys.errors_count` в выражениях/шаблонах (переменная `sys` только для
  чтения), выполнение может завершиться `completed` с `handled_errors > 0`. В `preload` — `422 Not supported in the preload block`.
- `retry`: `{max_attempts: 1–20 (по умолчанию ACTION_RETRY_MAX + 1), base_delay, max_delay (длительности: 500ms, 2s,
  10m, 1h, 1d, PT5M, ≤ P7D, max ≥ base), backoff: 1–10, jitter: none|full|equal (по умолчанию ACTION_RETRY_JITTER = full),
  retry_on: [transient (по умолчанию) | 5xx | 4xx | any | "409"…], respect_retry_after: bool (true)}`. Задержка:
  `min(max_delay, base·backoff^(n−1))`, затем джиттер; `Retry-After` (429/503) поднимает её до `min(Retry-After, max_delay)`.
  Временные ошибки (`transient`): сеть, таймаут, HTTP `5xx`, `408`, `425`, `429`, Redis, БД. Никогда не повторяются:
  `CONFIG`, `CRM_CAPABILITY_MISSING`, `UNKNOWN_OUTCOME`.
- Ошибки `422`: `Must be "stop", "continue" or {"goto": "<step id>"}`, `Step "x" not found`, `Step "x" must be a
  top-level step after the failing step`, `Must be an integer between 1 and 20`, `Invalid duration (e.g. 2s, 10m, 1h;
  at most 7 days)`, `Must be greater than or equal to base_delay`, `Must be a number between 1 and 10`, `Must be one of:
  none, full, equal`, `Unknown retry class "6xx"`, `Unknown retry option`.

**Выражения `calculate`**: числа, строки в кавычках (`'abc'`, `"abc"`), пути (`object.amount`, `v1.price`),
`true` / `false` / `null`, списки `[a, b]`, операторы `+ - * / %`, сравнения `== != < <= > >=` (синонимы `=`, `<>`),
логика `and or not` (синонимы `&& || !`), скобки. Приоритет (от слабого): `or` < `and` < `not` < сравнения < `+ -` <
`* / %` < унарный минус; цепочка сравнений `a < b < c` — ошибка. Ключевые слова без учёта регистра; слово с точкой
(`object.or`) — путь. `+` складывает числа и конкатенирует, если хотя бы один операнд — не число (`true + 1` → `true1`).
Сравнение: `null` = `''`; `< <= > >=` с пустым операндом — `false`; булево сравнивается с приведением; строгие числа
(`^[+-]?(\d+(\.\d*)?|\.\d+)$`) — десятично (`'100' == 100.00`); списки — поэлементно; иначе строки, регистр важен;
даты не распознаются (сравнивать в формате `Y-m-d`). Условия `and/or/not/if/ifs`: `true/1/yes/on/да`,
`false/0/no/off/нет/''/null`, иначе `Condition must be boolean`.
Функции: `if(cond, then[, else])`, `coalesce(a, b, …)`, `ifempty(a, default)`, `ifs(c1, v1, …[, default])`,
`switch(x, k1, v1, …[, default])` (аргументы вычисляются лениво), `concat(a, b, …)`, `upper(s)`, `lower(s)`, `trim(s)`,
`len(s)`, `substr(s, start[, length])`, `round(x[, digits])`, `abs(x)`, `min(a, b, …)`, `max(a, b, …)` и функции дат
(ниже) — справочник `GET /api/dictionaries/functions`. Отсутствующее поле даёт пустую строку. Неизвестная функция и неверное число
аргументов — ошибка сохранения (`422`, `Function "if" expects 2..3 arguments, got 1`). Лимиты: 4000 символов,
500 узлов, вложенность 32, результат ≤ 1 МБ.

**Функции дат** (`Wps\Expression\Functions\DateFunctions` поверх `Wps\Time\DateMath`, «сейчас» — `Wps\Time\Clock`).
Результат — строка: дата `Y-m-d`, дата-время `Y-m-d H:i:s` в UTC. Ввод: `Y-m-d`, `Y-m-d H:i[:s]`, ISO 8601 (с поясом и
без), `d.m.Y[ H:i[:s]]`; дата-время без смещения — UTC; дата там, где нужен момент, — полночь в `WPS_TIMEZONE`; пустое
значение (`''`, `null`, `0000-00-00`) → `''`. Аргумент `tz`: `'local'` (= `WPS_TIMEZONE`), `'UTC'`, имя IANA.
`now()`, `today([tz])` (`pure: false`), `date(value[, format])`, `datetime(value[, format[, tz]])`,
`add_minutes|add_hours(d, n)` (абсолютное время), `add_days|add_weeks(d, n)` (местное время сохраняется при переходе на
летнее время), `add_months|add_years(d, n)` (обрезка конца месяца: 31.01 + 1 → 28.02),
`date_diff(from, to, unit)` (`minutes|hours|days|weeks|months|years`, усечение к нулю; `days` — календарные дни в local;
`months/years` — полные), `year|month|day|hour|minute|weekday|week|quarter(d[, tz])` (ISO: 1 — понедельник),
`start_of_*|end_of_*(d)` для `day|week|month|quarter|year` (неделя с понедельника; для даты-времени — граница в local,
переведённая в UTC), `is_weekend(d[, tz])`, `format_date(d, format[, tz])` (токены PHP, по умолчанию local),
`format_date_locale(d, pattern, locale[, tz])` (шаблон ICU; только с расширением `intl`, в образе есть).
При сохранении проверяются литералы: `unit` (`Function "date_diff": unit must be one of …`), `tz` (`Unknown timezone
"…"`), пустой `format`; `add_workdays` — `Unknown function` (рабочие дни вне скоупа). Во время выполнения:
`Cannot parse date "abc" in add_days()`, `Function "add_days": count must be an integer, got "x"`.

### Эндпоинты

| Метод | URI | Роль | Описание |
|---|---|---|---|
| GET | `/api/processes?search=&object_type=&is_child=true\|false&is_active=true\|false&trigger_on=create\|update\|delete&page=1&per_page=20` | любая | Список, `meta` с пагинацией. Сортировка: `updated_at DESC`. `per_page` ≤ 1000 |
| GET | `/api/processes/{id}` | любая | `Process` + `references: { children: [{id,name,is_active,is_child}], parents: [{id,name}] }` |
| POST | `/api/processes` | editor+ | Тело: `{ name, object_type, is_child, is_active?, priority?, run_once?, trigger_on?, definition }` → `201 Process` (+ необязательные `warnings: [{field, message}]`) |
| PUT | `/api/processes/{id}` | editor+ | То же тело → `Process` (`version` + 1, + необязательные `warnings`); без `trigger_on` сохраняется текущее значение |
| DELETE | `/api/processes/{id}?force=true` | admin | `{ "deleted": true }`; `409 PROCESS_IN_USE` если на него ссылается `start_process`; `409 PROCESS_HAS_WAITING_EXECUTIONS` («The process has N waiting executions; delete with force=true to cancel them», `details: { waiting: N }`), если есть ожидающие выполнения и нет `force=true`; с `force=true` они отменяются (`cancelled`, `error_message: "Process deleted"`). Деактивация ожидающие не останавливает |
| POST | `/api/processes/{id}/clone` | editor+ | `201 Process` — копия «<имя> (копия)», неактивна. `run_once` и `trigger_on` копируются, отметки — нет |
| POST | `/api/processes/{id}/run` | editor+ | Ручной запуск, см. ниже |
| GET | `/api/processes/{id}/executions?status=&date_from=&date_to=&page=&per_page=` | любая | Журнал запусков процесса |
| GET | `/api/processes/{id}/runs?object_id=&page=&per_page=` | любая | Отметки однократного запуска (`ProcessObjectRun[]` + `meta`), сортировка `created_at DESC` |
| DELETE | `/api/processes/{id}/runs/{object_type}/{object_id}` | editor+ | Снять отметку: следующее событие записи снова запустит процесс → `{ "deleted": true }`; `404 NOT_FOUND`, если отметки нет |

`ProcessObjectRun`:
```json
{ "process_id": 16, "object_type": "Leads", "object_id": "9185c76a-…", "execution_id": "uuid",
  "event_id": "1792043807117-0", "trigger": "stream", "created_at": "…", "blocked_count": 2, "last_blocked_at": "…" }
```

Ошибки валидации (`422 VALIDATION_ERROR`) содержат `details: [{ field, message }]`.
Цикл запуска процессов (А → Б → А) — `422 CYCLE_DETECTED`, `details: { "path": [{id, name}, …] }`.

#### Ручной запуск `POST /api/processes/{id}/run`

Тело: `{ "object": { "id": "123", "module": "Accounts", "status": "Active" }, "force": false }`
(если ключа `object` нет — всё тело считается объектом). `force: true` — запуск без проверки условий.
В `object` можно передать служебные поля события контракта 2.0: `event_type` (`create`|`update`|`delete`), `before`
(`{поле: старое значение}`), `changed_fields` (`[поле]`). Они не остаются в записи: старые значения — переменная `object_before`
(только чтение), тип и изменённые поля — `context.event_info`. Без них — событие старого формата (`object_before` = `null`).

`object.id` обязателен (id записи CRM), без него ответ `422`.

- Условия выполнены → `201`: `{ "conditions_met": true, "execution_id": "<uuid>", "conditions": [ConditionTrace], "run_once_applied": bool, "run_once_blocked": false, "previous_execution_id": null }`;
  действия выполняются в фоне, прогресс виден через `GET /api/executions/{id}`.
- Условия не выполнены → `200`: `{ "conditions_met": false, "execution_id": null, "conditions": [ConditionTrace], … }`.
- У процесса `run_once`, для записи уже есть отметка → `200`: `{ "conditions_met": true, "run_once_blocked": true,
  "execution_id": null, "previous_execution_id": "<uuid>", … }`. `force: true` пропускает только условия, однократный
  запуск он не обходит.
- `run_once_applied` равен `true`, когда проверка однократности выполнялась: у процесса `run_once` и у объекта есть `id`.
- Строгое условие (`on_cast_error: "error"`) не смогло привести значение → `200`: `{ "conditions_met": false,
  "execution_id": null, "conditions": [ConditionTrace], …, "error": "CAST_ERROR", "error_message": "…" }`.

`ConditionTrace`: `{ field, operator, value_type, value, actual, expected, result: true|false|null }`; `actual`/`expected` —
исходные значения. При заданном `cast`: `cast`, `cast_options` (непустые параметры), `actual_cast`, `expected_cast`
(приведённые значения). Дополнительно при необходимости: `missing` (`"actual"`|`"expected"` — поле отсутствует), `empty`
(сторона пуста), `cast_error` (`{ "side": "actual", "message": "…" }`), `found` (для `is_empty` / `is_null` и
парных — найдено ли поле), для `in` / `not_in` `expected_cast` — список приведённых элементов.

## Выполнения (аудит)

`Execution`:
```json
{
  "id": "uuid", "process_id": 1, "process_name": "…", "process_version": 3,
  "parent_execution_id": null, "trigger": "stream" ,
  "event_id": "1718000000000-0", "object_type": "Accounts", "object_id": "123",
  "status": "running", "retry_count": 0, "handled_errors": 0, "error_message": null,
  "started_at": "…", "finished_at": null, "duration_ms": null,
  "waiting_until": null, "wait_reason": null
}
```
- `trigger`: `stream` | `manual` | `child`.
- `status`: `running` | `retrying` | `waiting` | `completed` | `failed` | `cancelled`.
- `waiting_until` (UTC), `wait_reason` (`delay` | `retry`) — у ожидающего выполнения, иначе `null`.
- `handled_errors` — число ошибок шагов, обработанных `on_error` (`continue` / `goto`) или циклом (`on_item_error: continue`).

`ExecutionAction` (шаг):
```json
{
  "id": 10, "step_index": 0, "action_type": "preload", "status": "success",
  "attempts": 1, "started_at": "…", "duration_ms": 12,
  "request_data": {}, "result_data": {}, "error_message": null,
  "pc": 0, "step_path": "preload[0]", "iteration": "", "step_id": null,
  "error_code": null, "handled": null
}
```
- `status`: `success` | `waiting` | `failed` | `skipped` (`waiting` — шаг `delay`, пока выполнение ждёт).
- `step_index` — порядковый номер записи аудита (`seq`); `pc` — номер инструкции программы (для линейного процесса
  `pc == step_index`); `step_path` — место шага в определении (`actions[1].branches[0].actions[0]`); `iteration` —
  номер итерации цикла `foreach`, во вложенных циклах `"2/0"` (`""` вне циклов); `step_id` — пользовательский `id` шага.
- У упавшего шага `error_code` (`HTTP_503`, `TIMEOUT`, `TRANSPORT`, `CONFIG`, `UNKNOWN_OUTCOME` …) и `handled`
  (`null` — ошибка остановила выполнение, `continue`, `goto`, `item_continue` — цикл перешёл к следующему элементу); `result_data`: `error_class`, `recoverable`,
  `error_code`, `error` (`{code, message, transient, http_status?, retry_after?}`), `details`, при `goto` — `goto` (id цели). Внешние действия (`send_email`, `http_request`, `notify`) добавляют `idempotency_key`
  (`{execution_id}:{effect_key}`) и `replayed` (`true` — ответ взят из журнала побочных эффектов без повторного вызова).

`SideEffect` (запись журнала внешних вызовов `step_side_effects`, md/tasks_list/done/0.4-side-effect-ledger.md):
```json
{ "effect_key": "3@2/0#slack", "action_type": "notify", "delivery": "at_most_once", "status": "done",
  "attempts": 1, "started_at": "…", "finished_at": "…", "error_message": null }
```
- `effect_key`: `{pc}`, в циклах `{pc}@{iteration}`, для нескольких вызовов в шаге — `#{sub}`.
- `status`: `started` (вызов начат или исход неизвестен) | `done` | `failed`. Ответ внешней системы не отдаётся.
- Поле шага `delivery` (`at_most_once` | `at_least_once`) допустимо только у действий с внешними эффектами; у остальных —
  `422` `Action "modify" does not support "delivery"`; неверное значение — `Delivery must be "at_most_once" or "at_least_once"`.

| Метод | URI | Описание |
|---|---|---|
| GET | `/api/executions?status=&process_id=&due_before=&date_from=&date_to=&page=&per_page=` | Выполнения всех процессов (`Execution[]` + `meta`). `status` — `running`, `retrying`, `waiting`, `completed`, `failed`, `cancelled`, иначе `422`; `due_before` — `waiting_until` ≤ даты, неверная дата — `422 Invalid date` |
| GET | `/api/executions/{id}` | `Execution` + `wait` (только у ожидающего: `{ due_at, reason, attempts, info }`) + `context` (`{ event, variables, meta, event_info, conditions }`; `event_info` = `{ event_type, changed_fields (null — неизвестно), has_before, origin (wps_origin), legacy }`, в `variables` — `object_before` (старые значения изменённых полей или `null`); пока выполняется — живой контекст из Redis, а также `current_step` (pc следующей инструкции), `seq`, `frames`) + `actions: ExecutionAction[]` + `children: Execution[]` |
| GET | `/api/executions/{id}/actions` | `ExecutionAction[]` |
| GET | `/api/executions/{id}/side-effects` | `SideEffect[]` (любая роль; пусто, если внешних вызовов не было); `404 EXECUTION_NOT_FOUND` |

## Справочники

Все — `[{ "value": "…", "label": "…" }]` (у типов действий есть ещё `description` и `kind`: `action` — действие,
`block` — блок с вложенными списками, например `condition`; у операторов — `casts`: допустимые типы
`cast`, `operands`: форма правой части, `value` — одно значение; у типов приведения — `orderable` и `options`:
`[{ name, type, default, min?, max? }]`).

| Метод | URI | Роль |
|---|---|---|
| GET | `/api/dictionaries/modules` | любая (модули CRM, кэш Redis `dict:modules` 300 с) |
| POST | `/api/dictionaries/modules/refresh` | editor+ (сброс кэша и повторная загрузка; заодно перечитывает возможности CRM) |
| GET | `/api/dictionaries/crm-capabilities` | любая (версия контракта CRM и возможности, см. ниже) |
| GET | `/api/dictionaries/operators` | любая |
| GET | `/api/dictionaries/functions` | любая (функции выражений: `name`, `category` (`logic`, `string`, `number`, `date`, …), `signature`, `min_args`, `max_args`, `returns`, `description`, `example`, `pure` (`false` у `now`, `today`); `meta.operators`: `[{op, synonyms, precedence}]`) |
| GET | `/api/dictionaries/action-types` | любая |
| GET | `/api/dictionaries/change-types` | любая |
| GET | `/api/dictionaries/aggregate-functions` | любая (функции `aggregate`: `[{value, label}]` — `count`, `count_distinct`, `sum`, `avg`, `min`, `max`) |
| GET | `/api/dictionaries/date-units` | любая (единицы `date_shift`: `[{value, label}]` — `minute` … `year`) |
| GET | `/api/dictionaries/event-types` | любая (типы событий для `trigger_on`: `[{value, label}]` — `create`, `update`, `delete`) |
| GET | `/api/dictionaries/template-filters` | любая (фильтры шаблонов: `value`, `label`, `category`, `signature`, `min_args`, `max_args` — число аргументов фильтра, `example`; все функции выражений, принимающие значение, и `raw`) |
| GET | `/api/dictionaries/error-policies` | любая (`on_error`: `stop`, `continue`, `goto`) |
| GET | `/api/dictionaries/retry-classes` | любая (классы `retry.retry_on`: `transient`, `5xx`, `4xx`, `any`; допустим и трёхзначный код) |
| GET | `/api/dictionaries/preload-sources` | любая |
| GET | `/api/dictionaries/casts` | любая (типы `cast` для условий) |
| GET | `/api/dictionaries/delivery-policies` | любая (`at_most_once` — «Не более одного раза», `at_least_once` — «Не менее одного раза») |
| GET | `/api/dictionaries/secret-kinds` | любая (виды секретов: `composite`, `fields: [{name, required, secret}]`) |
| GET | `/api/dictionaries/value-types` | любая (`[{value, label}]` — `static`, `from_object_field`, `expression`) |
| GET | `/api/dictionaries/relative-dates` | любая (`{anchors: [{value, label}], units: [{value, label}], timezone}`) |
| GET | `/api/dictionaries/assign-strategies` | любая (`[{value, label}]` — `round_robin`, `least_busy`, `random`, `manager`, `user`) |
| GET | `/api/dictionaries/notify-channels` | любая (`[{value, label, secret_kinds}]` — `crm_alert`, `telegram`, `slack`, `teams`) |
| GET | `/api/dictionaries/mail-transports` | admin, editor (`{ default_configured: bool, secrets: string[] }` — задан ли `MAILER_DSN` и имена секретов `smtp_dsn`) |

`GET /api/dictionaries/crm-capabilities` — что реализует плагин CRM (контракт WPS ↔ CRM, `doc/*/04-operations/crm-integration.md`).
Кэш Redis `crm:capabilities` на `DICT_CACHE_TTL`, сбрасывается `POST /api/dictionaries/modules/refresh`:

```json
{"contract_version":"2.0",
 "features":["filter_operators","filter_nin","sort","cursor_pagination","fields","count","delete","relationships","relationship_fields","event_v2","delete_events","origin_headers"],
 "limits":{"max_page_size":100,"max_link_batch":100,"max_in_values":100},
 "crm":{"name":"SuiteCRM","version":"8.10.2","plugin_version":"1.2.0"},
 "source":"crm","cached_at":"2026-10-20T09:00:00+00:00"}
```

`source`: `crm` — ответ `GET {CRM}/capabilities`; `fallback` — CRM без этого эндпоинта (старый плагин): `contract_version: "1.0"`,
`features: []`. CRM недоступна — `503 CRM_UNAVAILABLE`. Функция WPS без нужной возможности завершает шаг ошибкой
`CRM_CAPABILITY_MISSING` (`result_data.error_code`).

## Служебные

| Метод | URI | Описание |
|---|---|---|
| GET | `/api/health` | Публичный. `200` / `503`, `data: { status: "ok"\|"degraded", checks: { postgres, redis_data, redis_stream }, stream: {...} }` |
| GET | `/metrics` | Публичный. Метрики Prometheus (text format) |
