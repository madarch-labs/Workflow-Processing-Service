# Справочник API: эндпоинты

Все 28 эндпоинтов API WPS: роли, параметры, примеры запросов и ответов.

<!-- lang-switch -->
[🇬🇧 English](../../en/06-api-reference/endpoints.md) | 🇷🇺 Русский
<!-- /lang-switch -->

В примерах используются `http://localhost:9501` и `$TOKEN` из раздела [Аутентификация](index.md#аутентификация).
Ответы получены на работающем стенде и сокращены (`…`). Общий формат ответа (`success`, `data`, `error`, `meta`) описан в
разделе [Основы API](index.md).

## Сводка

| Метод | Путь | Роль | Описание |
|---|---|---|---|
| `POST` | [`/api/auth/login`](#post-apiauthlogin) | публичный | Вход, получение токенов |
| `POST` | [`/api/auth/refresh`](#post-apiauthrefresh) | публичный | Новая пара токенов по refresh-токену |
| `GET` | [`/api/auth/me`](#get-apiauthme) | любая | Текущий пользователь |
| `GET` | [`/api/users`](#get-apiusers) | admin | Список пользователей |
| `GET` | [`/api/users/{id}`](#get-apiusersid) | admin | Пользователь |
| `POST` | [`/api/users`](#post-apiusers) | admin | Создать пользователя |
| `PUT` | [`/api/users/{id}`](#put-apiusersid) | admin | Изменить пользователя |
| `DELETE` | [`/api/users/{id}`](#delete-apiusersid) | admin | Удалить пользователя |
| `GET` | [`/api/processes`](#get-apiprocesses) | любая | Список процессов |
| `GET` | [`/api/processes/{id}`](#get-apiprocessesid) | любая | Процесс со ссылками |
| `POST` | [`/api/processes`](#post-apiprocesses) | editor+ | Создать процесс |
| `PUT` | [`/api/processes/{id}`](#put-apiprocessesid) | editor+ | Изменить процесс |
| `DELETE` | [`/api/processes/{id}`](#delete-apiprocessesid) | admin | Удалить процесс (мягко) |
| `POST` | [`/api/processes/{id}/clone`](#post-apiprocessesidclone) | editor+ | Клонировать процесс |
| `POST` | [`/api/processes/{id}/run`](#post-apiprocessesidrun) | editor+ | Ручной запуск с тестовым объектом |
| `GET` | [`/api/processes/{id}/executions`](#get-apiprocessesidexecutions) | любая | Журнал выполнений процесса |
| `GET` | [`/api/processes/{id}/runs`](#get-apiprocessesidruns) | любая | Отметки однократного запуска |
| `DELETE` | [`/api/processes/{id}/runs/{object_type}/{object_id}`](#delete-apiprocessesidrunsobject_typeobject_id) | editor+ | Снять отметку однократного запуска |
| `GET` | [`/api/executions`](#get-apiexecutions) | любая | Выполнения всех процессов (например, ожидающие) |
| `GET` | [`/api/executions/{id}`](#get-apiexecutionsid) | любая | Выполнение с контекстом, шагами и ведомыми |
| `GET` | [`/api/executions/{id}/actions`](#get-apiexecutionsidactions) | любая | Шаги выполнения |
| `GET` | [`/api/executions/{id}/side-effects`](#get-apiexecutionsidside-effects) | любая | Журнал внешних вызовов выполнения |
| `GET` | [`/api/secrets`](#get-apisecrets) | admin, editor | Список секретов (маски, без значений) |
| `GET` | [`/api/secrets/{name}`](#get-apisecretsname) | admin, editor | Секрет с использованиями |
| `POST` | [`/api/secrets`](#post-apisecrets) | admin | Создать секрет |
| `PUT` | [`/api/secrets/{name}`](#put-apisecretsname) | admin | Изменить значение, описание, хосты |
| `DELETE` | [`/api/secrets/{name}`](#delete-apisecretsname) | admin | Удалить неиспользуемый секрет |
| `POST` | [`/api/secrets/rotate`](#post-apisecretsrotate) | admin | Перешифровать все секреты текущим ключом |
| `POST` | [`/api/mail/test`](#post-apimailtest) | admin | Отправить тестовое письмо (проверка настроек почты) |
| `POST` | [`/api/notify/test`](#post-apinotifytest) | admin, editor | Отправить тестовое уведомление в один канал (проверка канала) |
| `GET` | [`/api/dictionaries/modules`](#get-apidictionariesmodules) | любая | Модули CRM (из кэша) |
| `GET` | [`/api/dictionaries/crm-capabilities`](#get-apidictionariescrm-capabilities) | любая | Возможности CRM (версия контракта) |
| `POST` | [`/api/dictionaries/modules/refresh`](#post-apidictionariesmodulesrefresh) | editor+ | Перезагрузить модули CRM |
| `GET` | [`/api/dictionaries/operators`](#get-apidictionariesoperators) | любая | Операторы сравнения |
| `GET` | [`/api/dictionaries/functions`](#get-apidictionariesfunctions) | любая | Функции и операторы выражений |
| `GET` | [`/api/dictionaries/action-types`](#get-apidictionariesaction-types) | любая | Типы действий |
| `GET` | [`/api/dictionaries/change-types`](#get-apidictionarieschange-types) | любая | Типы изменений |
| `GET` | [`/api/dictionaries/template-filters`](#get-apidictionariestemplate-filters) | любая | Фильтры шаблонов |
| `GET` | [`/api/dictionaries/notify-channels`](#get-apidictionariesnotify-channels) | любая | Каналы уведомлений и подходящие виды секретов |
| `GET` | [`/api/dictionaries/aggregate-functions`](#get-apidictionariesaggregate-functions) | любая | Функции агрегата по записям |
| `GET` | [`/api/dictionaries/assign-strategies`](#get-apidictionariesassign-strategies) | любая | Стратегии назначения ответственного |
| `GET` | [`/api/dictionaries/date-units`](#get-apidictionariesdate-units) | любая | Единицы сдвига даты |
| `GET` | [`/api/dictionaries/relative-dates`](#get-apidictionariesrelative-dates) | любая | Якоря и единицы относительных дат, бизнес-часовой пояс |
| `GET` | [`/api/dictionaries/value-types`](#get-apidictionariesvalue-types) | любая | Типы значения правой части условия |
| `GET` | [`/api/dictionaries/error-policies`](#get-apidictionarieserror-policies) | любая | Политики `on_error` шага |
| `GET` | [`/api/dictionaries/retry-classes`](#get-apidictionariesretry-classes) | любая | Классы ошибок для `retry.retry_on` |
| `GET` | [`/api/dictionaries/event-types`](#get-apidictionariesevent-types) | любая | Типы событий для `trigger_on` |
| `GET` | [`/api/dictionaries/preload-sources`](#get-apidictionariespreload-sources) | любая | Источники предзагрузки |
| `GET` | [`/api/dictionaries/casts`](#get-apidictionariescasts) | любая | Типы приведения для условий (`cast`) |
| `GET` | [`/api/dictionaries/delivery-policies`](#get-apidictionariesdelivery-policies) | любая | Политики доставки внешних действий (`delivery`) |
| `GET` | [`/api/dictionaries/secret-kinds`](#get-apidictionariessecret-kinds) | любая | Виды секретов и их поля |
| `GET` | [`/api/dictionaries/mail-transports`](#get-apidictionariesmail-transports) | admin, editor | Транспорты почты для `send_email` |
| `GET` | [`/api/health`](#get-apihealth) | публичный | Проверка состояния |
| `GET` | [`/metrics`](#get-metrics) | публичный | Метрики Prometheus |

«любая» — любая роль после входа, «editor+» — `editor` или `admin`.

## Объекты

### `User`

```json
{ "id": 2, "email": "editor@example.com", "role": "editor", "created_at": "2026-10-15T07:49:47+00:00", "updated_at": "…" }
```

`role`: `admin` | `editor` | `viewer`. Хеш пароля никогда не возвращается.

### `Process`

```json
{
  "id": 1,
  "name": "Active deal → mark account as VIP",
  "object_type": "Deals",
  "is_child": false,
  "is_active": true,
  "priority": 0,
  "run_once": false,
  "trigger_on": ["create", "update"],
  "version": 1,
  "definition": {
    "conditions": [ { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" } ],
    "preload": [ { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" } ],
    "actions": [ { "type": "modify", "target": "v1.rating", "change_type": "set_value", "value": "VIP", "save_to_crm": true } ]
  },
  "created_at": "2026-10-15T07:49:36+00:00",
  "updated_at": "2026-10-15T07:49:36+00:00"
}
```

| Поле | Описание |
|---|---|
| `object_type` | Модуль CRM, события которого запускают процесс (`^[A-Za-z][A-Za-z0-9_]*$`) |
| `is_child` | `true` — ведомый процесс: запускается только действием `start_process` другого процесса |
| `is_active` | Неактивные процессы не запускаются событиями и пропускаются в `start_process` |
| `priority` | Целое число; среди процессов одного типа объекта меньшее выполняется раньше |
| `run_once` | Не больше одного запуска для записи CRM (`object_type` + `id` объекта) |
| `trigger_on` | Типы событий CRM, на которые запускается процесс: подмножество `create`, `update`, `delete`, по умолчанию `["create", "update"]`; `[]` — только вручную. У ведомых процессов игнорируется, ручной запуск его не проверяет. См. [Тип события](../03-user-guide/triggers-and-conditions.md#тип-события-и-поле-запускать-при-trigger_on) |
| `version` | Увеличивается при каждом `PUT`; выполнения хранят версию, с которой работали |
| `definition` | `conditions` (строка условия: `field`, `operator`, `value_type`, `value`, необязательные `cast`, `cast_options`, `on_cast_error` — см. [Тип сравнения (cast)](../03-user-guide/triggers-and-conditions.md#тип-сравнения-cast)), `preload`, `actions` — см. [Триггеры и условия](../03-user-guide/triggers-and-conditions.md), [Действия](../03-user-guide/actions.md), [Типы изменений и выражения](../03-user-guide/change-types-and-expressions.md). Элементы `actions` могут быть блоками с вложенными списками (`{"type":"condition","branches":[{"conditions":[…],"actions":[…]}],"else":[…]}`); у любого шага — необязательные `id` и `name`, см. [Вложенные блоки](../03-user-guide/actions.md#вложенные-блоки-и-поля-шага), а также `on_error` и `retry`, см. [Ошибки шага](../03-user-guide/actions.md#ошибки-шага-on_error-и-повторы) |

### `Execution` и `ExecutionAction`

```json
{
  "id": "7a0d6f22-aa92-4fef-ac54-5674b6b138eb", "process_id": 9, "process_name": "API doc test", "process_version": 2,
  "parent_execution_id": null, "trigger": "manual", "event_id": "manual-ce71f9d2-…",
  "object_type": "Tasks", "object_id": "T-1", "status": "completed", "retry_count": 0, "handled_errors": 0, "error_message": null,
  "started_at": "2026-10-15T08:02:39+00:00", "finished_at": "2026-10-15T08:02:39+00:00", "duration_ms": 3
}
```

- `trigger`: `stream` (событие CRM) | `manual` | `child`.
- `status`: `running` | `retrying` | `waiting` | `completed` | `failed` | `cancelled`. У ожидающего выполнения
  `waiting_until` (срок, UTC) и `wait_reason` (`delay` | `retry`), у остальных — `null`. `handled_errors` — число ошибок шагов, обработанных `on_error` или циклом с `on_item_error: continue`.

```json
{
  "id": 27, "step_index": 0, "action_type": "modify", "status": "success", "attempts": 1,
  "started_at": "2026-10-15T08:02:39+00:00", "duration_ms": 0,
  "request_data": { "value": "closed by WPS", "target": "object.description", "change_type": "set_value" },
  "result_data": { "new_value": "closed by WPS", "old_value": null },
  "error_message": null,
  "pc": 0, "step_path": "actions[0]", "iteration": "", "step_id": null,
  "error_code": null, "handled": null
}
```

- `action_type`: `preload` | `modify` | `save_object` | `start_process` (или свой тип).
- `status`: `success` | `waiting` | `failed` | `skipped`. `attempts` = 1 + число повторов.
- `step_index` — порядковый номер записи аудита (`seq`); `pc` — номер инструкции программы; `step_path` — место шага в
  определении (`actions[1].branches[0].actions[0]`); `iteration` — номер итерации цикла `foreach`, во вложенных циклах `"2/0"` (`""` вне циклов);
  `step_id` — пользовательский `id` шага. У записей, созданных до миграции `004`, `pc = step_index`, `step_path = null`.
- `action_type: modify` с `change_type: template` — `result_data.missing` (пути без значения, если были) и
  `result_data.warnings`.
- `action_type: modify` с `change_type: date_shift` — `result_data.base` (база в каноническом виде), `result_data.shifts`
  (`[{amount, unit}]`, числа из полей уже подставлены) и `result_data.missing_base` (`true`, если базы не было).
- `action_type: foreach` — запись блока цикла, одна на цикл: `request_data` — `source`, `as`, `max_items`, `on_overflow`,
  `on_item_error`, `has_where`; по окончании цикла `result_data` перезаписывается итогами (`count`, `processed`,
  `skipped_by_where`, `failed_items`, `failed_indexes`, `unsaved_items`, `truncated`, `source_missing`, `where_traces`,
  при ошибке `failed_at_index`), см. [Журнал выполнений](../03-user-guide/executions-and-audit.md#запись-цикла-foreach).
- `action_type: condition` — запись блока: `request_data` — `branches` (число веток), `has_else`; `result_data.selected`
  (индекс ветки, `"else"` или `"none"`) и `result_data.branches` — только проверенные ветки:
  `[{branch, name, result, conditions: [ConditionTrace]}]` (`name` — если задан). См.
  [Запись блока condition](../03-user-guide/executions-and-audit.md#запись-блока-condition).
- `action_type: stop_process` — запись блока: `request_data` — `status`, `code`, `message` (шаблон); `result_data` —
  `stopped: true`, `status`, `code`, `message` после подстановок, `message_error` при ошибке шаблона; следующие шаги —
  `skipped`. См. [Остановленное выполнение](../03-user-guide/executions-and-audit.md#остановленное-выполнение).
- `action_type: delay` — `request_data`: `mode`, поля срока, `due_at` (UTC), `due_at_local`; `result_data`: `waited`,
  `waited_seconds`, `inline` (пауза в корутине), `lateness_ms` (после парковки), `past_due` (момент уже прошёл). Пока
  выполнение ждёт, шаг в статусе `waiting`. См. [Пауза](../03-user-guide/actions.md#пауза-delay).

- У упавшего шага `error_code` (`HTTP_503`, `TIMEOUT`, `CONFIG`, `UNKNOWN_OUTCOME` …) и `handled` (`null` — ошибка
  остановила выполнение, `continue`, `goto`, `item_continue` — цикл `foreach` перешёл к следующему элементу); `result_data` содержит `error_class`, `recoverable`, `error_code`, `error`
  (`{code, message, transient, http_status?, retry_after?}`), `details`, при `goto` — `goto` (id цели). Внешние действия (`send_email`, `http_request`, `notify`) добавляют
  `idempotency_key` и `replayed` (`true` — ответ взят из журнала побочных эффектов без повторного вызова).

### `ProcessObjectRun` (отметка однократного запуска)

```json
{
  "process_id": 9, "object_type": "Tasks", "object_id": "T-1",
  "execution_id": "7a0d6f22-aa92-4fef-ac54-5674b6b138eb", "event_id": "manual-ce71f9d2-…", "trigger": "manual",
  "created_at": "2026-10-15T08:02:39+00:00", "blocked_count": 1, "last_blocked_at": "2026-10-15T08:02:39+00:00"
}
```

### `ConditionTrace`

```json
{ "field": "object.status", "operator": "equals", "value_type": "static", "value": "Done",
  "actual": "Open", "expected": "Done", "result": false }
```

`result` равен `null` для строки, которая не вычислялась. `actual` / `expected` — исходные значения сторон.
Если в строке задан `cast` (или `cast_options`), добавляются ключи:

```json
{ "field": "object.amount", "operator": "equals", "value_type": "static", "value": "100",
  "cast": "decimal", "cast_options": { "scale": 2 },
  "actual": "100.00", "expected": "100", "actual_cast": "100", "expected_cast": "100", "result": true }
```

| Ключ | Когда есть | Значение |
|---|---|---|
| `cast`, `cast_options` | задан тип | тип и непустые параметры |
| `actual_cast`, `expected_cast` | задан тип, сторона приведена | приведённое значение (дата — `Y-m-d`, дата и время — ISO 8601 в UTC, список — массив) |
| `missing` | поле отсутствует | `actual` или `expected` |
| `empty` | сторона пуста (типы, кроме `string` / `bool`) | `actual` или `expected` |
| `cast_error` | значение не приводится к типу | `{"side": "actual", "message": "Value \"abc\" is not a valid decimal"}`; у `in` / `not_in` для элемента — `side: "expected"`, `Item 1: …` |
| `found` | операторы `is_empty`, `is_not_empty`, `is_null`, `is_not_null` | найдено ли поле (`expected` — `null`) |
| `expected_cast` (список) | `in`, `not_in` | приведённые элементы списка |

## Аутентификация

### `POST /api/auth/login`

Публичный. Тело: `{ "email", "password" }`.

```bash
curl -s -X POST http://localhost:9501/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@wps.local","password":"admin"}'
```

`200`: `{ access_token, refresh_token, token_type: "Bearer", expires_in: 900, user: User }`.

Ошибки: `401 INVALID_CREDENTIALS` («Invalid email or password»); `422 VALIDATION_ERROR` (не указан `email` / `password`).

### `POST /api/auth/refresh`

Публичный. Тело: `{ "refresh_token": "…" }`. Ответ как у входа (новая пара; пользователь заново читается из базы).

```bash
curl -s -X POST http://localhost:9501/api/auth/refresh -H 'Content-Type: application/json' \
  -d '{"refresh_token":"'"$REFRESH"'"}'
```

Ошибки: `401 INVALID_TOKEN` («Refresh token is required», «Invalid or expired refresh token», «User no longer exists»).

### `GET /api/auth/me`

Любая роль. Возвращает текущего `User`.

```bash
curl -s http://localhost:9501/api/auth/me -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"id":1,"email":"admin@wps.local","role":"admin","created_at":"2026-10-15T07:48:53+00:00","updated_at":"2026-10-15T07:48:53+00:00"},"error":null}
```

## Пользователи (только admin)

### `GET /api/users`

Параметры: `page`, `per_page` (по умолчанию 50).

```bash
curl -s 'http://localhost:9501/api/users?per_page=2' -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"id":1,"email":"admin@wps.local","role":"admin",…},{"id":2,"email":"editor@example.com","role":"editor",…}],"error":null,"meta":{"page":1,"per_page":2,"total":3}}
```

### `GET /api/users/{id}`

```bash
curl -s http://localhost:9501/api/users/2 -H "Authorization: Bearer $TOKEN"
```

`200 User`; `404 USER_NOT_FOUND`.

### `POST /api/users`

Тело: `{ "email", "password" (не короче 6 символов), "role" }`.

```bash
curl -s -X POST http://localhost:9501/api/users -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"email":"analyst@example.com","password":"secret1","role":"viewer"}'
```

```json
{"success":true,"data":{"id":4,"email":"analyst@example.com","role":"viewer","created_at":"…","updated_at":"…"},"error":null}
```

`201 User`. Ошибки: `409 EMAIL_EXISTS`; `422 VALIDATION_ERROR`:

```json
{"field":"email","message":"A valid email is required"},
{"field":"password","message":"Password must be at least 6 characters"},
{"field":"role","message":"Role must be one of: admin, editor, viewer"}
```

### `PUT /api/users/{id}`

Тело: любые из `{ "email", "password", "role" }`. Пустой или отсутствующий `password` оставляет текущий пароль.

```bash
curl -s -X PUT http://localhost:9501/api/users/4 -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"role":"editor","password":""}'
```

`200 User`. Ошибки: `404 USER_NOT_FOUND`, `409 EMAIL_EXISTS`, `409 LAST_ADMIN` («The last administrator cannot lose the admin role»), `422`.

### `DELETE /api/users/{id}`

```bash
curl -s -X DELETE http://localhost:9501/api/users/4 -H "Authorization: Bearer $TOKEN"
```

`200 {"deleted": true}`. Ошибки: `404 USER_NOT_FOUND`, `409 CANNOT_DELETE_SELF`, `409 LAST_ADMIN`.

## Процессы

### `GET /api/processes`

Любая роль. Параметры запроса:

| Параметр | Описание |
|---|---|
| `search` | Подстрока названия, без учёта регистра |
| `object_type` | Точное имя модуля, например `Leads` |
| `is_child` | `true` / `false` (также `1` / `0`) |
| `is_active` | `true` / `false` |
| `trigger_on` | Процессы, у которых в `trigger_on` есть этот тип: `create`, `update` или `delete` |
| `page`, `per_page` | Пагинация (по умолчанию 20, максимум 1000) |

Сортировка — `updated_at DESC`. Удалённые процессы не возвращаются.

```bash
curl -s 'http://localhost:9501/api/processes?object_type=Leads&per_page=2' -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"id":6,"name":"New lead → schedule a call (once per lead)","object_type":"Leads","is_child":false,"is_active":true,"priority":0,"run_once":true,"version":1,"definition":{…},…},{"id":4,"name":"Create follow-up task",…}],"error":null,"meta":{"page":1,"per_page":2,"total":2}}
```

### `GET /api/processes/{id}`

Любая роль. `Process` и `references`: ведомые процессы, которые он запускает, и родители, которые запускают его.

```bash
curl -s http://localhost:9501/api/processes/6 -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"id":6,"name":"New lead → schedule a call (once per lead)",…,
 "definition":{"conditions":[…],"preload":[],"actions":[{"type":"start_process","process_id":4}]},
 "references":{"children":[{"id":4,"name":"Create follow-up task","is_active":true,"is_child":true}],"parents":[]}},"error":null}
```

`404 PROCESS_NOT_FOUND` («Process with id 999 not found»).

### `POST /api/processes`

Editor или admin. Тело: `{ name, object_type, is_child, is_active?, priority?, run_once?, trigger_on?, definition }`
(по умолчанию `is_active` — `true`, `priority` — `0`, `run_once` — `false`, `trigger_on` — `["create", "update"]`; повторы в
`trigger_on` убираются, порядок приводится к `create, update, delete`). Ошибки `trigger_on`: `Must be an array of event types`,
`Unknown event type "link" (allowed: create, update, delete)`; процесс только на `delete`, сохраняющий `object`, —
`The process runs only on delete: the deleted record cannot be saved` (поле `definition.actions[k]`). Definition проверяется в порядке выполнения,
все ошибки возвращаются сразу.

```bash
curl -s -X POST http://localhost:9501/api/processes -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "name": "API doc test", "object_type": "Tasks", "is_child": false, "is_active": false, "priority": 5,
  "definition": {
    "conditions": [{"field":"object.status","operator":"equals","value_type":"static","value":"Done"}],
    "preload": [],
    "actions": [{"type":"modify","target":"object.description","change_type":"set_value","value":"closed by WPS","save_to_crm":true}]
  }}'
```

```json
{"success":true,"data":{"id":9,"name":"API doc test","object_type":"Tasks","is_child":false,"is_active":false,"priority":5,"run_once":false,"version":1,"definition":{…},"created_at":"2026-10-15T08:02:29+00:00","updated_at":"2026-10-15T08:02:29+00:00"},"error":null}
```

`201 Process`. Если определение сохранено, но в нём есть потенциальная проблема, к процессу добавляется необязательный
массив `warnings` (тот же формат, что `details` ошибок), например
`"warnings":[{"field":"definition.actions[1].value","message":"Variable \"task\" may be undefined here: it is set only in some branches"}]`.
Ошибки:

```json
{"success":false,"data":null,"error":{"code":"VALIDATION_ERROR","message":"Validation failed","details":[
  {"field":"name","message":"Process name is required"},
  {"field":"definition.conditions[0].field","message":"Variable \"v9\" is not defined at this point"},
  {"field":"definition.actions[0].value","message":"Unknown function \"foo\""}]}}
```

- `422 VALIDATION_ERROR` — также `Process 6 "…" is not a child process`, `Process 42 not found`, `A process cannot start itself`.
- `422 CYCLE_DETECTED` — см. `PUT`.
- `400 BAD_REQUEST` — тело не является корректным JSON или это JSON-скаляр.

### `PUT /api/processes/{id}`

Editor или admin. Тело такое же, как у `POST` (процесс целиком); если `trigger_on` не передан, сохраняется текущее значение. `version` увеличивается. Кэш процессов для старого и нового
типа объекта сбрасывается, поэтому следующее событие использует новое definition; идущие выполнения сохраняют свою версию.

```bash
curl -s -X PUT http://localhost:9501/api/processes/9 -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "name": "API doc test", "object_type": "Tasks", "is_child": false, "is_active": true, "priority": 5, "run_once": true,
  "definition": {"conditions":[{"field":"object.status","operator":"equals","value_type":"static","value":"Done"}],
                 "preload":[],
                 "actions":[{"type":"modify","target":"object.description","change_type":"set_value","value":"closed by WPS","save_to_crm":false}]}}'
```

`200 Process` (`"version": 2`), при необходимости с `warnings`, как у `POST`. Ошибки: `404 PROCESS_NOT_FOUND`, `422 VALIDATION_ERROR` (в том числе при попытке сделать
процессом верхнего уровня ведомый процесс, который запускают другие), `422 CYCLE_DETECTED`:

```json
{"success":false,"data":null,"error":{"code":"CYCLE_DETECTED","message":"Process launch cycle detected: Doc cycle B → Doc cycle A → Doc cycle B",
 "details":{"path":[{"id":12,"name":"Doc cycle B"},{"id":13,"name":"Doc cycle A"},{"id":12,"name":"Doc cycle B"}]}}}
```

### `DELETE /api/processes/{id}`

Только admin. Мягкое удаление (`deleted_at`): процесс пропадает из списков и перестаёт запускаться, его выполнения остаются в аудите.

Параметр `force=true` нужен, если у процесса есть ожидающие выполнения (`waiting`): они отменяются — статус `cancelled`,
`error_message: "Process deleted"`, строки `execution_waits` удаляются. Деактивация процесса (`is_active: false`)
ожидающие выполнения не останавливает.

```bash
curl -s -X DELETE http://localhost:9501/api/processes/10 -H "Authorization: Bearer $TOKEN"
```

`200 {"deleted": true}`. Ошибки: `404 PROCESS_NOT_FOUND`, `409 PROCESS_HAS_WAITING_EXECUTIONS` (без `force=true`):

```json
{"success":false,"data":null,"error":{"code":"PROCESS_HAS_WAITING_EXECUTIONS",
 "message":"The process has 2 waiting executions; delete with force=true to cancel them","details":{"waiting":2}}}
```

`409 PROCESS_IN_USE`:

```json
{"success":false,"data":null,"error":{"code":"PROCESS_IN_USE","message":"The process is started by other processes",
 "details":[{"id":6,"name":"New lead → schedule a call (once per lead)"}]}}
```

### `POST /api/processes/{id}/clone`

Editor или admin. Создаёт **неактивную** копию с названием `<название> (копия)`. `run_once` и `trigger_on` копируются, отметки однократного
запуска — нет. Тело не нужно.

```bash
curl -s -X POST http://localhost:9501/api/processes/9/clone -H "Authorization: Bearer $TOKEN"
```

`201 Process` (`"name":"API doc test (копия)","is_active":false,"version":1`).

### `POST /api/processes/{id}/run`

Editor или admin. Ручной запуск с тестовым объектом. Тело: `{ "object": {…}, "force": false }`; если ключа `object` нет, объектом
считается всё тело. `object.id` обязателен. `object.module` (или `object_type`) по умолчанию равен типу объекта процесса.
`force: true` пропускает условия, но не однократный запуск. Условия проверяются синхронно, шаги выполняются в фоне.
Служебные поля события контракта 2.0 — `event_type` (`create` | `update` | `delete`), `before` (старые значения изменённых полей),
`changed_fields` — передаются в `object` на верхнем уровне; в запись они не попадают, а становятся переменной `object_before`
и `context.event_info` выполнения. Без них запуск считается событием старого формата (`object_before` = `null`).

```bash
curl -s -X POST http://localhost:9501/api/processes/9/run -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"object":{"id":"T-1","module":"Tasks","status":"Done"}}'
```

| Результат | HTTP | `data` |
|---|---|---|
| Условия выполнены, выполнение создано | `201` | `{"conditions_met":true,"execution_id":"7a0d6f22-…","conditions":[ConditionTrace],"run_once_applied":true,"run_once_blocked":false,"previous_execution_id":null}` |
| Условия не выполнены | `200` | `{"conditions_met":false,"execution_id":null,"conditions":[{"field":"object.status",…,"actual":"Open","expected":"Done","result":false}],…}` |
| Заблокировано однократным запуском | `200` | `{"conditions_met":true,"execution_id":null,"conditions":[],"run_once_applied":true,"run_once_blocked":true,"previous_execution_id":"7a0d6f22-…"}` |
| Строгое условие не смогло привести значение (`on_cast_error: "error"`) | `200` | `{"conditions_met":false,"execution_id":null,"conditions":[{…,"cast_error":{"side":"actual","message":"…"}}],…,"error":"CAST_ERROR","error_message":"Condition on object.amount: Value \"12.5\" is not an integer"}` |

`run_once_applied` равен `true`, когда у процесса включён `run_once` и у объекта есть `id`. За ходом выполнения следите через
`GET /api/executions/{execution_id}`. Ошибки: `404 PROCESS_NOT_FOUND`, `422 VALIDATION_ERROR`
(`object`: «A JSON object is required»; `object.id`: «The object must have an \"id\"»).

### `GET /api/processes/{id}/executions`

Любая роль. Параметры: `status` (`running` | `retrying` | `waiting` | `completed` | `failed` | `cancelled`), `date_from`, `date_to` (сравниваются со
`started_at`; дата-время ISO 8601, время без зоны считается UTC; дата `YYYY-MM-DD` в `date_to` означает «до конца этого дня»),
`page`, `per_page`. Сортировка — `started_at DESC`.

```bash
curl -s 'http://localhost:9501/api/processes/9/executions?status=completed' -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"id":"7a0d6f22-…","process_id":9,"process_name":"API doc test","process_version":2,"parent_execution_id":null,"trigger":"manual","event_id":"manual-ce71f9d2-…","object_type":"Tasks","object_id":"T-1","status":"completed","retry_count":0,"error_message":null,"started_at":"…","finished_at":"…","duration_ms":3}],"error":null,"meta":{"page":1,"per_page":20,"total":1}}
```

Ошибки: `404 PROCESS_NOT_FOUND`; `422` («Status must be one of: running, retrying, waiting, completed, failed, cancelled», «Invalid date»).

### `GET /api/processes/{id}/runs`

Любая роль. Отметки однократного запуска процесса, сортировка `created_at DESC`. Параметры: `object_id` (точное совпадение),
`page`, `per_page`.

```bash
curl -s http://localhost:9501/api/processes/9/runs -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"process_id":9,"object_type":"Tasks","object_id":"T-1","execution_id":"7a0d6f22-…","event_id":"manual-ce71f9d2-…","trigger":"manual","created_at":"…","blocked_count":1,"last_blocked_at":"…"}],"error":null,"meta":{"page":1,"per_page":20,"total":1}}
```

### `DELETE /api/processes/{id}/runs/{object_type}/{object_id}`

Editor или admin. Снимает отметку: следующее событие этой записи снова запустит процесс. Сегменты пути кодируйте для URL.

```bash
curl -s -X DELETE http://localhost:9501/api/processes/9/runs/Tasks/T-1 -H "Authorization: Bearer $TOKEN"
```

`200 {"deleted": true}`. Ошибки: `404 PROCESS_NOT_FOUND`; `404 NOT_FOUND` («Process 9 has no run mark for Tasks T-1»).

## Выполнения

### `GET /api/executions`

Любая роль. Выполнения всех процессов — `Execution[]` с пагинацией. Параметры: `status` (`running` | `retrying` |
`waiting` | `completed` | `failed` | `cancelled`), `process_id`, `due_before` (`waiting_until` не позже этой даты-времени —
«что проснётся до …»), `date_from`, `date_to` (по `started_at`, как в журнале процесса), `page`, `per_page`.

```bash
curl -s 'http://localhost:9501/api/executions?status=waiting&due_before=2026-11-01T00:00:00Z' -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"id":"5c1e…","process_id":12,"process_name":"Перезвонить через 2 дня","status":"waiting",
 "waiting_until":"2026-10-22T08:15:03+00:00","wait_reason":"delay","started_at":"2026-10-20T08:15:03+00:00","finished_at":null,…}],
 "error":null,"meta":{"page":1,"per_page":20,"total":1}}
```

Ошибки: `422` («Status must be one of: running, retrying, waiting, completed, failed, cancelled», `due_before` — «Invalid date»).

### `GET /api/executions/{id}`

Любая роль. `Execution`, а также:

| Поле | Описание |
|---|---|
| `context` | `{event, variables, meta, event_info, conditions, stop?}` — итоговый снимок; во время выполнения — живое состояние из Redis. `event` и `variables.object` — запись без служебных ключей события; `variables.object_before` — старые значения изменённых полей или `null`; `event_info` — `{event_type, changed_fields, has_before, origin, legacy}` (`changed_fields: null` — неизвестно, `origin` — `wps_origin` события); `stop` — `{status, code, message}`, если выполнение завершил шаг `stop_process` |
| `current_step` | Только во время выполнения: `pc` следующей инструкции программы |
| `seq` | Только во время выполнения: номер следующей записи аудита |
| `frames` | Только во время выполнения: фреймы открытых циклов `foreach` (`[]` вне циклов): `begin`, `next`, `end`, `as`, `loop_var`, `module`, `items_key`, `index`, `count`, `audit_seq`, `stats` … — без самих элементов (они в снимке `exec:{id}:loop:{pc}`) |
| `wait` | Только у ожидающего выполнения: `{due_at, reason, attempts, info}` — строка `execution_waits` (`info`: `pc`, `seq`, `mode`, `parked_at` …) |
| `actions` | `ExecutionAction[]` по `step_index` |
| `children` | `Execution[]`, запущенные действием `start_process` этого выполнения |

```bash
curl -s http://localhost:9501/api/executions/7a0d6f22-aa92-4fef-ac54-5674b6b138eb -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"id":"7a0d6f22-…","process_id":9,…,"status":"completed",
 "context":{"meta":{"object":{"module":"Tasks","source":"event"},"object_before":{"module":"Tasks","source":"event_before"}},
            "event":{"id":"T-1","module":"Tasks","status":"Done"},
            "variables":{"object":{"id":"T-1","module":"Tasks","status":"Done","description":"closed by WPS"},"object_before":null},
            "event_info":{"event_type":"update","changed_fields":null,"has_before":false,"origin":null,"legacy":true},
            "conditions":[{"field":"object.status","value":"Done","actual":"Done","result":true,"expected":"Done","operator":"equals","value_type":"static"}]},
 "actions":[{"id":27,"step_index":0,"action_type":"modify","status":"success","attempts":1,…}],
 "children":[]},"error":null}
```

`404 EXECUTION_NOT_FOUND` (в том числе для id, который не является UUID).

### `GET /api/executions/{id}/actions`

Любая роль. Только шаги (`ExecutionAction[]`).

```bash
curl -s http://localhost:9501/api/executions/7a0d6f22-aa92-4fef-ac54-5674b6b138eb/actions -H "Authorization: Bearer $TOKEN"
```

`404 EXECUTION_NOT_FOUND`.

### `GET /api/executions/{id}/side-effects`

Любая роль. Журнал внешних вызовов выполнения (таблица `step_side_effects`, без ответов внешних систем) — для разбора
`UNKNOWN_OUTCOME` и повторов ([Надёжность](../04-operations/reliability.md#побочные-эффекты-и-повторы)).

```bash
curl -s http://localhost:9501/api/executions/7a0d6f22-aa92-4fef-ac54-5674b6b138eb/side-effects -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
  {"effect_key":"3","action_type":"send_email","delivery":"at_most_once","status":"done","attempts":1,
   "started_at":"2026-10-19T19:40:02+00:00","finished_at":"2026-10-19T19:40:03+00:00","error_message":null}]}
```

- `effect_key` — `{pc}`, `{pc}@{iteration}` в циклах, `#{sub}` для нескольких вызовов в шаге.
- `status`: `started` (вызов начат, исход неизвестен), `done`, `failed`.
- Выполнение без внешних вызовов — пустой список. `404 EXECUTION_NOT_FOUND`.

## Секреты

Учётные данные внешних действий (см. [Секреты](../03-user-guide/secrets.md)). Значение секрета только на запись: ни один
эндпоинт его не возвращает. Объект `Secret`:

```json
{ "name": "erp_token", "kind": "bearer", "description": "ERP API", "mask": "****cdef", "allowed_hosts": ["erp.example.com"],
  "version": 2, "created_at": "2026-10-19T19:29:33Z", "updated_at": "2026-10-19T19:40:00Z", "last_used_at": "2026-10-19T19:41:02Z" }
```

У составных видов (`basic_auth`, `oauth2_client`) `mask` — объект: несекретные поля как есть, остальные замаскированы
(`{"username":"erp","password":"********"}`).

Ошибки раздела: `503 SECRETS_KEY_NOT_CONFIGURED` (не задан `WPS_SECRETS_KEY`), `409 SECRET_EXISTS`, `409 SECRET_IN_USE`,
`404 NOT_FOUND`, `422 VALIDATION_ERROR`, `409 ROTATION_FAILED`.

### `GET /api/secrets`

admin, editor. `Secret[]`, отсортированы по имени, у каждого ещё `usages_count` — число ссылок из неудалённых процессов.

```json
{"success":true,"data":[{"name":"erp_token","kind":"bearer","description":"ERP API","mask":"****cdef","allowed_hosts":[],
 "version":1,"created_at":"…","updated_at":"…","last_used_at":null,"usages_count":1}],"error":null}
```

### `GET /api/secrets/{name}`

admin, editor. `Secret` + `usages: [{process_id, process_name, path}]` (`path` — поле в определении, например
`definition.actions[1].auth.token`). Нет секрета — `404 NOT_FOUND`.

### `POST /api/secrets`

admin. Тело `{ name, kind?, value, description?, allowed_hosts? }` → `201 Secret` (без значения).

```bash
curl -s -X POST http://localhost:9501/api/secrets -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"erp_basic","kind":"basic_auth","value":{"username":"erp","password":"p@ssw0rd"}}'
```

Ошибки валидации (`422`, поле → сообщение): `name` — `Secret name must match ^[a-z][a-z0-9_]{1,63}$`; `kind` —
`Unknown secret kind (allowed: …)`; `value` — `Secret value is required`, `Secret value is too short (min 4 characters)`,
`Value must be a string`, `Value must be an object for kind "basic_auth"`, `Invalid mailer DSN (…)`,
`Value must be an http(s) URL`; `value.password` — `Field "password" is required for kind "basic_auth"`;
`value.token_url` — `Token URL must use https`; `allowed_hosts[0]` — `Invalid host "…"`. Имя занято — `409 SECRET_EXISTS`.

### `PUT /api/secrets/{name}`

admin. Тело `{ value?, description?, allowed_hosts? }` → `Secret`. Новое значение увеличивает `version`. Имя и вид
поменять нельзя (`422`: `A secret cannot be renamed (processes refer to it by name)`,
`The kind of a secret cannot be changed`). Воркеры подхватывают новое значение в пределах `SECRETS_CACHE_TTL`.

### `DELETE /api/secrets/{name}`

admin. `{ "deleted": true }`. Если на секрет ссылаются неудалённые процессы — `409 SECRET_IN_USE`, в `details` —
`{ "usages": [{process_id, process_name, path}] }`.

### `POST /api/secrets/rotate`

admin. Перешифровывает текущим ключом все секреты, зашифрованные прежним (`WPS_SECRETS_KEY_PREVIOUS`) →
`{ "reencrypted": N }`. Строка, которую нельзя расшифровать ни одним ключом, — `409 ROTATION_FAILED`.

## Почта

### `POST /api/mail/test`

admin. Отправляет тестовое письмо, чтобы проверить настройки почты без процесса. Тело: `{ "to": "<адрес>", "transport"?:
"secret://smtp_main" }`; без `transport` — транспорт по умолчанию `MAILER_DSN`.

```bash
curl -s -X POST http://localhost:9501/api/mail/test -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"to":"you@company.ru"}'
```

```json
{"success":true,"data":{"sent":true,"to":"you@company.ru","message_id":"27xdaVLBnTJG95wPWc7fmU"},"error":null}
```

Ошибка отправки — тоже `200`: `{ "sent": false, "error": "<текст ошибки SMTP, пароль замаскирован>", "error_code": "SMTP_AUTH" }`.
Неверный адрес — `422` (`to`: `Invalid email address`). Роль editor или viewer — `403`.

## Уведомления

### `POST /api/notify/test`

admin, editor. Отправляет тестовое уведомление в **один** канал действия [`notify`](../03-user-guide/actions.md#уведомление),
чтобы проверить канал без процесса. Тело: `{ "channel": { …канал как в channels… }, "message"?: "текст" }`; заголовок —
«WPS», текст по умолчанию — «Это тестовое уведомление WPS. Канал работает.», `delivery: at_least_once`.

```bash
curl -s -X POST http://localhost:9501/api/notify/test -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"channel":{"channel":"telegram","connection":"secret://sales_bot","chat_id":"-1001234567890"}}'
```

```json
{"success":true,"data":{"sent":true,"result":{"channels":[{"channel":"telegram","status":"success","deliveries":[{"connection":"secret://sales_bot","chat_id":"-1001234567890","message_id":42,"replayed":false}]}]}},"error":null}
```

Ошибка отправки — тоже `200`: `{ "sent": false, "error": "notify: 1 of 1 channels failed: telegram: HTTP 403 …" }`
(секреты замаскированы). Ошибки конфигурации канала — `422` с полями `channel.…` (`channel.connection`: `Connection
secret is required (secret://name)` и др.). Роль viewer — `403`.

## Справочники

Все возвращают `[{ "value", "label" }]`, у типов действий есть ещё `description`. Подписи — на русском; переводите по `value`.

### `GET /api/dictionaries/modules`

Любая роль. Модули CRM, кэшируются в Redis на `DICT_CACHE_TTL` (300 с). Источник: `MOCK_SERVICE_URL/modules`, затем `/modules`
CRM (см. [Интеграция с CRM](../04-operations/crm-integration.md#справочник-модулей)).

```bash
curl -s http://localhost:9501/api/dictionaries/modules -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"Accounts","label":"Контрагенты"},{"value":"Contacts","label":"Контакты"},{"value":"Leads","label":"Лиды"},…],"error":null}
```

### `POST /api/dictionaries/modules/refresh`

Editor или admin. Сбрасывает кэш, загружает список заново и возвращает его. Заодно перечитывает возможности CRM (`crm-capabilities`): после обновления плагина CRM достаточно этого запроса.

```bash
curl -s -X POST http://localhost:9501/api/dictionaries/modules/refresh -H "Authorization: Bearer $TOKEN"
```

### `GET /api/dictionaries/crm-capabilities`

Любая роль. Версия контракта WPS ↔ CRM и возможности плагина CRM ([Интеграция с CRM](../04-operations/crm-integration.md#возможности-и-деградация)).
Кэшируется в Redis `crm:capabilities` на `DICT_CACHE_TTL`, сбрасывается `POST /api/dictionaries/modules/refresh`.

```bash
curl -s http://localhost:9501/api/dictionaries/crm-capabilities -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"contract_version":"2.0",
 "features":["filter_operators","sort","cursor_pagination","fields","count","delete","relationships","relationship_fields","event_v2","delete_events","origin_headers"],
 "limits":{"max_page_size":100,"max_link_batch":100,"max_in_values":100},
 "crm":{"name":"SuiteCRM","version":"8.10.2","plugin_version":"1.2.0"},
 "source":"crm","cached_at":"2026-10-20T09:00:00+00:00"},"error":null}
```

`source`: `crm` — ответ `GET {CRM}/capabilities`; `fallback` — у CRM нет этого эндпоинта (старый плагин): `contract_version: "1.0"`,
`features: []`. Если CRM недоступна — `503 CRM_UNAVAILABLE`.

### `GET /api/dictionaries/operators`

```bash
curl -s http://localhost:9501/api/dictionaries/operators -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"equals","label":"Равен","casts":["string","int","decimal","date","datetime","bool","list"],"operands":"value"},
 {"value":"not_equals","label":"Не равен","casts":["string","int","decimal","date","datetime","bool","list"],"operands":"value"},
 {"value":"greater_than","label":"Больше","casts":["int","decimal","date","datetime"],"operands":"value"},
 {"value":"greater_or_equal","label":"Больше или равно","casts":["int","decimal","date","datetime"],"operands":"value"},
 {"value":"less_than","label":"Меньше","casts":["int","decimal","date","datetime"],"operands":"value"},
 {"value":"less_or_equal","label":"Меньше или равно","casts":["int","decimal","date","datetime"],"operands":"value"},
 {"value":"between","label":"В диапазоне","casts":["int","decimal","date","datetime"],"operands":"range"},
 {"value":"not_between","label":"Вне диапазона","casts":["int","decimal","date","datetime"],"operands":"range"},
 {"value":"in","label":"Входит в","casts":["string","int","decimal","date"],"operands":"list"},
 {"value":"not_in","label":"Не входит в","casts":["string","int","decimal","date"],"operands":"list"},
 {"value":"contains","label":"Включает","casts":["string","list"],"operands":"value"},
 {"value":"not_contains","label":"Не включает","casts":["string","list"],"operands":"value"},
 {"value":"starts_with","label":"Начинается с","casts":["string"],"operands":"value"},
 {"value":"ends_with","label":"Заканчивается на","casts":["string"],"operands":"value"},
 {"value":"contains_any","label":"Содержит любое из","casts":["list"],"operands":"list"},
 {"value":"contains_all","label":"Содержит все из","casts":["list"],"operands":"list"},
 {"value":"contains_none","label":"Не содержит ни одного из","casts":["list"],"operands":"list"},
 {"value":"is_empty","label":"Пусто","casts":["string","int","decimal","date","datetime","bool","list"],"operands":"none"},
 {"value":"is_not_empty","label":"Не пусто","casts":["…"],"operands":"none"},
 {"value":"is_null","label":"Данные отсутствуют","casts":["…"],"operands":"presence"},
 {"value":"is_not_null","label":"Данные есть","casts":["…"],"operands":"presence"},
 {"value":"changed","label":"Изменилось","casts":["string","int","decimal","date","datetime","bool","list"],"operands":"change"},
 {"value":"not_changed","label":"Не изменилось","casts":["…"],"operands":"change"},
 {"value":"changed_to","label":"Изменилось на","casts":["…"],"operands":"change_value"},
 {"value":"changed_from","label":"Изменилось с","casts":["…"],"operands":"change_value"}],"error":null}
```

`casts` — типы `cast`, с которыми работает оператор; `operands` — форма правой части: `value` — одно значение, `list` —
массив значений (`value` — JSON-массив или путь к полю-списку, разбор строки — `value_list_options`), `none` и
`presence` — значения нет (`value` и `value_type` не указываются), `range` — пара `[от, до]` (`between`, `not_between`:
обе границы статические или обе — пути при `from_object_field`). Для операторов порядка `cast` обязателен.
`change` (`changed`, `not_changed`) и `change_value` (`changed_to`, `changed_from`) — операторы изменения поля объекта события
(только `object.<поле>`, все типы `cast`): у `change` значения нет, у `change_value` — обязательное `value`. Строка трассы
условия дополняется ключами `before`, `before_cast`, `changed`, `event_type`, а без сведений об изменениях —
`change_info: "missing"` / `"delete"`. См. [Условия на изменение поля](../03-user-guide/triggers-and-conditions.md#условия-на-изменение-поля).
См. [Тип сравнения (cast)](../03-user-guide/triggers-and-conditions.md#тип-сравнения-cast).

### `GET /api/dictionaries/functions`

Функции и операторы языка выражений (тип изменения **Расчёт**). Доступно любой роли.

```bash
curl -s http://localhost:9501/api/dictionaries/functions -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"name":"add_days","category":"date","signature":"add_days(d, n)","min_args":2,"max_args":2,"returns":"date|datetime","description":"Прибавляет календарные дни; местное время сохраняется","example":"add_days(today(), 3)","pure":true},
 {"name":"date_diff","category":"date","signature":"date_diff(from, to, unit)","min_args":3,"max_args":3,"returns":"number","description":"…","example":"date_diff(object.date_modified, now(), 'days')","pure":true},
 {"name":"now","category":"date","signature":"now()","min_args":0,"max_args":0,"returns":"datetime","description":"Текущий момент, UTC","example":"now()","pure":false},
 … остальные функции дат …,
 {"name":"coalesce","category":"logic","signature":"coalesce(a, b, …)","min_args":1,"max_args":null,"returns":"any","description":"Первое непустое значение (не null и не пустая строка)","example":"coalesce(object.phone_mobile, object.phone_work, v1.phone_office)","pure":true},
 {"name":"if","category":"logic","signature":"if(cond, then[, else])","min_args":2,"max_args":3,"returns":"any","description":"Значение then, если cond истинно, иначе else (или пустая строка)","example":"if(object.amount > 100000, 'VIP', 'Regular')","pure":true},
 {"name":"ifempty","category":"logic","signature":"ifempty(a, default)","min_args":2,"max_args":2,"returns":"any","description":"…","example":"ifempty(object.title, 'Без названия')","pure":true},
 {"name":"ifs","category":"logic","signature":"ifs(cond1, value1, cond2, value2, …[, default])","min_args":2,"max_args":null,"returns":"any","description":"…","example":"…","pure":true},
 {"name":"switch","category":"logic","signature":"switch(x, key1, value1, key2, value2, …[, default])","min_args":3,"max_args":null,"returns":"any","description":"…","example":"…","pure":true},
 {"name":"abs","category":"number","signature":"abs(x)","min_args":1,"max_args":1,"returns":"number","description":"Модуль числа","example":"abs(object.balance)","pure":true},
 {"name":"max","category":"number","signature":"max(a, b, …)","min_args":1,"max_args":null,"returns":"number","description":"…","example":"…","pure":true},
 {"name":"min","category":"number","signature":"min(a, b, …)","min_args":1,"max_args":null,"returns":"number","description":"…","example":"…","pure":true},
 {"name":"round","category":"number","signature":"round(x[, digits])","min_args":1,"max_args":2,"returns":"number","description":"Округление до digits знаков","example":"round(object.amount * 0.9, 2)","pure":true},
 {"name":"concat","category":"string","signature":"concat(a, b, …)","min_args":1,"max_args":null,"returns":"string","description":"Склеивает значения в одну строку","example":"concat(v1.first_name, ' ', v1.last_name)","pure":true},
 {"name":"len","category":"string","signature":"len(s)","min_args":1,"max_args":1,"returns":"number","description":"…","example":"…","pure":true},
 {"name":"lower","category":"string","signature":"lower(s)","min_args":1,"max_args":1,"returns":"string","description":"…","example":"…","pure":true},
 {"name":"substr","category":"string","signature":"substr(s, start[, length])","min_args":2,"max_args":3,"returns":"string","description":"…","example":"…","pure":true},
 {"name":"trim","category":"string","signature":"trim(s)","min_args":1,"max_args":1,"returns":"string","description":"…","example":"…","pure":true},
 {"name":"upper","category":"string","signature":"upper(s)","min_args":1,"max_args":1,"returns":"string","description":"…","example":"…","pure":true}],
 "meta":{"operators":[
 {"op":"or","synonyms":["||"],"precedence":1},
 {"op":"and","synonyms":["&&"],"precedence":2},
 {"op":"not","synonyms":["!"],"precedence":3},
 {"op":"==","synonyms":["="],"precedence":4},
 {"op":"!=","synonyms":["<>"],"precedence":4},
 {"op":"<","synonyms":[],"precedence":4},
 {"op":"<=","synonyms":[],"precedence":4},
 {"op":">","synonyms":[],"precedence":4},
 {"op":">=","synonyms":[],"precedence":4},
 {"op":"+","synonyms":[],"precedence":5},
 {"op":"-","synonyms":[],"precedence":5},
 {"op":"*","synonyms":[],"precedence":6},
 {"op":"/","synonyms":[],"precedence":6},
 {"op":"%","synonyms":[],"precedence":6}]},"error":null}
```

Элементы `data` отсортированы по `category`, затем по `name`. Функции дат (категория `date`) описаны в разделе [Даты и время](../03-user-guide/change-types-and-expressions.md#даты-и-время); `format_date_locale` есть, только если в образе установлен `intl`:

| Поле | Описание |
|---|---|
| `name` | имя функции (нижний регистр) |
| `category` | `logic`, `string`, `number`, `date`, `list`, `type`, `system` или `custom` (функция, зарегистрированная без описания) |
| `signature` | сигнатура; `[…]` — необязательные аргументы, `…` — переменное число |
| `min_args`, `max_args` | допустимое число аргументов; `max_args: null` — без верхней границы. Проверяется при сохранении процесса: `Function "if" expects 2..3 arguments, got 1` → `422` |
| `returns` | тип результата: `string`, `number`, `bool`, `date`, `datetime`, `date\|datetime` (как у аргумента), `any` |
| `description`, `example` | описание и пример вызова |
| `pure` | `true` — результат зависит только от аргументов; `false` — от текущего времени (`now`, `today`): при повторе шага значение вычисляется заново |

`meta.operators` — операторы: `op`, синонимы `synonyms` и приоритет `precedence` (1 — самый слабый: `or`; 6 — самый
сильный: `*` `/` `%`). Операторы с приоритетом 4 (сравнения) не объединяются в цепочку. Подробнее —
[Типы изменений и выражения](../03-user-guide/change-types-and-expressions.md#синтаксис).

### `GET /api/dictionaries/action-types`

```bash
curl -s http://localhost:9501/api/dictionaries/action-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"preload","label":"Предзагрузка объекта","description":"Загружает объект из CRM по ID или другому полю в переменную процесса","kind":"action"},
 {"value":"modify","label":"Изменить объект/переменную","description":"…","kind":"action"},
 {"value":"save_object","label":"Сохранить объект в CRM","description":"…","kind":"action"},
 {"value":"start_process","label":"Запустить следующий процесс","description":"…","kind":"action"},
 {"value":"send_email","label":"Отправить письмо","description":"Отправляет письмо через SMTP: получатели, тема и тело — шаблоны с подстановками","kind":"action"},
 {"value":"http_request","label":"HTTP-запрос","description":"Вызывает внешний API (вебхук): URL, заголовки и тело — шаблоны, ответ сохраняется в переменную","kind":"action"},
 {"value":"link_records","label":"Связать записи","description":"Связывает запись с другими записями через link-поле CRM (участники встречи, контакты контрагента), можно с полями связи","kind":"action"},
 {"value":"unlink_records","label":"Отвязать записи","description":"Удаляет связи записи с другими записями через link-поле CRM","kind":"action"},
 {"value":"set_variable","label":"Записать в переменную процесса","description":"Сохраняет промежуточное значение в переменную, которая не является записью CRM","kind":"action"},
 {"value":"find_records","label":"Найти записи","description":"Находит записи CRM по фильтру или связанные записи и сохраняет список в переменную","kind":"action"},
 {"value":"update_records","label":"Изменить записи списка","description":"Меняет поля у всех записей списка, найденного шагом «Найти записи», одним шагом","kind":"action"},
 {"value":"assign","label":"Назначить ответственного","description":"Назначает ответственного по правилу: по кругу, наименее загруженного, случайно, руководителя","kind":"action"},
 {"value":"notify","label":"Уведомление","description":"Отправляет короткое уведомление в колокольчик CRM, Telegram, Slack или Microsoft Teams","kind":"action"},
 {"value":"delay","label":"Пауза","description":"Приостанавливает выполнение на срок или до даты; длинная пауза не занимает воркер и переживает перезапуск","kind":"action"},
 {"value":"condition","label":"Условие (если / иначе)","description":"…","kind":"block"},
 {"value":"stop_process","label":"Завершить процесс","description":"…","kind":"block"},
 {"value":"foreach","label":"Для каждого элемента","description":"Выполняет вложенные действия для каждого элемента списка","kind":"block"}],"error":null}
```

Элементы — типы действий (`kind: "action"`) и блоки (`kind: "block"`): и те, и другие задаются полем `type` строки `actions`.

### `GET /api/dictionaries/change-types`

```bash
curl -s http://localhost:9501/api/dictionaries/change-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"set_value","label":"Равен значению"},{"value":"set_from_variable","label":"Равен переменной из поля"},{"value":"calculate","label":"Расчет"},{"value":"template","label":"Шаблон"},{"value":"date_shift","label":"Сдвиг даты"},{"value":"increment","label":"Увеличить на"},{"value":"decrement","label":"Уменьшить на"},{"value":"clear","label":"Очистить"},{"value":"append","label":"Дописать в конец"},{"value":"prepend","label":"Дописать в начало"},{"value":"remove","label":"Удалить из списка"},{"value":"map","label":"По таблице соответствия"},{"value":"aggregate","label":"Агрегат по записям"}],"error":null}
```

### `GET /api/dictionaries/error-policies`

Значения `on_error` шага ([Ошибки шага](../03-user-guide/actions.md#ошибки-шага-on_error-и-повторы)).

```json
{"success":true,"data":[{"value":"stop","label":"Остановить процесс"},{"value":"continue","label":"Продолжить"},{"value":"goto","label":"Перейти к шагу"}],"error":null}
```

### `GET /api/dictionaries/retry-classes`

Классы ошибок для `retry.retry_on`; кроме них допустим трёхзначный код HTTP (`"409"`).

```json
{"success":true,"data":[
 {"value":"transient","label":"Временные ошибки: сеть, таймаут, 5xx, 408/425/429 (по умолчанию)"},
 {"value":"5xx","label":"Ответы HTTP 5xx"},
 {"value":"4xx","label":"Ответы HTTP 4xx"},
 {"value":"any","label":"Любая ошибка, кроме ошибок конфигурации и неизвестного исхода"}
],"error":null}
```

### `GET /api/dictionaries/notify-channels`

Любая роль. Каналы действия `notify` и виды секретов, которые подходят для их `connection` (пустой список — канал без
подключения).

```bash
curl -s http://localhost:9501/api/dictionaries/notify-channels -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"crm_alert","label":"Уведомление в CRM","secret_kinds":[]},{"value":"telegram","label":"Telegram","secret_kinds":["telegram_bot","generic"]},{"value":"slack","label":"Slack","secret_kinds":["webhook_url","generic"]},{"value":"teams","label":"Microsoft Teams","secret_kinds":["webhook_url","generic"]}],"error":null}
```

### `GET /api/dictionaries/aggregate-functions`

Функции типа изменения `aggregate` ([Агрегат по записям](../03-user-guide/change-types-and-expressions.md#агрегат-по-записям)).

```bash
curl -s http://localhost:9501/api/dictionaries/aggregate-functions -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"count","label":"Количество"},{"value":"count_distinct","label":"Количество различных"},{"value":"sum","label":"Сумма"},{"value":"avg","label":"Среднее"},{"value":"min","label":"Минимум"},{"value":"max","label":"Максимум"}],"error":null}
```

### `GET /api/dictionaries/assign-strategies`

Любая роль. Стратегии действия `assign` ([Назначить ответственного](../03-user-guide/actions.md#назначить-ответственного)).

```bash
curl -s http://localhost:9501/api/dictionaries/assign-strategies -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"round_robin","label":"По кругу"},{"value":"least_busy","label":"Наименее загруженный"},{"value":"random","label":"Случайно"},{"value":"manager","label":"Руководитель"},{"value":"user","label":"Конкретный пользователь"}],"error":null}
```

### `GET /api/dictionaries/date-units`

Единицы типа изменения `date_shift` ([Сдвиг даты](../03-user-guide/change-types-and-expressions.md#сдвиг-даты)). Рабочих дней нет.

```bash
curl -s http://localhost:9501/api/dictionaries/date-units -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"minute","label":"Минуты"},{"value":"hour","label":"Часы"},{"value":"day","label":"Дни"},{"value":"week","label":"Недели"},{"value":"month","label":"Месяцы"},{"value":"year","label":"Годы"}],"error":null}
```

### `GET /api/dictionaries/relative-dates`

Любая роль. Якоря и единицы [относительных дат](../03-user-guide/triggers-and-conditions.md#относительные-даты) в условиях и фильтрах и часовой пояс, в котором считаются якоря (`WPS_TIMEZONE`). Ответ — объект, а не список.

```bash
curl -s http://localhost:9501/api/dictionaries/relative-dates -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"anchors":[{"value":"now","label":"Сейчас"},{"value":"today","label":"Сегодня"},{"value":"yesterday","label":"Вчера"},{"value":"tomorrow","label":"Завтра"},{"value":"start_of_day","label":"Начало дня"},"…",{"value":"end_of_year","label":"Конец года"}],"units":[{"value":"min","label":"минут"},{"value":"h","label":"часов"},{"value":"d","label":"дней"},{"value":"w","label":"недель"},{"value":"mo","label":"месяцев"},{"value":"y","label":"лет"}],"timezone":"Europe/Moscow"},"error":null}
```

### `GET /api/dictionaries/value-types`

Любая роль. Значения поля `value_type` строки условия: `static`, `from_object_field` и `expression` — правая часть
вычисляется выражением ([Сравнение с выражением](../03-user-guide/triggers-and-conditions.md#сравнение-с-выражением)).

```bash
curl -s http://localhost:9501/api/dictionaries/value-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"static","label":"Значение"},{"value":"from_object_field","label":"Из поля объекта/переменной"},{"value":"expression","label":"Выражение"}],"error":null}
```

### `GET /api/dictionaries/event-types`

Типы событий CRM для поля процесса `trigger_on` ([Тип события](../03-user-guide/triggers-and-conditions.md#тип-события-и-поле-запускать-при-trigger_on)).

```bash
curl -s http://localhost:9501/api/dictionaries/event-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"create","label":"Создание записи"},{"value":"update","label":"Изменение записи"},{"value":"delete","label":"Удаление записи"}],"error":null}
```

### `GET /api/dictionaries/template-filters`

Фильтры шаблонов ([Шаблон](../03-user-guide/change-types-and-expressions.md#шаблон)): `{{ x | f:a:b }}` вызывает функцию
выражений `f(x, a, b)`, поэтому в списке — все функции, принимающие значение первым аргументом (без `now()`), и
псевдофильтр `raw`.

```bash
curl -s http://localhost:9501/api/dictionaries/template-filters -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"truncate","label":"Обрезает текст до length символов вместе с suffix","category":"string","signature":"truncate(s, length[, suffix='…'])","min_args":1,"max_args":2,"example":"{{ topic.text | truncate:40 }}"},
 {"value":"upper","label":"Строка в верхнем регистре","category":"string","signature":"upper(s)","min_args":0,"max_args":0,"example":"{{ object.field | upper }}"},
 {"value":"raw","label":"Без HTML-экранирования (только там, где действие это разрешает)","category":"template","signature":"raw","min_args":0,"max_args":0,"example":"{{ v1.signature_html | raw }}"}
],"error":null}
```

| Поле | Значение |
|---|---|
| `value` | имя фильтра |
| `label` | описание (из описания функции) |
| `category` | категория функции (`string`, `number`, `date`, `logic`) или `template` для `raw` |
| `signature` | сигнатура функции; первый аргумент — значение слева от `\|` |
| `min_args`, `max_args` | число аргументов **фильтра** (на один меньше, чем у функции); `max_args: null` — без ограничения |
| `example` | пример использования в шаблоне |

### `GET /api/dictionaries/preload-sources`

```bash
curl -s http://localhost:9501/api/dictionaries/preload-sources -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"object_field","label":"Из поля объекта"},{"value":"variable_field","label":"Из поля переменной"},{"value":"manual","label":"Значение вручную"}],"error":null}
```

### `GET /api/dictionaries/casts`

```bash
curl -s http://localhost:9501/api/dictionaries/casts -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"string","label":"Строка","orderable":false,"options":[{"name":"case_sensitive","type":"bool","default":true},{"name":"trim","type":"bool","default":false}]},
 {"value":"int","label":"Целое число","orderable":true,"options":[]},
 {"value":"decimal","label":"Десятичное число","orderable":true,"options":[{"name":"scale","type":"int","default":10,"min":0,"max":20}]},
 {"value":"date","label":"Дата","orderable":true,"options":[{"name":"format","type":"string|list","default":null},{"name":"timezone","type":"string","default":"UTC"}]},
 {"value":"datetime","label":"Дата и время","orderable":true,"options":[{"name":"format","type":"string|list","default":null},{"name":"timezone","type":"string","default":"UTC"}]},
 {"value":"bool","label":"Логическое","orderable":false,"options":[]},
 {"value":"list","label":"Список","orderable":false,"options":[{"name":"separator","type":"string","default":","},{"name":"wrapper","type":"string","default":null},{"name":"case_sensitive","type":"bool","default":true}]}],"error":null}
```

Типы для поля `cast` строки условия. `orderable` — тип поддерживает сравнения «больше / меньше»; `options` — допустимые
параметры `cast_options` со значениями по умолчанию. `case_sensitive: false` — складывание регистра Unicode обеих сторон,
`trim: true` — обрезка краёв с неразрывным пробелом и BOM (см. [Регистр и пробелы](../03-user-guide/triggers-and-conditions.md#регистр-и-пробелы)).

### `GET /api/dictionaries/delivery-policies`

Любая роль. Значения поля `delivery` действий с внешними эффектами.

```json
{"success":true,"data":[{"value":"at_most_once","label":"Не более одного раза"},{"value":"at_least_once","label":"Не менее одного раза"}]}
```

По умолчанию `send_email` — `at_most_once`, `http_request` — `at_most_once` для `POST`/`PATCH` и `at_least_once` для
остальных методов. Поле `delivery` допустимо только у действий с внешними эффектами (`send_email`, `http_request`, `notify`); у остальных
сохранение процесса отклоняется: `Action "modify" does not support "delivery"`. Неверное значение —
`Delivery must be "at_most_once" or "at_least_once"`.

Шаг `http_request` (подробно — [Действия → HTTP-запрос](../03-user-guide/actions.md#http-запрос)): `request_data` —
`method`, `url`, `headers` (только имена), `body_size`, `body_preview`, `delivery`; `result_data` — `status`, `duration_ms`,
`var`, `replayed`, `response`. Переменная `var` в `context.variables`: `status`, `ok`, `headers`, `body` / `body_text` /
`body_truncated` и поля `response.extract`.

Шаги `link_records` / `unlink_records` (подробно — [Действия → Связать / отвязать записи](../03-user-guide/actions.md#связать--отвязать-записи)):
`request_data` — `record`, `link`, `module`, `record_id`, `related_module`, `related_ids` (первые 100), `related_count`, для
`link_records` — `fields`, `batches`; `result_data` — `linked`, `updated`, `not_found`, `warnings` (для `unlink_records` —
`removed`, `not_linked`), `missing_paths`. Без `id` у записи или без связанных id шаг `skipped`. Нужен контракт CRM 2.0
(`relationships`), иначе `CRM_CAPABILITY_MISSING`.

Шаг `find_records` (подробно — [Действия → Найти записи](../03-user-guide/actions.md#найти-записи)): `request_data` — `var`,
`limit`, `module` / `related_to`, `filter`, `sort`, `fields`, в режиме модуля — `crm_filter` (что ушло в CRM) и `crm_requests`;
`result_data` — `count`, `has_more`, первые 100 `ids`, `reason`, `warnings`. Переменная-список `{items, count, has_more, ids}` в
`context.variables`, в `context.meta.<имя>.source` — `list`.

Шаг `set_variable` (подробно — [Действия → Записать в переменную процесса](../03-user-guide/actions.md#записать-в-переменную-процесса)):
`request_data` — `var`, `fields` (`имя → {change_type, value}`); `result_data.fields.<имя>` — `old_value`, `new_value`.
Переменная в `context.variables`, в `context.meta.<имя>.source` — `local`.

### `GET /api/dictionaries/mail-transports`

admin, editor. Есть ли транспорт по умолчанию (`MAILER_DSN`) и имена секретов вида `smtp_dsn` (без значений) — для поля
`transport` действия `send_email`.

```json
{"success":true,"data":{"default_configured":true,"secrets":["smtp_main","smtp_support"]},"error":null}
```

### `GET /api/dictionaries/secret-kinds`

```bash
curl -s http://localhost:9501/api/dictionaries/secret-kinds -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"generic","label":"Произвольный","composite":false,"fields":[]},
 {"value":"smtp_dsn","label":"SMTP (DSN)","composite":false,"fields":[]},
 {"value":"basic_auth","label":"Basic-авторизация","composite":true,"fields":[{"name":"username","required":true,"secret":false},{"name":"password","required":true,"secret":true}]},
 {"value":"bearer","label":"Bearer-токен","composite":false,"fields":[]},
 {"value":"api_key","label":"API-ключ","composite":false,"fields":[]},
 {"value":"oauth2_client","label":"OAuth2 client credentials","composite":true,"fields":[{"name":"token_url","required":true,"secret":false},{"name":"client_id","required":true,"secret":false},{"name":"client_secret","required":true,"secret":true},{"name":"scope","required":false,"secret":false}]},
 {"value":"webhook_url","label":"URL вебхука","composite":false,"fields":[]},
 {"value":"telegram_bot","label":"Токен Telegram-бота","composite":false,"fields":[]}],"error":null}
```

Виды секретов для поля `kind`; `composite` — значение-объект, `fields` — его поля (`secret: false` — поле показывается в маске как есть).

## Служебные

### `GET /api/health`

Публичный. `200`, если PostgreSQL и оба Redis отвечают, иначе `503` и `"status": "degraded"`.

```bash
curl -s http://localhost:9501/api/health
```

```json
{"success":true,"data":{"status":"ok","checks":{"postgres":{"status":"ok","latency_ms":0},"redis_data":{"status":"ok","latency_ms":0},"redis_stream":{"status":"ok","latency_ms":0}},"stream":{"stream":"crm_events","group":"wps_workers","length":15,"lag":0,"pending":0,"consumers":2},"time":"2026-10-15T08:03:03+00:00"},"error":null}
```

Значение полей: [Мониторинг](../04-operations/monitoring.md#проверка-состояния-get-apihealth).

### `GET /metrics`

Публичный. Текстовый формат Prometheus без общей обёртки. Доступен только на порту backend (nginx UI его не проксирует).

```bash
curl -s http://localhost:9501/metrics
```

```text
# HELP wps_events_received_total Всего получено событий
# TYPE wps_events_received_total counter
wps_events_received_total 15
…
# TYPE wps_redis_stream_lag gauge
wps_redis_stream_lag 0
```

Список метрик — в разделе [Мониторинг](../04-operations/monitoring.md#метрики-get-metrics).

<!-- footer-nav -->
---

← Назад: [Справочник API: основы](index.md) | [Оглавление](../README.md) | [Среда разработки](../07-developer-guide/dev-environment.md) Далее →
<!-- /footer-nav -->
