# Testing

How the backend unit and integration tests, the cases on the SuiteCRM 8 stand and the frontend checks work, how to run them and how to add a new check.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/07-developer-guide/testing.md)
<!-- /lang-switch -->

## Overview

| Part | Tool | Command |
|---|---|---|
| Backend logic (unit) | PHPUnit, `back/tests/Unit` | `docker build -f back/Dockerfile --target test -t wps-backend-test . && docker run --rm wps-backend-test --testsuite unit` |
| Backend API | `back/tests/IntegrationTest.php` — plain PHP, HTTP requests to a running server on the mock CRM | `docker compose exec -T backend php tests/IntegrationTest.php` |
| Scenarios on a real CRM | `.tehnical/suiteCRM8/scripts/run_case.php` + cases `processes/cases/*.json` | `php .tehnical/suiteCRM8/scripts/run_case.php <case.json>` |
| Editor form logic (unit) | vitest, `front/src/**/*.spec.ts` | `npm test` |
| Frontend types | `vue-tsc` | `npm run typecheck` |
| Frontend translations | `scripts/i18n-check.mjs` | `npm run i18n:check` |
| Frontend build | i18n check + typecheck + `vite build` | `npm run build` |

Pure logic (comparisons, expressions, conditions, the execution context) is covered by unit tests that run in seconds
without the stack. Whole scenarios are covered by integration tests through the API, against the real PostgreSQL, both
Redis instances and the mock CRM. The business scenario of every task is additionally checked by a case on the SuiteCRM 8
stand with the real plugin.

The order for a new feature (from `rules_agent.md`): code → unit tests → an integration test block on the mock → a SuiteCRM 8
case until `PASSED` → documentation. Before the final report all cases and all integration tests are run.

Latest results at the time of writing: unit — `OK (809 tests, 2357 assertions)`, integration — `Passed: 156, Failed: 0`.

## Backend unit tests

### Run

Unit tests are built in a separate `test` stage of the backend image: it has the dev dependencies (PHPUnit), the
production stage `prod` does not.

```bash
# from the repository root
docker build -q -f back/Dockerfile --target test -t wps-backend-test .
docker run --rm wps-backend-test --testsuite unit
docker run --rm wps-backend-test --filter ExpressionEngineTest              # one class
docker run --rm wps-backend-test --filter 'ListFunctionsTest::testToList'   # one method
docker run --rm wps-backend-test tests/Unit/Expression                      # one directory
```

The `wps-backend-test` image holds a **copy** of the code at build time: rebuild it after changing code (or tests) — with the layer
cache it takes seconds, `composer install` repeats only when `composer.lock` changes.

Without rebuilding the image (the code is mounted, `back/vendor` is created on the host and ignored by git and Docker):

```bash
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD/back":/app -w /app \
  phpswoole/swoole:6.0-php8.3 sh -c "composer install -q && vendor/bin/phpunit --testsuite unit"
```

### Layout

```
back/
  phpunit.xml.dist          suite unit = tests/Unit (failOnWarning, failOnRisky)
  tests/
    IntegrationTest.php     integration tests (not PHPUnit, not part of the unit suite)
    Unit/
      bootstrap.php         autoload + UTC time zone
      Actions/              change types and actions: Aggregate, Assign, DateShift, Delay, LinkRecords, SetVariable,
                            SimpleChangeTypes, UpdateRecords
      Comparators/          operators: basic, order, between, in/not_in, lists, presence, changed*, starts/ends_with
      Context/              ExecutionContext, SystemVariables (sys.*, config.*)
      Crm/                  CRM contract: CrmFilter, FilterTranslator (find_records filter)
      Events/               EventEnvelope, event types
      Expression/           expression language: engine, conditionals, dates, numbers, Decimal, strings, lists, types/JSON
      Http/                 outgoing HTTP (EgressGuard, auth, response extraction)
      Mail/                 send_email: recipients, SMTP errors
      Notify/               notification channels
      Persistence/          the Process entity
      Program/              compiler, interpreter, condition / stop_process / foreach blocks; fixtures/
      Secrets/              encryption, masks, secret:// references
      Services/             ConditionEngineService, expression value, DefinitionScope, on_error / retry
      SideEffects/          SideEffectLedger
      Support/              InMemorySideEffectStore (fake), JsonPath
      Template/             TemplateEngine
      Time/                 DateMath
      Types/                TypeCaster, RelativeDate, case and spaces
```

