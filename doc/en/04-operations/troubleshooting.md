# Troubleshooting

Typical problems, how to recognise them and how to fix them.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/04-operations/troubleshooting.md)
<!-- /lang-switch -->

## Where to look first

1. **The execution page** in the UI: error banner, steps with `request`/`result` data and the context (event, variables,
   condition trace). See [Executions and audit](../03-user-guide/executions-and-audit.md).
2. **`GET /api/health`** — are PostgreSQL and both Redis instances reachable, does the stream have lag or pending messages.
3. **Backend logs** — `docker compose logs -f backend` (JSON lines; set `LOG_LEVEL=debug` to see every CRM request).
4. **The DLQ** — `docker compose exec -T redis-stream redis-cli XRANGE crm_events:dlq - + COUNT 10`.

![Failed execution: error banner, four attempts of the preload step, the next step skipped](../../images/screenshots/en/12-execution-detail-failed.png)
*A failed CRM call: the CRM answered HTTP 500, the step was retried 3 times (4 attempts), the following step was skipped.*

## Symptom → cause → fix

| # | Symptom | Likely cause | Fix |
|---|---|---|---|
| 1 | An event was sent but no execution appears | No **active top-level** process for this `module`; the process is a child; the object type differs in case (`deals` ≠ `Deals`) | Check the process list filters "Object type", "Process type", "Status". Child processes never start from events |
| 2 | Still no execution, the process is correct | The conditions are not met. A failed check creates no execution (by design) | Use [manual run](../03-user-guide/manual-run-and-reset.md) with the same object: the dialog shows the condition trace with `actual` / `expected` |
| 3 | No execution, conditions are met | Run once per record: the record already has a mark. Log: `Process skipped: run_once` | Process card → "Run-once records" → reset the mark, or turn off "Run once per record" |
| 4 | Nothing is processed at all, `stream.lag` grows | The backend does not consume: it is down, or it connects to another Redis/stream name | `docker compose ps`, `docker compose logs backend`; check `REDIS_STREAM_HOST`, `REDIS_STREAM_NAME`, the group in `XINFO GROUPS crm_events` |
| 5 | Events disappear, `wps_events_invalid_total` grows | Invalid event → DLQ: `Event has no "module"/"object_type"`, `Event object has no "id"`, `Field "data" is not a JSON object` | Read `reason` in `crm_events:dlq` and fix the publisher (see [event format](crm-integration.md#event-format-crm--wps)) |
| 6 | Step fails with `CRM GET … returned HTTP 500` after ~7 s, `attempts: 4` | The CRM is failing; WPS retried 3 times | Fix the CRM, then re-run manually. Tune `ACTION_RETRY_*` if the CRM needs more time |
| 7 | Step fails with `CRM … : connection failed` / `request timeout` | Wrong `CRM_API_BASE_URL`, the CRM container is not in the WPS network, or it is slower than `CRM_API_TIMEOUT` | Check reachability from the backend container: `docker compose exec backend curl -s -o /dev/null -w '%{http_code}' <CRM base URL>/modules`; join the CRM to the WPS network; increase `CRM_API_TIMEOUT` |
| 8 | Step fails with `CRM … returned HTTP 401` | Wrong `CRM_API_STATIC_KEY`, or server clocks differ by more than an hour | Use the same key in WPS and the CRM (`MOCK_CRM_STATIC_KEY` / `WPS_API_STATIC_KEY`); sync clocks (NTP, UTC) |
| 9 | Step fails with `CRM PUT …/{id} returned HTTP 404` | `save_object`/`save_to_crm` on a record that does not exist in the CRM (e.g. a hand-made event, or the mock lost its data) | Load the record with preload first, or make sure the event `id` is a real CRM id |
| 10 | Requests go to `/api/v8/...` but the CRM serves `/api/v9` | `CRM_API_BASE_URL` does not end with `/api/vN`, so WPS appends `/api/v8` | Set `CRM_API_BASE_URL=http://<crm>/api/v9` |
| 11 | "Object type" list shows the mock modules after switching to SuiteCRM | `MOCK_SERVICE_URL` still set, or the dictionary is cached (300 s) | Set `MOCK_SERVICE_URL=` (empty), restart backend, click refresh next to "Object type" or `POST /api/dictionaries/modules/refresh` |
| 12 | Preload variable is `null`, next steps write nothing or `save_object` is `skipped` | The record was not found (`404` or empty search) or the lookup value was empty — not an error | Check the preload step result (`found: false`, `lookup_value`). Fix the path (`object.account_id`) or `by_field` |
| 13 | Save is `skipped` with `No changed fields to save` | `save_object` sends only fields changed in this execution | Change a field before saving, or use `"mode": "full"` in the action JSON |
| 14 | Save fails: `Cannot save "x": CRM module is unknown` | A new variable (created by `modify`) has no module | Set "Module" in the save row (`module` in the action) |
| 15 | Saving a process: `422 VALIDATION_ERROR`, `Variable "v1" is not defined at this point` | The variable is used before its preload row, or the preload row is missing | Reorder the rows; in child processes parent variables are allowed |
| 16 | Saving a process: `CYCLE_DETECTED` | `start_process` would form a loop A → B → A | Remove one of the links; the error `details.path` lists the chain |
| 17 | Saving: `Process N "…" is not a child process` | `start_process` can start only child processes | Mark the target process as "Child process" |
| 18 | Deleting a process: `409 PROCESS_IN_USE` | Another process starts it | Remove the `start_process` reference in the processes listed in `details` |
| 19 | A process starts itself again and again | It saves the record that triggered it → new `after_save` → new event | Enable "Run once per record", or add a condition on a marker field that the process sets |
| 20 | Execution stuck in `running` | The worker crashed. `RecoveryJob` resumes it after `RECOVERY_STALE_SECONDS` (5 min) or marks it failed ("Execution context expired or lost") | Wait for recovery; check logs for `Resuming interrupted execution` |
| 21 | `401 TOKEN_EXPIRED` from API scripts | Access tokens live 15 min | Call `POST /api/auth/refresh` or log in again |
| 22 | `403 FORBIDDEN` | The role does not allow the operation (e.g. viewer runs a process, editor deletes one) | See the [role table](security.md#roles) |
| 23 | `/api/health` returns `503` | One of PostgreSQL / `redis-data` / `redis-stream` is unreachable; `checks.<name>.error` says why | Start the failed service; check host/port/password variables |
| 24 | Backend exits on start with `[migrate] database is not reachable` | PostgreSQL not ready within `DB_WAIT_SECONDS` (60) or wrong `DB_POSTGRES_*` | Check the postgres container and credentials; increase `DB_WAIT_SECONDS` |
| 25 | Code changes in `back/` have no effect | The code is baked into the image | `docker compose up -d --build backend` |
| 26 | `+` in `calculate` returns glued text (`100 USD5`) or just one operand (`1000` when the other field is empty) | `+` concatenates when one operand is not numeric (an empty or missing field is an empty string) | Make sure both fields are numbers; `- * / %` treat an empty value as `0` and fail with `Operator "*" requires numbers, got "…"` on text |
| 27 | Step fails with `Division by zero in expression` | A divisor field is `0` or empty | Guard the divisor with `max(object.qty, 1)` or a condition |
| 28 | UI is in the wrong language | Language is chosen by `?lang=`, then the saved choice, then the browser | Use the language switcher in the header or add `?lang=en` |
| 29 | `send_email` fails with `Mail transport: Connection could not be established…` (code `SMTP_CONNECTION`), retries exhausted | Wrong host/port in `MAILER_DSN` or the secret, the SMTP server is unreachable from the backend container | Check the settings with `POST /api/mail/test` (administrator): `sent: false` carries the SMTP error text. Check the network: `docker compose exec backend php -r 'var_dump(fsockopen("host", 587));'` |
| 30 | `Mail transport authentication failed` (`SMTP_AUTH`) | Wrong SMTP login or password (reply 535), the server requires TLS (`smtps://` or port 587 with STARTTLS) | Fix the `smtp_dsn` secret or `MAILER_DSN`; there are no retries — it is a configuration error |
| 31 | `send_email` fails with `SMTP_550` / `SMTP_553` / `SMTP_554` | Permanent refusal: no mailbox, sender not allowed, rejected as spam | Check the recipient address and `from`; the sender must be allowed by the SMTP server (SPF/DKIM are configured there) |
| 32 | The step is retried with `SMTP_421` / `SMTP_451` or `Mail rate limit exceeded` (`MAIL_RATE_LIMIT`) | Temporary refusal of the server or `MAILER_RATE_PER_MINUTE` exceeded | Nothing: the step is retried by its `retry` profile. With constant refusals raise `retry.max_delay` or the limit |
| 33 | `send_email` — `UNKNOWN_OUTCOME` | The connection broke **after** the letter was handed over (`at_most_once`): it may have been sent | Check the recipient mailbox or Mailpit by the `Message-ID` from the step result; send manually if needed. Not repeating is intentional |
| 34 | The letter did not reach everybody, the step result has `invalid_recipients` / `rate_limited` | An address from a field is empty or invalid; the recipient already got `MAILER_RATE_PER_RECIPIENT_HOUR` letters this hour | Fix the data in the CRM; check that the process does not send on every save (`trigger_on`, `changed_to`) |
| 35 | Letters do not arrive on the stand | On the stand `MAILER_DSN=smtp://mailpit:1025`: letters are not delivered | Open Mailpit `http://localhost:8025` |
| 36 | `http_request` — `Destination address is not allowed (private network)` | The host resolves to a private address (internal network, `localhost`, cloud metadata) — SSRF protection | If it is your internal system, add its subnet to `HTTP_ALLOW_PRIVATE_CIDRS`; otherwise fix the URL |
| 37 | `http_request` — `Host "x" is not allowed` / `… is not in HTTP_ALLOW_HOSTS` / `Port N is not allowed` | The host is in `HTTP_DENY_HOSTS` (default — the CRM, `postgres`, `redis-*`, `mock`), not in the allow list, or the port is forbidden | For the CRM use the CRM actions, not `http_request`; otherwise adjust `HTTP_ALLOW_HOSTS` / `HTTP_DENY_PORTS` |
| 38 | `http_request` — `Secret "x" is not allowed for host "y"` | The secret has `allowed_hosts`, the request goes to another host | Check the URL or add the host to the secret's `allowed_hosts` |
| 39 | `http_request` — `Response is too large` (`RESPONSE_TOO_LARGE`) | The response is larger than `HTTP_MAX_RESPONSE_BYTES` | Request less data (API filters, pages) or raise the limit |
| 40 | `http_request` — `UNKNOWN_OUTCOME` | `500`/`502`/`504` or a broken connection after sending with `at_most_once` and no `Idempotency-Key`: the request may have been executed | Check the external system (`GET /api/executions/{id}/side-effects`). To retry such steps, use `Idempotency-Key` on the server side or `delivery: at_least_once` |

## Useful commands

```bash
# send a test event through the mock
curl -sg 'http://localhost:9502/test-event?module=Deals&set[status]=Active'

# look at an object stored in the mock CRM
curl -s http://localhost:9502/mock/objects/Accounts/<account_id>

# re-run with a test object (editor or admin token)
curl -s -X POST http://localhost:9501/api/processes/1/run \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"object":{"id":"<deal id>","module":"Deals","status":"Active","account_id":"<account_id>"}}'

# pending messages and consumers
docker compose exec -T redis-stream redis-cli XPENDING crm_events wps_workers
docker compose exec -T redis-stream redis-cli XINFO CONSUMERS crm_events wps_workers
```

The ids in the examples are placeholders: take `id` and `account_id` from the `event` in the `test-event` response.

> **Warning**
> If the account does not exist in the CRM, process 1 does not fail: the preload returns `null` and the `modify` step with
> **Save to CRM** creates a new account (`POST`). See [Actions](../03-user-guide/actions.md).

If the problem is not listed, look at [Reliability](reliability.md) for the exact retry and recovery rules and at
[FAQ](../08-faq.md).

<!-- footer-nav -->
---

← Previous: [Security](security.md) | [Contents](../README.md) | [Architecture overview](../05-architecture/overview.md) Next →
<!-- /footer-nav -->
