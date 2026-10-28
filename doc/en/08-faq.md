# FAQ

Short answers to frequent questions about WPS, with links to the detailed pages.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../ru/08-faq.md)
<!-- /lang-switch -->

## Processes and conditions

### My process did not start. What should I check?

Go through the list:

1. The process is **active**: the card shows the "Active" tag. A new process is saved inactive unless you click
   **Save and activate**.
2. It is a **top-level process**. Child processes are never matched to CRM events; only `start_process` starts them.
3. The **object type** matches the event's `module` (or `object_type`), including case: `Deals`, not `deals`.
4. The **event type** is in the process `trigger_on`: a process with `["update"]` does not start on record creation,
   with `[]` — never starts from events. The field is visible in `GET /api/processes/{id}`.
5. The **conditions** hold. Run the process manually with the same object — the dialog shows the result of every
   condition. **Changed** conditions need `event_type`, `changed_fields` and `before` in the object.
   See [Manual run and reset](03-user-guide/manual-run-and-reset.md).
6. **Run once per record** is not in the way. Check the "Run-once records" tab.
7. The event is valid. An event without `id` or without `module`/`object_type` goes to the dead-letter stream
   `crm_events:dlq` and reaches no process. See [Troubleshooting](04-operations/troubleshooting.md).

An event that does not pass the conditions creates no execution and does not appear in the log — an empty log does not
mean the event never arrived.

### Which actions can be configured in the UI and which only through the API?

