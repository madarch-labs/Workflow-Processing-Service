# Child processes

How to split a scenario into a parent and child processes, what a child receives and how it is started.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/03-user-guide/subprocesses.md)
<!-- /lang-switch -->

## When to use a child process

A **child process** (`is_child: true`) is never started by a CRM event directly: the stream resolver only picks active
**top-level** processes. A child runs only when another process starts it with a **Start next process**
(`start_process`) action. Use child processes to:

- reuse one chain of actions from several parent processes;
- run independent branches in parallel (each child runs in its own coroutine);
- keep a long parent process readable.

## Create a child process

1. Open **Create process**.
2. In **Process type** select **Child process**.
3. Select **Object type (object.type)**. For a child it is informational: the child works with the object of the
   parent event, whatever its type.
4. Configure conditions, preload and actions as usual, then save.

In a child process you can reference variables that are not declared in it (for example `v1.name` loaded by the
parent): the validator allows unknown variables because the child inherits all variables of its parent.

The child process card shows **Started from** with links to every parent that starts it.

![Child process card: "Started from" points to the parent, four actions build and save a task](../../images/screenshots/en/18-process-detail-child.png)

*Child process #4 "Create follow-up task": started from the lead process, creates a new `Tasks` record.*

## Start a child process from a parent

1. Open the parent process in edit mode.
2. In **Block 2. Actions** expand **Start next process**.
3. Click **Add process** and select the child in **Select a child process**. Only processes of type
   **Child process** are listed; inactive ones are marked **inactive**. The refresh button reloads the list
   (**Refresh the list of child processes**), **open** opens the selected child.
4. Save the parent.

![Start next process row pointing to child process #4](../../images/screenshots/en/08-process-edit-start-process.png)

*Parent process #6: two conditions joined by AND and one "Start next process" row.*

In JSON the action is stored as:

```json
{ "type": "start_process", "process_id": 4 }
```

or, for several children in one step:

```json
{ "type": "start_process", "process_ids": [4, 7] }
```

`4` and `7` are the ids of **your** child processes (the number after `#` in the process card).

## What the child receives

| | Behaviour |
|---|---|
| Event | The same event as the parent (`event_id`, `object`). The `object` variable contains the values **after** the parent's changes. |
| Variables | All parent variables at the moment of the `start_process` step (`v1`, `task`, …). |
| Unsaved changes | Not inherited: in the child nothing is "dirty", so `save_object` in the child sends only fields changed by the child. |
| Trigger | `child` (shown as **Child** in the log), `parent_execution_id` points to the parent execution. |
| Conditions | The child's own conditions are checked; if they are not met, the child does not start. |
| Run once | Applies to the record of the parent event (`object.id`). |

## How it runs

- **Asynchronously.** The parent does not wait for the child: the `start_process` step finishes as soon as the
  children are launched, and the parent continues with its next action. A child failure does not fail the parent.
- **Inactive child is skipped.** The step stays `success`; its result lists the child with
  `"status": "skipped", "reason": "Process is inactive"`.
- **Depth limit.** A chain parent → child → grandchild … is limited by `MAX_CHILD_DEPTH` (default 10). Exceeding it
  fails the step with `Maximum child process depth (10) exceeded`.
- **Once per step.** If the parent step is executed again after a crash recovery, the same child is not started twice.

Result of a `start_process` step (from the audit):

```json
{
  "children": [
    { "process_id": 18, "name": "Inactive child", "status": "skipped", "reason": "Process is inactive" },
    { "process_id": 16, "name": "Notify manager", "status": "started" }
  ]
}
```

The parent execution page lists the children in **Child executions**; click an id to open the child execution.

![Parent execution with a start_process step and the Child executions table](../../images/screenshots/en/13-execution-detail-parent-child.png)

*Execution of process #6: one `start_process` step and the completed child execution below.*

## Validation rules

| Situation | Result on save |
|---|---|
| The selected process is not a child | `422 VALIDATION_ERROR`: `Process 1 "…" is not a child process` |
| The process does not exist | `422 VALIDATION_ERROR`: `Process 42 not found` |
| A process starts itself | `422 VALIDATION_ERROR`: `A process cannot start itself` |
| A start loop, e.g. A → B → A | `422 CYCLE_DETECTED`, the UI shows **A process launch cycle was detected** and the chain |
| Deleting a child that is still referenced | `409 PROCESS_IN_USE`: **The process is used by other processes (start_process) and cannot be deleted** |

Example of a cycle error:

```json
{
  "code": "CYCLE_DETECTED",
  "message": "Process launch cycle detected: Cycle A → Cycle B → Cycle A",
  "details": { "path": [ { "id": 16, "name": "Cycle A" }, { "id": 17, "name": "Cycle B" }, { "id": 16, "name": "Cycle A" } ] }
}
```

> **Note**
> A child can be run manually from its card like any other process (see [Manual run and reset](manual-run-and-reset.md)).
> In that case it has no parent: the trigger is `manual` and there are no inherited variables.

See also: [Recipes](recipes.md) (recipes 4 and 6),
[Actions](actions.md), [Execution engine](../05-architecture/execution-engine.md).

<!-- footer-nav -->
---

← Previous: [Change types and expressions](change-types-and-expressions.md) | [Contents](../README.md) | [Secrets](secrets.md) Next →
<!-- /footer-nav -->
