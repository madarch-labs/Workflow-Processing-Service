# FAQ

Short answers to frequent questions about WPS, with links to the detailed pages.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../ru/08-faq.md)
<!-- /lang-switch -->

## Processes and conditions

### My process did not start. What should I check?

Go through this list in order:

1. The process is **active**: the status tag on the card says "Active". A new process is saved inactive unless you click
   **Save and activate**.
2. It is a **top-level process**. Child processes are never matched to CRM events; only `start_process` starts them.
3. The **object type** equals the `module` (or `object_type`) of the event, including letter case: `Deals`, not `deals`.
4. The **conditions** are met. Run the process manually with the same object: the dialog shows the result of every
   condition. See [Manual run and reset](03-user-guide/manual-run-and-reset.md).
5. **Run once per record** is not blocking it. Check the "Run-once records" tab.
6. The event is valid. An event without `id` or without `module`/`object_type` is moved to the dead-letter stream
   `crm_events:dlq` and never reaches any process. See [Troubleshooting](04-operations/troubleshooting.md).

### Does a "modify" action change the record in the CRM?

Not by itself. `modify` changes the value in the process memory only. The CRM is updated when the row has **Save to CRM**
checked (`save_to_crm: true`), or by a separate `save_object` row. Only the changed fields are sent. See
[Actions](03-user-guide/actions.md).

### Can conditions use OR or brackets?

The table editor combines all rows with AND. The engine already accepts a nested form
`{ "logical_operator": "AND" | "OR", "conditions": [...], "groups": [...] }` through the API. The editor shows such
conditions as "advanced" and saves them unchanged. See [Triggers and conditions](03-user-guide/triggers-and-conditions.md).

### Can a condition use a field of a related record?

Yes. Declare the variable in **Preload** (for example `v1` = the account) and use `v1.status` in a condition. WPS first
checks the rows on `object.*`; only if they pass does it run the preload and check the remaining rows ("lazy preload").

### How do I get all tasks (deals, contacts) of a record, not just one?

