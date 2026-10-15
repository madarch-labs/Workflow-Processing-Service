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
1 s, 2 s, 4 s by default. While waiting, the execution status is "Retrying". After the last attempt the step and the
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