The test namespace is `Wps\Tests\` (`autoload-dev` in `back/composer.json`).

### Rules

- One `*Test` class per class under test; "input → expected" tables via `#[DataProvider]`.
- A unit test opens no network connections: `Database`, `RedisConnection`, `ExternalApiAdapterService` are replaced by
  fakes.
- Pure logic does not use Swoole coroutines; if a test cannot avoid them, it is wrapped in `Swoole\Coroutine\run()`.
- Tests run in UTC. The current time comes from `Wps\Time\Clock`; tests use `FrozenClock` with a fixed moment (e.g.
  `RelativeDateTest`, `DateFunctionsTest`). Services that need "now" get it as a parameter
  (`ConditionEngineService::evaluate(..., now)`) instead of keeping it in a field.
- `ExternalApiAdapterService` is `final`: unit tests create it with
  `(new \ReflectionClass(ExternalApiAdapterService::class))->newInstanceWithoutConstructor()` (where no CRM calls are needed),
  and execution against the CRM is checked by the integration tests on the mock.
- The side-effect journal in tests is `Wps\Tests\Unit\Support\InMemorySideEffectStore`.
- `definition` is stored as `jsonb`, which does not keep the key order of objects: compare objects after `ksort` in tests and
  keep ordered data in lists.
- New behaviour is pinned by a test first; regression tests pin the actual behaviour, even a surprising one (for
  example, `'' + 5` in an expression yields the string `'5'`).

## Backend integration tests

### Run

The tests work only on the mock CRM. The root `./.env` may point the backend to SuiteCRM 8 (`CRM_API_BASE_URL=http://suitecrm8/api/v9`),
so the mock mode is set with environment variables **in the same command** (the environment does not survive between separate
shell calls):

```bash
export CRM_API_BASE_URL=http://mock:9502 CRM_API_STATIC_KEY=mySecretKey MOCK_SERVICE_URL=http://mock:9502 \
       TELEGRAM_API_BASE=http://mock:9502/mock/telegram
docker compose up -d --build backend mock      # the code is baked into the image — rebuild first
docker compose ps                              # mock must be running, otherwise "Could not resolve host: mock"
docker compose exec -T backend php tests/IntegrationTest.php
```

| Variable | Why |
|---|---|
| `CRM_API_BASE_URL=http://mock:9502`, `CRM_API_STATIC_KEY=mySecretKey` | the CRM API is the mock (the key matches `MOCK_CRM_STATIC_KEY` of the `mock` service) |
| `MOCK_SERVICE_URL=http://mock:9502` | the modules dictionary comes from the mock |
| `TELEGRAM_API_BASE=http://mock:9502/mock/telegram` | `[notify]` block: Telegram goes to the mock, not api.telegram.org (otherwise the test fails) |

To return the backend to SuiteCRM 8 after the tests: `unset CRM_API_BASE_URL CRM_API_STATIC_KEY MOCK_SERVICE_URL TELEGRAM_API_BASE;
docker compose up -d backend`.

Expected tail of the output:

```
[run_once]
  …
Passed: 156, Failed: 0, Skipped: 0
```

The exit code is `0` when nothing failed and `1` otherwise, so the command can be used in CI.

> **Warning**
> The tests need the **mock CRM**: they seed objects through `http://mock:9502/mock/objects/...` and check what was
> saved there. The mock keeps its state between runs, so test object ids must be unique (`random_bytes`, `random_int`).
> Instead of switching the main stack you can start a separate stack on the mock
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

