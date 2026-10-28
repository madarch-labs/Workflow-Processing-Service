# Your first process

A step-by-step tutorial: build the process "Active deal → mark account as VIP" in the UI and test it with a mock event.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/02-getting-started/first-process.md)
<!-- /lang-switch -->

## What we build

When a deal (`Deals`) is saved with status `Active`, WPS loads the deal's account from the CRM and sets the account field
`rating` to `VIP`. Then it saves the account back to the CRM.

The tutorial uses four building blocks:

| Block | Setting |
|---|---|
| Condition | `object.status` **Equals** value `Active` |
| Preload | variable `v1` ← module `Accounts` by lookup field `id` = `object.account_id` |
| Modify | `v1.rating` **Set to value** `VIP`, **Save to CRM**: Yes |

You need a running stack with the mock CRM and a user with the `admin` or `editor` role. See [Quick start](quick-start.md).

## Step 1. Open the editor

1. Sign in to the UI.
2. Click **Create process** in the left menu, or the **Create process** button on the "Processes" page.

## Step 2. Fill in the process configuration

In the **Process configuration** block:

1. **Process name**: `Active deal → mark account as VIP`.
2. **Process type**: leave **Top-level process**. A top-level process is started by CRM events; a child process is only
   started by another process.
3. **Object type (object.type)**: choose the deal module, `Deals`. The list comes from the CRM. In the mock the module labels
   are in Russian (`Сделки`), and the technical name `Deals` is shown next to the label. The ↻ button next to the field
   (**Refresh module list from CRM**) reloads the list.
4. **Priority**: leave `0`. **Lower runs earlier** among processes of the same object type.
5. Leave **Run once per record** unchecked.

The **Variables** sidebar on the right now shows `object` (the source object of type `Deals`).

![Process editor](../../images/screenshots/en/04-process-edit-header.png)
*The process editor: "Process configuration", "Block 1. Trigger conditions", the start of "Block 2. Actions" and the "Variables" sidebar.*

## Step 3. Add the trigger condition

In **Block 1. Trigger conditions**:

1. Click **Add row**.
2. **Field (object.field / v1.field)**: `object.status`.
3. **Comparison**: **Equals**.
4. **Compare with**: **Value**.
5. **Value to compare**: `Active`.

All rows are combined with AND. With no rows the process runs for every event of its object type.

