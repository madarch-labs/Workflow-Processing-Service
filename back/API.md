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

### Структура `definition`

**Условия** (`conditions[]`, все строки объединяются по И; пустой массив — запуск всегда):
```json
{ "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }
```
- `field` — путь `object.<поле>` или `<переменная>.<поле>` (например `v1.rating`).
- `operator` — `equals` | `not_equals` | `contains` | `not_contains` (справочник `/api/dictionaries/operators`).
- `value_type` — `static` (значение) | `from_object_field` (`value` — путь к полю объекта или переменной).
- Если поле отсутствует в объекте — условие ложно.
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
{ "type": "save_object", "target": "v1" }
{ "type": "start_process", "process_id": 42 }
```
- `modify.target` — `object.<поле>` или `<переменная>.<поле>`; переменная, которой нет в предзагрузке, создаётся как новый объект.
- `change_type` — `set_value` | `set_from_variable` | `calculate` (справочник `/api/dictionaries/change-types`).
- `save_to_crm: true` — сразу после изменения переменная сохраняется в CRM (как `save_object`).
- `save_object` — отправляет изменённые поля переменной в CRM (`PUT`, при отсутствии `id` — `POST`);
  необязательное поле `module` задаёт модуль для новых переменных.
- `start_process` — асинхронно запускает ведомый (`is_child = true`) процесс, передавая ему событие и все переменные; не ждёт завершения.
  Допустима форма `{ "type": "start_process", "process_ids": [42, 43] }`.
- `preload` допустим и в `actions[]` (загрузка посреди процесса) с теми же полями, что в блоке предзагрузки.

**Выражения `calculate`**: числа, строки в кавычках (`'abc'`, `"abc"`), пути (`object.amount`, `v1.price`),
операторы `+ - * / %`, скобки. `+` складывает числа и конкатенирует, если хотя бы один операнд — не число.
Функции: `concat(a, b, …)`, `upper(s)`, `lower(s)`, `trim(s)`, `len(s)`, `substr(s, start[, length])`,
`round(x[, precision])`, `abs(x)`, `min(a, b, …)`, `max(a, b, …)`. Отсутствующее поле даёт пустую строку.

### Эндпоинты

| Метод | URI | Роль | Описание |
|---|---|---|---|
| GET | `/api/processes?search=&object_type=&is_child=true\|false&is_active=true\|false&page=1&per_page=20` | любая | Список, `meta` с пагинацией. Сортировка: `updated_at DESC`. `per_page` ≤ 1000 |
| GET | `/api/processes/{id}` | любая | `Process` + `references: { children: [{id,name,is_active,is_child}], parents: [{id,name}] }` |
| POST | `/api/processes` | editor+ | Тело: `{ name, object_type, is_child, is_active?, priority?, run_once?, definition }` → `201 Process` |
| PUT | `/api/processes/{id}` | editor+ | То же тело → `Process` (`version` + 1) |
| DELETE | `/api/processes/{id}` | admin | `{ "deleted": true }`; `409 PROCESS_IN_USE` если на него ссылается `start_process` |
| POST | `/api/processes/{id}/clone` | editor+ | `201 Process` — копия «<имя> (копия)», неактивна. `run_once` копируется, отметки — нет |
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

`object.id` обязателен (id записи CRM), без него ответ `422`.

- Условия выполнены → `201`: `{ "conditions_met": true, "execution_id": "<uuid>", "conditions": [ConditionTrace], "run_once_applied": bool, "run_once_blocked": false, "previous_execution_id": null }`;
  действия выполняются в фоне, прогресс виден через `GET /api/executions/{id}`.
- Условия не выполнены → `200`: `{ "conditions_met": false, "execution_id": null, "conditions": [ConditionTrace], … }`.
- У процесса `run_once`, для записи уже есть отметка → `200`: `{ "conditions_met": true, "run_once_blocked": true,
  "execution_id": null, "previous_execution_id": "<uuid>", … }`. `force: true` пропускает только условия, однократный
  запуск он не обходит.
- `run_once_applied` равен `true`, когда проверка однократности выполнялась: у процесса `run_once` и у объекта есть `id`.

`ConditionTrace`: `{ field, operator, value_type, value, actual, expected, result: true|false|null }`.

## Выполнения (аудит)

`Execution`:
```json
{
  "id": "uuid", "process_id": 1, "process_name": "…", "process_version": 3,
  "parent_execution_id": null, "trigger": "stream" ,
  "event_id": "1718000000000-0", "object_type": "Accounts", "object_id": "123",
  "status": "running", "retry_count": 0, "error_message": null,
  "started_at": "…", "finished_at": null, "duration_ms": null
}
```
- `trigger`: `stream` | `manual` | `child`.
- `status`: `running` | `retrying` | `completed` | `failed`.

`ExecutionAction` (шаг):
```json
{
  "id": 10, "step_index": 0, "action_type": "preload", "status": "success",
  "attempts": 1, "started_at": "…", "duration_ms": 12,
  "request_data": {}, "result_data": {}, "error_message": null
}
```
- `status`: `success` | `failed` | `skipped`.

| Метод | URI | Описание |
|---|---|---|
| GET | `/api/executions/{id}` | `Execution` + `context` (`{ event, variables, conditions }`; пока выполняется — живой контекст из Redis) + `actions: ExecutionAction[]` + `children: Execution[]` |
| GET | `/api/executions/{id}/actions` | `ExecutionAction[]` |

## Справочники

Все — `[{ "value": "…", "label": "…" }]` (у типов действий есть ещё `description`).

| Метод | URI | Роль |
|---|---|---|
| GET | `/api/dictionaries/modules` | любая (модули CRM, кэш Redis `dict:modules` 300 с) |
| POST | `/api/dictionaries/modules/refresh` | editor+ (сброс кэша и повторная загрузка) |
| GET | `/api/dictionaries/operators` | любая |
| GET | `/api/dictionaries/action-types` | любая |
| GET | `/api/dictionaries/change-types` | любая |
| GET | `/api/dictionaries/preload-sources` | любая |

## Служебные

| Метод | URI | Описание |
|---|---|---|
| GET | `/api/health` | Публичный. `200` / `503`, `data: { status: "ok"\|"degraded", checks: { postgres, redis_data, redis_stream }, stream: {...} }` |
| GET | `/metrics` | Публичный. Метрики Prometheus (text format) |
