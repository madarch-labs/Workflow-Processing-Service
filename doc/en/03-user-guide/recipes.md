# Recipes

Eight ready-made end-to-end scenarios: what each process does, how to set it up, its JSON and how to test it with the mock CRM.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/03-user-guide/recipes.md)
<!-- /lang-switch -->

All recipes were checked on the bundled mock CRM (see [Quick start](../02-getting-started/quick-start.md)): the mock
publishes test events into the stream and emulates the CRM API, so you can try everything without a real CRM.

## How to use a recipe

- **Set it up in the UI**: **Create process**, fill in the fields as described, then **Save and activate**.
- **Or create it through the API** with the JSON from the recipe:

  ```bash
  TOKEN=$(curl -s -X POST http://localhost:9501/api/auth/login -H 'Content-Type: application/json' \
    -d '{"email":"admin@wps.local","password":"admin"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["access_token"])')

  curl -s -X POST http://localhost:9501/api/processes -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' -d @recipe.json
  ```

  The JSON files of recipes 1–6 are in the repository: `doc/_meta/demo_processes/`.
- **Send a test event** to the mock. Use `curl -g`, otherwise curl treats the brackets in `set[status]` as a pattern:

  ```bash
  curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=Active"
  ```

  The mock generates an object of the module, stores it in the mock CRM, applies the `set[...]` overrides and publishes
  the event. The response contains the generated `event` (note its `id` and `account_id`).
- **Check the result** on the process card → **Execution log**, then open the execution
  (see [Executions and audit](executions-and-audit.md)). Records of the mock CRM can be read with
  `curl -s http://localhost:9502/mock/objects/<Module>/<id>`.

> **Note**
> A process reacts to events within a second, but the list of processes per object type is cached for up to
> `PROCESS_CACHE_TTL` (60 s). Saving a process clears the cache, so a new process is picked up at once.

