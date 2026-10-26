# Testing

How the backend unit and integration tests and the frontend checks work, how to run them and how to add a new check.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/07-developer-guide/testing.md)
<!-- /lang-switch -->

## Overview

| Part | Tool | Command |
|---|---|---|
| Backend logic (unit) | PHPUnit, `back/tests/Unit` | `docker build -f back/Dockerfile --target test -t wps-backend-test . && docker run --rm wps-backend-test --testsuite unit` |
| Backend API | `back/tests/IntegrationTest.php` — plain PHP, HTTP requests to a running server | `docker compose exec backend php tests/IntegrationTest.php` |
| Frontend types | `vue-tsc` | `npm run typecheck` |
| Frontend translations | `scripts/i18n-check.mjs` | `npm run i18n:check` |
| Frontend build | i18n check + typecheck + `vite build` | `npm run build` |

Pure logic (comparisons, expressions, conditions, the execution context) is covered by unit tests that run in seconds
without the stack. Whole scenarios are covered by integration tests through the API, against the real PostgreSQL, both
Redis instances and the mock CRM.

## Backend unit tests

### Run

Unit tests are built in a separate `test` stage of the backend image: it has the dev dependencies (PHPUnit), the
production stage `prod` does not.

```bash
# from the repository root
docker build -f back/Dockerfile --target test -t wps-backend-test .
docker run --rm wps-backend-test --testsuite unit
docker run --rm wps-backend-test --filter ExpressionEngineTest   # one class
```

Without rebuilding the image (the code is mounted, `back/vendor` is created on the host and ignored by git and Docker):

```bash
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD/back":/app -w /app \
  phpswoole/swoole:6.0-php8.3 sh -c "composer install -q && vendor/bin/phpunit --testsuite unit"
```

### Layout

```
back/
  phpunit.xml.dist          suite unit = tests/Unit
  tests/
    IntegrationTest.php     integration tests (not PHPUnit, not part of the unit suite)
    Unit/
      bootstrap.php         autoload + UTC time zone
      Comparators/          comparison operators
      Context/              ExecutionContext
      Crm/                  CRM contract: CrmFilter, FilterTranslator (find_records filter)
      Expression/           expression language
      Services/             ConditionEngineService and other services with pure logic
      Support/              fakes and test helpers
```

The test namespace is `Wps\Tests\` (`autoload-dev` in `back/composer.json`).

### Rules

- One `*Test` class per class under test; "input → expected" tables via `#[DataProvider]`.
- A unit test opens no network connections: `Database`, `RedisConnection`, `ExternalApiAdapterService` are replaced by
  fakes.
- Pure logic does not use Swoole coroutines; if a test cannot avoid them, it is wrapped in `Swoole\Coroutine\run()`.
- Tests run in UTC. The current time comes from a clock that tests freeze (`Wps\Time\Clock`, added together with the
  date functions).
- New behaviour is pinned by a test first; regression tests pin the actual behaviour, even a surprising one (for
  example, `'' + 5` in an expression yields the string `'5'`).

## Backend integration tests

### Run

```bash
export TELEGRAM_API_BASE=http://mock:9502/mock/telegram   # [notify] block: Telegram goes to the mock, not api.telegram.org
docker compose up -d --build backend     # the code is baked into the image — rebuild first
docker compose exec backend php tests/IntegrationTest.php
```

Without `TELEGRAM_API_BASE` pointing to the mock, the Telegram test of the `[notify]` block calls the real Bot API and
fails. The variable lives in this shell only; restart the backend without it to work with the real Telegram.

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

### Mock CRM modes