Preload loads one record. For a list use the **Find records** action (`find_records`): by module with a filter
(`Tasks`, `parent_id equals object.id`, `status not_in [...]`) or by relationship (`related_to: {record: object, link:
contacts}`). The result is a list variable `{items, count, has_more, ids}`: templates and expressions read it
(`{{ tasks.count }}`), and `ids` can be passed to "Link records". The list is read-only — see
[Actions → Find records](03-user-guide/actions.md#find-records).

### Can a field be compared with a computed value, e.g. "more than 80 % of the limit"?

Yes: `"value_type": "expression"` — the right side is computed by an expression (`v1.credit_limit * 0.8`,
`coalesce(v1.limit, 0)`, `add_days(today(), 30)`) and cast with the same `cast` as the field. For `between` — a pair of
expressions. API only for now. See [Trigger conditions → Comparing with an expression](03-user-guide/triggers-and-conditions.md#comparing-with-an-expression).

### How do I write a condition "within the next 30 days" or "within the last day"?

With a relative date in a static value of a condition with `cast: date` / `datetime`: `today+30d`, `now-24h`,
`["start_of_quarter", "end_of_quarter"]` for `between`. Anchors are computed in `WPS_TIMEZONE`, there are no working
days. The same works in Find records and aggregate filters. See
[Trigger conditions → Relative dates](03-user-guide/triggers-and-conditions.md#relative-dates).

### How do I distribute leads or cases between employees?

With the **Assign an owner** action (`assign`): `round_robin`, `least_busy` — to the least busy, `random`, `manager` —
to the manager of the current owner, `user` — to a specific user. The pool is a list of users or a CRM security group;
inactive employees and those on leave are skipped. A repeated step after an error does not move the queue. Reset a
queue with `DEL assign:rr:<queue_key>` in redis-data. See
[Actions → Assign an owner](03-user-guide/actions.md#assign-an-owner).

### How do I send a notification to the CRM bell, Telegram or Slack?

With the **Notification** action (`notify`): one step, several channels — `crm_alert` (an `Alerts` record, visible in
the SuiteCRM 7 and 8 bell), `telegram` (a bot, a `telegram_bot` secret), `slack` and `teams` (a webhook, a
`webhook_url` secret). A repeated step does not duplicate what was delivered. Check a channel without a process —
`POST /api/notify/test`. E-mails to customers — with the Send an e-mail action. See
[Actions → Notification](03-user-guide/actions.md#notification).

### How do I compute the sum or the count of related records?

With the **Aggregate over records** change type (`aggregate`) in `modify` or `set_variable`: `count`, `sum`, `avg`,
`min`, `max` over module records by a filter or by relationship — without a search and a loop. If a selection is needed
but there is no module filter (in SuiteCRM deals have no `account_id` column), use Find records by relationship with a
filter and a loop accumulator. See [Aggregate over records](03-user-guide/change-types-and-expressions.md#aggregate-over-records).

### How do I change every found record?

If all records need the same fields (status, owner, date) — with the **Update list records** action
(`update_records`): one step, unchanged records are not sent, see
[Actions → Update list records](03-user-guide/actions.md#update-list-records). If every record needs different
actions — with the **Loop** block (`foreach`): `"source": "deals.items", "as": "deal"`, in the body — `modify deal.…` and
always `save_object` with `target: "deal"` (the item is a copy, without saving the changes do not reach the CRM; such
iterations show up in `unsaved_items`). Filter items with `where`, go on after an item error with
`on_item_error: continue`. See [Actions → Loop](03-user-guide/actions.md#loop-for-each-item).

### Several processes match the same event. In which order do they run?

In ascending `priority` (lower runs earlier). Each process gets its own fresh copy of the event, so one process does not see
the variables of another. A failure in one process does not stop the others.

### Does a child process wait for the parent, or the parent for the child?

The parent does not wait. `start_process` starts the child asynchronously and goes on with its next step. The child
receives the parent's event and all its variables at the moment of the start. See [Subprocesses](03-user-guide/subprocesses.md).

### Can a process start itself, or form a loop A → B → A?

No. Saving is rejected with "a process cannot start itself" or with `422 CYCLE_DETECTED` and the path of the loop. At run
time the nesting depth is also limited by `MAX_CHILD_DEPTH` (10 by default).

### What happens to running executions when I edit a process?

Every save increases `version`. Executions that have already started finish with the definition they started with: the
definition is stored in the execution state. The execution page shows the version that was used. New events use the
new version immediately, because the process cache is cleared on save.

## Reliability

### What if the CRM is down?

Temporary errors (timeout, connection error, HTTP 5xx) are retried `ACTION_RETRY_MAX` times (3) with exponential backoff:
1 s, 2 s, 4 s by default. While waiting, the execution status is "Retrying"; a delay longer than
`WAIT_INLINE_MAX_SECONDS` (5 s, e.g. `retry.max_delay: 10m`) parks the execution — status "Waiting", no worker is held,
the wait survives a restart. After the last attempt the step and the
execution are "Failed", and the remaining steps are "Skipped". A CRM 4xx answer is not retried. See
[Reliability](04-operations/reliability.md).

### Can an event be processed twice?

Stream processing is at-least-once, but every (event, process) pair has an idempotency key, so a re-delivered event does
not start the process again. To allow a process at most once per CRM **record** (not per event), enable **Run once per
record**.

### Is the execution history ever deleted?

WPS has no automatic cleanup of the `executions` and `execution_actions` tables: the audit is kept until you delete it in
the database. Only the Redis state of finished executions expires, after `EXECUTION_FINISHED_TTL_SECONDS` (3600 s).
Deleting a process is a soft delete, so its audit stays consistent.

## Administration

### Who can do what?

`admin`: everything, including deleting processes and managing users. `editor`: create, edit, clone and run processes
manually. `viewer`: read only. See [Users and roles](03-user-guide/users-and-roles.md).

### Why can't I delete a process?

Only `admin` can delete processes. A child process that another process starts with `start_process` cannot be deleted
(`409 PROCESS_IN_USE`). Remove the reference first.

### The object type list is empty or outdated.

The modules dictionary is cached in Redis for `DICT_CACHE_TTL` (300 s). Click **Refresh module list from CRM** next to the
field. If it stays empty, check `MOCK_SERVICE_URL` and `CRM_API_BASE_URL`, see [CRM integration](04-operations/crm-integration.md).

### How do I switch the interface language?

Use the language menu in the header or on the sign-in page, or add `?lang=en` / `?lang=ru` to the URL. The choice is
remembered in the browser. Messages that come from the server (for example validation details) are in English.

### How do I add a new action, operator or function?

They are PHP classes registered in `Wps\Kernel\Container`. Step-by-step guides with complete examples are in the
[Developer guide](07-developer-guide/extending/new-action-type.md).

<!-- footer-nav -->
---

← Previous: [Contributing](07-developer-guide/contributing.md) | [Contents](README.md)
<!-- /footer-nav -->
