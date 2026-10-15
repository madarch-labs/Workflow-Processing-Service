# Новый адаптер CRM

Как подключить WPS к CRM, отличной от мока и SuiteCRM 7/8, на примере проверенного сервиса-моста.

<!-- lang-switch -->
[🇬🇧 English](../../../en/07-developer-guide/extending/new-crm-adapter.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## 1. Когда это нужно

WPS работает ровно с одной CRM, которая задаётся в `CRM_API_BASE_URL`. Мок и плагин SuiteCRM говорят на одном и том же
небольшом REST-контракте. Эта страница нужна, если у вашей CRM другой API (другие URL, другая аутентификация, обычный
JSON вместо JSON:API) или если она пока не публикует события в Redis Stream.

## 2. Как это устроено

```mermaid
classDiagram
    class ExternalApiAdapterService {
        <<final>>
        +authKey() string
        +getById(module, id) array
        +findOneByField(module, field, value) array
        +update(module, id, attributes) array
        +create(module, attributes) array
        +getModules() list
        +request(method, url, body) array
        +flatten(resource)$
    }
    Container --> ExternalApiAdapterService : crm()
    PreloadHandler --> ExternalApiAdapterService
    CrmObjectSaver --> ExternalApiAdapterService
    DictionaryService --> ExternalApiAdapterService : getModules()
    LoadStrategyInterface ..> ExternalApiAdapterService : load(api, …)
    ByIdStrategy ..|> LoadStrategyInterface
    ByFieldStrategy ..|> LoadStrategyInterface
```

- Клиент CRM **один**: `Wps\Services\ExternalApiAdapterService`, он создаётся в `Wps\Kernel\Container::crm()`.
  Это `final`-класс **без интерфейса**, и его конкретный тип используется в `PreloadHandler`, `CrmObjectSaver`
  (`modify` с `save_to_crm`, `save_object`), `DictionaryService` (справочник модулей) и в сигнатуре
  `LoadStrategyInterface::load()`. Реестра адаптеров CRM, куда можно было бы подключить вторую реализацию, нет.
- Адаптер работает по контракту из раздела [Интеграция с CRM](../../04-operations/crm-integration.md#контракт-api-crm-wps--crm):
  `GET/PUT {base}/{module}/{id}`, `GET {base}/{module}?filter[field]=value&size=1`, `POST {base}/{module}`,
  `GET {base}/modules`, заголовок `X-Auth-Key = CRM_API_STATIC_KEY . gmdate("Y.m.d H")`, ресурсы JSON:API
  `{type, id, attributes}`. `AppConfig::crmUrl()` дописывает `/api/v8`, если базовый адрес ещё не заканчивается на `/api/v<N>`.
- События идут в обратную сторону и адаптер вообще не используют: CRM пишет сообщения в Redis Stream `crm_events`
  ([формат события](../../04-operations/crm-integration.md#формат-события-crm--wps)).

Поэтому «новый адаптер CRM» — это один из двух вариантов:

| Способ | Что вы пишете | Изменения кода WPS | Когда подходит |
|---|---|---|---|
| **A. Мост (рекомендуется)** | Небольшой HTTP-сервис, который обслуживает контракт WPS и обращается к вашей CRM | нет, только `.env` | У CRM есть любой REST API, который можно вызвать |
| **B. Плагин внутри CRM** | Эндпоинты в самой CRM, как пакеты SuiteCRM `../suiteCRM7`, `../SuiteCRM8` (`WpsApi`, rewrite `/api/v9/…`) | нет | В CRM можно установить свой код |
| C. Изменить адаптер | Правка `ExternalApiAdapterService` (и подсказок типов, которые его используют) | да, класс ядра | Сам контракт должен измениться для всех CRM |

На этой странице разобран способ A с законченным проверенным примером. Способ B — это плагин SuiteCRM: см.
`.tehnical/suiteCRM7/README.md`. Способ C — изменение ядра, а не расширение; правила, которым должен следовать любой
адаптер, — в разделе [Валидация и ошибки](#4-валидация-и-ошибки).

## 3. Пошагово

У целевой CRM в примере простой REST API:

| Запрос к целевой CRM | Ответ |
|---|---|
| `GET {TARGET_URL}/{module}/{id}` | `200 {"success": true, "data": {плоский объект с id}}`, `404`, если записи нет |
| `PUT {TARGET_URL}/{module}/{id}` с полным плоским объектом | `200 {"success": true, "data": {…}}` (создание или замена) |
| `Authorization: Bearer <token>` | |

Поиска у неё нет. (При проверке целевой CRM был вспомогательный API мока `/mock/objects/{module}/{id}` — у него ровно
такой формат.)

### 3.1 Мост — `crm-bridge/bridge.php`

Чистый PHP 8.3 без расширений и пакетов Composer, работает на встроенном веб-сервере.

```php
<?php

declare(strict_types=1);

/**
 * WPS CRM bridge: serves the WPS CRM API contract and translates it to another CRM's REST API.
 *   php -S 0.0.0.0:8080 bridge.php
 *
 * WPS side (ExternalApiAdapterService):           target CRM side (example API):
 *   GET  /api/vN/modules                           — (static list from BRIDGE_MODULES)
 *   GET  /api/vN/{module}/{id}                     GET {TARGET_URL}/{module}/{id}
 *   GET  /api/vN/{module}?filter[f]=v&size=1       — (not supported by the target → 422)
 *   PUT  /api/vN/{module}/{id}                     GET + PUT {TARGET_URL}/{module}/{id} (full object)
 *   POST /api/vN/{module}                          PUT {TARGET_URL}/{module}/{new id}
 * Target responses: {"success": true, "data": {flat object}}; missing record → HTTP 404.
 */

function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

/**
 * @param array<mixed> $payload
 */
function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error(int $status, string $title): never
{
    respond($status, ['errors' => [['status' => (string)$status, 'title' => $title]]]);
}

/**
 * @param array<string, mixed> $object flat object with "id"
 * @return array<string, mixed> JSON:API resource expected by WPS
 */
function resource(string $module, array $object): array
{
    $id = (string)($object['id'] ?? '');
    unset($object['id']);
    return ['type' => $module, 'id' => $id, 'attributes' => (object)$object];
}

/**
 * Calls the target CRM. Transport errors and 5xx become 502, which WPS treats as recoverable (retry).
 *
 * @param array<string, mixed>|null $body
 * @return array{status: int, data: array<string, mixed>|null}
 */
function target(string $method, string $path, ?array $body = null): array
{
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if (env('TARGET_TOKEN') !== '') {
        $headers[] = 'Authorization: Bearer ' . env('TARGET_TOKEN');
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE),
        'timeout' => (float)env('TARGET_TIMEOUT', '5'),
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents(rtrim(env('TARGET_URL'), '/') . $path, false, $context);
    if ($raw === false || !isset($http_response_header[0])) {
        error(502, 'Target CRM is unavailable');
    }
    preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m);
    $status = (int)($m[1] ?? 0);
    if ($status >= 500 || $status === 0) {
        error(502, "Target CRM returned HTTP {$status}");
    }
    $decoded = json_decode($raw, true);
    $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
    return ['status' => $status, 'data' => is_array($data) ? $data : null];
}

function authorized(): bool
{
    $key = env('WPS_API_STATIC_KEY');
    $given = $_SERVER['HTTP_X_AUTH_KEY'] ?? '';
    // the key changes every hour; accept the previous hour too (clock skew)
    return $key !== '' && (hash_equals($key . gmdate('Y.m.d H'), $given)
        || hash_equals($key . gmdate('Y.m.d H', time() - 3600), $given));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!preg_match('#^/api/v\d+/([A-Za-z][A-Za-z0-9_]*)(?:/([^/]+))?/?$#', $path, $m)) {
    error(404, 'Route not found');
}
if (!authorized()) {
    error(401, 'Invalid X-Auth-Key');
}
$module = $m[1];
$id = isset($m[2]) ? rawurldecode($m[2]) : null;
$input = json_decode((string)file_get_contents('php://input'), true);
$attributes = (array)($input['data']['attributes'] ?? []);

if ($module === 'modules' && $id === null && $method === 'GET') {
    $names = array_filter(array_map('trim', explode(',', env('BRIDGE_MODULES', 'Accounts,Contacts,Deals'))));
    respond(200, ['data' => array_map(static fn (string $n) => ['value' => $n, 'label' => $n], array_values($names))]);
}

$item = '/' . rawurlencode($module) . ($id !== null ? '/' . rawurlencode($id) : '');
switch (true) {
    case $method === 'GET' && $id !== null:
        $res = target('GET', $item);
        if ($res['status'] === 404 || $res['data'] === null) {
            error(404, "{$module} {$id} not found");
        }
        respond(200, ['data' => resource($module, $res['data'])]);

    case $method === 'GET':
        error(422, 'Search by field is not supported by the target CRM');

    case $method === 'PUT' && $id !== null:
        $current = target('GET', $item);
        if ($current['status'] === 404 || $current['data'] === null) {
            error(404, "{$module} {$id} not found");
        }
        $object = ['id' => $id] + $attributes + $current['data'];
        $saved = target('PUT', $item, $object);
        respond(200, ['data' => resource($module, $saved['data'] ?? $object)]);

    case $method === 'POST' && $id === null:
        $newId = bin2hex(random_bytes(8));
        $object = ['id' => $newId] + $attributes;
        $saved = target('PUT', $item . '/' . $newId, $object);
        respond(201, ['data' => resource($module, $saved['data'] ?? $object)]);

    default:
        error(405, 'Method not allowed');
}
```

Что мост делает для каждого вызова WPS:

| Вызов WPS (`ExternalApiAdapterService`) | Мост → целевая CRM | Ответ WPS |
|---|---|---|
| `getModules()` → `GET /api/v1/modules` | — (`BRIDGE_MODULES`) | `{"data": [{"value", "label"}]}` |
| `getById()` → `GET /api/v1/{module}/{id}` | `GET /{module}/{id}` | ресурс с `200` или `404` |
| `findOneByField()` → `GET /api/v1/{module}?filter…` | — | `422` (не поддерживается) |
| `update()` → `PUT /api/v1/{module}/{id}` | `GET` + `PUT` объединённого объекта | ресурс с `200` или `404` |
| `create()` → `POST /api/v1/{module}` | `PUT /{module}/{новый id}` | ресурс с `201` и новым `id` |

### 3.2 Запуск рядом с WPS — `docker-compose.override.yml`

Docker Compose читает `docker-compose.override.yml` автоматически, поэтому `docker-compose.yml` остаётся без изменений:

```yaml
services:
  crm-bridge:
    image: php:8.3-cli
    command: ["php", "-S", "0.0.0.0:8080", "/srv/bridge/bridge.php"]
    volumes:
      - ./crm-bridge:/srv/bridge:ro
    environment:
      WPS_API_STATIC_KEY: bridgeKey                # must equal CRM_API_STATIC_KEY of the backend
      TARGET_URL: https://crm.example.com/rest     # your CRM
      TARGET_TOKEN: change-me                      # your CRM token
      BRIDGE_MODULES: Accounts,Contacts,Deals      # object types offered in the UI
```

### 3.3 Направить бэкенд на мост — `./.env`

```bash
CRM_API_BASE_URL=http://crm-bridge:8080/api/v1
CRM_API_STATIC_KEY=bridgeKey
MOCK_SERVICE_URL=             # empty: the modules dictionary comes from the bridge
```

`/api/v1` в конце не даёт `AppConfig::crmUrl()` дописать `/api/v8`; подходит любой `/api/v<N>`. Затем:

```bash
docker compose up -d --build backend crm-bridge
curl -s -X POST http://localhost:9501/api/dictionaries/modules/refresh -H "Authorization: Bearer $TOKEN"
```

### 3.4 Публикация событий

Мост закрывает только направление WPS → CRM. CRM также должна отправлять события «запись сохранена» в `crm_events` —
из обработчика вебхука, хука CRM или фонового опроса: подойдёт любой код, который умеет выполнить `XADD`:

```bash
redis-cli -h <redis-stream host> -p 6379 XADD crm_events MAXLEN '~' 100000 '*' \
  data '{"id":"d-1","module":"Deals","status":"Active","account_id":"194","event_id":"deal-d-1-1"}'
```

`module` (или `object_type`) и `id` обязательны; подробнее — в разделе
[Интеграция с CRM](../../04-operations/crm-integration.md#публикация-событий-из-любой-системы).

## 4. Валидация и ошибки

Что делать дальше, WPS решает по **HTTP-статусу**, который вернул API CRM (здесь — мост), в `ExternalApiAdapterService::request()`:

| Ответ моста | Как его понимает WPS | Результат шага |
|---|---|---|
| `200`/`201` с JSON | успех | `success`; `{id, attributes}` разворачивается в плоский объект |
| `404` на `GET` | запись не найдена | переменная предзагрузки = `null` |
| ошибка соединения, тайм-аут (`CRM_API_TIMEOUT`), `5xx` | `CrmApiException`, восстановимая | повтор `ACTION_RETRY_MAX` раз, затем `failed` |
| другие `4xx` (в том числе `404` на `PUT`), некорректный JSON | `CrmApiException`, невосстановимая | сразу `failed`, остальные шаги `skipped` |

Правила для моста или плагина:

- Возвращайте `5xx` (в примере — `502`) только при **временных** проблемах целевой CRM, чтобы WPS их повторял.
- Возвращайте `4xx` для всего, что повтор не исправит: неподдерживаемая операция, некорректные данные, отклонённая запись.
- Возвращайте `404` на `GET`, если записи нет, — никогда не пустой `200`.
- `PUT` должен принимать неполные `attributes` (WPS отправляет только изменённые поля, если `save_object.mode` не `full`).
- `POST` должен вернуть новый `id` в `data.id`; WPS записывает его обратно в переменную (`created_id` в результате шага).
- Проверяйте `X-Auth-Key` для текущего **и** предыдущего часа, как это делают мок и плагин SuiteCRM.

Проверено на отдельном стенде (мост + мок в роли целевой CRM):

```text
Execution steps (process "Bridge: active deal → VIP account + contact"):
0 preload      success  {"found": true, "object": {"id": "194", "name": "ИП Иванов", "rating": "", …}}
1 modify       success  {"save": {"saved": true, "status": "success", "response": {"id": "194", "rating": "VIP", …}}}
2 modify       success  {"new_value": "Deal 29311", "old_value": null}
3 save_object  success  {"saved": true, "response": {"id": "bf535877c6d21f1c", "last_name": "Deal 29311"}, "created_id": "bf535877c6d21f1c"}

Target CRM down:        failed, retry_count 3, "CRM GET http://crm-bridge:8080/api/v1/Accounts/194 returned HTTP 502"
Preload by other field: failed, retry_count 0, "CRM GET http://crm-bridge:8080/api/v1/Accounts?filter%5Bname%5D=X&size=1 returned HTTP 422"
```

> **Внимание**
> Процесс, который сохраняет запись, вызвавшую его запуск, порождает новое событие по той же записи. Чтобы не получить
> цикл, используйте [однократный запуск для записи](../../03-user-guide/manual-run-and-reset.md) (`run_once`) или
> защитное условие.

## 5. UI и переводы

Во фронтенде ничего менять не нужно. Списки «Тип объекта» и «Модуль» заполняются из
`GET /api/dictionaries/modules`, который теперь отдаёт мост (`BRIDGE_MODULES`); подписи показываются так, как их вернули
CRM или мост (через `locales/*.json` они не переводятся). Список кэшируется в Redis `dict:modules` на
`DICT_CACHE_TTL` (300 с) — после его изменения нажмите кнопку обновления рядом с «Тип объекта» или вызовите
`POST /api/dictionaries/modules/refresh`.

## 6. Тест

`back/tests/IntegrationTest.php` создаёт и читает объекты через вспомогательный API мока `/mock/objects/...`, поэтому
его запускают против **мока**, а не против настоящей CRM или моста (см. [Тестирование](../testing.md)). Мост проверяйте
smoke-тестом изнутри контейнера бэкенда (там та же сеть и тот же ключ, что у WPS):

```bash
KEY="bridgeKey$(date -u +'%Y.%m.%d %H')"
docker compose exec backend sh -c "
  curl -s -H 'X-Auth-Key: $KEY' http://crm-bridge:8080/api/v1/modules; echo
  curl -s -H 'X-Auth-Key: $KEY' http://crm-bridge:8080/api/v1/Accounts/<existing id>; echo
  curl -s -o /dev/null -w '%{http_code}\n' -H 'X-Auth-Key: $KEY' http://crm-bridge:8080/api/v1/Accounts/does-not-exist
  curl -s -o /dev/null -w '%{http_code}\n' -H 'X-Auth-Key: wrong' http://crm-bridge:8080/api/v1/Accounts/1"
```

Ожидается: список модулей, `{"data":{"type":"Accounts","id":…,"attributes":{…}}}`, `404`, `401`.

Затем прогоните один процесс целиком: создайте его из JSON ниже (`POST /api/processes`), опубликуйте событие `Deals`
со `status = Active` и существующим `account_id` ([3.4](#34-публикация-событий)) и откройте выполнение в UI —
все четыре шага должны быть `success`, а у аккаунта в CRM должно стать `rating = VIP`.

```json
{
  "name": "Bridge: active deal → VIP account + contact", "object_type": "Deals", "is_child": false, "is_active": true,
  "definition": {
    "conditions": [ { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" } ],
    "preload": [ { "var": "acc", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" } ],
    "actions": [
      { "type": "modify", "target": "acc.rating", "change_type": "set_value", "value": "VIP", "save_to_crm": true },
      { "type": "modify", "target": "contact.last_name", "change_type": "calculate", "value": "concat('Deal ', object.id)", "save_to_crm": false },
      { "type": "save_object", "target": "contact", "module": "Contacts" }
    ]
  }
}
```

## 7. Чек-лист готовности

- [ ] Обслуживается каждая операция, которую используют ваши процессы: `GET` по id, поиск по полю (если предзагрузка ищет по другим полям), `PUT`, `POST`, `modules`
- [ ] Ресурсы JSON:API `{type, id, attributes}` в `data`; `404` для отсутствующей записи; новый `id` в ответе на `POST`
- [ ] `5xx` только для временных сбоев, `4xx` — для постоянных
- [ ] `X-Auth-Key` проверяется для текущего и предыдущего часа; тот же ключ указан в `CRM_API_STATIC_KEY`
- [ ] `CRM_API_BASE_URL` заканчивается на `/api/v<N>`, `MOCK_SERVICE_URL` пуст, кэш модулей обновлён
- [ ] CRM публикует события в `crm_events` с `module` и `id`; циклы исключены через `run_once` или условия
- [ ] Smoke-тест и одно сквозное выполнение проходят
- [ ] Если CRM становится поддерживаемым вариантом, она описана в разделе [Интеграция с CRM](../../04-operations/crm-integration.md) (en + ru)

<!-- footer-nav -->
---

← Назад: [Новый эндпоинт API](new-api-endpoint.md) | [Оглавление](../../README.md) | [Новый экран UI](new-ui-screen.md) Далее →
<!-- /footer-nav -->
