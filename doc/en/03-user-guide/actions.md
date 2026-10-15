# Actions

What a process does once its conditions are met: preload related objects, change fields, save to the CRM and start child processes.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/03-user-guide/actions.md)
<!-- /lang-switch -->

## Execution order

**Block 2. Actions** (run sequentially) has three sections in the editor:

| Section | Action type (`type`) | Purpose |
|---|---|---|
| **Preload** | `preload` (the `definition.preload` block) | Load related CRM objects into variables |
| **Modify object / variable** | `modify`, `save_object` | Change fields; save a variable to the CRM |
| **Start next process** | `start_process` | Start child processes asynchronously |

At run time every row becomes a **step**, and the steps run strictly one after another:

1. all **Preload** rows, top to bottom;
2. the **Modify object / variable** rows in their order (change the order with the arrow buttons);
3. the **Start next process** rows — the editor always places them at the end.

If a step fails, the remaining steps are not run (they are recorded as **Skipped**) and the execution ends as **Failed**. Temporary errors (CRM timeout, CRM 5xx, network) are retried first — see [Executions and audit](executions-and-audit.md).

The data a process works with lives in **variables**:

- `object` — the object from the event (the record that was saved in the CRM);
- variables loaded by **Preload**, e.g. `v1`;
- variables created by **Modify** when it writes to a new name, e.g. `task.name`.

Fields are addressed with a dot: `object.phone`, `v1.contact_id`. The **Variables** panel on the right of the editor shows what is available at the current point.

## Preload

Loads one object from the CRM into a variable. Typical use: the event is a deal, and you need its account.

![Preload row](../../images/screenshots/en/06-process-edit-preload.png)

*Preload `v1`: an `Accounts` object whose `id` is taken from `object.account_id`.*

| Column | Purpose | Values | Example |
|---|---|---|---|
| **Variable** | Name of the variable to fill | Latin letters, digits, `_`, not starting with a digit; not `object`; unique in the process | `v1`, `account` |
| **Source** | Where the lookup value comes from | **From object field** (`object_field`), **From variable field** (`variable_field`), **Manual input** (`manual`) | **From object field** |
| **Object field / variable field / value** | The lookup value: a path for the first two sources, a literal for **Manual input** | `object.account_id`; `v1.parent_id`; `123` | `object.account_id` |
| **Module (object type)** | CRM module to load from | Latin letters, digits, `_`; pick from the list or type | `Accounts` |
| **Lookup field** | Which field to search by | `id` (default) or any field name | `id`, `inn` |

How the lookup works:

- **Lookup field** `id` → the object is read by identifier (`GET /{module}/{id}` in the CRM API).
- Any other field → search by that field (`GET /{module}?filter[<field>]=<value>`); if several records match, **the first one is used** (**Lookup field “id” loads by identifier; any other field searches by that field (the first record is used).**).
- **From variable field** may refer only to variables declared in the rows above.
- **From object field** accepts `account_id` as a shortcut for `object.account_id`.

**When nothing is found.** If the lookup value is empty or the CRM has no such object, the variable is set to empty (`null`) and the step still succeeds (in the audit: `"found": false`). After that:

- conditions on this variable are false;
- **Save to CRM** of this variable is **Skipped** (`Variable "v1" is empty (object was not loaded) — nothing to save`);
- reading its fields gives an empty value.

> **Warning**
> A **Modify** that writes to a field of an empty variable turns it into a new object containing only that field and **without** an `id`. If this variable is then saved to the CRM, a **new** record is created (POST) in the preload module. Guard such processes with a condition on the variable, e.g. `v1.id` **Not equals** *(empty)*, which is false when `v1` was not found.

Errors when saving the process:

| Message | Cause |
|---|---|
| `Preload 1 (v1): invalid variable name` / `Variable name is required (letters, digits, "_"; e.g. v1)` | Bad name |
| `the name “object” is reserved` / `Name "object" is reserved for the event object` | Name `object` |
| `variable name is already in use` / `Variable "v1" is already defined` | Duplicate name |
| `specify a module` / `CRM module is required` | Module is empty or invalid |
| `specify a value` / `Value is required` / `Field path is required` | No lookup value |
| `path must start with object.` / `For "from object field" the path must start with "object."` | Wrong path for **From object field** |
| `variable “v2” must be declared above` / `Variable "v2" is not defined at this point` | **From variable field** refers to a later or unknown variable |
| `For "from variable field" the path must reference a variable (e.g. v1.contact_id)` | **From variable field** with an `object.` path |

### Preload inside actions (API only)