- `run()` calls test groups in order (44 blocks): `[cleanup]` → `[health]` → `[auth]` → `[users]` → `[dictionaries]` →
  `[processes]` → `[validation]` → `[rbac]` → `[manual run]` → `[executions]` → `[stream]` → `[delete]` → `[run_once]` →
  `[typed conditions]` → `[program blocks]` → `[secrets]` → `[side effects]` → `[crm contract]` → `[event format]` →
  `[event types]` → `[change operators]` → `[on_error]` → `[send_email]` → `[http_request]` → `[link_records]` →
  `[find_records]` → `[foreach]` → `[update_records]` → `[aggregate]` → `[notify]` → `[assign]` → `[starts_with]` →
  `[between]` → `[relative dates]` → `[case_sensitive]` → `[list operators]` → `[expression value]` →
  `[change types 5.1.3-5.1.6]` → `[condition branches]` → `[stop_process]` → `[set_variable]` → `[waiting]` → `[expressions]` →
  `[system variables]`.
  Later groups use data from earlier ones
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
| `executionsOf($processId, $objectId)`, `parallelRuns($processId, $body, $count)` | executions of a process for a record; parallel manual runs (race checks) |
| `xadd($event)`, `redis()`, `redisData()`, `pdo()` | publish an event to the stream; direct access to Redis and PostgreSQL |
| `requireValue($v)`, `skip($reason)` | skip a check when data from a previous one is missing |
| `assertTrue`, `assertFalse`, `assertEquals` (strict), `assertNotNull`, `assertIsArray`, `assertArrayHasKey`, `assertArrayNotHasKey` | assertions |

- Test data is named `TEST …` (processes), `…@test.local` (users) and `test_…` (secrets); `cleanupTestData()` deletes them
  (with their executions and marks) at the start of each run, so the tests can be re-run on the same database.

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

2. Call it from `run()` (after `testLogin()` if it needs tokens) — new blocks go to the end of the list:

