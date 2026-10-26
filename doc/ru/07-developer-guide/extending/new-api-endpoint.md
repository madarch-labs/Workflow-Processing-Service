# Новый эндпоинт API

Как добавить REST-эндпоинт — на учебном примере `GET /api/dictionaries/function-categories`.

<!-- lang-switch -->
[🇬🇧 English](../../../en/07-developer-guide/extending/new-api-endpoint.md) | 🇷🇺 Русский
<!-- /lang-switch -->

> `function-categories` — учебный эндпоинт, в WPS его нет. Настоящий справочник функций выражений —
> [`GET /api/dictionaries/functions`](../../06-api-reference/endpoints.md#get-apidictionariesfunctions)
> (`FunctionsController`), пример построен на тех же данных.

## 1. Когда это нужно

Когда UI или внешней системе нужны данные или операция, которых API пока не даёт: новый справочник, отчёт,
административная операция. Эндпоинты тонкие — бизнес-логика живёт в сервисе в `back/src/Services/`.

## 2. Как это устроено

```mermaid
sequenceDiagram
    participant C as Клиент
    participant A as WpsApplication
    participant R as WpsRoutesCollectionBuilder
    participant M as AuthMiddleware
    participant P as AccessPolicy
    participant Ctl as YourController
    participant S as Сервис (Container)
    C->>A: GET /api/dictionaries/function-categories
    A->>R: совпадение метода и точного числа сегментов
    R-->>A: класс контроллера (из #[Route])
    A->>Ctl: executeWithMiddlewares()
    Ctl->>M: глобальный middleware (config.json)
    M->>P: rolesFor(method, path)
    P-->>M: PUBLIC или разрешённые роли
    M->>Ctl: next() (иначе 401/403)
    Ctl->>S: handle() → вызов сервиса
    Ctl-->>C: {"success":true,"data":…,"error":null}
```

- **Обнаружение.** SwooleApp сканирует пространство имён `Wps\Controllers` из `back/config/config.json` (включая
  вложенные) и регистрирует каждый класс с атрибутом `#[Route(uri: …, method: …)]`. Никакой список править не нужно.
- **Сопоставление.** `WpsRoutesCollectionBuilder` требует того же числа сегментов URI, что и в шаблоне; `{id}` совпадает
  с одним непустым сегментом и доступен как `$this->intParam('id')` / `$this->param('id')`. Завершающий слэш допускается.
- **Доступ.** `Wps\Middleware\AuthMiddleware` (глобальный) проверяет JWT и спрашивает `Wps\Auth\AccessPolicy::rolesFor()`.
  Правила — регулярные выражения, проверяются по порядку; для всего, что не указано: `GET /api/*` → все
  аутентифицированные роли, остальные методы → `admin` и `editor`.
- **Ответ.** `BaseController::execute()` оборачивает `handle()`: `ApiException` → конверт ошибки с её статусом и кодом;
  любое другое исключение → `500 INTERNAL_ERROR` (текст сообщения — только при `APP_DEBUG=true`).

## 3. Пошагово

### 3.1 Контроллер — `back/src/Controllers/Dictionaries/FunctionCategoriesController.php`

```php
<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;

/**
 * Categories of the expression functions with the number of functions in each (for the UI help).
 */
#[Route(uri: '/api/dictionaries/function-categories', method: 'GET')]
final class FunctionCategoriesController extends BaseController
{
    protected function handle(): Response
    {
        $counts = [];
        foreach ($this->container()->expressions()->functionSpecs() as $spec) {
            $counts[$spec->category] = ($counts[$spec->category] ?? 0) + 1;
        }
        ksort($counts);
        $items = [];
        foreach ($counts as $category => $count) {
            $items[] = ['value' => $category, 'label' => $category, 'count' => $count];
        }
        return $this->ok($items);
    }
}
```

### 3.2 Пересборка и вызов

```bash
docker compose up -d --build backend
TOKEN=$(curl -s -X POST http://localhost:9501/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@wps.local","password":"admin"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["access_token"])')
curl -s http://localhost:9501/api/dictionaries/function-categories -H "Authorization: Bearer $TOKEN"
```

Ответ при встроенном наборе функций (функция из [Функций выражений](expression-functions.md) увеличит счётчик своей категории):

```json
{"success":true,"data":[{"value":"date","label":"date","count":32},{"value":"logic","label":"logic","count":5},{"value":"number","label":"number","count":4},{"value":"string","label":"string","count":6}],"error":null}
```

### 3.3 Инструменты `BaseController`

| Метод | Назначение |
|---|---|
| `$this->body()` | JSON-тело как массив (`400 BAD_REQUEST`, если это не объект) |
| `$this->query('key', default)`, `queryBool('key')` | строка запроса |
| `[$page, $perPage] = $this->pagination(20, 1000)` + `$this->meta($page, $perPage, $total)` | постраничный вывод; meta передаётся третьим аргументом `ok()` |
| `$this->intParam('id')`, `$this->param('name')` | параметры URI (`404 NOT_FOUND` для нечислового id) |
| `$this->user()` | `{id, email, role}` вызывающего |
| `$this->ok($data, 201)` | конверт успеха со статусом |
| `$this->container()` | сервисы (`processService()`, `executions()`, `crm()`, `metrics()`, …) |

Пример эндпоинта на запись:

```php
#[Route(uri: '/api/processes/{id}/archive', method: 'POST')]
final class ArchiveProcessController extends BaseController
{
    protected function handle(): Response
    {
        $process = $this->container()->processService()->get($this->intParam('id')); // 404 PROCESS_NOT_FOUND
        // … call a service method that does the work …
        return $this->ok(['archived' => true, 'id' => $process->id]);
    }
}
```

(`archive` — только иллюстрация, такого метода сервиса в WPS нет.)

## 4. Валидация и ошибки

- Бросайте `ApiException::validation([['field' => 'name', 'message' => '…']])` → `422 VALIDATION_ERROR`,
  `ApiException::notFound('XXX_NOT_FOUND', '…')`, `ApiException::conflict('XXX', '…', $details)`,
  `ApiException::badRequest('…')`, `ApiException::forbidden()`.
- Добавьте правило в `AccessPolicy::RULES`, если поведение по умолчанию (GET — всем, запись — редакторам) не подходит.
  Конкретные правила ставьте **перед** общими — побеждает первое совпадение:

```php
['POST', '#^/api/processes/[^/]+/archive$#', self::ADMIN],
```

- Публичные эндпоинты (без токена) нужно перечислить с `self::PUBLIC`, как `/api/health` и `/metrics`.
- Опишите эндпоинт (метод, URI, роль, тело, ответ, коды ошибок) в `back/API.md` и в
  [Эндпоинтах API](../../06-api-reference/endpoints.md) (en + ru).

## 5. UI и переводы

Добавьте функцию в подходящий модуль `front/src/api/` (или в новый модуль), а типы — в `front/src/types/index.ts`:

```ts
// front/src/api/reference.ts
import { apiGet } from './client'
import type { DictionaryItem } from '@/types'

export const referenceApi = {
  /** Categories of the expression functions */
  functionCategories(): Promise<DictionaryItem[]> {
    return apiGet<DictionaryItem[]>('/dictionaries/function-categories')
  },
}
```

`apiGet` добавляет префикс `/api`, отправляет токен, разворачивает `data` и показывает переведённые сообщения для
известных кодов ошибок (`front/src/utils/errors.ts`). Текст для нового кода ошибки добавьте туда и в обе локали
(`errors.*`). Страница, использующая эндпоинт, разобрана в примере [Новый экран UI](new-ui-screen.md).

## 6. Тест

```php
$this->test('Function categories dictionary lists date', function () {
    $res = $this->request('GET', '/api/dictionaries/function-categories', token: $this->viewerToken, expectedStatus: 200);
    $this->assertTrue(in_array('date', array_column($res['data'], 'value'), true));
});
$this->test('Function categories dictionary requires a token', function () {
    $this->request('GET', '/api/dictionaries/function-categories', expectedStatus: 401);
});
```

Первая проверка входит в проверенную группу `testExtensions()` ([полный текст](new-action-type.md#6-тест)). Для
эндпоинтов на запись проверьте также `viewer` → `403`.

## 7. Чек-лист готовности

- [ ] `final`-контроллер в `back/src/Controllers/<Area>/`, `#[Route]`, наследует `BaseController`, логика — в сервисе
- [ ] Правило доступа в `AccessPolicy`, если поведение по умолчанию не подходит
- [ ] Ошибки через `ApiException` с документированными кодами
- [ ] Обновлены `back/API.md` и [Эндпоинты API](../../06-api-reference/endpoints.md) (en + ru)
- [ ] Функция API и типы во фронтенде (+ тексты `errors.*` для новых кодов)
- [ ] Интеграционные проверки (успех, 401, 403), тесты зелёные

<!-- footer-nav -->
---

← Назад: [Функции выражений](expression-functions.md) | [Оглавление](../../README.md) | [Новый адаптер CRM](new-crm-adapter.md) Далее →
<!-- /footer-nav -->