| # | Recipe | Object type | Shows |
|---|---|---|---|
| 1 | [Active deal → mark the account as VIP](#1-active-deal--mark-the-account-as-vip) | Deals | condition, preload by id, **Set to value** + **Save to CRM** |
| 2 | [Invoice discount 10 %](#2-invoice-discount-10-) | Invoices | **Calculate**, functions `round`, `concat`, saving the event object |
| 3 | [Contact: copy the account name](#3-contact-copy-the-account-name) | Contacts | condition on a preloaded variable, **Set from variable** |
| 4 | [Child: create a follow-up task](#4-child-create-a-follow-up-task) | Leads (child) | a new variable saved as a new CRM record |
| 5 | [Payment sync: CRM failure](#5-payment-sync-crm-failure) | Payments | retries and a failed execution |
| 6 | [New lead → schedule a call, once per lead](#6-new-lead--schedule-a-call-once-per-lead) | Leads | **Contains**, **Start next process**, **Run once per record** |
| 7 | [Order: find the customer account by INN](#7-order-find-the-customer-account-by-inn) | Orders | preload by a non-id field |
| 8 | [Ticket: note on the account's contact](#8-ticket-note-on-the-accounts-contact) | Tickets | preload chain **From variable field**, guard condition |

---

## 1. Active deal → mark the account as VIP

**Goal.** When a deal is saved with status `Active`, set the field `rating` of its account to `VIP` in the CRM.

**Setup in the UI**

1. **Process name**: `Active deal → mark account as VIP`; **Process type**: **Top-level process**;
   **Object type (object.type)**: `Deals`.
2. **Block 1. Trigger conditions** → **Add row**: `object.status` · **Equals** · **Value** · `Active`.
3. **Preload** → **Add row**: variable `v1`, source **From object field**, `object.account_id`, module `Accounts`,
   lookup field `id`.
4. **Modify object / variable** → **Change**: target `v1.rating`, **Set to value**, `VIP`, tick **Save to CRM**.

![Edit form of recipe 1](../../images/screenshots/en/04-process-edit-header.png)

*The edit form: configuration, one condition, the preload of `v1` and the variables sidebar.*

**JSON**

```json
{
  "name": "Active deal → mark account as VIP",
  "object_type": "Deals", "is_child": false, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }
    ],
    "preload": [
      { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" }
    ],
    "actions": [
      { "type": "modify", "target": "v1.rating", "change_type": "set_value", "value": "VIP", "save_to_crm": true }
    ]
  }
}
```

**Test**

```bash
curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=Active"   # starts the process
curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=Closed"   # does not start it
curl -s  "http://localhost:9502/mock/objects/Accounts/<account_id from the event>"   # "rating": "VIP"
```

**What you see.** One execution per `Active` deal with status **Completed** and two steps: `preload` (`found: true`,
the account in `result_data.object`) and `modify` (`old_value: ""`, `new_value: "VIP"`, and a `save` block with
`"method": "PUT"`, `"attributes": {"rating": "VIP"}`). The `Closed` deal leaves no execution: its conditions are not met.

![Completed execution of recipe 1](../../images/screenshots/en/11-execution-detail-success.png)

*Execution of recipe 1: preload of the account, then the change saved to the CRM.*

---

## 2. Invoice discount 10 %

**Goal.** For an `Active` invoice reduce `amount` by 10 %, write a note to `description` and save both fields.

**Setup in the UI**

1. **Object type**: `Invoices`; **Priority**: `10` (when several processes react to one object type, a lower value runs earlier).
2. Condition: `object.status` · **Equals** · **Value** · `Active`.
3. **Modify object / variable**:
   - **Change**: `object.amount`, **Calculate**, `round(object.amount * 0.9, 2)`;
   - **Change**: `object.description`, **Calculate**, `concat('Discount applied, new amount: ', object.amount)`;
   - **Save to CRM** (the button under the table): variable `object`.

![Two Calculate rows and a Save to CRM row](../../images/screenshots/en/07-process-edit-modify.png)

*Recipe 2: the second expression already uses the new `object.amount` computed by the first one.*

**JSON**

```json
{
  "name": "Invoice discount 10%",
  "object_type": "Invoices", "is_child": false, "is_active": true, "priority": 10, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.amount", "change_type": "calculate", "value": "round(object.amount * 0.9, 2)", "save_to_crm": false },
      { "type": "modify", "target": "object.description", "change_type": "calculate", "value": "concat('Discount applied, new amount: ', object.amount)", "save_to_crm": false },
      { "type": "save_object", "target": "object" }
    ]
  }
}
```

**Test**

```bash
curl -sg "http://localhost:9502/test-event?module=Invoices&set[status]=Active&set[amount]=19500"
```

**What you see.** Three successful steps. `modify` #0: `old_value "19500"` → `new_value 17550`; `modify` #1:
`"Discount applied, new amount: 17550"`; `save_object`: `"method": "PUT"` with only the two changed fields in
`attributes`. Actions run strictly in order, and unsaved changes are collected and sent in one request.

---

## 3. Contact: copy the account name

**Goal.** When a contact that is not `Closed` is saved and its account is `Active`, copy the account name into the
contact's `description`.

**Setup in the UI**

1. **Object type**: `Contacts`.
2. Conditions:
   - `object.status` · **Not equals** · **Value** · `Closed`;
   - `v1.status` · **Equals** · **Value** · `Active`.
3. **Preload**: `v1`, **From object field**, `object.account_id`, `Accounts`, `id`.
4. **Change**: `object.description`, **Set from variable**, `v1.name`, **Save to CRM**.

![Conditions on the object and on the variable v1](../../images/screenshots/en/05-process-edit-conditions.png)

*The second condition uses `v1`: the variables sidebar shows that `v1` is loaded from the CRM.*

**How it works.** A condition on a variable triggers a **lazy preload**: WPS first checks the rows on `object.*`; only
if they pass, it runs the preload block and then checks the rest. A contact with status `Closed` therefore costs no
CRM request.

**JSON**

```json
{
  "name": "Contact: copy account name",
  "object_type": "Contacts", "is_child": false, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "not_equals", "value_type": "static", "value": "Closed" },
      { "field": "v1.status", "operator": "equals", "value_type": "static", "value": "Active" }
    ],
    "preload": [
      { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" }
    ],
    "actions": [
      { "type": "modify", "target": "object.description", "change_type": "set_from_variable", "value": "v1.name", "save_to_crm": true }
    ]
  }
}
```

**Test**

```bash
curl -sg "http://localhost:9502/test-event?module=Contacts&set[status]=New"
```

**What you see.** Steps `preload` (the account) and `modify` with `new_value` = the account name and
`save.attributes = {"description": "<account name>"}`. The **Conditions** tab of the context shows both rows with
`result: true`.

---

## 4. Child: create a follow-up task

**Goal.** A reusable child process that creates a `Tasks` record "Call back: <lead name>" linked to the lead. It is
started by recipe 6.

**Setup in the UI**

1. **Process type**: **Child process**; **Object type**: `Leads`; no conditions.
2. **Modify object / variable**:
   - `task.name`, **Calculate**, `concat('Call back: ', object.name)`;
   - `task.status`, **Set to value**, `Not Started`;
   - `task.parent_id`, **Calculate**, `object.id`;
   - **Save to CRM**: variable `task`, module `Tasks`.

`task` is not preloaded: the first change creates it as a new object. A variable without `id` is saved with `POST`,
i.e. as a new CRM record, so its module must be given in the save row.

**JSON**

```json
{
  "name": "Create follow-up task",
  "object_type": "Leads", "is_child": true, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "task.name", "change_type": "calculate", "value": "concat('Call back: ', object.name)", "save_to_crm": false },
      { "type": "modify", "target": "task.status", "change_type": "set_value", "value": "Not Started", "save_to_crm": false },
      { "type": "modify", "target": "task.parent_id", "change_type": "calculate", "value": "object.id", "save_to_crm": false },
      { "type": "save_object", "target": "task", "module": "Tasks" }
    ]
  }
}
```

**Test.** A child process does not react to events; test it through recipe 6, or run it manually from its card with
`{"id": "1", "module": "Leads", "name": "Test lead"}`.

**What you see.** Four steps; `save_object` has `"method": "POST"`, `"module": "Tasks"` and `created_id` — the id of
the new task. The execution trigger is **Child** and **Parent execution** links to the execution of recipe 6.

![Child process card](../../images/screenshots/en/18-process-detail-child.png)

*Recipe 4: **Started from** shows the parent process.*

---

## 5. Payment sync: CRM failure

**Goal.** Show what happens when the CRM fails. The preload requests the account `fail500-demo`; the mock answers any
id starting with `fail500` with HTTP 500.

**JSON**

```json
{
  "name": "Payment sync (CRM failure demo)",
  "object_type": "Payments", "is_child": false, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "type": "preload", "var": "v1", "source": "manual", "module": "Accounts", "by_field": "id", "value": "fail500-demo" },
      { "type": "modify", "target": "object.status", "change_type": "set_value", "value": "Synced", "save_to_crm": true }
    ]
  }
}
```

A preload row can also be an action (`"type": "preload"` in `actions`); **Manual input** uses the typed value
instead of a field.

**Test**

```bash
curl -sg "http://localhost:9502/test-event?module=Payments"
```

**What you see** (after about 7 seconds: retry delays of 1 s, 2 s and 4 s). Status **Failed**, **Retry count** 3,
the banner `CRM GET http://mock:9502/api/v8/Accounts/fail500-demo returned HTTP 500`; the `preload` step has
**Attempts** 4 and `recoverable: true`; the `modify` step is **Skipped**. While retrying, the execution shows the
status **Retrying**.

![Failed execution of recipe 5](../../images/screenshots/en/12-execution-detail-failed.png)

*Recipe 5: three retries of a temporary error, then the execution fails and stops.*

---

## 6. New lead → schedule a call, once per lead

**Goal.** For a lead with status `New` and an e-mail, start the child process of recipe 4 — but only once per lead,
however many times the lead is saved.

**Setup in the UI**

1. **Object type**: `Leads`; tick **Run once per record**.
2. Conditions: `object.status` · **Equals** · `New`; `object.email` · **Contains** · `@`.
3. **Start next process** → **Add process** → the child process of recipe 4.

![Recipe 6: conditions and the Start next process row](../../images/screenshots/en/08-process-edit-start-process.png)

*Recipe 6: two conditions joined by AND and the child process #4.*

**JSON** — `4` is the id of **your** child process from recipe 4:

```json
{
  "name": "New lead → schedule a call (once per lead)",
  "object_type": "Leads", "is_child": false, "is_active": true, "priority": 0, "run_once": true,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "New" },
      { "field": "object.email", "operator": "contains", "value_type": "static", "value": "@" }
    ],
    "preload": [],
    "actions": [
      { "type": "start_process", "process_id": 4 }
    ]
  }
}
```

**Test**

```bash
curl -sg "http://localhost:9502/test-event?module=Leads&set[status]=New"        # starts the parent and the child
curl -sg "http://localhost:9502/test-event?module=Leads&set[status]=Inactive"   # conditions not met
```

Then repeat for the same lead with a manual run (**Run**) and the lead `id` from the first event:
`{"id": "<lead id>", "module": "Leads", "status": "New", "email": "lead@example.com"}`.

**What you see.** The parent execution has one `start_process` step with
`children: [{"process_id": 4, "status": "started"}]` and the table **Child executions**. The manual repeat answers
**The process has already run for this record — no new run was created**; the tab **Run-once records** shows the
lead with **Blocked** = 1. To allow a new run, click **Reset** (see [Manual run and reset](manual-run-and-reset.md)).

![Parent execution with the child](../../images/screenshots/en/13-execution-detail-parent-child.png)

*Recipe 6: the parent finished at once; the child ran in parallel and created the task.*

---

## 7. Order: find the customer account by INN

**Goal.** An order carries the customer's tax number `customer_inn`, not the account id. Find the account by its `inn`
field, link the order to it and write the customer name and phone into the order.

**Setup in the UI**

1. **Object type**: `Orders`.
2. Condition: `object.customer_inn` · **Not equals** · **Value** · empty (leave the value empty).
3. **Preload**: variable `acc`, **From object field**, `object.customer_inn`, module `Accounts`, lookup field `inn`.
   Any lookup field other than `id` searches by that field; if several records match, the first one is used.
4. Changes:
   - `object.account_id`, **Set from variable**, `acc.id`;
   - `object.description`, **Calculate**, `concat('Customer: ', acc.name, ', phone ', acc.phone)`;
   - **Save to CRM**: `object`.

**JSON**

```json
{
  "name": "Order: find customer account by INN",
  "object_type": "Orders", "is_child": false, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.customer_inn", "operator": "not_equals", "value_type": "static", "value": "" }
    ],
    "preload": [
      { "var": "acc", "source": "object_field", "module": "Accounts", "by_field": "inn", "value": "object.customer_inn" }
    ],
    "actions": [
      { "type": "modify", "target": "object.account_id", "change_type": "set_from_variable", "value": "acc.id", "save_to_crm": false },
      { "type": "modify", "target": "object.description", "change_type": "calculate", "value": "concat('Customer: ', acc.name, ', phone ', acc.phone)", "save_to_crm": false },
      { "type": "save_object", "target": "object" }
    ]
  }
}
```

**Test.** Mock accounts have an `inn`. Take an account id from any generated event, read its `inn` and send an
order with it:

```bash
curl -s  "http://localhost:9502/mock/objects/Accounts/<account_id>"            # → "inn": "7726436001"
curl -sg "http://localhost:9502/test-event?module=Orders&set[customer_inn]=7726436001"
```

**What you see.** The `preload` step has `"strategy": "by_field"`, `"by_field": "inn"`, `lookup_value` = the INN and
the found account in `result_data.object`. After `save_object` the order in the mock CRM has the `account_id` of that
account and `description` like `Customer: ПАО "Восток", phone +74954813492`. An order without `customer_inn` does not
start the process: a missing field makes a condition false.

---

## 8. Ticket: note on the account's contact

**Goal.** When a `New` ticket is saved, find the contact of the ticket's account and write the ticket name into the
contact's `description` — only if such a contact exists.

**Setup in the UI**

1. **Object type**: `Tickets`.
2. Conditions:
   - `object.status` · **Equals** · `New`;
   - `v2.id` · **Not equals** · **Value** · empty — the guard condition, see below.
3. **Preload**, two rows in this order:
   - `v1`, **From object field**, `object.account_id`, `Accounts`, `id`;
   - `v2`, **From variable field**, `v1.id`, `Contacts`, lookup field `account_id`.
4. **Change**: `v2.description`, **Calculate**, `concat('Open ticket: ', object.name, ' (', v1.name, ')')`,
   **Save to CRM**.

A **From variable field** row may only use variables declared above it (**Variable “…” must be declared above**).

> **Warning**
> Without the guard condition `v2.id` the process also runs when the account has no contact. Then `v2` is `null`, the
> change creates a new object `v2`, and **Save to CRM** sends it with `POST` — a new, almost empty `Contacts` record
> appears in the CRM. Always guard changes of a preloaded variable that may be empty with a condition on it, or use a
> separate `save_object` step (it is **Skipped** for an empty variable).

**JSON**

```json
{
  "name": "Ticket: note on the account's contact",
  "object_type": "Tickets", "is_child": false, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "New" },
      { "field": "v2.id", "operator": "not_equals", "value_type": "static", "value": "" }
    ],
    "preload": [
      { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" },
      { "var": "v2", "source": "variable_field", "module": "Contacts", "by_field": "account_id", "value": "v1.id" }
    ],
    "actions": [
      { "type": "modify", "target": "v2.description", "change_type": "calculate", "value": "concat('Open ticket: ', object.name, ' (', v1.name, ')')", "save_to_crm": true }
    ]
  }
}
```

**Test.** Mock contacts are not linked to accounts, so create one with the test helper and send a ticket for that account:

```bash
curl -s -X PUT http://localhost:9502/mock/objects/Contacts/c-706 -H 'Content-Type: application/json' \
  -d '{"name":"Anna Smirnova","account_id":"706","email":"anna@example.com"}'
curl -sg "http://localhost:9502/test-event?module=Tickets&set[status]=New&set[account_id]=706"   # runs
curl -sg "http://localhost:9502/test-event?module=Tickets&set[status]=New"                      # no contact → no run
```

(`706` must be an existing mock account: take an `account_id` from any generated event.)

**What you see.** For the first ticket: two `preload` steps (`v2` found by `account_id`) and a `modify` step with
`save.method: "PUT"` for `c-706`. For the second ticket no execution is created; a manual run with the same object
shows the row `v2.id` with **Actual** empty and **Result** `false`.

---

See also: [Triggers and conditions](triggers-and-conditions.md), [Actions](actions.md),
[Change types and expressions](change-types-and-expressions.md), [Child processes](subprocesses.md).

<!-- footer-nav -->
---

← Previous: [Users and roles](users-and-roles.md) | [Contents](../README.md) | [Deployment](../04-operations/deployment.md) Next →
<!-- /footer-nav -->