> **Note**
> The **Comparison** list comes from the server and contains all 25 operators. The editor has one value field and no
> comparison type (`cast`) selector, so only operators with one text value can be saved from the form: **Equals**,
> **Not equals**, **Contains**, **Does not contain**, and `starts_with`, `ends_with`, `changed_to`, `changed_from` (shown
> with Russian labels in the English UI). For `greater_than`, `between`, `in`, `is_empty`, `changed` and others the
> server answers with an error (for example `Operator "greater_than" requires cast: int, decimal, date or datetime`) —
> such conditions are set through the API, see [Triggers and conditions](../03-user-guide/triggers-and-conditions.md#operators).

## Step 4. Preload the account

In **Block 2. Actions**, expand **Preload** and click **Add row**:

| Column | Value |
|---|---|
| **Variable** | `v1` |
| **Source** | **From object field** |
| **Object field / variable field / value** | `object.account_id` |
| **Module (object type)** | `Accounts` |
| **Lookup field** | `id` |

Lookup field `id` loads the record by its identifier (`GET /Accounts/{id}`). Any other field searches by that field and
takes the first record. If nothing is found, `v1` is set to `null`.

The sidebar now shows `v1` with the label "loaded from CRM".

## Step 5. Change the account rating

Expand **Modify object / variable** and click **Change**:

| Column | Value |
|---|---|
| **Object / variable (target)** | `v1.rating` |
| **Change type** | **Set to value** |
| **New value** | `VIP` |
| **Save to CRM** | check **Yes** |

With **Save to CRM** checked, the variable `v1` is saved to the CRM right after the change (`PUT /Accounts/{id}` with the
changed fields only). Without it the change stays in the process memory. In that case add a separate save row with the
**Save to CRM** button.

## Step 6. Save

Click **Save and activate**. A new process is inactive by default; **Save** keeps it inactive, **Save and activate**
saves it and enables it at once.

If something is wrong, the editor shows a red banner "Fix the errors before saving" with the list of problems, and the
server repeats the checks (for example, "Variable "v9" is not defined at this point"). See
[Processes](../03-user-guide/processes.md).

After saving you get to the process card:

![Process card](../../images/screenshots/en/03-process-detail.png)
*The process card: properties, "Block 1. Start conditions", "Block 2. Preload" and "Block 3. Actions" with the "+ save to CRM" tag.*

## Step 7. Test it with an event

Send a deal with status `Active` to the stream:

```bash
curl -g "http://localhost:9502/test-event?module=Deals&set[status]=Active"
```

The mock generates a deal with a random `account_id` and creates that account in the mock CRM. Send a second event with
`set[status]=Closed` to see that it does not start the process.

On the process card, open the **Execution log** tab (click **Refresh** if needed):

![Execution log](../../images/screenshots/en/10-process-executions.png)
*"Execution log": one row per run, with trigger "CRM event", status, duration and retry count. Filters by status and period are above the table.*

Click the execution ID to open its page:

![Execution details](../../images/screenshots/en/11-execution-detail-success.png)
*A completed execution: step 0 `preload` loaded the account, step 1 `modify` changed `v1.rating` and saved it to the CRM.*

Expand a step row to see **Request (request_data)** and **Result (result_data)**. For the `modify` step the result holds
`old_value`, `new_value` and the save response. The **Execution context** block at the bottom shows the event, the
variables and how each condition was evaluated.

Check the account in the mock CRM. Take `account_id` from the event (the **Event (event)** tab):

```bash
curl -s http://localhost:9502/mock/objects/Accounts/<account_id>   # "rating":"VIP"
```

## Step 8. Try without the CRM

To check a process without waiting for events, use **Run** on the process card. Enter a JSON object, for example
`{"id": "1", "module": "Deals", "status": "Active", "account_id": "<an existing account id>"}`, and start it. See
[Manual run and reset](../03-user-guide/manual-run-and-reset.md).

## If something went wrong

| Symptom | What to check |
|---|---|
| **Save** does nothing, a red banner is shown at the top | Expand the error list: an empty field (`specify a module`), a path without `object.` for **From object field**, a variable declared below the place where it is used |
| **Server validation error** with an English text | The server checks more than the form: an operator without `cast`, an unknown module, an undeclared variable (`Variable "v9" is not defined at this point`) |
| The event was sent but the **Execution log** is empty | The process was saved without activation (the **Inactive** tag), the event has another object type or the status is not `Active`. Click **Refresh** above the log |
| There is an execution and the `preload` step returned `found: false` | The deal has no `account_id` or there is no account with this id in the CRM; the `modify` step writes into an empty variable and the save is **Skipped** |
| A **Failed** execution with `HTTP 500` or `connection failed` | The CRM is down; with the mock check `docker compose ps mock` |

## What next

- More scenarios: [Recipes](../03-user-guide/recipes.md). This process is recipe 1 there, with its JSON.
- All condition options: [Triggers and conditions](../03-user-guide/triggers-and-conditions.md).
- All actions: [Actions](../03-user-guide/actions.md) and [Change types and expressions](../03-user-guide/change-types-and-expressions.md).
- E-mails, HTTP requests, loops, pauses and other actions are configured as a JSON definition through the API — see the
  [Overview](../01-overview/index.md#what-is-configured-in-the-editor-and-what-through-the-api).

<!-- footer-nav -->
---

← Previous: [Quick start](quick-start.md) | [Contents](../README.md) | [Sign-in and the interface](../03-user-guide/login-and-ui.md) Next →
<!-- /footer-nav -->