The mock implements CRM contract 2.0 ([CRM integration](../04-operations/crm-integration.md#option-1-the-mock-default-for-development)).
Two modes are switched at run time without restarting the container (they are kept in Redis):

```bash
curl -s -X PUT http://localhost:9502/mock/config -d '{"contract": "1.0"}'          # an old plugin: no capabilities, equality only
curl -s -X PUT http://localhost:9502/mock/config -d '{"publish_on_write": true}'   # v2 events on POST/PUT/DELETE through the API
curl -s http://localhost:9502/mock/config                                          # current modes
```

Defaults come from the variables of the `mock` service in `docker-compose.yml`: `MOCK_CRM_CONTRACT` (`2.0`) and
`MOCK_PUBLISH_ON_WRITE` (`0`). A test that changes a mode restores it in `finally` (see `testCrmContract()`); after switching
the contract reset the WPS capabilities cache: `POST /api/dictionaries/modules/refresh`.

Search data (`find_records`, block `[find_records]`) is put into the mock directly: `PUT /mock/objects/{module}/{id}`
(a record) and `PUT /mock/relationships/{module}/{id}/{link}/{related_id}` with `{"related_module": "Contacts"}` (a link).
The mock supports all filter operators of the contract, including `nin`.

The `[foreach]` block prepares `LoopContacts` contacts the same way and checks: changing the responsible user of all
contacts (one `foreach` record, body records with `iteration` 0..2), `on_item_error: continue` / `stop` on a contact
with id `fail500-loop` (the mock answers 500 to its save), a 6 s pause inside the loop (the execution is `waiting`, no
`exec:{id}*` keys in Redis, after waking the loop continues over the snapshot) and the block validation. Loop unit tests
are in `tests/Unit/Program/ForeachTest.php` (an in-memory snapshot store, resuming in the middle of a loop).

The `[update_records]` block checks: the account owner → contacts by relationship (`updated: 2`, `unchanged: 1`, new
values in the context list); a record missing in the mock CRM (404) with `only_changed: false` and `item_var: c` — the
other records are updated, the step `failed` ("1 of 4 records failed") is handled by `on_error: continue`; validation;
an empty list → `skipped`. Unit tests are in `tests/Unit/Actions/UpdateRecordsTest.php` (validation rules, the temporary
record variable, also on an error).

The `[aggregate]` block checks on the mock CRM: `sum`, `count` via `meta.total` (one request), `avg`, `max` of a date,
`count` by relationship, `on_limit: partial` with `truncated`, an empty filter value (no request), `on_limit: error`,
the `aggregate-functions` dictionary and validation. Unit tests are in `tests/Unit/Actions/AggregateTest.php` (folds, a
cast error, an empty filter and an empty relationship id without a request, validation rules).

The `[notify]` block uses the messenger endpoints of the mock CRM: `POST /mock/slack/{id}` (id `fail429…` — the first
answer is 429, `gone…` — 404), `POST /mock/teams/{id}`, `POST /mock/telegram/bot{token}/sendMessage` (chat `forbidden` —
403); received messages — `GET /mock/notify`, cleanup — `DELETE /mock/notify`. It checks: `crm_alert` + Telegram + Slack
+ Teams in one step and the message formats; the token and the webhook URL do not reach the audit; 429 → a repeated
step, the first message from the journal (`replayed`), no duplicates; a forbidden chat → `failed` with `fail_on: any` and
`completed` with `all`; `POST /api/notify/test`, the `notify-channels` dictionary, secret kind checks. Unit tests are in
`tests/Unit/Notify/NotifyTest.php` (escaping, text cutting, links by profile, Teams bodies, `Alerts` fields,
recipients, the `fail_on` outcome, validation).

The `[assign]` block prepares users in the mock CRM (u4 inactive, u1 the manager of u2 and u3) and a security group and
checks: `round_robin` by the group — u1, u2, u3, u1 again, the inactive one skipped; a save failing once (`fail1x500`)
→ two attempts, `reused_decision: true`, the queue moved once; `least_busy` (2/1/0 open → u3); `manager` with
`save_to_crm`; `fallback: keep`; the `assign-strategies` dictionary; validation. Unit tests are in
`tests/Unit/Actions/AssignTest.php` (user activity, the round robin skipping inactive and missing users, `manager` and
`fallback`, the `least_busy` pool limit, validation rules).

The `[starts_with]` block checks the operators dictionary, a manual run (the condition `ends_with @gmail.com` is met for
a real address and not met for `…@gmail.com.evil.ru`) and `422` for `cast: int` and an empty prefix. Unit tests are in
`tests/Unit/Comparators/StartsEndsWithTest.php` (`+7`, a mail domain, Cyrillic with `case_sensitive: false`, an empty
and a missing field, a number, the translation into the CRM filter `like`).

The `[between]` block checks the dictionary (`operands: range`), a manual run (50,000 in `[10000, 50000]` — yes, 50,001
— no) and the validation rules (type, pair, empty bound, cast, `from > to`). Unit tests are in
`tests/Unit/Comparators/BetweenTest.php` (included bounds, an empty and a missing field, dates in different formats,
bounds from fields, `range_inverted`, a missing bound, the lazy preload, the translation into `gte` + `lte`).

The `[relative dates]` block checks the `relative-dates` dictionary, a manual run (`expected_resolved` in the trace; a
record older than a day fails `now-24h`) and validation errors in a condition, in a `between` bound and in a
`find_records` filter. Unit tests are in `tests/Unit/Types/RelativeDateTest.php`: parsing and errors, computing around
midnight in `Europe/Moscow`, the end of February and 31.01 + 1 month, the start of the week, the quarter, the daylight
saving switch in `Europe/Berlin` (`+1d` and `+24h`), conditions with a frozen clock (`FrozenClock`), a field value is
not interpreted, a relative date in a CRM filter.

The `[case_sensitive]` block checks the `casts` dictionary, a strict and a non-strict condition on "  МОСКВА ",
`actual_cast` and `cast_options` in the trace and the rejection of a flat `case_sensitive` and of `trim` for `int`. Unit
tests are in `tests/Unit/Types/CaseInsensitiveTest.php` ("МОСКВА " ≡ " Москва" with a no-break space, `Straße` ≠
`STRASSE`, `ё` ≠ `е`, BOM, a multienum; the `equals`, `contains`, `in`, `changed` operators).

The `[list operators]` block checks the dictionary, manual runs on a multienum (`^VIP^,^gold^` — yes; `^vipclient^` —
no; `^vip^,^spam^` with `contains_none` — no) and the validation of the type and of an empty list. Unit tests are in
`tests/Unit/Comparators/ListOperatorsTest.php` (intersection and subset, no substring match, an empty and a missing
field, case, a string on the right, `matched`, a right side from a field and an empty one).

The `[expression value]` block checks the `value-types` dictionary, a process with an account preload and an
expression condition (90,000 — yes, `expected = 80000`; 70,000 — no) and the validation rules. Unit tests are in
`tests/Unit/Services/ConditionExpressionValueTest.php` (`v1.credit_limit * 0.8`, the lazy preload, `coalesce`, the
string `'1 200,50'` with `decimal`, `lower()`, division by zero — false and `expression_error`, an exception in strict
mode, a missing field, a pair of expressions for `between`).

The `[change types 5.1.3-5.1.6]` block checks the change types dictionary, seven steps on one record (`increment` →
`5`, a fractional `increment` → `80.25`, `clear` → `''`, a history with `append`, a multienum `+vip` / `−cold`, `map` →
`High`) and validation rules. Unit tests are in `tests/Unit/Actions/SimpleChangeTypesTest.php` (`increment` /
`decrement` in all modes, `clear`, `append` / `prepend` with a separator and `max_length`, lists with `unique` and
`remove`, `map` with case, `keep`, ranges, a template and the `""` key, validation rules).

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

### Restart during a wait

1. Create a process with a `{"type": "delay", "mode": "duration", "duration": "30s"}` step before `modify` +
   `save_object` and publish an event (as above).
2. `curl -s 'http://localhost:9501/api/executions?status=waiting' -H "Authorization: Bearer $TOKEN"` — the execution is
   `waiting`, `waiting_until` is 30 s ahead; `wps_executions_waiting` in `/metrics` is 1.
3. Kill the backend during the pause: `docker compose kill -s KILL backend && docker compose up -d backend`. To check a
   Redis loss, also run `docker compose exec redis-data redis-cli FLUSHALL`.
4. After the due time the execution continues: the `delay` step is `success` with `waited: true` and `lateness_ms`, the
   execution is `completed`, the log has `Execution woken`. It does not wake up twice (`wps_executions_woken_total` grew by 1).

The case `.tehnical/suiteCRM8/processes/cases/7.2-waiting-executions.json` checks the same scenario automatically.

See [Executions and audit](../03-user-guide/executions-and-audit.md).

<!-- footer-nav -->
---

← Previous: [Coding conventions](coding-conventions.md) | [Contents](../README.md) | [New action type](extending/new-action-type.md) Next →
<!-- /footer-nav -->