In the editor — AND conditions with one text value, preload, **Change** and **Save to CRM** rows, **Start next
process**. E-mails, HTTP requests, notifications, owner assignment, record search, bulk update, loops, branches,
pauses, `set_variable`, `stop_process`, `trigger_on`, constants, conditions with `cast`, lists and OR groups — through
the JSON definition (`POST` / `PUT /api/processes`). The table is in the
[Overview](01-overview/index.md#what-is-configured-in-the-editor-and-what-through-the-api), ready-made JSON — in the
[Recipes](03-user-guide/recipes.md).

### Can I open a process created through the API in the editor?

Yes, both open and save it. The form edits the definition on top of the original JSON: if you open a process and
click **Save** without changes, the definition stays the same (only `version` grows). On save:

- the `definition.constants` constants and other definition keys the form does not show are kept;
- condition, change, save, preload and start-process rows keep the fields the form does not show: `cast`,
  `cast_options`, `on_cast_error`, `id`, `on_error`, `retry`, `mode`, `strategy`; numbers and booleans do not turn into
  strings;
- **Start next process** rows stay in their places in the action list; new rows are added at the end;
- if you change the operator or the change type of a row, the settings of the old kind are dropped; only `cast`,
  `cast_options`, `on_cast_error` of conditions and `id`, `on_error`, `retry` of actions are kept.

What the form cannot show is shown **read-only** and saved unchanged:

- the conditions, if at least one of them has a value list (`in`, `between`), an expression (`value_type: expression`)
  or is a nested group: the whole conditions block is shown as JSON with the warning **Some conditions use value lists,
  ranges or nested groups**;
- change rows with the **Date shift**, **Append** / **Remove from list**, **Map** and **Aggregate** change types;
- a `start_process` step with a `process_ids` list;
- all actions that have no panel in the editor (grey rows with a JSON summary).

`trigger_on` is kept (the form does not send it, and the server keeps the previous value). To change what is shown
read-only, use `PUT /api/processes/{id}`; before editing you can make a copy with **Clone**.

### Why does the "Comparison" list have no "greater than", "between" and "in"?

The editor has no comparison type (`cast`) selector and only one value field, so the list shows only the operators the
form can save:

- with one value — **Equals**, **Not equals**, **Contains**, **Does not contain**, `starts_with`, `ends_with`,
  `changed_to`, `changed_from`;
- without a value — `is_empty`, `is_not_empty`, `is_null`, `is_not_null`, `changed`, `not_changed`; the value fields of
  such a row are replaced by dashes and `value` is not sent.

Comparisons with `cast` (`greater_than`, `less_than` …), lists (`in`, `contains_any` …) and ranges (`between`) are set
through the API — see [Triggers and conditions](03-user-guide/triggers-and-conditions.md#operators). If a process
already has such a condition, its operator stays in that row's list and the `cast` is kept. Change types are limited
the same way to those with an input: **Set to value**, **Set from variable**, **Calculate**, `template`, `increment` /
`decrement` (an empty step means 1), `clear`. Operators other than the four basic ones are shown with Russian labels in
the English UI.

### Does a "modify" action change the record in the CRM?

Not by itself. `modify` changes the value only in the process memory. The CRM is updated when the row has **Save to
CRM** checked (`save_to_crm: true`) or there is a separate `save_object` row. Only changed fields are sent.
See [Actions](03-user-guide/actions.md).

### Can conditions use OR or brackets?

The table editor combines all rows with AND. The API accepts the nested form
`{ "logical_operator": "AND" | "OR", "conditions": [...], "groups": [...] }`. The editor shows such conditions as
"advanced" and saves them unchanged. See [Triggers and conditions](03-user-guide/triggers-and-conditions.md). Inside
actions, the `condition` block with several branches provides "if / else".

### How do I run a process only on creation or on deletion of a record?

With the `trigger_on` field (through the API): `["create"]` — only on creation, `["update"]` — only on update,
`["delete"]` — on deletion, `[]` — only manually. The default is `["create", "update"]`. See
[Triggers and conditions → Event type](03-user-guide/triggers-and-conditions.md#event-type-and-the-run-on-field-trigger_on).

### How do I react only to a change of a field, not to every save?

With the **Changed**, **Changed to**, **Changed from**, **Not changed** operators (`changed`, `changed_to`,
`changed_from`, `not_changed`): `object.sales_stage` `changed_to` `Closed Won` fires once, on the transition, not on
every save of a won deal. A CRM plugin with contract 2.0 is required. The old value is `object_before.<field>`. See
[Conditions on a field change](03-user-guide/triggers-and-conditions.md#conditions-on-a-field-change).

### The process saves the record, and that starts the same process again. How do I avoid a loop?

Cascade protection is not implemented yet, so the condition of a process that saves its own `object` must become false
after the save. The most reliable way is the **Changed** operator on a field the process does not change (or changes to
a final state): after the save the CRM does not report that field as changed. Another option is **Run once per
record**. Examples — recipes [54](03-user-guide/recipes.md#54-normalise-a-phone-to-e164-regex_replace-right) and
[56](03-user-guide/recipes.md#56-contact-cleanup-name-and-e-mail-regular-expressions-strings).

### Can a condition use a field of a related record?

Yes. Declare a variable in **Preload** (for example `v1` — the account) and use `v1.status` in a condition. WPS checks
the `object.*` rows first, and only if they hold does it run the preload and check the remaining rows ("lazy preload").

### How do I get all tasks (deals, contacts) of a record, not just one?

Preload loads a single record. For a list, use the **Find records** action (`find_records`): by module with a filter
(`Tasks`, `parent_id equals object.id`, `status not_in [...]`) or by a relationship (`related_to: {record: object, link:
contacts}`). The result is a list variable `{items, count, has_more, ids}`: templates and expressions read it
(`{{ tasks.count }}`, `sum(pluck(tasks.items, 'amount'))`), and `ids` can be passed to "Link records". The list is
read-only — see [Actions → Find records](03-user-guide/actions.md#find-records).

### Can a field be compared with a computed value, e.g. "more than 80 % of the limit"?

Yes: `"value_type": "expression"` — the right-hand side is computed by an expression (`v1.credit_limit * 0.8`,
`coalesce(v1.limit, 0)`, `add_days(today(), 30)`, `config.min_amount`) and cast with the same `cast` as the field. For
`between` — a pair of expressions. API only. See
[Triggers and conditions → Comparing with an expression](03-user-guide/triggers-and-conditions.md#comparing-with-an-expression).

### How do I write a condition "within the next 30 days" or "within the last day"?

With a relative date in the static value of a condition with `cast: date` / `datetime`: `today+30d`, `now-24h`,
`["start_of_quarter", "end_of_quarter"]` for `between`. Anchors are computed in `WPS_TIMEZONE`, there are no business
days. The same works in "Find records" and aggregate filters. See
[Triggers and conditions → Relative dates](03-user-guide/triggers-and-conditions.md#relative-dates).

### How do I change a threshold or an address in all rows of a process at once?

Move the value into **process constants**: `"constants": { "vip_threshold": 100000, "manager_email": "…" }` in the
`definition`, and write `config.vip_threshold` in the rows. Constants are visible in conditions, expressions, templates
and filters, read-only; secrets must not be kept in them. They are set through the API; the editor does not show them
but keeps them on save. See [Processes → Process constants](03-user-guide/processes.md#process-constants) and recipe
[58](03-user-guide/recipes.md#58-deal-segment-and-discount-from-constants-config).

## Expressions and data

### How do I work with a multienum (`^a^,^b^`) and value lists?

In conditions — the `contains_any`, `contains_all`, `contains_none` operators with `cast: list`. In expressions — list
functions: `to_list(object.tags_c, ',', '^')` parses a multienum, `in('vip', …)` checks an item, `unique`, `sort`,
`without`, `count`, `first`, `pluck`, `where`, `sum` process the list, and `join(list, ',', '^')` builds it back into
the CRM format. A list written into a field directly is saved as a JSON array. See
[Lists](03-user-guide/change-types-and-expressions.md#lists) and recipe
[55](03-user-guide/recipes.md#55-lead-interests-order-and-vip-tag-list-functions).

### How do I clean up a string: a phone, a name, an e-mail?

With string functions in **Calculate**: `trim`, `lower`, `upper`, `title_case`, `replace`, `pad_left`, `translit`,
`slug` and regular expressions `regex_replace(object.phone, '[^0-9+]', '')`, `regex_match`, `regex_extract`. The pattern
is written without delimiters, Unicode is on; in JSON the backslash is doubled (`'\\d'`). See
[Regular expressions](03-user-guide/change-types-and-expressions.md#regular-expressions) and recipes
[54](03-user-guide/recipes.md#54-normalise-a-phone-to-e164-regex_replace-right),
[56](03-user-guide/recipes.md#56-contact-cleanup-name-and-e-mail-regular-expressions-strings).

### How do I get a value out of JSON — from a CRM field or from an external API response?

`json_get(source, '$.path', 'default')` and `json_get_all(source, '$.items[*].id')` accept both a JSON string (a CRM
field) and an already parsed `http_request` response (`erp.body`). The path is JSONPath with filters
(`$.items[?(@.qty > 3)]`). Simple response fields can be moved into `response.extract` of the `http_request` step or
read with the path `erp.body.data.id`. See [JSON](03-user-guide/change-types-and-expressions.md#json) and recipe
[57](03-user-guide/recipes.md#57-lead-to-the-erp-parsing-the-json-response-http_request-json_get).

### How do I check the type of a value: is it a number, a date?

With `is_number`, `is_numeric_text`, `is_int`, `is_date`, `is_bool`, `is_empty`, `is_list`, `is_object` — they always
return `true` / `false` and never fail the step. Conversions — `to_number`, `to_int`, `to_bool`, `to_date`,
`to_string`. See [Type checks and conversions](03-user-guide/change-types-and-expressions.md#type-checks-and-conversions).

### How do I put the execution id, the process name, the author of the change into an e-mail or a notification?

With the `sys.*` system variables: `{{ sys.execution_id }}`, `{{ sys.process_name }}`, `{{ sys.process_version }}`,
`{{ sys.trigger }}`, `{{ sys.user_id }}`, `{{ sys.event_type }}`, `{{= join(sys.changed_fields, ', ') }}`,
`{{ sys.now }}`. They are read-only and computed on access. The full list — `GET /api/dictionaries/system-variables`
and [System variables](03-user-guide/change-types-and-expressions.md#system-variables-and-constants); an example —
recipe [59](03-user-guide/recipes.md#59-a-big-deal-a-notification-with-execution-data-sys-config).

### How do I compute the sum or the count of related records?

With the **Aggregate over records** change type (`aggregate`) in `modify` or `set_variable`: `count`, `sum`, `avg`,
`min`, `max` over the records of a module with a filter or over a relationship — no search and no loop. If you need a
selection but there is no module filter (SuiteCRM deals have no `account_id` column), use "Find records" by a
relationship and `sum(pluck(…))` or an accumulator in a loop. See
[Aggregate over records](03-user-guide/change-types-and-expressions.md#aggregate-over-records).

### How do I change every found record?

If all records need the same fields (status, owner, date), use the **Update list records** action (`update_records`):
one step, unchanged records are not sent, see [Actions → Update list records](03-user-guide/actions.md#update-list-records).
If every record needs different actions, use the **Loop** block (`foreach`): `"source": "deals.items", "as": "deal"`, with
`modify deal.…` in the body and a mandatory `save_object` with `target: "deal"` (the item is a copy; without saving,
the changes never reach the CRM; such iterations show up in `unsaved_items`). Filter items with `where`, continue after
an item error with `on_item_error: continue`. See [Actions → Loop](03-user-guide/actions.md#loop-for-each-item).

## E-mails, notifications, external systems

### How do I send an e-mail and where do I see it on a test stack?

With the **Send an e-mail** action (`send_email`): recipients are addresses, templates or paths to fields
(`owner.email1`), the subject and the body are templates, optionally a letter history record in the CRM. The transport
is `MAILER_DSN` or an `smtp_dsn` secret. On the stack from `docker-compose.yml` Mailpit catches all letters:
`http://localhost:8025`. Check the mail settings without a process — `POST /api/mail/test` (admin). There are no
attachments or CRM e-mail templates yet. See [Actions → Send an e-mail](03-user-guide/actions.md#send-an-e-mail).

### How do I send a notification to the CRM bell, Telegram or Slack?

With the **Notification** action (`notify`): one step, several channels — `crm_alert` (an `Alerts` record shown in the
SuiteCRM 7 and 8 bell), `telegram` (a bot, `telegram_bot` secret), `slack` and `teams` (a webhook, `webhook_url`
secret). A repeated step does not duplicate what was already delivered. Check a channel without a process —
`POST /api/notify/test`. E-mails to clients — with "Send an e-mail". See
[Actions → Notification](03-user-guide/actions.md#notification).

### How do I call an external API or a webhook?

With the **HTTP request** action (`http_request`): method, URL (scheme and host as a literal), query, headers and body
are templates, authorisation only from secrets (`bearer`, `basic`, `api_key`, OAuth2 client credentials). The response
goes into a variable (`var`) and is parsed with JSONPath. The address is checked by the SSRF protection: internal
addresses are denied by default. See [Actions → HTTP request](03-user-guide/actions.md#http-request).

### Where do I keep passwords and tokens of external systems?

In WPS **secrets** (`/api/secrets`, created by an administrator): the value is encrypted with `WPS_SECRETS_KEY`, no
API response returns it, it is masked in the audit and the logs. A process refers to a secret as `secret://name` only in
credential fields (`transport`, `auth`, `connection`, the webhook `url`). There is no secrets screen in the UI. See
[Secrets](03-user-guide/secrets.md).

### Can an e-mail or a request be sent twice after a crash?

No, unless configured otherwise. Every external call is written to the side-effect journal: a step repeated after a
worker crash takes the response from the journal (`replayed: true`). If the worker crashed in the middle of sending and
the outcome is unknown, with `delivery: at_most_once` (the default for e-mails, `POST` and notifications) the step ends
with `UNKNOWN_OUTCOME` without a repeat; `at_least_once` repeats. The journal of an execution —
`GET /api/executions/{id}/side-effects`. See
[Reliability → Side effects](04-operations/reliability.md#side-effects-and-repeats).

### How do I distribute leads or cases between employees?

With the **Assign an owner** action (`assign`): `round_robin` — in turn, `least_busy` — to the least loaded, `random`,
`manager` — to the manager of the current owner, `user` — to a specific user. The pool is a list of users or a CRM
security group; inactive users and users on leave are skipped. A step repeated after an error does not advance the
queue. Reset the queue with `DEL assign:rr:<queue_key>` in redis-data. See
[Actions → Assign an owner](03-user-guide/actions.md#assign-an-owner).

## Execution, waiting, errors

### How do I do "in 2 days" or "30 days before the contract ends"?

With the **Delay** step (`delay`): `"mode": "duration", "duration": "2d"` or `"mode": "until", "until":
"object.end_date", "offset": "-P30D", "time": "09:00"`. A long pause parks the execution in PostgreSQL (status
**Waiting**), the worker is free, the wait survives restarts. Short pauses (up to `WAIT_INLINE_MAX_SECONDS`, 5 s) wait
in memory. See [Actions → Delay](03-user-guide/actions.md#delay).

### What happens to waiting executions when I change, deactivate or delete the process?

- **Change** — a waiting execution continues with its own version of the definition and its own constants.
- **Deactivate** — the wait is not stopped: the execution wakes up and finishes.
- **Delete** — without `?force=true` the server answers `409 PROCESS_HAS_WAITING_EXECUTIONS`; with `force=true` (the
  **Delete** button in the UI does not pass it — use the API) the waiting executions become **Cancelled**.

Find waiting ones: `GET /api/executions?status=waiting`. See
[Executions and audit → Waiting executions](03-user-guide/executions-and-audit.md#waiting-executions).

### How do I continue a process when one step fails?

With the step field `on_error`: `"continue"` — go to the next step, `{"goto": "<id>"}` — jump forward to the step with
that `id` (for example to a "create a manual check task" step), `"stop"` — the default. Retries of temporary errors —
the `retry` profile of the step. An execution with handled errors ends **Completed**, the number of errors is
`handled_errors`, the text of the last one is `sys.last_error`. See
[Actions → Step errors](03-user-guide/actions.md#step-errors-on_error-and-retries).

### How do I find all failed or waiting executions?

In the UI every process has its own log (the **Status** filter). Across all processes — through the API:
`GET /api/executions?status=failed&date_from=…` or `?status=waiting&due_before=…`. For alerts on growing errors — the
`wps_executions_failed_total` metric in Prometheus. See [Executions and audit](03-user-guide/executions-and-audit.md).

### Several processes match the same event. In which order do they run?

By ascending `priority`: a lower value runs first. Each process gets its own fresh copy of the event, so the variables of
one process are invisible to another. An error in one process does not stop the others.

### Does a child process wait for the parent, or the parent for the child?

The parent does not wait. `start_process` starts the child asynchronously and moves on to the next step. The child
receives the parent's event and all its variables at the moment of the start, its own constants and its own `sys.*`
(`sys.depth` is one higher, `sys.parent_execution_id` is the parent). See [Child processes](03-user-guide/subprocesses.md).

### Can a process start itself, or form a loop A → B → A?

No. The save is rejected with "a process cannot start itself" or with `422 CYCLE_DETECTED` and the cycle path. At run
time the nesting depth is also limited by `MAX_CHILD_DEPTH` (10 by default).

### What happens to running executions when I edit a process?

Each save increments `version`. Executions already in progress finish with the definition they started with: it is
stored in the execution state. The execution page shows the version used. New events use the new version right away:
saving clears the process cache.

## Reliability

### What if the CRM is down?

Temporary errors (timeout, connection error, HTTP 5xx) are retried `ACTION_RETRY_MAX` times (3) with exponential
backoff: 1 s, 2 s, 4 s by default; the `retry` profile of a step changes the attempts and delays. While waiting, the
execution status is "Retrying"; a delay longer than `WAIT_INLINE_MAX_SECONDS` (5 s, e.g. `retry.max_delay: 10m`) parks
the execution — status "Waiting", the worker is free, the wait survives restarts. After the last attempt the step and
the execution become "Failed", the remaining steps "Skipped". A CRM 4xx response is not retried.
See [Reliability](04-operations/reliability.md).

### Can an event be processed twice?

The stream is processed at least once, but every (event, process) pair has an idempotency key. So a redelivered event
does not start the process again. To run a process at most once per CRM **record** (rather than per event), enable
**Run once per record**.

### Is the execution history ever deleted?

WPS has no automatic cleanup of the `executions` and `execution_actions` tables: the audit stays until you delete it
in the database. Only the Redis state of finished executions expires — after `EXECUTION_FINISHED_TTL_SECONDS` (3600 s) —
and side-effect journal records older than `SIDE_EFFECTS_RETENTION_DAYS`. Deleting a process is a soft delete, so its
audit stays consistent.

### How do I check that the service is alive?

`GET /api/health` (no sign-in): `status: ok` or `degraded` with checks of PostgreSQL and both Redis instances and the
stream state (`lag`, `pending`, `consumers`). Prometheus metrics — `GET /metrics`: events, executions, e-mails,
notifications, HTTP, waits. See [Monitoring](04-operations/monitoring.md).

## Administration

### Who can do what?

`admin` — everything, including deleting processes, secrets and user management. `editor` — creating, editing,
cloning and manually running processes, resetting run-once marks, viewing the list of secrets. `viewer` — read-only.
See [Users and roles](03-user-guide/users-and-roles.md).

### Why can't I delete a process?

Only `admin` can delete processes. A child process that another process starts with `start_process` cannot be deleted
(`409 PROCESS_IN_USE`) — remove the reference first. A process with waiting executions is deleted only through the API
with `?force=true` (`409 PROCESS_HAS_WAITING_EXECUTIONS`).

### The object type list is empty or outdated.

The module dictionary is cached in Redis for `DICT_CACHE_TTL` (300 s). Click **Refresh module list from CRM** next to
the field. If the list is still empty, check `MOCK_SERVICE_URL` and `CRM_API_BASE_URL`, see
[CRM integration](04-operations/crm-integration.md).

### How do I switch the interface language?

Through the language menu in the header or on the sign-in page, or with `?lang=ru` / `?lang=en` in the address. The
choice is remembered in the browser. Messages coming from the server (validation error details) are in English, and
dictionary labels (operators, change types other than the basic ones) are in Russian in any UI language.

### How do I add a new action, operator or function?

They are PHP classes registered in `Wps\Kernel\Container`. Step-by-step instructions with complete examples are in the
[Developer guide](07-developer-guide/extending/new-action-type.md).

<!-- footer-nav -->
---

← Previous: [Contributing](07-developer-guide/contributing.md) | [Contents](README.md)
<!-- /footer-nav -->