A `preload` step can also be placed in `definition.actions` with the same fields, e.g. to load an object after a modification. The editor shows such rows as non-editable (**This action can't be edited in the table editor and will be saved unchanged**). Through the API a preload row may also set `"strategy": "by_id"` or `"by_field"` explicitly; by default it is derived from **Lookup field**.

```json
{ "type": "preload", "var": "v2", "source": "variable_field", "module": "Contacts", "by_field": "id", "value": "v1.contact_id" }
```

## Modify object / variable

Changes one field of the event object or of a variable **in the process memory**. The CRM is not touched unless **Save to CRM** is checked in this row or a separate **Save to CRM** row follows.

![Modify rows](../../images/screenshots/en/07-process-edit-modify.png)

*Two **Calculate** rows and a **Save to CRM** row for `object`.*

| Column | Purpose | Values | Example |
|---|---|---|---|
| **Object / variable (target)** | Field to write | `object.<field>` or `<variable>.<field>`; a new variable name creates a new object | `object.phone`, `v1.rating`, `task.name` |
| **Change type** | How the new value is computed | **Set to value**, **Set from variable**, **Calculate** | **Set to value** |
| **New value** | Value, source path or expression — depends on the change type | see [Change types and expressions](change-types-and-expressions.md) | `VIP` |
| **Save to CRM** | Save the variable to the CRM right after this change (same as a **Save to CRM** row) | **Yes** / not checked | checked |

Add a row with **Change**. The audit stores `old_value` and `new_value` of every change.

Writing to a variable that does not exist yet (e.g. `task.name`) creates it; the **Variables** panel marks it **created in process**. Such a variable has no module — give one in the **Save to CRM** row.

Errors: `Action 1: specify a target field (object.field or v1.field)`, `Action 1: specify a value` (for **Set from variable** and **Calculate**); server: `"object" must point to a field, e.g. object.name`, `Change type must be one of: set_value, set_from_variable, calculate`, `Must be true or false` (`save_to_crm`).

## Save to CRM

The **Save to CRM** button in **Modify object / variable** adds a row of type `save_object`. It sends the changed fields of a variable to the CRM.

| Column | Purpose | Values | Example |
|---|---|---|---|
| **Object / variable (target)** (placeholder **Variable**) | What to save: `object` or any variable | variable name | `object`, `v1`, `task` |
| **Module (for new variables, optional)** | CRM module; needed only for variables created in the process | module name | `Tasks` |

What happens:

| Situation | Result |
|---|---|
| Variable has an `id` and changed fields | `PUT /{module}/{id}` with **only the changed fields**; step **Success** |
| Variable has an `id` but nothing changed | Step **Skipped**: `No changed fields to save` |
| Variable has no `id` (created in the process, or a not-found preload modified later) | `POST /{module}` with all fields — a new record; the new `id` is written back into the variable |
| Variable is empty (preload found nothing) | Step **Skipped**: `Variable "v1" is empty (object was not loaded) — nothing to save` |
| Module is unknown | Step **Failed**: `Cannot save "task": CRM module is unknown (set "module" in the save action)` |

The module is taken from the row, otherwise from the variable: `object` → the event module, a preload variable → its preload module.

After a successful save the variable has no unsaved changes, so a second save without new changes is skipped.

Through the API you can send all fields instead of only changed ones: `"mode": "full"` (default `"changed"`).

```json
{ "type": "save_object", "target": "task", "module": "Tasks" }
```

CRM answers: 5xx, timeouts and network errors are retried; 4xx (validation in the CRM, missing record on PUT) fail the step immediately.

Errors: `Action 2: specify the variable to save`; server: `Invalid CRM module`, `Mode must be "changed" or "full"`, `Variable "x" is not defined at this point`.

## Start next process

Starts one or more **child** processes asynchronously; the current process does not wait for them. The child receives the same event and all variables. Details, limits and examples are in [Child processes](subprocesses.md).

```json
{ "type": "start_process", "process_id": 4 }
```

## Example: the whole chain

Process **Active deal → mark account as VIP** (`Deals`):

```json
{
  "conditions": [{ "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }],
  "preload": [{ "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" }],
  "actions": [{ "type": "modify", "target": "v1.rating", "change_type": "set_value", "value": "VIP", "save_to_crm": true }]
}
```

Steps of an execution: `0 preload` (loads the account) → `1 modify` (sets `v1.rating = "VIP"` and saves the account with `PUT /Accounts/{id}`, only the `rating` field). More scenarios: [Recipes](recipes.md).

<!-- footer-nav -->
---

← Previous: [Trigger conditions](triggers-and-conditions.md) | [Contents](../README.md) | [Change types and expressions](change-types-and-expressions.md) Next →
<!-- /footer-nav -->
