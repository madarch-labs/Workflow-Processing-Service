# Child processes

How to split a scenario into a parent and child processes, what a child receives, how it is started and runs, and
which limits and errors apply.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/03-user-guide/subprocesses.md)
<!-- /lang-switch -->

## When to use a child process

A **child process** (`is_child: true`) is never started by a CRM event directly: the stream resolver only picks active
**top-level** processes. A child runs only when another process starts it with a **Start next process**
(`start_process`) action. Use child processes to:

- reuse one chain of actions from several parent processes;
- run independent branches in parallel (each child runs in its own coroutine);
- keep a long parent process readable;
- get a separate execution with its own log for every item of a list — `start_process` inside a
  [`foreach` loop](actions.md#loop-for-each-item).

When a child process **does not fit**:

| Task | Use instead |
|---|---|
| Get the result of the child and continue with it in the parent | Nothing: the parent does not wait for the child and does not receive its variables. Keep the steps in the parent |
| Choose one of several branches of actions | A [`condition`](actions.md#condition-if--else) block in the same process |
| Change the same fields of all found records | [`update_records`](actions.md#update-list-records) |
| Stop everything when the child fails | Not possible: an error of the child does not affect the parent |

## Create a child process

1. Open **Create process**.
2. In **Process type** select **Child process**.
3. Select **Object type (object.type)**. For a child it is informational: the child works with the object of the
   parent event, whatever its type.
4. Configure conditions, preload and actions as usual, then save.

Through the API this is `"is_child": true` in the body of `POST /api/processes` ([API reference](../06-api-reference/endpoints.md)).

In a child process you can reference variables that are not declared in it (for example `v1.name` loaded by the
parent): the validator allows unknown variables because the child inherits all variables of its parent. The flip side:
the validator does not catch a typo in such a name — at run time the variable is simply not found (an empty value in a
template, a false condition).

Which process settings apply to a child:

| Setting | For a child process |
|---|---|
| `is_active` | Applies: an inactive child is not started (the parent step still succeeds, see below) |
| `trigger_on` | **Ignored**: the parent starts the child for any event type |
| `run_once` | Applies: the mark is set on the record of the parent event (`object.id`); another start for the same record is skipped |
| `priority` | No effect: the order of children is the order of the `start_process` steps |
| `definition.constants` | Its own: the child reads `config.*` of **its own** definition, not the parent's |

The child process card shows **Started from** with links to every parent that starts it. While such references exist,
the process cannot become a top-level one (`is_child: false`): saving returns `422`,
`is_child: The process is started by other processes (Parent) and must stay a child process`.

![Child process card: "Started from" points to the parent, four actions build and save a task](../../images/screenshots/en/18-process-detail-child.png)

*Child process #4 "Create follow-up task": started from the lead process, creates a new `Tasks` record.*

## Start a child process from a parent

1. Open the parent process for editing.
2. In **Block 2. Actions** expand **Start next process**.
3. Click **Add process** and pick a child in **Select a child process**. Only processes of type **Child process** are
   listed; inactive ones are marked **inactive**. The refresh button reloads the list (**Refresh the list of child
   processes**), the **open** link opens the selected process.
4. Save the parent process.

![Start next process row pointing to child process #4](../../images/screenshots/en/08-process-edit-start-process.png)

*Parent process #6: two conditions joined by AND and one "Start next process" row.*

In JSON the action is stored as:

```json
{ "type": "start_process", "process_id": 4 }
```

or, to start several processes in one step:

```json
{ "type": "start_process", "process_ids": [4, 7] }
```

`4` and `7` here are the ids of **your** child processes (the number after `#` on the process card).

The editor puts **Start next process** rows at the end of the action list. Through the API a `start_process` step can
be placed anywhere: between other actions, in a branch of a [condition](actions.md#condition-if--else), in the body of a
[loop](actions.md#loop-for-each-item).

### Fields of the `start_process` action

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `type` | string | yes | — | `start_process` |
| `process_id` | integer (or a string of digits) | `process_id` or `process_ids` | — | Id of one child process |
| `process_ids` | list of integers | `process_id` or `process_ids` | — | Several children; started in list order, repeated ids are removed. When both fields are set, `process_ids` is used |
| `id`, `name` | string | no | — | Common step fields: a `goto` target and an audit label ([Step fields](actions.md#nested-blocks-and-step-fields)) |
| `on_error`, `retry` | | no | `stop`, the global profile | [Step errors](actions.md#step-errors-on_error-and-retries) |

`start_process` does not support `delivery` (`Action "start_process" does not support "delivery"`): a repeated start
is prevented by an idempotency key (see [How it runs](#how-it-runs)).

## What the child receives

| | Behaviour |
|---|---|
| Event | The same event as the parent (`event_id`, event type, changed fields). |
| `object` | Values **after** the changes the parent made before the `start_process` step — including ones not yet saved to the CRM. |
| `object_before` | The same old values as in the parent (read-only). |
| Variables | A **copy** of all parent variables at the moment of the step: preloads (`v1`), variables created by `modify` (`task`), local `set_variable` variables (`calc`), `find_records` lists, `http_request` responses, and inside a loop — the current item and the iteration data. Variable modules are copied too: `save_object` in the child knows the module of a parent variable. |
| Changes of variables | The child works with its own copy: its changes are not visible to the parent, and changes the parent makes after the start are not visible to the child. |
| Unsaved changes | Not inherited: the child starts with no changed fields, so `save_object` in the child sends only fields changed by the child itself. |
| `config.*` | Constants of the **child** process. |
| `sys.*` | Values of the child execution (table below). |
| Trigger | `child` (shown as **Child** in the log); `parent_execution_id` points to the parent execution. |
| Conditions | The child's own conditions are evaluated; if they are not met, the child does not run. |
| Preload | The child runs its own preload like any process. Preload names should not match variables coming from the parent, otherwise the parent's value is overwritten. |
| Run once | Applies to the record of the parent event (`object.id`). |

### System variables in a child process

| Variable | Value in the child |
|---|---|
| `sys.execution_id` | id of the child execution |
| `sys.parent_execution_id` | id of the parent execution (empty in top-level processes) |
| `sys.trigger` | `child` |
| `sys.depth` | nesting depth: `0` in the top-level parent, `1` in its child, `2` in the next one |
| `sys.process_id`, `sys.process_name`, `sys.process_version` | data of the **child** process |
| `sys.started_at`, `sys.now` | start time and current time of the child execution |
| `sys.event_id`, `sys.event_type`, `sys.changed_fields`, `sys.module`, `sys.record_id`, `sys.user_id` | from the parent event |
| `sys.last_error`, `sys.errors_count` | the parent's values at the moment of the start: if the parent handled an error (`on_error`) before the `start_process` step, the child sees it in `sys.last_error`, and its `sys.errors_count` (and the execution's `handled_errors`) starts from the parent's count |

The full `sys.*` table is in [System variables and constants](change-types-and-expressions.md#system-variables-and-constants).

## How it runs

1. **The list is checked.** The step first checks every id: the process exists and is a child. If any of them fails,
   the step fails and **none** of the listed processes is started.
2. **An inactive child is skipped.** The step still succeeds; its result shows
   `"status": "skipped", "reason": "Process is inactive"` for that process.
3. **Asynchronous start.** Each child is started in its own coroutine. The parent does not wait for it: the
   `start_process` step finishes right after the start (`started` means "handed over", not "done"), and the parent
   moves on to the next action.
4. **Conditions and run once** are checked in the child execution. When the conditions are not met, no execution is
   created, while the parent step result still says `started`.
5. **Independence.** An error, a pause or `stop_process` of the child does not affect the parent; an error or a stop
   of the parent after the start does not stop the child.
6. **Once per step.** The start key is "parent execution × step × process". If the parent step runs again after a
   crash recovery, the same child is not started twice. In a loop each iteration is a separate step, so
   `start_process` in a `foreach` body starts one child execution per item.
7. **A pause in the parent.** A `start_process` step after a [delay](actions.md#delay) starts the child after the
   parent wakes up; the child receives the variables as of that moment.

### Depth limit

A chain "parent → child → next child …" is limited by `MAX_CHILD_DEPTH` (10 by default, see
[Configuration](../04-operations/configuration.md)). The depth of a child execution is `sys.depth`. A `start_process`
step in an execution of depth `MAX_CHILD_DEPTH` fails with `Maximum child process depth (10) exceeded` (not retried).
The limit protects against chains the save-time cycle check does not catch — for example, a process changed bypassing
the validation.

### Step result and log

`request_data` of the step: `{"process_ids": [4, 7]}` (after removing repeats). `result_data`:

```json
{
  "children": [
    { "process_id": 18, "name": "Inactive child", "status": "skipped", "reason": "Process is inactive" },
    { "process_id": 16, "name": "Notify manager", "status": "started" }
  ]
}
```

The parent's execution page lists its children in the **Child executions** table (`children` in the response of
`GET /api/executions/{id}`). Click an id to open the child execution. A child execution has `trigger = child` and
`parent_execution_id` set to the parent id.

![Parent execution with a start_process step and the Child executions table](../../images/screenshots/en/13-execution-detail-parent-child.png)

*Execution of process #6: one `start_process` step and the completed child execution below.*

## Validation rules

| Situation | Result on save |
|---|---|
| Neither `process_id` nor `process_ids`, or an empty list | `422 VALIDATION_ERROR`: `Child process is required` (`…process_id` / `…process_ids`) |
| An id is not an integer | `422 VALIDATION_ERROR`: `Process id must be an integer` |
| The selected process is not a child | `422 VALIDATION_ERROR`: `Process 1 "…" is not a child process` |
| The process does not exist | `422 VALIDATION_ERROR`: `Process 42 not found` |
| A process starts itself | `422 VALIDATION_ERROR`: `A process cannot start itself` |
| A start loop, e.g. A → B → A | `422 CYCLE_DETECTED`, the UI shows **A process launch cycle was detected** and the chain |
| The step has `delivery` | `422 VALIDATION_ERROR`: `Action "start_process" does not support "delivery"` |
| A referenced child is turned into a top-level process | `422 VALIDATION_ERROR`: `is_child: The process is started by other processes (…) and must stay a child process` |
| Deleting a child that is still referenced | `409 PROCESS_IN_USE`: `The process is started by other processes`, `details` lists the parents `[{id, name}]`; the UI shows **The process is used by other processes (start_process) and cannot be deleted** |

Cycles are checked when an existing process is saved: starting from its new children the `start_process` links of all
processes are followed; reaching the saved process again is a cycle. Example of a cycle error:

```json
{
  "code": "CYCLE_DETECTED",
  "message": "Process launch cycle detected: Cycle A → Cycle B → Cycle A",
  "details": { "path": [ { "id": 16, "name": "Cycle A" }, { "id": 17, "name": "Cycle B" }, { "id": 16, "name": "Cycle A" } ] }
}
```

### Run-time errors

| Step `error_message` | Cause |
|---|---|
| `Maximum child process depth (10) exceeded` | The `MAX_CHILD_DEPTH` depth is exceeded |
| `Child process 42 not found` | Safety check: there is no process with this id (deleted bypassing the reference check) |
| `Process 42 "…" is not a child process` | Safety check: the process is no longer a child (changed bypassing the reference check) |

These errors are not retried; the step's `on_error` applies. In normal work through the API and the editor the last two
cannot happen: a referenced child can be neither deleted nor turned into a top-level process.

## Example: a task for the owner in a child process

The parent process for new leads computes a priority in the local variable `calc` and starts a child. The child creates
a task for the lead owner: it reads the parent's `object` and `calc`, and writes `sys.parent_execution_id` into the
task description to link it to the parent execution.

The child process (id `4`, for example):

```json
{
  "name": "Task for the lead owner",
  "object_type": "Leads",
  "is_child": true,
  "is_active": true,
  "definition": {
    "conditions": [ { "field": "object.assigned_user_id", "operator": "is_not_empty" } ],
    "actions": [
      { "type": "modify", "target": "task.name", "change_type": "template",
        "value": "Contact {{ object.first_name }} {{ object.last_name }}" },
      { "type": "modify", "target": "task.description", "change_type": "template",
        "value": "Source: {{ object.lead_source | default:'—' }}. Started by execution {{ sys.parent_execution_id }}" },
      { "type": "modify", "target": "task.priority", "change_type": "set_from_variable", "value": "calc.priority" },
      { "type": "modify", "target": "task.date_due", "change_type": "date_shift", "base": "today", "shift": "+1 day", "set_time": "18:00" },
      { "type": "modify", "target": "task.parent_type", "change_type": "set_value", "value": "Leads" },
      { "type": "modify", "target": "task.parent_id", "change_type": "set_from_variable", "value": "object.id" },
      { "type": "modify", "target": "task.assigned_user_id", "change_type": "set_from_variable", "value": "object.assigned_user_id" },
      { "type": "save_object", "target": "task", "module": "Tasks" }
    ]
  }
}
```

The parent process:

```json
{
  "name": "New lead: priority and task",
  "object_type": "Leads",
  "trigger_on": ["create"],
  "definition": {
    "actions": [
      { "type": "set_variable", "target": "calc.priority", "change_type": "calculate",
        "value": "if(object.lead_source == 'Web Site', 'High', 'Medium')" },
      { "id": "start_task", "type": "start_process", "process_id": 4 }
    ]
  }
}
```

A lead from `Web Site` with an owner gets the task "Contact Anna Petrova" with priority `High` due tomorrow at 18:00.
A lead without an owner: the parent's `start_process` step succeeds (`started`), but the child's conditions are not met
and no child execution is created.

### A child process for every item of a list

```json
{
  "object_type": "Accounts",
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.account_type", "operator": "changed_to", "value": "Customer" } ],
    "actions": [
      { "type": "find_records", "var": "contacts", "related_to": { "record": "object", "link": "contacts" },
        "fields": ["first_name", "last_name", "email1"] },
      { "type": "foreach", "source": "contacts.items", "as": "contact", "actions": [
        { "type": "start_process", "process_id": 7 }
      ] }
    ]
  }
}
```

Child process `7` runs separately for every contact and sees the current contact in `contact`
(`{{ contact.email1 }}`) and the iteration number in `contact_loop.number`. An error in one child execution does not
affect the others.

> **Note**
> A child can be run manually from its card like any other process (see [Manual run and reset](manual-run-and-reset.md)).
> In that case it has no parent: the trigger is `manual`, there are no inherited variables, `sys.parent_execution_id`
> is empty and `sys.depth` is `0`.

See also: [Recipes](recipes.md) (recipes 4 and 6),
[Actions](actions.md), [Execution engine](../05-architecture/execution-engine.md).

<!-- footer-nav -->
---

← Previous: [Change types and expressions](change-types-and-expressions.md) | [Contents](../README.md) | [Secrets](secrets.md) Next →
<!-- /footer-nav -->
