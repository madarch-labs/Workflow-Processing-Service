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
| `GET` | [`/api/executions/{id}`](#get-apiexecutionsid) | любая | Выполнение с контекстом, шагами и ведомыми |
| `GET` | [`/api/executions/{id}/actions`](#get-apiexecutionsidactions) | любая | Шаги выполнения |
| `GET` | [`/api/dictionaries/modules`](#get-apidictionariesmodules) | любая | Модули CRM (из кэша) |
| `POST` | [`/api/dictionaries/modules/refresh`](#post-apidictionariesmodulesrefresh) | editor+ | Перезагрузить модули CRM |
| `GET` | [`/api/dictionaries/operators`](#get-apidictionariesoperators) | любая | Операторы сравнения |
| `GET` | [`/api/dictionaries/action-types`](#get-apidictionariesaction-types) | любая | Типы действий |
| `GET` | [`/api/dictionaries/change-types`](#get-apidictionarieschange-types) | любая | Типы изменений |
| `GET` | [`/api/dictionaries/preload-sources`](#get-apidictionariespreload-sources) | любая | Источники предзагрузки |
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
| `version` | Увеличивается при каждом `PUT`; выполнения хранят версию, с которой работали |
| `definition` | `conditions`, `preload`, `actions` — см. [Триггеры и условия](../03-user-guide/triggers-and-conditions.md), [Действия](../03-user-guide/actions.md), [Типы изменений и выражения](../03-user-guide/change-types-and-expressions.md) |

### `Execution` и `ExecutionAction`

```json
{
  "id": "7a0d6f22-aa92-4fef-ac54-5674b6b138eb", "process_id": 9, "process_name": "API doc test", "process_version": 2,
  "parent_execution_id": null, "trigger": "manual", "event_id": "manual-ce71f9d2-…",
  "object_type": "Tasks", "object_id": "T-1", "status": "completed", "retry_count": 0, "error_message": null,
  "started_at": "2026-10-15T08:02:39+00:00", "finished_at": "2026-10-15T08:02:39+00:00", "duration_ms": 3
}
```

- `trigger`: `stream` (событие CRM) | `manual` | `child`.
- `status`: `running` | `retrying` | `completed` | `failed`.

```json
{
  "id": 27, "step_index": 0, "action_type": "modify", "status": "success", "attempts": 1,
  "started_at": "2026-10-15T08:02:39+00:00", "duration_ms": 0,
  "request_data": { "value": "closed by WPS", "target": "object.description", "change_type": "set_value" },
  "result_data": { "new_value": "closed by WPS", "old_value": null },
  "error_message": null
}
```

- `action_type`: `preload` | `modify` | `save_object` | `start_process` (или свой тип).
- `status`: `success` | `failed` | `skipped`. `attempts` = 1 + число повторов.

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

`result` равен `null` для строки, которая не вычислялась.

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

Editor или admin. Тело: `{ name, object_type, is_child, is_active?, priority?, run_once?, definition }`
(по умолчанию `is_active` — `true`, `priority` — `0`, `run_once` — `false`). Definition проверяется в порядке выполнения,
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

`201 Process`. Ошибки:

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

Editor или admin. Тело такое же, как у `POST` (процесс целиком). `version` увеличивается. Кэш процессов для старого и нового
типа объекта сбрасывается, поэтому следующее событие использует новое definition; идущие выполнения сохраняют свою версию.

```bash
curl -s -X PUT http://localhost:9501/api/processes/9 -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "name": "API doc test", "object_type": "Tasks", "is_child": false, "is_active": true, "priority": 5, "run_once": true,
  "definition": {"conditions":[{"field":"object.status","operator":"equals","value_type":"static","value":"Done"}],
                 "preload":[],
                 "actions":[{"type":"modify","target":"object.description","change_type":"set_value","value":"closed by WPS","save_to_crm":false}]}}'
```

`200 Process` (`"version": 2`). Ошибки: `404 PROCESS_NOT_FOUND`, `422 VALIDATION_ERROR` (в том числе при попытке сделать
процессом верхнего уровня ведомый процесс, который запускают другие), `422 CYCLE_DETECTED`:

```json
{"success":false,"data":null,"error":{"code":"CYCLE_DETECTED","message":"Process launch cycle detected: Doc cycle B → Doc cycle A → Doc cycle B",
 "details":{"path":[{"id":12,"name":"Doc cycle B"},{"id":13,"name":"Doc cycle A"},{"id":12,"name":"Doc cycle B"}]}}}
```

### `DELETE /api/processes/{id}`

Только admin. Мягкое удаление (`deleted_at`): процесс пропадает из списков и перестаёт запускаться, его выполнения остаются в аудите.

```bash
curl -s -X DELETE http://localhost:9501/api/processes/10 -H "Authorization: Bearer $TOKEN"
```

`200 {"deleted": true}`. Ошибки: `404 PROCESS_NOT_FOUND`, `409 PROCESS_IN_USE`:

```json
{"success":false,"data":null,"error":{"code":"PROCESS_IN_USE","message":"The process is started by other processes",
 "details":[{"id":6,"name":"New lead → schedule a call (once per lead)"}]}}
```

### `POST /api/processes/{id}/clone`

Editor или admin. Создаёт **неактивную** копию с названием `<название> (копия)`. `run_once` копируется, отметки однократного
запуска — нет. Тело не нужно.

```bash
curl -s -X POST http://localhost:9501/api/processes/9/clone -H "Authorization: Bearer $TOKEN"
```

`201 Process` (`"name":"API doc test (копия)","is_active":false,"version":1`).

### `POST /api/processes/{id}/run`

Editor или admin. Ручной запуск с тестовым объектом. Тело: `{ "object": {…}, "force": false }`; если ключа `object` нет, объектом
считается всё тело. `object.id` обязателен. `object.module` (или `object_type`) по умолчанию равен типу объекта процесса.
`force: true` пропускает условия, но не однократный запуск. Условия проверяются синхронно, шаги выполняются в фоне.

```bash
curl -s -X POST http://localhost:9501/api/processes/9/run -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"object":{"id":"T-1","module":"Tasks","status":"Done"}}'
```

| Результат | HTTP | `data` |
|---|---|---|
| Условия выполнены, выполнение создано | `201` | `{"conditions_met":true,"execution_id":"7a0d6f22-…","conditions":[ConditionTrace],"run_once_applied":true,"run_once_blocked":false,"previous_execution_id":null}` |
| Условия не выполнены | `200` | `{"conditions_met":false,"execution_id":null,"conditions":[{"field":"object.status",…,"actual":"Open","expected":"Done","result":false}],…}` |
| Заблокировано однократным запуском | `200` | `{"conditions_met":true,"execution_id":null,"conditions":[],"run_once_applied":true,"run_once_blocked":true,"previous_execution_id":"7a0d6f22-…"}` |

`run_once_applied` равен `true`, когда у процесса включён `run_once` и у объекта есть `id`. За ходом выполнения следите через
`GET /api/executions/{execution_id}`. Ошибки: `404 PROCESS_NOT_FOUND`, `422 VALIDATION_ERROR`
(`object`: «A JSON object is required»; `object.id`: «The object must have an \"id\"»).

### `GET /api/processes/{id}/executions`

Любая роль. Параметры: `status` (`running` | `retrying` | `completed` | `failed`), `date_from`, `date_to` (сравниваются со
`started_at`; дата-время ISO 8601, время без зоны считается UTC; дата `YYYY-MM-DD` в `date_to` означает «до конца этого дня»),
`page`, `per_page`. Сортировка — `started_at DESC`.

```bash
curl -s 'http://localhost:9501/api/processes/9/executions?status=completed' -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"id":"7a0d6f22-…","process_id":9,"process_name":"API doc test","process_version":2,"parent_execution_id":null,"trigger":"manual","event_id":"manual-ce71f9d2-…","object_type":"Tasks","object_id":"T-1","status":"completed","retry_count":0,"error_message":null,"started_at":"…","finished_at":"…","duration_ms":3}],"error":null,"meta":{"page":1,"per_page":20,"total":1}}
```

Ошибки: `404 PROCESS_NOT_FOUND`; `422` («Status must be one of: running, retrying, completed, failed», «Invalid date»).

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

### `GET /api/executions/{id}`

Любая роль. `Execution`, а также:

| Поле | Описание |
|---|---|
| `context` | `{event, variables, meta, conditions}` — итоговый снимок; во время выполнения — живое состояние из Redis |
| `current_step` | Только во время выполнения: номер следующего шага |
| `actions` | `ExecutionAction[]` по `step_index` |
| `children` | `Execution[]`, запущенные действием `start_process` этого выполнения |

```bash
curl -s http://localhost:9501/api/executions/7a0d6f22-aa92-4fef-ac54-5674b6b138eb -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":{"id":"7a0d6f22-…","process_id":9,…,"status":"completed",
 "context":{"meta":{"object":{"module":"Tasks","source":"event"}},
            "event":{"id":"T-1","module":"Tasks","status":"Done"},
            "variables":{"object":{"id":"T-1","module":"Tasks","status":"Done","description":"closed by WPS"}},
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

Editor или admin. Сбрасывает кэш, загружает список заново и возвращает его.

```bash
curl -s -X POST http://localhost:9501/api/dictionaries/modules/refresh -H "Authorization: Bearer $TOKEN"
```

### `GET /api/dictionaries/operators`

```bash
curl -s http://localhost:9501/api/dictionaries/operators -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"equals","label":"Равен"},{"value":"not_equals","label":"Не равен"},{"value":"contains","label":"Включает"},{"value":"not_contains","label":"Не включает"}],"error":null}
```

### `GET /api/dictionaries/action-types`

```bash
curl -s http://localhost:9501/api/dictionaries/action-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[
 {"value":"preload","label":"Предзагрузка объекта","description":"Загружает объект из CRM по ID или другому полю в переменную процесса"},
 {"value":"modify","label":"Изменить объект/переменную","description":"…"},
 {"value":"save_object","label":"Сохранить объект в CRM","description":"…"},
 {"value":"start_process","label":"Запустить следующий процесс","description":"…"}],"error":null}
```

### `GET /api/dictionaries/change-types`

```bash
curl -s http://localhost:9501/api/dictionaries/change-types -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"set_value","label":"Равен значению"},{"value":"set_from_variable","label":"Равен переменной из поля"},{"value":"calculate","label":"Расчет"}],"error":null}
```

### `GET /api/dictionaries/preload-sources`

```bash
curl -s http://localhost:9501/api/dictionaries/preload-sources -H "Authorization: Bearer $TOKEN"
```

```json
{"success":true,"data":[{"value":"object_field","label":"Из поля объекта"},{"value":"variable_field","label":"Из поля переменной"},{"value":"manual","label":"Значение вручную"}],"error":null}
```

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
