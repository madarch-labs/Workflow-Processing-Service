# Testing

How the backend integration tests and the frontend checks work, how to run them and how to add a new check.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/07-developer-guide/testing.md)
<!-- /lang-switch -->

## Overview

| Part | Tool | Command |
|---|---|---|
| Backend API | `back/tests/IntegrationTest.php` — plain PHP, HTTP requests to a running server | `docker compose exec backend php tests/IntegrationTest.php` |
| Frontend types | `vue-tsc` | `npm run typecheck` |
| Frontend translations | `scripts/i18n-check.mjs` | `npm run i18n:check` |
| Frontend build | i18n check + typecheck + `vite build` | `npm run build` |

There is no unit-test framework: the backend is tested end to end through the API, against the real PostgreSQL, both
Redis instances and the mock CRM.

## Backend integration tests

### Run

```bash
docker compose up -d --build backend     # the code is baked into the image — rebuild first
docker compose exec backend php tests/IntegrationTest.php
```

Expected tail of the output:

```
[run_once]
  …
Passed: 67, Failed: 0, Skipped: 0
```

The exit code is `0` when nothing failed and `1` otherwise, so the command can be used in CI.

> **Warning**
> The tests need the **mock CRM**: they seed objects through `http://mock:9502/mock/objects/...` and check what was
> saved there. If your `./.env` points the backend to a real SuiteCRM, run the tests in a separate stack on the mock
> (see [Development environment](dev-environment.md#a-second-isolated-stack)).

### Environment

Inside the `backend` container the defaults already fit:

| Variable | Default | Used for |
|---|---|---|
| `API_BASE_URL` | `http://localhost:9501` | WPS API (the test runs in the same container as the server) |
| `CRM_API_BASE_URL` | `http://localhost:9502` | mock CRM helpers `/mock/objects/{module}/{id}` |
| `DB_POSTGRES_*` / `DB_*` | from Compose | direct cleanup of test data |
| `REDIS_STREAM_*` | from Compose | the stream test publishes an event with `XADD` |

### How the test file works

`IntegrationTest` is one class (`md/doc/concept_apitest_back.md`):

- `run()` calls test groups in order: cleanup → health → auth → users → dictionaries → processes → validation → RBAC →
  manual run → executions → stream → delete rules → run once. Later groups use data from earlier ones
  (`$this->adminToken`, `$this->createdProcesses['top']`, `$this->executionId`).
- A group is a `private function testXxx(): void` that prints a header and calls `$this->test('name', fn)` for each check.
  A check passes if the closure returns, fails on any `Throwable`, and is skipped with `$this->skip('reason')`.
- Helpers:

| Helper | Purpose |
|---|---|
| `request($method, $path, $body, $token, $expectedStatus)` | JSON request to WPS, throws if the status differs, returns the decoded envelope |
| `raw($method, $path, $body, $token, $base)` | any HTTP request, returns `{status, body}` (e.g. to the mock with `base: $this->crmUrl`) |
| `waitForExecution($id, $timeout = 10)` | polls `GET /api/executions/{id}` until `completed`/`failed` |
| `waitForExecutions($processId, $objectId, $count)` | waits for stream-started executions |
| `seedAccount()`, `crmObject($module, $id)` | put / read an object in the mock CRM |
| `assertTrue`, `assertFalse`, `assertEquals` (strict), `assertNotNull`, `assertIsArray`, `assertArrayHasKey`, `assertArrayNotHasKey` | assertions |

- Test data is named `TEST …` (processes) and `…@test.local` (users); `cleanupTestData()` deletes leftovers at the start
  of each run, so the tests can be re-run on the same database.

### Add a check

1. Write a group method next to the others:

```php
private function testMyFeature(): void
{
    echo "[my feature]\n";
    $this->test('Operators dictionary contains starts_with', function () {
        $res = $this->request('GET', '/api/dictionaries/operators', token: $this->viewerToken, expectedStatus: 200);
        $this->assertTrue(in_array('starts_with', array_column($res['data'], 'value'), true));
    });
}
```

2. Call it from `run()` (after `testLogin()` if it needs tokens):

```php
$this->testRunOnce();
$this->testMyFeature();
```

3. Rules from `md/doc/concept_apitest_back.md`: always pass `expectedStatus`; check `success`/`data` explicitly; name
   created processes `TEST …`; store created ids in `$this->created*` so later checks can use them; for protected endpoints
   check no token → 401, wrong role → 403.
4. Rebuild the backend and run the file. Each guide in [Extending WPS](extending/new-action-type.md) ends with a ready
   check for that extension.

> **Note**
> When you add a dictionary item, update the existing exact-match checks too: for example
> *"Operators contain the four comparison types"* compares the full list of operator codes.

## Frontend checks

```bash
cd front
npm ci
npm run typecheck    # vue-tsc --noEmit
npm run i18n:check   # translations
npm run build        # i18n:check + typecheck + vite build → dist/
```

`npm run i18n:check` reports, for example:

```
✗ de.json: нет ключа nav.functions
```

It verifies that all `src/locales/*.json` have the same keys as `ru.json`, that every `t('…')`/`te('…')` key used in
the code exists, and that `.vue`/`.ts` files contain no Cyrillic outside comments. The Docker image of the frontend runs
the same `npm run build`, so a broken translation also breaks `docker compose up --build frontend`.

## Manual end-to-end check

1. `curl -g 'http://localhost:9502/test-event?module=Deals&set[status]=Active'` — publish an event.
2. Open the process in the UI → execution log → the new execution → steps and context.
3. `curl http://localhost:9501/metrics | grep wps_` — counters moved.

See [Executions and audit](../03-user-guide/executions-and-audit.md).

<!-- footer-nav -->
---

← Previous: [Coding conventions](coding-conventions.md) | [Contents](../README.md) | [New action type](extending/new-action-type.md) Next →
<!-- /footer-nav -->
