# New API endpoint

How to add a REST endpoint, using a tutorial `GET /api/dictionaries/function-categories` as the example.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../../ru/07-developer-guide/extending/new-api-endpoint.md)
<!-- /lang-switch -->

> `function-categories` is a tutorial endpoint that WPS does not have. The real dictionary of expression functions is
> [`GET /api/dictionaries/functions`](../../06-api-reference/endpoints.md#get-apidictionariesfunctions)
> (`FunctionsController`); the example is built on the same data.

## 1. When you need it

When the UI or an external system needs data or an operation that the API does not expose yet: a new dictionary,
a report, an admin operation. Endpoints are thin — business logic belongs to a service in `back/src/Services/`.

## 2. How it works

```mermaid
sequenceDiagram
    participant C as Client
    participant A as WpsApplication
    participant R as WpsRoutesCollectionBuilder
    participant M as AuthMiddleware
    participant P as AccessPolicy
    participant Ctl as YourController
    participant S as Service (Container)
    C->>A: GET /api/dictionaries/function-categories
    A->>R: match method + exact number of segments
    R-->>A: controller class (from #[Route])
    A->>Ctl: executeWithMiddlewares()
    Ctl->>M: global middleware (config.json)
    M->>P: rolesFor(method, path)
    P-->>M: PUBLIC or allowed roles
    M->>Ctl: next() (401/403 otherwise)
    Ctl->>S: handle() → service call
    Ctl-->>C: {"success":true,"data":…,"error":null}
```

- **Discovery.** SwooleApp scans the namespace `Wps\Controllers` from `back/config/config.json` (sub-namespaces included)
  and registers every class with a `#[Route(uri: …, method: …)]` attribute. No list to edit.
- **Matching.** `WpsRoutesCollectionBuilder` requires the same number of URI segments as the pattern; `{id}` matches one
  non-empty segment and is available as `$this->intParam('id')` / `$this->param('id')`. A trailing slash is tolerated.
- **Access.** `Wps\Middleware\AuthMiddleware` (global) checks the JWT and asks `Wps\Auth\AccessPolicy::rolesFor()`.
  Rules are regexes checked in order; anything not listed: `GET /api/*` → all authenticated roles, other methods →
  `admin` and `editor`.
- **Response.** `BaseController::execute()` wraps `handle()`: `ApiException` → error envelope with its status/code;
  any other exception → `500 INTERNAL_ERROR` (with the message only when `APP_DEBUG=true`).

## 3. Step by step

### 3.1 The controller — `back/src/Controllers/Dictionaries/FunctionCategoriesController.php`

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

### 3.2 Rebuild and call

```bash
docker compose up -d --build backend
TOKEN=$(curl -s -X POST http://localhost:9501/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@wps.local","password":"admin"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["access_token"])')
curl -s http://localhost:9501/api/dictionaries/function-categories -H "Authorization: Bearer $TOKEN"
```

Response with the built-in set of functions (a function from [Expression functions](expression-functions.md) increases the count of its category):

```json
{"success":true,"data":[{"value":"date","label":"date","count":32},{"value":"logic","label":"logic","count":5},{"value":"number","label":"number","count":4},{"value":"string","label":"string","count":6}],"error":null}
```

### 3.3 Toolbox of `BaseController`

| Method | Purpose |
|---|---|
| `$this->body()` | JSON body as an array (`400 BAD_REQUEST` if it is not an object) |
| `$this->query('key', default)`, `queryBool('key')` | query string |
| `[$page, $perPage] = $this->pagination(20, 1000)` + `$this->meta($page, $perPage, $total)` | paging; pass meta as the 3rd argument of `ok()` |
| `$this->intParam('id')`, `$this->param('name')` | URI parameters (`404 NOT_FOUND` for a non-numeric id) |
| `$this->user()` | `{id, email, role}` of the caller |
| `$this->ok($data, 201)` | success envelope with a status |
| `$this->container()` | services (`processService()`, `executions()`, `crm()`, `metrics()`, …) |

A write endpoint, for example:

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

(`archive` is an illustration — there is no such service method in WPS.)

## 4. Validation and errors

- Throw `ApiException::validation([['field' => 'name', 'message' => '…']])` → `422 VALIDATION_ERROR`,
  `ApiException::notFound('XXX_NOT_FOUND', '…')`, `ApiException::conflict('XXX', '…', $details)`,
  `ApiException::badRequest('…')`, `ApiException::forbidden()`.
- Add a rule to `AccessPolicy::RULES` when the default (GET: everyone, writes: editors) is not right. Put specific rules
  **before** general ones — the first match wins:

```php
['POST', '#^/api/processes/[^/]+/archive$#', self::ADMIN],
```

- Public endpoints (no token) must be listed with `self::PUBLIC`, as `/api/health` and `/metrics` are.
- Document the endpoint (method, URI, role, body, response, error codes) in `back/API.md` and in
  [API endpoints](../../06-api-reference/endpoints.md) (en + ru).

## 5. UI and translations

Add a function to the matching module in `front/src/api/` (or a new module) and types to `front/src/types/index.ts`:

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

`apiGet` prefixes `/api`, sends the token, unwraps `data` and shows translated error messages for known error codes
(`front/src/utils/errors.ts`). Add a text for a new error code there and in both locales (`errors.*`). A page that uses
the endpoint is the example in [New UI screen](new-ui-screen.md).

## 6. Test

```php
$this->test('Function categories dictionary lists date', function () {
    $res = $this->request('GET', '/api/dictionaries/function-categories', token: $this->viewerToken, expectedStatus: 200);
    $this->assertTrue(in_array('date', array_column($res['data'], 'value'), true));
});
$this->test('Function categories dictionary requires a token', function () {
    $this->request('GET', '/api/dictionaries/function-categories', expectedStatus: 401);
});
```

The first check is part of the verified `testExtensions()` group ([full text](new-action-type.md#6-test)). For write
endpoints also check `viewer` → `403`.

## 7. Checklist

- [ ] `final` controller under `back/src/Controllers/<Area>/`, `#[Route]`, extends `BaseController`, logic in a service
- [ ] Access rule in `AccessPolicy` if the default is wrong
- [ ] Errors via `ApiException` with documented codes
- [ ] `back/API.md` + [API endpoints](../../06-api-reference/endpoints.md) (en + ru) updated
- [ ] Frontend API function + types (+ `errors.*` texts for new codes)
- [ ] Integration checks (success, 401, 403), suite green

<!-- footer-nav -->
---

← Previous: [Expression functions](expression-functions.md) | [Contents](../../README.md) | [New CRM adapter](new-crm-adapter.md) Next →
<!-- /footer-nav -->
