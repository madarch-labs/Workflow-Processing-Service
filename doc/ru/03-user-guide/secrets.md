# Секреты

Как администратор хранит учётные данные внешних систем в WPS и как процесс ссылается на них, не видя значения.

<!-- lang-switch -->
[🇬🇧 English](../../en/03-user-guide/secrets.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Зачем

Внешним действиям нужны учётные данные: пароль SMTP, токен API, ключ Telegram-бота, client secret OAuth2. Класть их в
определение процесса нельзя: определение видят все роли, оно попадает в аудит и в выгрузки. Поэтому значение хранится в
WPS отдельно и зашифрованно, а процесс содержит только ссылку `secret://name`.

- Администратор заводит секрет и при необходимости меняет его значение — процессы править не нужно.
- Редактор видит имя, вид и маску секрета и ссылается на него в процессе, но значение не видит.
- В аудите выполнения вместо значения — `***`.

Как устроено шифрование, ключ и его ротация — [Безопасность → Секреты процессов](../04-operations/security.md#секреты-процессов).
Интерфейса для секретов пока нет: они управляются через API (`/api/secrets`, см.
[Справочник API](../06-api-reference/endpoints.md#секреты)).

## Секрет

| Поле | Тип | Описание |
|---|---|---|
| `name` | строка | Имя для ссылок: `^[a-z][a-z0-9_]{1,63}$` (латиница в нижнем регистре, цифры, `_`, от 2 символов), уникально. Переименовать нельзя — процессы ссылаются по имени |
| `kind` | строка | Вид (таблица ниже), задаётся при создании и не меняется. По умолчанию `generic` |
| `value` | строка или объект | Значение: строка для простых видов, объект для составных (`basic_auth`, `oauth2_client`). Только на запись — API его никогда не возвращает |
| `description` | строка | Свободный текст |
| `allowed_hosts` | список строк | Необязательный список хостов, на которые секрет можно отправлять: `api.example.com`, `*.example.com`, с портом или без (`api.example.com:8443`), без схемы. Проверяется полем `auth` действия [`http_request`](actions.md#http-запрос): запрос на другой хост завершается ошибкой — так редактор не сможет отправить токен на свой сервер. Пустой список — без ограничений |
| `mask` | строка или объект | Только чтение. `****` + 4 последних символа для значений от 12 символов, иначе `********`; у составных — объект, где несекретные поля показаны как есть |
| `version` | целое | Только чтение. Растёт при каждой смене значения |
| `created_at`, `updated_at` | дата-время | Только чтение |
| `last_used_at` | дата-время | Только чтение. Когда секрет последний раз расшифровывался для шага (обновляется не чаще раза в 5 минут на воркер) |

### Виды секретов

Справочник — `GET /api/dictionaries/secret-kinds` (`value`, `label`, `composite`, `fields: [{name, required, secret}]`).

| `kind` | Значение | Проверка при сохранении | Где используется |
|---|---|---|---|
| `generic` | строка | не короче 4 символов | любое поле учётных данных, где допустим «произвольный» секрет: `transport` письма, `url` HTTP-запроса, `connection` уведомлений, `auth` HTTP-запроса |
| `smtp_dsn` | строка DSN: `smtp://user:pass@smtp.example.com:587`, `smtps://…` | формат `схема://…` (`Invalid mailer DSN (expected e.g. smtp://user:pass@smtp.example.com:587)`) | `transport` действия [`send_email`](actions.md#отправить-письмо) |
| `basic_auth` | `{ "username", "password" }` | оба поля обязательны, `password` не короче 4 символов; `username` не секретен и виден в маске | `auth: {"type": "basic", "credentials": …}` действия `http_request` |
| `bearer` | строка-токен | не короче 4 символов | `auth: {"type": "bearer", "token": …}` |
| `api_key` | строка | не короче 4 символов | `auth: {"type": "api_key", "key": …}` |
| `oauth2_client` | `{ "token_url", "client_id", "client_secret", "scope"? }` | `token_url`, `client_id`, `client_secret` обязательны; `token_url` — только `https://`; `token_url`, `client_id`, `scope` видны в маске | `auth: {"type": "oauth2_client_credentials", "client": …}` |
| `webhook_url` | строка — URL `http(s)://` | корректный URL (`Value must be an http(s) URL`) | `url` действия `http_request` целиком; `connection` каналов `slack` и `teams` действия [`notify`](actions.md#уведомление) |
| `telegram_bot` | строка — токен бота | не короче 4 символов | `connection` канала `telegram` действия `notify` (токен попадает в путь URL, поэтому URL маскируется целиком) |

Секретные строки короче 4 символов не принимаются: такие значения нельзя надёжно вырезать из текста аудита. У
составного секрета в `value` допустимы только поля его вида; лишнее поле — ошибка `Unknown field "x" for kind "basic_auth"`.

### Создание

Только `admin`:

```bash
curl -s -X POST http://localhost:9501/api/secrets -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"erp_token","kind":"bearer","value":"tok-0123456789abcdef","description":"ERP API"}'
```

```json
{"success":true,"data":{"name":"erp_token","kind":"bearer","description":"ERP API","mask":"****cdef","allowed_hosts":[],
 "version":1,"created_at":"2026-10-19T19:29:33Z","updated_at":"2026-10-19T19:29:33Z","last_used_at":null},"error":null}
```

Составной секрет OAuth2 с ограничением по хосту:

```bash
curl -s -X POST http://localhost:9501/api/secrets -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"erp_oauth","kind":"oauth2_client","description":"ERP OAuth2","allowed_hosts":["erp.example.com"],
       "value":{"token_url":"https://auth.example.com/oauth/token","client_id":"wps-crm",
                "client_secret":"s3cr3t-client-secret-123","scope":"orders.write"}}'
```

```json
{"success":true,"data":{"name":"erp_oauth","kind":"oauth2_client","description":"ERP OAuth2",
 "mask":{"token_url":"https://auth.example.com/oauth/token","client_id":"wps-crm","client_secret":"****-123","scope":"orders.write"},
 "allowed_hosts":["erp.example.com"],"version":1,"created_at":"…","updated_at":"…","last_used_at":null},"error":null}
```

Секрет Basic-авторизации: `{"name":"erp_basic","kind":"basic_auth","value":{"username":"wps","password":"p@ssw0rd-2026"}}`
→ маска `{"username":"wps","password":"****2026"}`.

### Список и карточка

- `GET /api/secrets` — все секреты (`admin`, `editor`) с полем `usages_count` — числом ссылок из процессов.
- `GET /api/secrets/{name}` — карточка с `usages: [{process_id, process_name, path}]`: какие процессы и какие поля
  ссылаются на секрет. По ним видно, какие процессы затронет смена значения. Неизвестное имя — `404 NOT_FOUND`.

```json
{ "name": "erp_basic", "kind": "basic_auth", "mask": { "username": "wps", "password": "****2026" }, "version": 1,
  "usages": [ { "process_id": 12, "process_name": "Лид в ERP", "path": "definition.actions[0].auth.credentials" } ] }
```

Ссылки ищутся в определениях неудалённых процессов (активных и неактивных).

## Ссылка из процесса

| Форма | Что подставляется |
|---|---|
| `secret://erp_token` | значение целиком (строка или объект составного секрета) |
| `secret://erp_oauth.client_id` | одно поле составного секрета |

Ссылка — это **всё** значение поля учётных данных действия, без текста вокруг. Поля учётных данных:

| Действие | Поле | Допустимые виды |
|---|---|---|
| [`send_email`](actions.md#отправить-письмо) | `transport` | `smtp_dsn`, `generic` |
| [`http_request`](actions.md#http-запрос) | `url` (URL целиком из секрета) | `webhook_url`, `generic` |
| `http_request` | `auth.token` (`bearer`) | любой строковый секрет (обычно `bearer`) или поле составного |
| `http_request` | `auth.credentials` (`basic`) | `basic_auth` |
| `http_request` | `auth.key` (`api_key`) | строковый секрет или поле составного |
| `http_request` | `auth.client` (`oauth2_client_credentials`) | `oauth2_client` |
| [`notify`](actions.md#уведомление), канал `telegram` | `connection` | `telegram_bot`, `generic` |
| `notify`, каналы `slack`, `teams` | `connection` | `webhook_url`, `generic` |

Вид проверяется при сохранении для `transport`, `url` и `connection`; для `auth` — при выполнении (например,
`Secret secret://erp_token must be of kind basic_auth`, `Secret does not hold a token`).

Пример — один HTTP-запрос с OAuth2 и второй с Basic-авторизацией:

```json
{ "type": "http_request", "id": "erp_order", "method": "POST",
  "url": "https://erp.example.com/api/orders",
  "auth": { "type": "oauth2_client_credentials", "client": "secret://erp_oauth" },
  "body": { "type": "json", "value": { "crm_id": "{{ object.id }}", "name": "{{ object.name }}" } } }
{ "type": "http_request", "id": "legacy_sync", "method": "PUT",
  "url": "https://legacy.example.com/sync/{{ object.id }}",
  "auth": { "type": "basic", "credentials": "secret://erp_basic" } }
```

Значение подставляется в памяти в момент шага и никуда не записывается: ни в переменные процесса, ни в Redis, ни в
аудит.

### Где ссылка запрещена

В шаблонах и значениях шагов (`value`, `expression`, `template`, `subject`, `text`, `html`, `body`), в полях
`field` / `target` и в условиях секрет оказался бы в данных процесса, поэтому сохранение процесса отклоняется:
`Secrets can only be used in credential fields`.

```json
{ "type": "modify", "target": "object.description", "change_type": "template", "value": "Токен: secret://erp_token" }
```

→ `422`, `definition.actions[0].value: Secrets can only be used in credential fields`.

> **Внимание**
> В остальных полях, которые не являются полями учётных данных (например, в значениях `headers` и `query`
> HTTP-запроса), текст `secret://…` не подставляется: он уйдёт во внешнюю систему как обычная строка. Передавайте
> токены только через `auth` — для заголовка с произвольным именем подходит `{"type": "api_key", "name": "X-Token", "key": "secret://…"}`.

Секреты нельзя хранить и в [константах процесса](processes.md#константы-процесса): константа, в имени которой есть
`password`, `secret`, `token` или `api_key` (без учёта регистра), отклоняется (`Constant "api_token" looks like a secret; use a secret reference (secret://name)`).

## Маскирование

Каждое значение, которое шаг расшифровал, регистрируется в маскировщике этого шага. Перед записью в аудит и журналы
WPS заменяет на `***`:

- само значение и его производные формы: URL-кодированную, JSON-экранированную, base64, а для Basic-авторизации —
  base64 строки `username:password`;
- токен OAuth2, полученный по `client_credentials`;
- значения заголовков `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `X-Auth-Key` и
  полей / параметров `api_key`, `token`, `access_token`, `client_secret`, `password` — всегда, даже если это не секрет
  WPS.

Маскируется и то, что вернула внешняя система: если вебхук-эхо прислал заголовок авторизации в теле ответа, в
переменной ответа `http_request` и в аудите будет `"authorization": "***"`. Текст ошибки шага (`sys.last_error.message`)
тоже проходит маскирование.

## Ошибки

| Ситуация | Ответ |
|---|---|
| Ссылка на несуществующий секрет | `422`, `definition.actions[2].auth.token: Secret "erp_token" does not exist` |
| Литерал вместо ссылки в поле учётных данных (`"token": "tok-123"`) | `422`, `…auth.token: Credentials must reference a secret (secret://name)` (для `transport` — `Transport must be a secret reference (secret://name)`, для `connection` — `Connection secret is required (secret://name)`) |
| Ссылка в шаблоне, значении шага или условии | `422`, `… : Secrets can only be used in credential fields` |
| Неверная форма ссылки (`secret://Bad`) в поле, которое не проверяет само действие | `422`, `Invalid secret reference (expected secret://name or secret://name.field)` |
| Секрет не того вида | `422`, `Secret must be of kind smtp_dsn, got bearer`, `Secret must be of kind webhook_url, got bearer`, `Secret "erp_token" has kind "bearer", expected webhook_url or generic` |
| Поле составного секрета не существует (`secret://erp_basic.nofield`) | при сохранении не проверяется; шаг `failed`: `Secret "erp_basic" has no field "nofield"` |
| Секрет удалён или ключ не подходит во время выполнения | шаг `failed`: `Secret "erp_token" not found`, `Secret "erp_token": …` |
| Хост запроса не в `allowed_hosts` | шаг `failed`: `Secret "erp_oauth" is not allowed for host "mock"` (`error_code: SECRET_HOST`) |
| Ключ хранилища не задан во время выполнения | шаг `failed`: `Secrets are not available: WPS_SECRETS_KEY is not configured` |

Ошибки секретов при выполнении не повторяются; дальше действует `on_error` шага
([Ошибки шага](actions.md#ошибки-шага-on_error-и-повторы)).

Ошибки API секретов:

| Ситуация | Ответ |
|---|---|
| Неверное имя | `422`, `name: Secret name must match ^[a-z][a-z0-9_]{1,63}$` |
| Неизвестный вид | `422`, `kind: Unknown secret kind (allowed: generic, smtp_dsn, basic_auth, bearer, api_key, oauth2_client, webhook_url, telegram_bot)` |
| Нет значения | `422`, `value: Secret value is required` |
| Короткое значение | `422`, `value: Secret value is too short (min 4 characters)` (у составных — `value.password` и т. п.) |
| Строка вместо объекта и наоборот | `422`, `Value must be an object for kind "basic_auth"`, `Value must be a string` |
| Нет обязательного поля составного секрета | `422`, `value.client_id: Field "client_id" is required for kind "oauth2_client"` |
| `token_url` не `https` | `422`, `value.token_url: Token URL must use https` |
| Неверный хост в `allowed_hosts` | `422`, `allowed_hosts[0]: Invalid host "https://x.y" (a host name without scheme, e.g. api.example.com)` |
| Попытка переименовать или сменить вид | `422`, `A secret cannot be renamed (processes refer to it by name)`, `The kind of a secret cannot be changed` |
| Секрет с таким именем уже есть | `409 SECRET_EXISTS` |
| Удаление используемого секрета | `409 SECRET_IN_USE` со списком использований `details.usages: [{process_id, process_name, path}]` |
| Секрета нет | `404 NOT_FOUND`: `Secret "x" not found` |
| Ключ хранилища не задан | `503 SECRETS_KEY_NOT_CONFIGURED` |

Удалить секрет можно, только когда ссылок в неудалённых процессах не осталось.

## Смена значения

```bash
curl -s -X PUT http://localhost:9501/api/secrets/erp_token -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"value":"tok-new-0123456789"}'
```

- Можно менять `value`, `description` и `allowed_hosts`; имя и вид — нет: создайте новый секрет и переключите на него
  процессы.
- Значение составного секрета заменяется **целиком**: передайте все поля, в том числе необязательные (`scope`), иначе
  они пропадут.
- `version` увеличивается на 1, маска пересчитывается. Запрос без `value` (только описание или хосты) версию не меняет.
- Воркеры кэшируют расшифрованное значение в памяти на `SECRETS_CACHE_TTL` секунд (по умолчанию 60, см.
  [Конфигурация](../04-operations/configuration.md#секреты)) — новое значение начинает использоваться в пределах этого
  срока. Если нужно переключиться сразу, уменьшите `SECRETS_CACHE_TTL` или перезапустите backend.

**Ротация учётных данных без простоя.** Если внешняя система допускает два действующих ключа одновременно: выпустите
новый ключ, обновите значение секрета (`PUT`), подождите `SECRETS_CACHE_TTL` и только потом отзовите старый ключ во
внешней системе. Если два ключа сразу невозможны, учтите, что в течение `SECRETS_CACHE_TTL` часть шагов может уйти со
старым значением и завершиться ошибкой авторизации; повторите такие шаги или обработайте ошибку через `on_error`.

**Смена ключа шифрования хранилища** (`WPS_SECRETS_KEY`) — отдельная операция: старый ключ переносится в
`WPS_SECRETS_KEY_PREVIOUS`, затем `POST /api/secrets/rotate` (`admin`) перешифровывает все секреты новым ключом и
возвращает `{"reencrypted": N}`; секрет, который не удалось расшифровать, — `409 ROTATION_FAILED`. Порядок действий —
[Безопасность → Ротация ключа](../04-operations/security.md#ротация-ключа).

## Роли

| Действие | admin | editor | viewer |
|---|---|---|---|
| Список секретов и карточка с использованиями | да | да | нет |
| Создать, изменить, удалить, перешифровать | да | нет | нет |
| Прочитать значение | нет | нет | нет |
| Сослаться на секрет в процессе | да | да | — |

Создание, изменение и удаление секретов пишутся в журнал backend (`Secret created` / `Secret updated` /
`Secret deleted`, имя и `user_id`, без значения). Метрики: `wps_secret_resolve_total` и
`wps_secret_resolve_errors_total` ([Мониторинг](../04-operations/monitoring.md)).

<!-- footer-nav -->
---

← Назад: [Ведомые процессы](subprocesses.md) | [Оглавление](../README.md) | [Выполнения и аудит](executions-and-audit.md) Далее →
<!-- /footer-nav -->
