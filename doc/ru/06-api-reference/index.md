# Справочник API: основы

Базовый адрес, аутентификация, формат ответа, пагинация, роли и коды ошибок REST API WPS.

<!-- lang-switch -->
[🇬🇧 English](../../en/06-api-reference/index.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Базовый адрес

| Доступ | Адрес |
|---|---|
| Напрямую к backend | `http://localhost:9501` (`WPS_API_PORT`) |
| Через nginx UI | `http://localhost:<WPS_UI_PORT>/api/...` (только `/api/`; `/metrics` не проксируется) |

Все эндпоинты, кроме `/metrics`, начинаются с `/api`. Тела запросов и ответов — JSON (`Content-Type: application/json`);
тело запроса должно быть JSON-объектом. Слэш в конце пути игнорируется (`/api/processes/` = `/api/processes`). Время — UTC в
формате ISO 8601 (`2026-10-15T08:02:39+00:00`).

Полный список эндпоинтов с примерами — в разделе [Эндпоинты](endpoints.md).

## Аутентификация

```bash
# 1. вход
curl -s -X POST http://localhost:9501/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@wps.local","password":"admin"}'
```

```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…",
    "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…",
    "token_type": "Bearer",
    "expires_in": 900,
    "user": { "id": 1, "email": "admin@wps.local", "role": "admin", "created_at": "…", "updated_at": "…" }
  },
  "error": null
}
```

```bash
# 2. запрос к API с access-токеном
TOKEN=$(curl -s -X POST http://localhost:9501/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@wps.local","password":"admin"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["access_token"])')
curl -s http://localhost:9501/api/processes -H "Authorization: Bearer $TOKEN"

# 3. получили 401 TOKEN_EXPIRED — обменяйте refresh-токен на новую пару
curl -s -X POST http://localhost:9501/api/auth/refresh -H 'Content-Type: application/json' \
  -d '{"refresh_token":"<refresh-токен>"}'
```

Access-токен живёт `JWT_ACCESS_TTL` секунд (900), refresh-токен — `JWT_REFRESH_TTL` (7 дней). Подробнее:
[Безопасность](../04-operations/security.md).

## Формат ответа

Успех:

```json
{ "success": true, "data": { }, "error": null, "meta": { "page": 1, "per_page": 20, "total": 100 } }
```

`meta` есть только у списков с пагинацией.

Ошибка:

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "details": [ { "field": "definition.actions[0].value", "message": "Unknown function \"foo\"" } ]
  }
}
```

Ключ `details` есть, только если у ошибки есть подробности (иначе его в объекте `error` нет): список `{field, message}` для ошибок валидации, объект для
`CYCLE_DETECTED` (`{"path": [{id, name}, …]}`), список процессов `[{id, name}]` для `PROCESS_IN_USE`,
`{"waiting": N}` для `PROCESS_HAS_WAITING_EXECUTIONS`, `{"usages": [...]}` для `SECRET_IN_USE`. `field` — путь в теле запроса, например
`name`, `definition.conditions[0].field` или `definition.preload[1].module`.

Исключения: `GET /metrics` возвращает текст Prometheus; `GET /api/health` использует общий формат, но при деградации отвечает `503`.

## Пагинация

Списки принимают `page` (с 1) и `per_page` (1…1000; по умолчанию 20, у пользователей 50) и возвращают `meta`:

```bash
curl -s 'http://localhost:9501/api/processes?page=2&per_page=10' -H "Authorization: Bearer $TOKEN"
```

```json
"meta": { "page": 2, "per_page": 10, "total": 23 }
```

Некорректные значения приводятся к границам (`page=0` → 1, `per_page=0` → 1, `per_page=5000` → 1000).

Пагинацию поддерживают `GET /api/users`, `/api/processes`, `/api/processes/{id}/executions`, `/api/processes/{id}/runs` и
`/api/executions`. Остальные списки (`/api/secrets`, `/api/executions/{id}/actions`, `/api/executions/{id}/side-effects`,
справочники) отдаются целиком и без `meta`.

Булевы параметры запроса (`is_child`, `is_active`, `force`) принимают `true` / `1` и `false` / `0`; другое значение — как если
бы параметра не было.

## Роли

| Роль | Может |
|---|---|
| `viewer` | Читать процессы, выполнения, журнал внешних вызовов, отметки однократного запуска, справочники (кроме `mail-transports`) |
| `editor` | Всё, что `viewer`, а также создавать, изменять, клонировать и вручную запускать процессы, снимать отметки однократного запуска, обновлять справочник модулей, видеть секреты (имена и маски, без значений) и справочник `mail-transports`, отправлять тестовое уведомление (`POST /api/notify/test`) |
| `admin` | Всё, что `editor`, а также удалять процессы, управлять пользователями, создавать, менять, удалять и перешифровывать секреты, отправлять тестовое письмо (`POST /api/mail/test`) |

Правила проверяются по порядку, срабатывает первое совпадение (`Wps\Auth\AccessPolicy::RULES`):

| Метод | Путь | Роли |
|---|---|---|
| любой | `/api/health`, `/metrics` | публичный |
| `POST` | `/api/auth/login`, `/api/auth/refresh` | публичный |
| любой | `/api/users`, `/api/users/…` | admin |
| `GET` | `/api/secrets`, `/api/secrets/{name}` | admin, editor |
| любой | остальные `/api/secrets/…` | admin |
| любой | `/api/mail/…` | admin |
| `GET` | `/api/dictionaries/mail-transports` | admin, editor |
| `DELETE` | `/api/processes/{id}/runs/{object_type}/{object_id}` | admin, editor |
| `DELETE` | `/api/processes/{id}` | admin |
| `POST`, `PUT` | `/api/processes`, `/api/processes/{id}`, `…/clone`, `…/run` | admin, editor |
| `POST` | `/api/dictionaries/modules/refresh` | admin, editor |
| `GET` | любой другой `/api/…` | любая |
| прочие методы | всё остальное (например `POST /api/notify/test`) | admin, editor |

Роль для каждого эндпоинта указана в разделе [Эндпоинты](endpoints.md); таблица правил — в разделе
[Безопасность](../04-operations/security.md#правила-доступа-wpsauthaccesspolicy).

## Коды ошибок

| HTTP | Код | Когда |
|---|---|---|
| 400 | `BAD_REQUEST` | Тело — некорректный JSON или JSON-скаляр, например `"text"` («Request body must be a JSON object»). Пустое тело считается `{}`; JSON-массив здесь не отклоняется и падает уже на проверке полей |
| 401 | `UNAUTHORIZED` | Нет заголовка `Authorization: Bearer` |
| 401 | `INVALID_TOKEN` | Неверная подпись, повреждённый токен, не тот тип токена, некорректный refresh-токен, пользователь удалён |
| 401 | `TOKEN_EXPIRED` | Срок access-токена истёк |
| 401 | `INVALID_CREDENTIALS` | Неверный e-mail или пароль при входе |
| 403 | `FORBIDDEN` | Роль не разрешает операцию |
| 404 | `NOT_FOUND` | Неизвестный маршрут или метод («Route not found»: например `PATCH`, для него нет `405`); нечисловой id в URL («Invalid id»); нет отметки однократного запуска для сброса; нет секрета |
| 404 | `PROCESS_NOT_FOUND` | Процесса нет или он удалён |
| 404 | `EXECUTION_NOT_FOUND` | Выполнения нет или id — не UUID |
| 404 | `USER_NOT_FOUND` | Пользователя нет |
| 409 | `EMAIL_EXISTS` | Пользователь с таким e-mail уже есть |
| 409 | `PROCESS_IN_USE` | Процесс запускают другие процессы (они перечислены в `details`) |
| 409 | `PROCESS_HAS_WAITING_EXECUTIONS` | У удаляемого процесса есть ожидающие выполнения, а `force=true` не передан |
| 409 | `SECRET_EXISTS` | Секрет с таким именем уже есть |
| 409 | `SECRET_IN_USE` | Секрет используют неудалённые процессы (`details.usages`) |
| 409 | `ROTATION_FAILED` | При ротации есть секрет, который не расшифровывается ни текущим, ни прежним ключом |
| 409 | `CANNOT_DELETE_SELF` | Пользователь пытается удалить свою учётную запись |
| 409 | `LAST_ADMIN` | Удаление или понижение последнего администратора |
| 422 | `VALIDATION_ERROR` | Некорректные данные; поля перечислены в `details` |
| 422 | `CYCLE_DETECTED` | `start_process` создаст цикл запуска; цепочка — в `details.path` |
| 500 | `INTERNAL_ERROR` | Непредвиденная ошибка («Internal server error»; настоящий текст — только при `APP_DEBUG=true`) |
| 503 | `SECRETS_KEY_NOT_CONFIGURED` | Не задан `WPS_SECRETS_KEY`, а эндпоинту секретов нужен ключ |
| 503 | `CRM_UNAVAILABLE` | `GET /api/dictionaries/crm-capabilities`: CRM не отвечает |
| 503 | — | `GET /api/health`: недоступна одна из зависимостей (`"status": "degraded"`, общий формат) |

Сообщения `401`: «Authentication required» (`UNAUTHORIZED`), «Token expired» (`TOKEN_EXPIRED`), «Malformed token», «Invalid token
signature», «Unsupported token», «Wrong token type», «Invalid token payload», «Invalid or expired refresh token», «User no longer
exists» (`INVALID_TOKEN`), «Invalid email or password» (`INVALID_CREDENTIALS`). Сообщение `403` — «Insufficient permissions».

Ошибки выполнения шагов процесса (`HTTP_503`, `TIMEOUT`, `CRM_CAPABILITY_MISSING`, `CAST_ERROR` …) — это не коды ответа API: они
записываются в аудит выполнения (`error_code` шага), см. [Эндпоинты → Execution](endpoints.md#execution-и-executionaction).

## CORS

На запросы `OPTIONS` сервер отвечает `204` с заголовками `Access-Control-Allow-Origin` (`CORS_ALLOW_ORIGIN`, по умолчанию `*`),
`Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`, `Access-Control-Allow-Headers: Authorization, Content-Type`,
`Access-Control-Max-Age: 600`.

## Язык

UI передаёт `Accept-Language`, но API его не использует. Сообщения API и подписи справочников (поля `label`) фиксированы:
сообщения — на английском, подписи справочников — на русском. Клиенты переводят справочники по `value`.

<!-- footer-nav -->
---

← Назад: [Архитектурные решения](../05-architecture/decisions.md) | [Оглавление](../README.md) | [Справочник API: эндпоинты](endpoints.md) Далее →
<!-- /footer-nav -->