```php
$this->testSystemVariables();
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

## SuiteCRM 8 cases

A case is an executable JSON scenario of a real business process on the SuiteCRM 8 stand with the WPS plugin: it creates the
processes in WPS, changes records through the plugin API, waits for executions and checks the data in the CRM, the condition
trace and the WPS audit. The `.tehnical/` directory is not in git but exists in the working copy
([Project structure](project-structure.md#tehnical)).

### Run

```bash
# 1. backend on SuiteCRM 8 (values from the root .env): drop the mock-mode overrides
unset CRM_API_BASE_URL CRM_API_STATIC_KEY MOCK_SERVICE_URL TELEGRAM_API_BASE
docker compose up -d backend
# 2. the SuiteCRM 8 stand must be running (wps-suitecrm8-web-1, wps-suitecrm8-db-1)
docker ps --format '{{.Names}}' | grep suitecrm8
# 3. run a case on the host (PHP ≥ 8.1, no extensions)
php .tehnical/suiteCRM8/scripts/run_case.php .tehnical/suiteCRM8/processes/cases/5.2.9-system-variables.json
php .tehnical/suiteCRM8/scripts/run_case.php <case.json> --keep     # leave the case processes active
# regression of all cases
for f in .tehnical/suiteCRM8/processes/cases/*.json; do php .tehnical/suiteCRM8/scripts/run_case.php "$f" || echo "FAILED: $f"; done
```

The runner reads its settings from `.tehnical/suiteCRM8/.env` (the environment overrides them): `WPS_API_URL`, `WPS_API_EMAIL`,
`WPS_API_PASSWORD`, `WPS_API_STATIC_KEY`, `SUITECRM8_HTTP_PORT` (CRM API — `http://localhost:<port>/api/v9`) or `CRM_API_URL`,
`MAILPIT_URL`. Requests to the plugin API are signed with the header `X-Auth-Key: <WPS_API_STATIC_KEY><Y.m.d H UTC>`. The result is
the line `PASSED: N step(s) passed, T s` or `FAILED: …`, preceded by `FAIL: <reason>` of the failed step; exit code `0` / `1`.

### How the runner works

1. Processes listed in `deactivate` (e.g. the stand's demo processes) are switched off for the run and restored afterwards.
2. The `setup` steps run.
3. The case processes are imported into WPS by name (`POST` or `PUT /api/processes`, children first) and activated; they are
   available as `{{process.<index>.id}}`.
4. The `steps` run in order.
5. Always (even after a failure) the case processes are switched off again (unless `--keep`), the `teardown` steps run (their
   errors are only printed) and the processes from `deactivate` are switched back on.

### Case format

```json
{
  "id": "5.2.9",
  "title": "A short description of the business scenario",
  "description": "What is checked",
  "deactivate": ["suiteCRM8"],
  "setup": [ … ],
  "processes": [ { "name": "case 5.2.9 — sys и config", "object_type": "Accounts", "is_child": false,
                   "trigger_on": ["update"], "definition": { … } } ],
  "steps": [
    {"title": "account", "crm.create": {"module": "Accounts", "attributes": {"name": "S529 {{case.run}}"}}, "as": "a1"},
    {"crm.update": {"module": "Accounts", "id": "{{a1.id}}", "attributes": {"phone_office": "+7 999"}}},
    {"wps.wait": {"process": "case 5.2.9 — sys и config", "object_id": "{{a1.id}}", "status": "completed", "timeout": 60}, "as": "e1"},
    {"assert": {"actual": "{{e1.context.system.trigger}}", "equals": "stream"}},
    {"wps.api": {"method": "POST", "path": "/api/processes", "body": { … }, "expect": 422}, "as": "err"}
  ],
  "teardown": [ {"crm.api": {"method": "DELETE", "path": "Accounts/{{a1.id}}", "expect": [200, 404]}} ]
}
```

Each step is an object with one action key and optional `as` (a name for the result) and `title`:

| Step | What it does |
|---|---|
| `crm.create` / `crm.update` / `crm.get` | create / change / read a record through the plugin API (`module`, `id`, `attributes`) |
| `crm.find` | search: `module`, `filter` (equality or contract 2.0 operators: `{"amount": {"gte": "1000"}}`), `size` |
| `crm.api` | any plugin API call: `method`, `path`, `body`, `expect` (a status or a list) |
| `wps.wait` | wait for an execution of a process for a record: `process`, `object_id`, `status`, `timeout`, `count` (the N-th execution) |
| `wps.none` | make sure no execution appears: `process`, `object_id`, `wait` |
| `wps.run` | manual run: `process`, `object`, `force` |
| `wps.get` | wait for an execution status by id: `execution_id`, `status`, `timeout` |
| `wps.api` | any WPS API call; the result is `data` (or `error` when the expected status is ≥ 400) |
| `wps.restart` | restarts the backend (`docker compose restart`), `kill: true` — a hard kill, `env` — variables for the restart |
| `assert` | `actual` + one operator: `equals`, `not_equals`, `contains`, `not_contains`, `matches` (regular expression), `empty`, `not_empty`, `count` |
| `sleep` | a pause in seconds |
| `mail.find` | a letter in Mailpit by `subject` / `subject_contains`, `timeout` |
| `http.get` | JSON from any URL (`method` is optional), e.g. `http://localhost:9502/mock/echo-last?key=x` |
| `script` | a fixture from the stand's `scripts/`, e.g. `{"script": {"name": "seed_case_users.sh"}}` |

Placeholders: `{{name.path}}` — the result of an earlier step (`length` gives the size of a list); `{{case.run}}` — a unique marker
of the run (test records are marked with it); `{{case.date+N}}` / `{{case.date-N}}` — a date relative to the day of the run (UTC,
`Y-m-d`); `{{env.NAME}}` — an environment variable. A string made of one placeholder keeps the type of the value. `\{{` is a
literal `{{` (to send a WPS template in a step body). Useful paths: the condition trace — `context.conditions`, a step —
`actions.N.result_data`, `actions.N.request_data`, `actions.N.error_code`, `actions.N.handled`, system variables —
`context.system`.

### Case requirements

- A real business scenario: the CRM data is checked after the execution, the WPS trace or audit is checked, and at least one
  validation error (`422`) is checked through `wps.api`.
- Test records are marked with `{{case.run}}` and deleted in `teardown`.
- The plugin API does not create CRM users (`Users` is read-only) — use the `seed_case_users.sh` fixture.
- The case description goes to `md/tasks_list/cases/<id>-suitecrm8.md` (what was done, checks with numbers, a "data → result"
  table, an excerpt of the runner output).
- SuiteCRM limitations met so far (`name` of `Tasks` / `Meetings` is 50 characters, HTML stripping, relate fields without a
  column) are collected in `legend_project_agent.md`.

## Frontend checks

```bash
cd front
npm ci
npm test             # vitest: src/**/*.spec.ts
npm run typecheck    # vue-tsc --noEmit
npm run i18n:check   # translations
npm run build        # i18n:check + typecheck + vite build → dist/
```

`npm test` checks the editor form helpers (`src/utils/processForm.ts`): a process opened and saved without edits must
equal the original — for all demo processes `doc/_meta/demo_processes/*.json` and for separate cases (constants,
`cast`, value lists, `start_process` order, read-only rows). It needs Node 18+.

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
