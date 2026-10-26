# Actions

What a process does once its conditions are met: preload related objects, change fields, save to the CRM, find and bulk-update records, loop over them, send e-mails and notifications, call external APIs, link records, assign owners and start child processes.

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
| `Name "object_before" is reserved` (server) | Name `object_before` — old values of the event, read-only |
| `Name "sys" is reserved` (server) | Name `sys` — the system variable (the last handled error), read-only |
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

Errors: `Action 1: specify a target field (object.field or v1.field)`, `Action 1: specify a value` (for **Set from variable** and **Calculate**); server: `"object" must point to a field, e.g. object.name`, `Change type must be one of: set_value, set_from_variable, calculate`, `Must be true or false` (`save_to_crm`), `Variable "object_before" is read-only` (a target `object_before.*`; the same for `save_object`).

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

## Send an e-mail

`send_email` sends **one letter** to all recipients (`to`, `cc`, `bcc`) through the SMTP client of WPS itself (the
`symfony/mailer` library). The subject, bodies, headers and recipient names are [templates](change-types-and-expressions.md#template)
with substitutions. The call goes through the side-effect journal, so a step repeated after a worker crash does not
send the letter again.

```json
{
  "id": "welcome", "type": "send_email",
  "to": ["{{ object.email1 }}"],
  "cc": [{ "path": "owner.email1", "name": "{{ owner.first_name }} {{ owner.last_name }}" }],
  "bcc": ["audit@company.ru"],
  "from": { "email": "crm@company.ru", "name": "CRM" },
  "subject": "Deal {{ object.name }}: {{ object.sales_stage }}",
  "html": "<p>Amount: <b>{{ object.amount | number:2:'.':',' }}</b></p>",
  "headers": { "X-Deal": "{{ object.id }}" },
  "save_to_crm": { "module": "Emails", "parent": "object" }
}
```

### Fields

| Field | Required | Description |
|---|---|---|
| `transport` | no | A secret reference `secret://name` of kind `smtp_dsn` holding the connection string (`smtp://user:pass@host:587`, `smtps://…`). Not set — the default transport `MAILER_DSN`. Neither — the process cannot be saved |
| `from` | no | `"crm@company.ru"` or `{ "email": …, "name": "<template>" }`. Default — `MAILER_FROM` / `MAILER_FROM_NAME`. When `MAILER_ALLOWED_FROM` is set (addresses or domains `@company.ru`), another sender is not accepted — protection against spoofing |
| `to` | yes | List of recipients, at least one (forms below) |
| `cc`, `bcc`, `reply_to` | no | Lists of recipients |
| `subject` | yes | Template; line breaks become spaces, at most 998 characters. An empty subject after substitution → `(no subject)` and a warning |
| `text` | `text` or `html` | Template of the text part; values are inserted as is |
| `html` | | Template of the HTML part: substituted values are **escaped** (`<script>` from a CRM field arrives as text). Only `html` — the text part is generated from it |
| `headers` | no | Extra headers `{ "X-Name": "<template>" }`. `From`, `To`, `Cc`, `Bcc`, `Subject`, `Reply-To`, `Sender`, `Return-Path`, `Message-ID`, `MIME-Version`, `Content-*` cannot be set; line breaks in values are removed |
| `on_empty_recipients` | no | No recipient left after checking the addresses: `skip` (default — the step is `skipped` with the reason `No valid recipients`) or `fail` (the step fails) |
| `save_to_crm` | no | After sending, create a history record of the letter in the CRM (below) |
| `delivery` | no | `at_most_once` (default) or `at_least_once`, see [Reliability → Side effects and repeats](../04-operations/reliability.md#side-effects-and-repeats) |

Not supported yet: `attachments` and `template` (CRM `EmailTemplates`) — such fields are rejected on save.

### Recipients

| Form | Example | Resolution |
|---|---|---|
| Address | `"sales@company.ru"` | As is; an invalid static address is a save error |
| Template | `"{{ object.email1 }}"` | The result of the substitution: one address or several split by `,` / `;` |
| Path | `{ "path": "owner.email1", "name": "{{ owner.last_name }}" }` | The field value; `name` is a template of the recipient name |
| Path to a list | `{ "path": "team.emails" }` | An array of addresses or a string with `,` / `;` |

Rules:

- an address is trimmed, its domain is lower-cased (internationalized domains are supported);
- **an invalid or empty address coming from data does not fail the step**: it is skipped and the step result gets a
  warning `invalid_recipients` with the reason `invalid` or `missing`;
- duplicates are removed: an address already in `to` is not added to `cc` and `bcc`;
- at most `MAILER_MAX_RECIPIENTS` (50) recipients per letter, otherwise the step fails with
  `Too many recipients (N > 50)` — protection against mailing "everybody" by mistake.

**A letter to the assigned user.** There is no special "user" recipient: load the user with a preload and point to
their address.

```json
{
  "preload": [{ "var": "owner", "source": "object_field", "module": "Users", "by_field": "id", "value": "object.assigned_user_id" }],
  "actions": [{
    "type": "send_email",
    "to": [{ "path": "owner.email1", "name": "{{ owner.first_name }} {{ owner.last_name }}" }],
    "subject": "Lead {{ object.last_name }} is assigned to you",
    "text": "Lead: {{ object.first_name }} {{ object.last_name }}\nPhone: {{ object.phone_work | default:'—' }}"
  }]
}
```

The primary address of SuiteCRM people and companies (`email1`) is read and returned by the plugin 1.2.0 as a regular
field of the record, see [CRM integration](../04-operations/crm-integration.md).

### Sending and repeats

- The **Message-ID** of the letter is stable for the step: `<{execution_id}.{step position}@WPS_MAIL_MESSAGE_ID_DOMAIN>`,
  e.g. `<6c1e….0@wps.local>`; mail systems drop duplicates by it. `X-WPS-Execution-Id` is added as well.
- A step repeated after a worker crash finds "sent" in the journal and does not send again (`result.replayed: true`).
- Transport errors:

| Error | What happens |
|---|---|
| Cannot connect, timeout before the letter is handed over | Retried by the [`retry` profile](#the-retry-profile) |
| Temporary SMTP refusal `4xx` (421, 450, 451, 452) | Retried (`SMTP_4xx`) |
| Permanent SMTP refusal `5xx` (550 no mailbox, 553, 554) | The step fails without retries (`SMTP_5xx`) |
| Authentication error (530, 534, 535) | Configuration error `Mail transport authentication failed` (`SMTP_AUTH`), not retried |
| Connection lost **after** the letter was handed over (`DATA` accepted) | `at_most_once` — the step fails with `UNKNOWN_OUTCOME` and is not repeated (the letter may have been sent); `at_least_once` — retried |

- **Rate limits:** at most `MAILER_RATE_PER_MINUTE` (60) letters a minute per transport — above it the step is retried
  (`MAIL_RATE_LIMIT`); at most `MAILER_RATE_PER_RECIPIENT_HOUR` (10) letters an hour to one recipient — above it the
  recipient is skipped (`result.rate_limited`). The per-recipient limit protects from processes that by mistake send a
  letter on every save of a record; a [field change condition](triggers-and-conditions.md) is the safer trigger.

### History of the letter in the CRM

`"save_to_crm": { "module": "Emails", "parent": "object" }` creates a record in the CRM after sending. By default the
fields of SuiteCRM `Emails` are sent: `name` (subject), `description`, `description_html`, `from_addr`, `to_addrs`,
`cc_addrs`, `bcc_addrs`, `status: sent`, `type: out`, `date_sent_received`, `message_id`, and for `parent` —
`parent_type` (the module of the variable) and `parent_id`. Fields that are not columns of the module (SuiteCRM keeps
the body and addresses in a separate table) are skipped by the CRM.

For another CRM give a map of fields — the values are templates, `mail.*` is the data of the sent letter (`subject`,
`text`, `html`, `from`, `to`, `cc`, `bcc`, `message_id`):

```json
"save_to_crm": { "module": "Emails", "fields": { "name": "{{ mail.subject }}", "parent_id": "{{ object.id }}" } }
```

A failure to create the history does **not** fail the step (the letter is sent): the step is `success`, the result has
`crm_history_error`.

### Audit

Step request: `transport`, `from`, `to`, `cc`, `bcc` — **only the count** (`["***2 hidden***"]`), `subject`,
`text_preview` (first 500 characters), `html_size`, `message_id`. The password of the connection string is masked.
Result: `sent`, `message_id`, `recipients`, `replayed`, when needed `invalid_recipients`, `rate_limited`,
`crm_history_id` / `crm_history_error`.

### Example: a welcome letter to a new lead

```json
{
  "trigger_on": ["create"],
  "definition": {
    "conditions": [{ "field": "object.email1", "operator": "is_not_empty" }],
    "actions": [{
      "id": "welcome", "type": "send_email",
      "to": ["{{ object.email1 }}"],
      "cc": ["sales-lead@company.ru"],
      "from": { "email": "crm@company.ru", "name": "CRM WPS" },
      "subject": "Welcome, {{ object.first_name }}",
      "html": "<p>Hello, <b>{{ object.first_name | default:'customer' }}</b>!</p><p>Company: {{ object.account_name | default:'—' }}</p>",
      "save_to_crm": { "module": "Emails", "parent": "object" }
    }]
  }
}
```

The result on SuiteCRM 8 (case `.tehnical/suiteCRM8/processes/cases/4.1-send-email.json`): a letter to the lead and a
copy, the text part built from the HTML, an `Emails` record with the status `sent` linked to the lead. A lead without an
address is skipped with the warning `missing`, and the letter goes to the copy only.

### Checking the mail settings

`POST /api/mail/test` (administrator) with `{ "to": "you@company.ru", "transport": "secret://smtp_main" }` sends a test
letter and returns `sent: true` or the SMTP error text. On the stand every letter is caught by
[Mailpit](../04-operations/deployment.md#services): `http://localhost:8025`.

### Save errors

| Error | Field |
|---|---|
| `At least one recipient is required` | `…to` |
| `Recipient must be an email, a template or {"path": ...}`, `Invalid email address "abc"` | `…to[i]`, `…cc[i]`, … |
| `Variable "owner" is not defined at this point` | `…to[i].path` |
| `Subject is required` | `…subject` |
| `Email body is required (text or html)` | the step |
| `Unknown template filter "foo"` and other template errors | `…subject`, `…text`, `…html`, `…headers.*` |
| `Sender must be an email or {"email": ..., "name": ...}`, `Sender address is not allowed`, `Sender is required (set "from" or MAILER_FROM)` | `…from` |
| `Transport must be a secret reference (secret://name)`, `No mail transport configured (set MAILER_DSN or "transport")`, `Secret must be of kind smtp_dsn, got …`, `Secret "x" does not exist` | `…transport` |
| `Header "From" cannot be set here`, `Invalid header name` | `…headers.<name>` |
| `Must be "skip" or "fail"` | `…on_empty_recipients` |
| `Attachments are not supported yet`, `CRM e-mail templates are not supported yet` | `…attachments`, `…template` |
| `Invalid CRM module`, `Variable "x" is not defined at this point` | `…save_to_crm.module`, `…save_to_crm.parent` |

Execution errors: `Email body is empty`, `No valid recipients` (with `on_empty_recipients: fail`),
`Too many recipients (N > 50)`, transport errors from the table above.

## HTTP request

`http_request` calls an external API or webhook: the URL, query, headers and body are [templates](change-types-and-expressions.md#template)
with substitutions, credentials come only from [secrets](secrets.md), the response can be stored in a variable and parsed
with JSONPath. The request goes through the side-effect journal: a step repeated after a worker crash does not send it a
second time (see [Repeats and unknown outcome](#repeats-and-unknown-outcome)). The address is checked by the SSRF
protection — see [Security → Outgoing HTTP requests](../04-operations/security.md#outgoing-http-requests-ssrf-protection).

```json
{
  "id": "to_erp", "type": "http_request", "var": "erp", "method": "POST",
  "url": "https://erp.company.ru/api/orders/{{ object.id }}",
  "query": { "source": "crm" },
  "headers": { "X-Source": "WPS" },
  "auth": { "type": "bearer", "token": "secret://erp_token" },
  "body": { "type": "json", "value": {
    "number": "{{ object.name }}", "amount": "{{= to_number(object.amount) }}",
    "customer": { "inn": "{{ account.inn_c }}" } } },
  "response": { "extract": { "order_id": "$.data.order_id", "status_text": "$.data.status" } },
  "timeout": 20
}
```

### Fields

| Field | Required | Description |
|---|---|---|
| `var` | no | Variable that receives the response (below). Not set — the response is only in the audit. `object`, `object_before`, `sys` are not allowed |
| `method` | yes | `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE` |
| `url` | yes | Template. **The scheme and host are literal only**, before the first substitution: `https://erp.company.ru/api/orders/{{ object.erp_id_c }}` is fine, `{{ object.webhook_url }}` and `https://{{ v1.host }}/…` are not. Substitutions in the path and query are encoded (`a/b` → `a%2Fb`, no new path segment appears). A missing value in the URL fails the step. Exception — a whole URL from a secret of kind `webhook_url`: `"url": "secret://slack_hook"` |
| `query` | no | `{ "name": "<template>" }`, added to the query of `url`. An empty value is sent empty (`?a=`), a missing path skips the parameter |
| `headers` | no | `{ "Name": "<template>" }`; line breaks are removed. `Host`, `Content-Length`, `Transfer-Encoding`, `Connection` cannot be set |
| `auth` | no | Authorization (below) |
| `body` | no | Body (below); not allowed for `GET`, `HEAD`, `DELETE` |
| `response.type` | no | `auto` (default: JSON when `Content-Type` is JSON, text otherwise), `json`, `text`, `none` (the body is not kept) |
| `response.extract` | no | `{ "field": "<JSONPath>" }` — variable fields taken from the response body |
| `success_codes` | no | `"2xx"` (default) or a list of codes and ranges: `["2xx", 404]`. A code outside the list fails the step |
| `timeout` | no | Overall timeout, s: 1…`HTTP_MAX_TIMEOUT` (30). Default 30 |
| `connect_timeout` | no | Connection timeout, s: 1…30. Default 5 |
| `follow_redirects` | no | 0…5 (default 0 — a 3xx response is returned as is) |
| `delivery` | no | `at_most_once` (default for `POST` and `PATCH`) or `at_least_once` (default for the others), see [Reliability](../04-operations/reliability.md#side-effects-and-repeats) |
| `idempotency_header` | no | Header carrying the idempotency key. Default `Idempotency-Key` for `POST`/`PATCH`, `null` — do not send. The value is `{execution_id}:{step position}`, stable for the step |
| `retry`, `on_error` | no | [Step errors](#step-errors-on_error-and-retries) |

### Request body

| `body.type` | `value` | Content-Type |
|---|---|---|
| `json` (default) | JSON structure; string leaves are templates | `application/json` |
| `form` | `{ "name": "<template>" }` | `application/x-www-form-urlencoded` |
| `raw` | template string | `body.content_type` (required) |

In `json` a leaf made **entirely** of one substitution (`"{{ object.tags }}"`) gets the original value — a list stays a
list, a number stays a number. Mixed text (`"Deal {{ object.name }}"`) is a string. The JSON is built as a structure,
not by gluing strings, so a CRM value cannot inject an extra field. CRM fields arrive as strings:
`"{{ object.amount }}"` with `"1500.50"` gives a string; a number is explicit, with an expression
`"{{= to_number(object.amount) }}"` → `1500.5`. The process definition is stored in PostgreSQL as `jsonb`, so the keys of
an object in the body may arrive **in a different order** (irrelevant for JSON; where the order matters — see
[Set a process variable](#set-a-process-variable)). A body larger than
`HTTP_MAX_REQUEST_BYTES` (1 MB) fails the step. `multipart` is not supported yet.

### Authorization

| `auth.type` | Fields | How it is applied |
|---|---|---|
| `none` | — | default |
| `bearer` | `token`: secret (`bearer` / `generic`) | `Authorization: Bearer …` |
| `basic` | `credentials`: secret `basic_auth` | `Authorization: Basic …` |
| `api_key` | `key`: secret; `in`: `header` (default) or `query`; `name` (default `X-Api-Key`) | header or query parameter |
| `oauth2_client_credentials` | `client`: secret `oauth2_client` (`token_url`, `client_id`, `client_secret`, `scope`); `auth_style`: `header` (default) or `body` | the token is requested with `client_credentials`, cached in Redis until it expires (minus 60 s); one worker requests it at a time. A `401` response drops the token and repeats the request **once** with a new one |

Values are **only `secret://name` references**, a literal is rejected (`Credentials must reference a secret (secret://name)`).
When the secret has `allowed_hosts`, a request to another host fails with `Secret "x" is not allowed for host "y"`. The
`token_url` of an OAuth2 secret must be `https`. Secret values (and the OAuth2 token) are masked in the audit, the journal
and **in the response variable** — even when the external service echoes them in the body.

### Response variable

```json
{ "status": 201, "ok": true,
  "headers": { "content-type": "application/json", "location": "…" },
  "body": { "data": { "order_id": "A-1042" } },
  "order_id": "A-1042", "status_text": "new" }
```

- `status`, `ok` (`2xx`), `headers` — lower-case names, without `set-cookie` and authorization headers.
- `body` — parsed JSON; `body_text` — text (for `response.type: text` or a non-JSON response, converted to UTF-8 by
  `charset`). A body larger than `HTTP_MAX_STORED_BODY_BYTES` (64 KB) is not put into the variable: `body: null`,
  `body_truncated: true` (`extract` fields are still computed on the full body).
- `extract` fields are top-level: `erp.order_id`. JSONPath in Goessner syntax (`$.data.id`, `$.items[0]`, `$..id`,
  filters `[?(@.qty > 3)]`): nothing found → `null`, one match → the value, several → a list. Fields cannot be named
  `status`, `ok`, `headers`, `body`, `body_text`, `body_truncated`.
- Nested fields are read with a normal path: `erp.body.data.items`.
- The variable has no CRM module; to save it to the CRM, set `module` in `save_object`.

A response larger than `HTTP_MAX_RESPONSE_BYTES` (5 MB) is cut off while reading — error `Response is too large`
(`RESPONSE_TOO_LARGE`). Invalid JSON with `response.type: json` — error `Response is not valid JSON`.

### Repeats and unknown outcome

| Situation | What happens |
|---|---|
| A code from `success_codes` | `success` |
| Connection failed, DNS | Retried by the [`retry` profile](#the-retry-profile): the request was not sent |
| `408`, `425`, `429`, `503` | Retried; the delay respects `Retry-After` (seconds or an HTTP date), but not more than `HTTP_RETRY_AFTER_MAX` (60 s) |
| `500`, `502`, `504` | Retried **only if** an idempotency key was sent or `delivery: at_least_once`; otherwise `UNKNOWN_OUTCOME` without a retry — the server may have processed the request |
| Timeout or broken connection **after** sending | `at_most_once` without an idempotency key — `UNKNOWN_OUTCOME`, no retry; otherwise retried |
| Other codes (`400`, `401`, `403`, `404`, `409`, `422`, `3xx` without a redirect) | Step error `HTTP 404 from erp.company.ru` (`HTTP_404`), no retries; the response body (up to 2 KB) is in `details` |

When the worker crashes in the middle of a request, after recovery the step finds an unfinished journal record
(`started`). With `at_most_once` it does not send the request again and fails with `UNKNOWN_OUTCOME`. When the record is
finished (`done`), the response is taken from the journal, the variable is restored and the request is not repeated
(`result.replayed: true`).

### Audit

Step request: `method`, `url` (with the query; secrets masked), the list of header names, `body_size`, `body_preview`
(500 characters), `delivery`. Result: `status`, `duration_ms`, `var`, `replayed`, `response` (the variable; a body longer
than 2000 characters becomes a `body_preview`).

### Example: a lead to the ERP and the order number back to the CRM

```json
{
  "trigger_on": ["create"],
  "definition": { "actions": [
    { "id": "to_erp", "type": "http_request", "var": "erp", "method": "POST",
      "url": "https://erp.company.ru/api/leads/{{ object.id }}",
      "idempotency_header": null,
      "body": { "type": "json", "value": { "name": "{{ object.first_name }} {{ object.last_name }}", "status": "{{ object.status }}", "crm_id": "{{ object.id }}" } },
      "response": { "extract": { "order_id": "$.data.order_id" } } },
    { "type": "modify", "target": "object.description", "change_type": "template",
      "value": "ERP order: {{ erp.order_id }} (HTTP {{ erp.status }})", "save_to_crm": true }
  ] }
}
```

Result on SuiteCRM 8 (case `.tehnical/suiteCRM8/processes/cases/4.2-http-request.json`, the external system is the mock
`/mock/echo`): the lead description becomes the order number with HTTP 200, the system received the request once. In the
second scenario the external system answers after 8 s and the WPS worker is killed (`SIGKILL`) in the middle of the
request. After recovery the step does not repeat the request: the execution is `failed` with `UNKNOWN_OUTCOME`, the
journal record `GET /api/executions/{id}/side-effects` is `started`, the external system received the request exactly
once, the lead description is unchanged. For such a request to be retried, the external system must accept
`Idempotency-Key` (keep the default `idempotency_header`) or the step must use `delivery: at_least_once`.

### Save errors

| Error | Field |
|---|---|
| `Method must be one of: GET, HEAD, POST, PUT, PATCH, DELETE` | `…method` |
| `URL is required`, `Scheme and host of the URL must be literal (https://host/...)`, `Plain http is not allowed (HTTP_ALLOW_PLAIN_HTTP=false)`, `Secret must be of kind webhook_url, got …`, template errors, `Variable "v9" is not defined at this point` | `…url` |
| `Must be an object of name => template`, `Header "Host" cannot be set here` | `…query`, `…headers.<name>` |
| `Must be one of: none, bearer, basic, api_key, oauth2_client_credentials`, `Credentials must reference a secret (secret://name)`, `Must be "header" or "query"` | `…auth.*` |
| `A body is not allowed for GET`, `Must be one of: json, form, raw`, `Content type is required for a raw body`, `Multipart body is not supported yet` | `…body.*` |
| `Must be one of: auto, json, text, none`, `Invalid field name (reserved: …)`, `JSONPath must start with "$"`, `Invalid JSONPath: …` | `…response.*` |
| `Must be "2xx" or a list of codes and ranges (200, "2xx")` | `…success_codes` |
| `Must be an integer between 1 and 30` / `… 0 and 5` | `…timeout`, `…connect_timeout`, `…follow_redirects` |
| `Must be a header name or null` | `…idempotency_header` |
| `Variable name is required (letters, digits, "_"; not object, object_before, sys)` | `…var` |

Runtime errors: `Destination address is not allowed (private network)`, `Host "x" is not allowed`, `Port N is not allowed`,
`DNS lookup failed for "x"`, `Secret "x" is not allowed for host "y"`, `Response is too large`,
`Response is not valid JSON`, `HTTP 4xx from host`, `UNKNOWN_OUTCOME` — see [Troubleshooting](../04-operations/troubleshooting.md).

## Notification

The `notify` action sends a **short notification** to the responsible user or the team: to the CRM bell, Telegram,
Slack or Microsoft Teams — in one step, to several channels at once. For e-mails to customers use
[Send an e-mail](#send-an-e-mail): `notify` has no e-mail channel.

### Fields

```json
{ "type": "notify", "id": "notify_lead", "title": "New lead",
  "message": "You were assigned the lead {{ object.first_name }} {{ object.last_name }} ({{ object.lead_source | default:'no source' }})",
  "link": { "record": "object" },
  "channels": [
    { "channel": "crm_alert", "users": ["{{ object.assigned_user_id }}"] },
    { "channel": "telegram", "connection": "secret://sales_bot", "chat_id": "-1001234567890" },
    { "channel": "slack", "connection": "secret://slack_sales" }
  ],
  "fail_on": "any" }
```

| Field | Required | Value |
|---|---|---|
| `message` | yes | Text [template](change-types-and-expressions.md#template). Longer than 4000 characters — cut, with the warning `Message was cut to 4000 characters` in the audit. Empty after substitution — step error `Notification message is empty` |
| `title` | no | Title template: the bold first line in messengers, the `name` of the CRM bell record (without a title — the first 80 characters of the text) |
| `link` | no | The "Open" link: `{"record": "object"}` — to the record of a variable (the address is built by `CRM_RECORD_URL_TEMPLATE`), or `{"url": "https://…/{{ object.id }}"}` — an address template |
| `channels` | yes | 1..10 channels (below) |
| `fail_on` | no | `any` (default) — the step is `failed` when at least one channel failed; `all` — only when nothing was delivered |
| `delivery` | no | `at_most_once` (default) or `at_least_once` — what to do with an unknown outcome, see [below](#repeating-without-duplicates) |

The common step fields (`id`, `name`, `on_error`, `retry`) apply too. Channels are sent one by one; an error of one
channel does not stop the others.

### Channels

| Channel | Fields | What is sent |
|---|---|---|
| `crm_alert` — the CRM bell | `users` (required, up to 50): a user id, a template (`"{{ object.assigned_user_id }}"`) or `{"path": "team.ids"}` — a path to an id or a list of ids; empty ones and duplicates are removed. Optional: `module` (`Alerts` by default), `profile` (`suitecrm8` / `suitecrm7` / `custom`, `CRM_ALERT_PROFILE` by default), `fields` — a map "CRM field → template" overriding fields | An `Alerts` record per recipient: `name` (title), `description` (text), `assigned_user_id`, `is_read = 0`, `type = info`, `target_module`, `url_redirect` (the link); for SuiteCRM 8 also `date_start`, `snooze`, `status`. The recipient sees the notification in the bell of the CRM UI |
| `telegram` — a bot | `connection` — a secret of kind `telegram_bot` (or `generic`) with the token; `chat_id` — id of a chat, group or channel (`-100…`), a template is allowed | `sendMessage` with `parse_mode: HTML`: `<b>title</b>`, the text (substitutions are HTML-escaped), an "Open" link. The token is in the URL path, so the whole URL is masked in the audit and the logs |
| `slack` — an incoming webhook | `connection` — a secret of kind `webhook_url` (or `generic`) with the webhook URL | `text` (plain text for notifications) and a `mrkdwn` block with `*title*`, the text (`&`, `<`, `>` escaped) and a `<url\|Open>` link |
| `teams` — a Microsoft Teams webhook | `connection` — a secret of kind `webhook_url`; `format` — `adaptive_card` (default, Workflows webhooks) or `message_card` (the retiring Office 365 connectors) | A card with the title, the text and an "Open" button |

**Links.** `{"record": "object"}` gives a record link by the `CRM_RECORD_URL_TEMPLATE` template (by default by the
profile: SuiteCRM 8 — `#/leads/record/{id}`, SuiteCRM 7 — `index.php?module=Leads&action=DetailView&record={id}`). The
CRM bell gets a relative link, messengers an absolute one: `CRM_UI_BASE_URL` is prepended. If an absolute link cannot be
built (the CRM UI address is unknown), messengers are sent without the "Open" button.

### Repeating without duplicates

Every delivery is a separate record of the [side-effect journal](../04-operations/reliability.md#side-effects-and-repeats)
(`crm_alert` — one per recipient, a messenger — one per chat or webhook). When the step runs again (a retry after an
error, recovery after a worker crash), what was delivered **is not sent again**: the answer comes from the journal
(`replayed: true`).

| Channel answer | What happens |
|---|---|
| `2xx` (Telegram — also `ok: true`) | delivered |
| `408`, `425`, `429`, `503`, a network error before sending | recoverable: after all channels the step is repeated by the retry policy, `Retry-After` (Telegram — `retry_after`) is respected; delivered channels come from the journal on the repeat |
| `500`, `502`, `504`, a timeout or a break after sending | unknown outcome: with `at_most_once` (default) — `UNKNOWN_OUTCOME` without a repeat (the message may have arrived); with `at_least_once` — a repeat (a duplicate is possible) |
| others (`400`, `403`, `404`) | a channel error: `telegram: HTTP 403 Forbidden: bot was kicked`, no repeat |

Messenger webhooks have no idempotency keys, so by default WPS prefers not sending to sending twice.

**Step result.** `request_data`: `title`, `message_preview` (the first 500 characters), `link`, `channels` (`channel`,
`connection`, `chat_id`, `users`, `format` — no secrets), `delivery`. `result_data.channels` — per channel: `status`
(`success` / `failed` / `skipped` — no recipients), `deliveries` (`user` and `record` for the CRM, `chat_id` and
`message_id` for Telegram, `status` for webhooks, `replayed`), `error`, `error_code`; `warnings`. If the step failed,
the error text is `notify: 1 of 3 channels failed: telegram: HTTP 403 …`, the summary is in `details`.

### Connecting messengers

1. **Telegram.** Create a bot with [@BotFather](https://core.telegram.org/bots/features#botfather) and get the token.
   Add the bot to a group or a channel (to a channel — as an administrator). The `chat_id` of a group can be found in
   the [`getUpdates`](https://core.telegram.org/bots/api#getupdates) answer after a message in the group. Save the token
   as a secret: `{"name": "sales_bot", "kind": "telegram_bot", "value": "123456:ABC…"}`.
2. **Slack.** Create an [incoming webhook](https://api.slack.com/messaging/webhooks) (Slack app → Incoming Webhooks →
   channel) and save the URL as a secret of kind `webhook_url`. Whoever knows the URL can post to the channel — hence a
   secret only.
3. **Microsoft Teams.** Create a webhook with Workflows ("Post to a channel when a webhook request is received") —
   the Microsoft guide: [Create incoming webhooks](https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook);
   the URL is a secret of kind `webhook_url`, `format: adaptive_card`. Old Office 365 connectors — `format: message_card`.
4. **Network.** Messenger requests pass the same protection as the [HTTP request](#http-request): if the
   `HTTP_ALLOW_HOSTS` allow list is set, add `api.telegram.org`, `hooks.slack.com` and the Teams webhook host (e.g.
   `*.logic.azure.com`) — see [Configuration](../04-operations/configuration.md#outgoing-http-requests-http_request).
5. **A check without a process** — `POST /api/notify/test` (editors) with one channel:
   `{"channel": {"channel": "telegram", "connection": "secret://sales_bot", "chat_id": "-100…"}}` → `{"sent": true, …}`
   or `{"sent": false, "error": "…"}` ([API](../06-api-reference/endpoints.md#post-apinotifytest)).

### Example: a new lead → the bell, Telegram and Slack

The definition is at the [start of the section](#notification). Result on SuiteCRM 8 (case
`.tehnical/suiteCRM8/processes/cases/4.8-notify.json`, Telegram and Slack on the mock, texts in Russian): an `Alerts`
record was created in SuiteCRM 8 for the admin user — the title, the text with the lead name and source,
`target_module = Leads`, `url_redirect = #/leads/record/{id}`, the notification is visible in the bell; Telegram got
`<b>title</b>\ntext\n<a href="http://localhost:8801/#/leads/record/{id}">Открыть</a>`; the audit shows `chat_id` and
`connection: secret://…`, not the bot token.

### Save errors

| Error | Field |
|---|---|
| `Message is required`, template errors | `…message` |
| `Must be a template string`, template errors | `…title` |
| `Use {"record": "<variable>"} or {"url": "<template>"}`, `Variable name is required (e.g. object)`, `Variable "x" is not defined at this point` | `…link`, `…link.record` |
| `From 1 to 10 channels are required` | `…channels` |
| `Unknown channel "sms"`, `Channel is required` | `…channels[i].channel` |
| `At least one user is required`, `User id, template or {"path": ...} is required` | `…channels[i].users` |
| `Invalid CRM module`, `Must be one of: suitecrm8, suitecrm7, custom`, `Must be a map "CRM field" → template`, `Profile "custom" needs "fields"` | `…module`, `…profile`, `…fields` |
| `Connection secret is required (secret://name)`, `Secret "x" has kind "bearer", expected telegram_bot or generic` | `…channels[i].connection` |
| `Chat id is required` | `…channels[i].chat_id` |
| `Must be adaptive_card or message_card` | `…channels[i].format` |
| `Must be "any" or "all"` | `…fail_on` |

## Set a process variable

`set_variable` (**Set a process variable**) keeps an intermediate value in a **local variable** that is not a CRM
record. Unlike [Modify object / variable](#modify-object--variable), such a variable is never marked as changed and
cannot be saved to the CRM: use it for labels, sums, counters and other calculations that one `modify` later moves to a
record.

### Forms

One field — like `modify`:

```json
{ "type": "set_variable", "target": "calc.subject", "change_type": "template", "value": "Deal {{ object.name }} for {{ object.amount }}" }
```

Several fields in one step — `var` + `fields`. A **list** is computed strictly in order, a later field sees an earlier
one:

```json
{ "type": "set_variable", "var": "calc", "fields": [
  { "name": "rate", "change_type": "calculate", "value": "ifs(object.sales_stage == 'Negotiation/Review', 10, 0)" },
  { "name": "discount", "change_type": "calculate", "value": "round(percent(object.amount, calc.rate), 2)" },
  { "name": "net", "change_type": "calculate", "value": "object.amount - calc.discount" }
] }
```

`fields` may also be an object `{"name": {"change_type": …, "value": …}}`, but **the order of object keys is not kept**:
the definition is stored in PostgreSQL as `jsonb`, and PostgreSQL reorders keys (shorter ones first). Use an object only
for independent fields; when a field refers to another field of the same variable, saving returns the warning
`The order of object keys is not kept: use a list of fields when a field uses another field of "calc"`.

All [change types](change-types-and-expressions.md) work: `set_value`, `set_from_variable`, `calculate`, `template`,
`date_shift`.

### How it works

- A missing variable is created with the source `local` (`context.meta.<name>.source = "local"`); an existing local one
  gets its fields overwritten and added.
- Writing into a CRM record variable (`object`, a preload, one created by `modify`, an `http_request` response) is not
  allowed: `Variable "acc" is a CRM record variable; use modify` on save, `set_variable: "acc" is a CRM record variable;
  use modify` at run time.
- Fields are not marked as changed. `save_object` of a local variable and `modify … save_to_crm: true` on its field are
  rejected: `Variable "calc" is local (set_variable) and cannot be saved to the CRM` (on save and, when the variable came
  from a parent, at run time).
- `modify` of a field of a local variable is allowed and keeps it local.
- A child process gets local variables with the rest; the step audit has `request_data = {var, fields}`,
  `result_data.fields.<name> = {old_value, new_value}`.

**Accumulation.** The variable survives between steps (and iterations of the [`foreach` loop](#loop-for-each-item)), so sums are counted
this way. Initialize it first: without an initial value the first `'' + 500` concatenates by the `+` rule and gives
`'500'` (see [Values and types](change-types-and-expressions.md#values-and-types)):

```json
{ "type": "set_variable", "target": "totals.sum", "change_type": "set_value", "value": "0" }
{ "type": "set_variable", "target": "totals.sum", "change_type": "calculate", "value": "totals.sum + item.amount" }
```

### Example: discount and total of a deal

Case `.tehnical/suiteCRM8/processes/cases/4.13-set-variable.json`: a process for new deals computes in `calc` the
discount rate by stage, the discount, the total and a label (`fields` as a list), then moves the label to the deal:

```json
{ "type": "set_variable", "var": "calc", "fields": [
  { "name": "rate", "change_type": "calculate", "value": "ifs(object.sales_stage == 'Negotiation/Review', 10, object.sales_stage == 'Proposal/Price Quote', 5, 0)" },
  { "name": "discount", "change_type": "calculate", "value": "round(percent(object.amount, calc.rate), 2)" },
  { "name": "net", "change_type": "calculate", "value": "object.amount - calc.discount" },
  { "name": "label", "change_type": "template", "value": "Скидка {{ calc.rate }}%: {{ calc.discount | number:2:',':' ' }} ₽, к оплате {{ calc.net | number:2:',':' ' }} ₽" }
] }
{ "type": "modify", "target": "object.next_step", "change_type": "set_from_variable", "value": "calc.label", "save_to_crm": true }
```

A deal of 1 234 567.80 in Negotiation/Review gets `next_step` «Скидка 10%: 123 456,78 ₽, к оплате 1 111 111,02 ₽»;
`calc` itself never reaches the CRM.

### Save errors

| Rule | Error |
|---|---|
| neither `target` nor `var` + `fields`, or both | `Either "target" or "var" with "fields" is required` |
| `target` without a field | `"calc" must point to a field, e.g. calc.name` |
| invalid / reserved variable name (`object`, `object_before`, `sys`, `config`) | `Invalid variable name "…"` / `Name "sys" is reserved` |
| the variable is already declared as a CRM record | `Variable "acc" is a CRM record variable; use modify` |
| `fields` empty or not a list / object | `"fields" must be a non-empty list or object` |
| a list item without `name` | `Must be an object {"name": ..., "change_type": ..., "value": ...}` |
| invalid field name | `Invalid field name "…"` |
| unknown change type | `Change type must be one of: …` (path `…fields[k].change_type` / `…fields.<name>.change_type`) |
| value errors | as of the change type, with the field path |
| `save_object` / `save_to_crm` of a local variable | `Variable "calc" is local (set_variable) and cannot be saved to the CRM` |

## Link / unlink records

`link_records` ("Связать записи") and `unlink_records` ("Отвязать записи") create and remove links of a record to
other records through a link field of its CRM module: meeting participants (`Meetings.users`), contacts of an account
(`Accounts.contacts`), leads of a call (`Calls.leads`). Links are not kept in process variables — they are written to
the CRM at once.

```json
{ "type": "link_records", "record": "meeting", "link": "users", "related_module": "Users",
  "related": [ { "path": "object.assigned_user_id" }, { "path": "team.ids" }, { "value": "1" } ],
  "fields": { "accept_status": "accept" }, "on_not_found": "warn" }

{ "type": "unlink_records", "record": "m", "link": "leads", "related": [ { "path": "object.id" } ] }
```

### Fields

| Field | Req. | Description |
|---|---|---|
| `record` | yes | The record variable to link: `object`, a preload, a created and saved record (`meeting` after `save_object`). Its `id` is needed |
| `module` | no | Module of the record. By default — from the variable metadata: `object` — the event module, a preload — its module, a created record — the module of `save_object` |
| `link` | yes | Name of the link field of the record module (`users`, `contacts`, `leads`, `documents`, …). Given explicitly — WPS does not read CRM metadata |
| `related` | yes | Sources of ids. `{"path": "<variable>.<field>"}` — the field value: a string, a **list** (`team.ids`, expanded) or a list of records (their `id` is taken); `{"value": "<id>"}` — a literal |
| `related_module` | no | Module of the related records (sent to the plugin as `type`); by default the plugin takes it from the relationship definition |
| `fields` | no | `link_records` only: relationship fields, an object `name → string value` (`accept_status`, `required`, `contact_role`) |
| `on_not_found` | no | The CRM returned ids in `not_found`: `warn` (default — the step succeeds, the ids are in the result) or `error` (the step fails) |
| `on_missing_record` | no | The `record` variable has no `id` (not saved, empty): `skip` (default — the step is `skipped`) or `error` |

### How it works

1. The record `id` is `record.id`. No `id` → by `on_missing_record`: `skipped` with the reason
   `Record "meeting" has no id — save it before linking`, or an error.
2. The record module is unknown (no `module`, the variable has no module) → the error
   `Cannot link "meeting": CRM module is unknown (set "module")`.
3. Ids are collected from all sources in order: trimmed, empty values dropped, duplicates removed. A missing path is
   not an error: it goes to `missing_paths` of the result.
4. No ids at all → the step is `skipped`: `No related ids to link` / `No related ids to unlink`.
5. More than `LINK_RECORDS_MAX` (500 by default) ids → the error `Too many related ids (612 > 500)`.
6. `link_records` sends ids in batches of `max_link_batch` from the CRM capabilities (100 by default) and merges the
   answers. Linking is **idempotent**: an existing link is not duplicated, its fields are updated (`updated`), so a
   repeated step is safe. `unlink_records` removes links one by one: removed ones — `removed`, absent ones —
   `not_linked` (the step still succeeds).
7. CRM contract 2.0 with the `relationships` feature is required. With an old plugin the step fails with
   `CRM_CAPABILITY_MISSING`. Without the `relationship_fields` feature links are created without fields and the result
   gets a warning.
8. CRM errors: record not found (404) and an unknown link / invalid relationship field (4xx) — no retry; 5xx and network
   errors — retried by the usual step policy.
9. Links produce no stream events, so there are no cascades. The execution context does not change.

**Step result.** `request_data`: `module`, `record_id`, `link`, `related_module`, `related_ids` (the first 100),
`related_count`, for `link_records` also `fields` and `batches`. `result_data`: `linked`, `updated`, `not_found`,
`warnings` (or `removed`, `not_linked` for `unlink_records`), `missing_paths`.

### Common SuiteCRM link fields

| Record module | `link` | Related module | Relationship fields |
|---|---|---|---|
| Meetings | `users`, `contacts`, `leads` | Users, Contacts, Leads | `accept_status`: `none`, `accept`, `decline`, `tentative`; `required`: `0` / `1` |
| Calls | `users`, `contacts`, `leads` | Users, Contacts, Leads | `accept_status`, `required` |
| Accounts | `contacts` | Contacts | — |
| Opportunities | `contacts` | Contacts | `contact_role` |
| Accounts | `documents` | Documents | — |

### Example: a meeting with participants

```json
{
  "trigger_on": ["create"],
  "definition": { "actions": [
    { "type": "modify", "target": "meeting.name", "change_type": "template", "value": "Встреча: {{ object.first_name }} {{ object.last_name }}" },
    { "type": "modify", "target": "meeting.date_start", "change_type": "date_shift", "base": "today", "shift": "+1 day", "set_time": "10:00" },
    { "type": "modify", "target": "meeting.duration_hours", "change_type": "set_value", "value": "1" },
    { "type": "modify", "target": "meeting.parent_type", "change_type": "set_value", "value": "Leads" },
    { "type": "modify", "target": "meeting.parent_id", "change_type": "set_from_variable", "value": "object.id" },
    { "type": "save_object", "target": "meeting", "module": "Meetings" },
    { "id": "invite", "type": "link_records", "record": "meeting", "link": "users",
      "related": [ { "path": "object.assigned_user_id" } ], "fields": { "accept_status": "accept" } },
    { "type": "link_records", "record": "meeting", "link": "leads", "related": [ { "path": "object.id" } ] }
  ] }
}
```

Result on SuiteCRM 8 (case `.tehnical/suiteCRM8/processes/cases/4.12-link-records.json`): a meeting tomorrow at
10:00 is created, the participant is the lead owner with `accept_status = accept`, the meeting is linked to the lead.
The second process of the case on `object.status changed_to Dead` finds the lead's meeting with a preload by
`parent_id` and unlinks the lead (`unlink_records`, `removed: [lead id]`); the user participant stays.

### Save errors

| Error | Field |
|---|---|
| `Record variable is required (e.g. meeting)`, `Variable "v9" is not defined at this point` | `…record` |
| `Invalid CRM module` | `…module`, `…related_module` |
| `Link name is required (e.g. contacts)` | `…link` |
| `At least one related id source is required` | `…related` |
| `Use {"path": ...} or {"value": ...}` | `…related[k]` |
| `Variable "v9" is not defined at this point` | `…related[k].path` |
| `Related id must be a non-empty string` | `…related[k].value` |
| `Relationship fields must be an object of scalar values`, `Fields are not used when unlinking` | `…fields` |
| `Must be "warn" or "error"`, `Must be "skip" or "error"` | `…on_not_found`, `…on_missing_record` |

## Assign an owner

The `assign` action puts into a user field of a record (`assigned_user_id` by default) an employee chosen **by a
rule**: round robin, the least busy, random, the manager of the current owner or a specific user. It is the lead and
case distribution of AOW / Bitrix24 without hand-made formulas.

### Fields

```json
{ "type": "assign", "id": "rr", "target": "object", "strategy": "round_robin",
  "pool": { "security_group": "wps-case-sales" }, "queue_key": "leads_sales", "save_to_crm": true }
```

| Field | Required | Value |
|---|---|---|
| `target` | yes | The record variable that gets the owner (`object`, a preload variable); not a list, not a local variable |
| `field` | no | The user field, `assigned_user_id` by default |
| `strategy` | yes | `round_robin`, `least_busy`, `random`, `manager`, `user` (dictionary [`GET /api/dictionaries/assign-strategies`](../06-api-reference/endpoints.md#get-apidictionariesassign-strategies)) |
| `pool` | for `round_robin`, `least_busy`, `random` | Exactly one: `{"users": [{"value": "u1"}, {"path": "object.team_ids"}]}` — a list of id sources (a value or a path to an id / a list of ids), or `{"security_group": "<id>"}` / `{"security_group": {"path": "…"}}` — members of a CRM security group |
| `only_active` | no | `true` (default) — only active users (below) |
| `exclude` | no | Id sources not to assign: `[{"path": "object.created_by"}]` |
| `queue_key` | no | The `round_robin` / `least_busy` queue key, `^[A-Za-z0-9_:.-]{1,100}$`. By default the step's own: `p{process id}:{step id or pc}`. The same key in several processes — a **shared** queue |
| `least_busy` | for `least_busy` | `{"module": "Cases", "user_field": "assigned_user_id", "filter": [...]}` — which records count as "open" (the filter is required, the format is as in [Find records](#find-records)) |
| `manager_of` | no | For `manager`: whose manager (`{"path": …}` / `{"value": …}`); by default the current owner of the record |
| `user` | for `user` | `{"path": "…"}` or `{"value": "<id>"}` |
| `fallback` | no | When nobody can be chosen: `{"mode": "keep"}` (default) — the field is unchanged, the step is `skipped`; `{"mode": "error"}` — the step fails; `{"mode": "user", "user": {"value": "<id>"}}` — assign a backup user |
| `save_to_crm` | no | `true` — save the record right away (as `save_object`); otherwise a separate "Save to CRM" is needed |
| `module` | no | Module for `save_to_crm` when the variable does not know it |

### Strategies

| Strategy | How it chooses |
|---|---|
| `round_robin` | The pool is sorted by id; Redis keeps **the last assigned** (`assign:rr:{queue_key}`), the next one is the first id after it, after the last — the first again. The choice is atomic (a Lua script), so parallel executions do not get the same user. Adding or removing an employee from the pool does not give anybody two records in a row |
| `least_busy` | For every pool user a `count` request of open records (`least_busy.filter` + `user_field = user`, up to 10 requests in parallel); the minimum wins, a tie — the one assigned longest ago in this queue, then by id. The pool is at most `ASSIGN_LEAST_BUSY_MAX_POOL` (50) |
| `random` | A random pool user |
| `manager` | `reports_to_id` of the `manager_of` user (by default the current owner): escalation. No manager or an inactive one — `fallback` |
| `user` | The user from `user` (a value or a path); an inactive one — `fallback` |

**Pool and activity.** `{"users": …}` — one `Users` request with `in` for all ids (ids missing in the CRM are dropped,
at most 200); `{"security_group": …}` — members of `SecurityGroups/{id}/relationships/users`, cached for
`ASSIGN_POOL_CACHE_TTL` (60 s). With `only_active` a user fits when `status = Active`, `employee_status` is `Active` or
empty (leave — `Leave of Absence` — excludes), and it is not a group (`is_group`) or a portal (`portal_only`) user. The
dropped ones are visible in the audit: `inactive`, `excluded`. An empty pool — `fallback`.

**A repeated step does not move the queue.** The step decision is remembered for a day by execution, `pc` and loop
iteration. When the step runs again (a retry after a `save_to_crm` error, recovery after a worker crash), the same user
is assigned and the queue does not move again (`reused_decision: true`). In a [`foreach` loop](#loop-for-each-item)
every iteration has its own decision — the list records are distributed round robin.

**Resetting a queue** — delete the Redis key: `redis-cli -p 6380 DEL assign:rr:<queue_key>` (see
[Configuration](../04-operations/configuration.md#owner-assignment-assign)).

**Step result.** `request_data`: `target`, `field`, `strategy`, `only_active`, `queue_key` (for queues), `save`.
`result_data`: `chosen_user_id`, `previous_user_id`, `unchanged`, `reason` ("round_robin: next after u2",
"least_busy: u3 has 0 open Cases (min)", "manager of u3", "fallback: keep — pool is empty"), `pool_size`, `pool` (up to
50), `pool_source`, `inactive`, `excluded`, `counts` (for `least_busy`), `manager_of`, `fallback`, `reused_decision`,
`save`. Nobody to choose with `fallback: keep` — the step is `skipped` with the same summary.

### Example: leads round robin, a case to the least busy, escalation

```json
{ "type": "assign", "target": "object", "strategy": "round_robin",
  "pool": { "security_group": "wps-case-sales" }, "queue_key": "case49", "save_to_crm": true }

{ "type": "assign", "target": "object", "strategy": "least_busy",
  "pool": { "users": [ { "value": "wps-case-u1" }, { "value": "wps-case-u2" }, { "value": "wps-case-u3" } ] },
  "least_busy": { "module": "Cases", "filter": [ { "field": "state", "operator": "not_equals", "value": "Closed" } ] },
  "save_to_crm": true }

{ "type": "assign", "target": "object", "strategy": "manager", "fallback": { "mode": "keep" }, "save_to_crm": true }
```

Result on SuiteCRM 8 (case `.tehnical/suiteCRM8/processes/cases/4.9-assign.json`, users and the group from
`seed_case_users.sh`): 4 leads in a row got three different active managers of the group, the fourth the first one
again; the inactive `wps-case-u4` was skipped (`inactive`). A new case with two open cases of u1, one of u2 and one
closed of u3 went to u3 (`counts`: u1 = 2, u3 = 0). A case of u3 moved to priority P1 — its manager `wps-case-u1`
became the owner.

### Save errors

| Error | Field |
|---|---|
| `Record variable is required (e.g. object)`, `Variable "x" is not a CRM record` | `…target` |
| `Invalid field name` | `…field` |
| `Strategy must be one of: round_robin, least_busy, random, manager, user` | `…strategy` |
| `Pool is required: {"users": [...]} or {"security_group": ...}`, `At least one user source is required`, `Security group id is required` | `…pool…` |
| `Use {"path": ...} or {"value": ...}`, `Id must be a non-empty string` | id sources (`…pool.users[k]`, `…user`, `…manager_of`, `…exclude[k]`, `…fallback.user`) |
| `CRM module of the counted records is required (e.g. Cases)`, `Filter of "open" records is required for least_busy`, filter row errors | `…least_busy…` |
| `Invalid queue key` | `…queue_key` |
| `Fallback must be keep, error or user` | `…fallback` |
| `Must be true or false` | `…only_active`, `…save_to_crm` |

**Run-time errors:** `assign: pool is empty` and other reasons with `fallback: error`, `Security group "x" not found in
CRM`, `assign: pool is too large (N > 200)`, `Pool is too large for least_busy (N > 50)`.

## Find records

`find_records` ("Find records") finds CRM records and puts a **list** into a variable: open tasks of a lead, deals of
an account, contacts of a company. There are two modes:

- **by module** (`module`) — a search in a CRM module by a filter; filter, sort and limit run in the CRM;
- **by relationship** (`related_to`) — records linked to a record through a link field (`Accounts.contacts`,
  `Leads.calls`); the filter is applied on the WPS side.

```json
{ "type": "find_records", "var": "deals", "module": "Opportunities",
  "filter": [
    { "field": "account_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" },
    { "field": "sales_stage", "operator": "not_in", "value": ["Closed Won", "Closed Lost"] },
    { "field": "amount", "operator": "greater_or_equal", "value": "1000", "cast": "decimal" }
  ],
  "sort": [ { "field": "amount", "direction": "desc" } ], "fields": ["name", "amount"], "limit": 20 }

{ "type": "find_records", "var": "contacts", "related_to": { "record": "object", "link": "contacts" },
  "filter": [ { "field": "title", "operator": "equals", "value": "Decision Maker" } ] }
```

### Fields

| Field | Required | Description |
|---|---|---|
| `var` | yes | Name of the list variable (`deals`). Not allowed: `object`, `object_before`, `sys`, `event_info`, `config` and the name of another variable that is not a list. A repeated `find_records` into the same name overwrites the list |
| `module` | one of two | Module mode: the CRM module (`Tasks`, `Opportunities`) |
| `related_to` | one of two | Relationship mode: `{"record": "<record variable>", "link": "<link field>", "module": "<record module>"}`; `module` is needed only when the module of the variable is unknown |
| `filter` | no | A list of conditions in the format of process conditions: `field` (a field of the **found** records), `operator`, `value`, `value_type` (`static` or `from_object_field`), `cast`. All conditions are joined by **AND**. An empty filter — all records of the module (or relationship) |
| `sort` | no | Module mode only: up to 2 fields `[{"field": "date_due", "direction": "asc"}]` (`asc` by default, `desc`) |
| `fields` | no | Which fields to load (`id` is always added). Default — all fields. Narrow it: the list gets smaller and faster |
| `limit` | no | How many records to take, `1..FIND_RECORDS_MAX` (default 100) |
| `on_limit` | no | More records than `limit`: `truncate` (default — take the first ones, `has_more: true`) or `error` (the step fails) |
| `on_empty_value` | no | A `from_object_field` value is empty: `empty_result` (default — an empty list without a CRM request, with `reason` in the result), `ignore_condition` (the condition is dropped) or `error` |

**Filter operators in module mode** (the condition is sent to the CRM):

| Operator | In the CRM | Note |
|---|---|---|
| `equals` / `not_equals` | `eq` / `neq` | `not_equals` includes records with an empty field |
| `greater_than`, `greater_or_equal`, `less_than`, `less_or_equal` | `gt`, `gte`, `lt`, `lte` | `cast` is required: `int`, `decimal`, `date` or `datetime` |
| `between` | `gte` + `lte` | A pair of static bounds `[from, to]`, `cast` is needed; `not_between` is not available in module mode |
| `contains` | `like '%…%'` | `%`, `_` and `\` in the value are escaped — a literal substring is searched |
| `starts_with` / `ends_with` | `like '…%'` / `like '%…'` | A prefix / suffix, with the same escaping; an empty static value is not saved |
| `in` / `not_in` | `in` / `nin` | A list of 1..100 values (or a comma-separated string from a field). `not_in` includes records with an empty field; requires the plugin feature `filter_nin` |
| `is_empty` / `is_not_empty` | `null` / `notnull` | An empty string counts as empty too. No value needed |

`not_contains`, `not_between`, `is_null`, `is_not_null` and change operators (`changed`, …) are not available in module mode. Values
are cast by `cast` and sent in the CRM storage format: date — `Y-m-d`, datetime — UTC `Y-m-d H:i:s` (a literal without
a zone is read in `WPS_TIMEZONE`; a static value can be a [relative date](triggers-and-conditions.md#relative-dates):
`date_due less_than today` (`cast: datetime`) → `filter[date_due][lt]=<today 00:00 in WPS_TIMEZONE as UTC>`), decimal — with a dot (`1 000,5` → `1000.5`), boolean — `1`/`0`.

In **relationship mode** all operators of process conditions are available (including `not_contains`, `is_null`); the
filter is checked by the condition engine against each related record.

### The list variable

```json
{ "items": [ { "id": "…", "name": "Send the offer", "status": "In Progress", "date_due": "2026-11-01 09:00:00" }, … ],
  "count": 2, "has_more": true, "ids": ["…", "…"] }
```

- `items` — the found records (fields from `fields`), `count` — their number, `has_more` — the CRM has more matching
  records, `ids` — the list of ids (handy for `link_records`: `{"path": "deals.ids"}`).
- Readable in expressions and templates: `{{ deals.count }}`, `{{ deals.items.0.name }}`,
  `{{= if(deals.has_more, '+', '') }}`.
- The variable is **read-only**: `modify` and `save_object` on it are rejected when the process is saved
  (`Variable "deals" is a record list; use foreach to change its records`), and writing to it at run time is a step
  error. Change the same fields of all found records with [Update list records](#update-list-records), one by one — in a
  [`foreach` loop](#loop-for-each-item): `"source": "deals.items"`.

### How it works

1. **Module mode.** The filter is translated into a contract 2.0 request (`filter[f][op]=v`, `sort`, `fields`); records
   are read page by page with a cursor until `limit + 1` are collected (the extra record tells `has_more`).
2. **An empty value from a record** (`from_object_field` points to an empty field or a missing path) — by
   `on_empty_value`. By default there is no CRM request: an empty list,
   `reason: The value of filter[0] (object.account_id) is empty`.
3. **Relationship mode.** The record id is `record.id`; no `id` — an empty list with `reason`. The relationship is read
   page by page (up to `FIND_RECORDS_SCAN_MAX` records), the filter is applied to each record until `limit` is
   collected. The scan limit is reached and there are more records — `has_more: true` and the warning
   `Scanned 1000 related records (FIND_RECORDS_SCAN_MAX), more were not checked`.
4. `has_more` with `on_limit: error` → the error `find_records: more than 20 records match (on_limit=error)`.
5. A list larger than `FIND_RECORDS_MAX_BYTES` in JSON → the error
   `find_records: result is too large (1.4 MB); narrow "fields" or "limit"`.
6. The step only reads the CRM: no external effects, retried by the usual policy on 5xx and network failures.
7. CRM contract 2.0 is required: operators other than equality — `filter_operators`, sorting — `sort`, more than 100
   records — `cursor_pagination`, `not_in` — `filter_nin`, relationship mode — `relationships`. Without the feature the
   step fails with `CRM_CAPABILITY_MISSING` ([CRM integration](../04-operations/crm-integration.md)).

**Step result.** `request_data`: `var`, `limit`, `module` / `related_to`, `filter`, `sort`, `fields`, in module mode —
`crm_filter` (what was sent to the CRM, e.g. `{"filter": {"status": {"nin": ["Completed", "Deferred"]}}}`) and
`crm_requests`. `result_data`: `count`, `has_more`, the first 100 `ids`, `reason` (an empty result without a request),
`warnings`.

> **The `Leads.calls` link in SuiteCRM** means calls the lead is **invited** to (table `calls_leads`), not calls with the
> lead as `parent_id`. Find calls "by parent" in module mode: `Calls`, `parent_id equals object.id`. The same goes for
> `Leads.meetings`.

### Example: a lead summary

When a lead moves to the `In Process` status, the process finds the two nearest open tasks of the lead and the planned
calls the lead takes part in, and writes a summary into the lead description.

```json
{
  "object_type": "Leads",
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.status", "operator": "changed_to", "value_type": "static", "value": "In Process" } ],
    "actions": [
      { "type": "find_records", "var": "tasks", "module": "Tasks",
        "filter": [
          { "field": "parent_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" },
          { "field": "status", "operator": "not_in", "value": ["Completed", "Deferred"] }
        ],
        "sort": [ { "field": "date_due", "direction": "asc" } ], "fields": ["name", "status", "date_due"], "limit": 2 },
      { "type": "find_records", "var": "calls", "related_to": { "record": "object", "link": "calls" },
        "filter": [ { "field": "status", "operator": "equals", "value": "Planned" } ] },
      { "type": "modify", "target": "object.description", "change_type": "template",
        "value": "Open tasks: {{ tasks.count }}{{= if(tasks.has_more, '+', '') }}; nearest: {{ tasks.items.0.name }}; planned calls: {{ calls.count }}" },
      { "type": "save_object", "target": "object", "module": "Leads" }
    ]
  }
}
```

Result on SuiteCRM 8 (case `.tehnical/suiteCRM8/processes/cases/4.10-find-records.json`, with Russian texts): the lead
has 5 tasks (2 open in November, a completed one, a deferred one, an open one in December) and 3 calls (2 `Planned`,
1 `Held`). The CRM received `filter[status][nin][]=Completed&filter[status][nin][]=Deferred` sorted by `date_due`; the
lead description is "Open tasks: 2+; nearest: <the task due on 1 November>; planned calls: 2".

### Save errors

| Error | Field |
|---|---|
| `Variable name is required (letters, digits, "_"; e.g. deals)`, `Name "object" is reserved`, `Variable "x" is already defined` | `…var` |
| `Either "module" or "related_to" is required` | the step |
| `Invalid CRM module` | `…module` |
| `Record variable is required (e.g. account)`, `Variable "v7" is not defined at this point` | `…related_to.record` |
| `Link name is required (e.g. contacts)` | `…related_to.link` |
| `Must be a list of conditions`, `Condition must be an object` | `…filter`, `…filter[j]` |
| `Field name of the found records is required (e.g. sales_stage)` | `…filter[j].field` |
| `Unknown comparison operator`, `Operator "not_contains" is not supported in find_records filter`, `Operator "is_null" is not supported in find_records filter: use is_empty` | `…filter[j].operator` |
| `Operator "greater_than" requires cast: int, decimal, date or datetime` | `…filter[j].cast` |
| `Value "abc" is not an integer` (the literal does not cast by `cast`), `List of 1..100 values is required`, `Variable "v9" is not defined at this point` | `…filter[j].value` |
| `Must be "static" or "from_object_field"` | `…filter[j].value_type` |
| `At most 2 sort fields: …`, `Use {"field": "<name>", "direction": "asc" \| "desc"}`, `Sorting is not available for related records` | `…sort`, `…sort[k]` |
| `Must be an integer between 1 and 500` | `…limit` |
| `Must be a non-empty list of field names` | `…fields` |
| `Must be one of: truncate, error`, `Must be one of: empty_result, ignore_condition, error` | `…on_limit`, `…on_empty_value` |
| `Variable "deals" is a record list; use foreach to change its records` | `…target` of `modify` / `save_object` |

## Update list records

The `update_records` action changes **the same fields of every record** of a list found by
[Find records](#find-records) — in one step and one audit record. It is the counterpart of the AOW "Modify Record" of
related records: "a deal is lost → defer all its open tasks", "the account owner changed → all its contacts".

### Fields

```json
{ "type": "update_records", "id": "defer", "list": "tasks",
  "set": [
    { "field": "status", "change_type": "set_value", "value": "Deferred" },
    { "field": "description", "change_type": "template", "value": "{{ item.description }} Deferred." }
  ],
  "item_var": "item", "only_changed": true, "max": 200, "on_item_error": "continue" }
```

| Field | Required | Value |
|---|---|---|
| `list` | yes | A list variable of a Find records step (`tasks`); any other variable is a save error |
| `set` | yes | 1..20 rules `{field, change_type, …}`: the same change types as [`modify`](#modify-object--variable) (`set_value`, `set_from_variable`, `calculate`, `template`, `date_shift`) with their parameters. The value is computed for every record. `field` is a field name, not `id`, no duplicates |
| `item_var` | no | Name of the current record variable inside the rules, `item` by default: `item.status`, `{{ item.name }}`. The name must not be taken by another variable |
| `only_changed` | no | `true` (default) — only fields whose value differs from the record are sent; a record without changes is not sent to the CRM (`unchanged`). `false` — all rule fields are sent |
| `max` | no | Most records, `1..UPDATE_RECORDS_MAX` (200). More records in the list fail the step **before** any change: `update_records: list has 250 records, max is 200` |
| `on_item_error` | no | `continue` (default) — an error of one record does not stop the others; `stop` — after the first record error the rest are not processed |
| `module` | no | CRM module of the records when the list does not know it (usually not needed: Find records sets it) |

The action also takes the common step fields `id`, `name`, `on_error`, `retry`.

### How it works

1. **An empty list** — the step is `skipped` ("List "tasks" is empty — nothing to update"), no requests.
2. For every record: the `item_var` variable = the record (it exists only while the rules are computed), the rules are
   computed in order, with `only_changed` the differing fields remain, then a `PUT` of the record to the CRM. A record
   without `id` — `skipped_no_id`.
3. **Record errors** (a rule computation error, a 4xx answer, a missing record — 404) are collected: with `continue`
   the other records are processed. At the end the step is **`failed`** with `update_records: 1 of 4 records failed`,
   and the full summary (`updated`, `unchanged`, `errors`, `items`) is in `result_data.details`. Then the `on_error` of
   the step applies ([Step errors](#step-errors-on_error-and-retries)): `continue` — the execution goes on.
4. **Temporary errors** (CRM 5xx, network, timeout) repeat **the whole step** by the usual retry policy. This is safe: a
   repeated `PUT` of the same values changes nothing, and with `only_changed` already updated records become `unchanged`.
5. **The list in the context** gets the new values of the updated fields — later steps see them
   (`{{ tasks.items.0.status }}`). The list itself stays read-only and is not saved to the CRM.

**Step result.** `request_data`: `list`, `module`, `count`, `set`, `only_changed`, `max`. `result_data`: `updated`,
`unchanged`, `skipped_no_id`, `errors` and `items` — one entry per processed record (up to 200): `{id, status, fields,
error}`, where `status` is `updated`, `unchanged`, `skipped_no_id` or `error`.

### Action or loop

| Task | What to choose |
|---|---|
| The same fields for all records (status, owner, date, a template text from record fields) | `update_records`: one step, one audit record, unchanged records are not sent |
| Selecting records by a condition the Find records filter cannot express | [`foreach`](#loop-for-each-item) with `where`, or narrow the search filter |
| Several different actions per record: an e-mail, a link, a child process, a pause | `foreach` |
| A sum or a counter over records | `foreach` with a `set_variable` accumulator |
| Seeing the steps for every record in the journal | `foreach` (body records with `iteration`) |

### Example: a deal is lost → defer the open tasks

```json
{
  "object_type": "Opportunities",
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.sales_stage", "operator": "changed_to", "value_type": "static", "value": "Closed Lost" } ],
    "actions": [
      { "type": "find_records", "var": "tasks", "module": "Tasks",
        "filter": [
          { "field": "parent_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" },
          { "field": "status", "operator": "not_in", "value": ["Completed"] }
        ],
        "fields": ["name", "status", "description"] },
      { "id": "defer", "type": "update_records", "list": "tasks", "set": [
        { "field": "status", "change_type": "set_value", "value": "Deferred" },
        { "field": "description", "change_type": "calculate",
          "value": "if(item.status == 'Deferred', item.description, trim(concat(item.description, ' Deferred: the deal is lost.')))" }
      ] }
    ]
  }
}
```

Result on SuiteCRM 8 (case `.tehnical/suiteCRM8/processes/cases/4.6-update-records.json`, texts in Russian): the deal
has 4 tasks. "Prepare a quote" (`Not Started`, with a description) and "Call" (`In Progress`) became `Deferred` with the
appended note; "Wait for the budget" was already `Deferred` — `unchanged`, no request to the CRM; the completed
"Qualification" did not get into the list. The journal has 2 records for the whole execution, `updated = 2`,
`unchanged = 1`.

### Save errors

| Error | Field |
|---|---|
| `Record list variable is required (e.g. contacts)`, `Variable "x" is not defined at this point`, `Variable "x" is not a record list (use find_records)` | `…list` |
| `Variable name is required (letters, digits, "_"; e.g. item)`, `Variable "item" already exists; set "item_var"` | `…item_var` |
| `1..20 field rules are required: [{"field": ..., "change_type": ..., "value": ...}]` | `…set` |
| `Rule must be an object` | `…set[k]` |
| `Field name is required`, `Field "id" cannot be changed`, `Field "status" is set twice` | `…set[k].field` |
| `Change type must be one of: …`, errors of the change type parameters (template, expression, date shift) | `…set[k]…` |
| `Invalid CRM module` | `…module` |
| `Max must be between 1 and 200` | `…max` |
| `Must be one of: continue, stop` | `…on_item_error` |
| `Must be true or false` | `…only_changed` |

**Run-time errors:** `update_records: variable "x" is not a record list (use find_records)`,
`update_records: list has N records, max is M`, `update_records: CRM module of "x" is unknown (set "module")`,
`update_records: N of M records failed`.

## Loop (for each item)

The `foreach` block runs nested actions **for every item of a list**: records found by [Find records](#find-records),
related records, or values returned by an expression. It is the counterpart of the Bitrix24 "Iterator" and of changing
related records in AOW. Bulk operations need it: "change the responsible user of all open deals of the account", "an
e-mail to every meeting attendee", "a sum over the lines except cancelled ones".

### Format

```json
{ "type": "foreach", "id": "each_deal", "name": "Every open deal",
  "source": "deals.items", "as": "deal",
  "where": [ { "field": "deal.sales_stage", "operator": "not_in", "value_type": "static", "value": ["Closed Won", "Closed Lost"] } ],
  "max_items": 200, "on_overflow": "fail", "on_item_error": "stop",
  "actions": [
    { "type": "modify", "target": "deal.assigned_user_id", "change_type": "set_from_variable", "value": "object.assigned_user_id" },
    { "type": "save_object", "target": "deal" }
  ] }
```

| Field | Required | Value |
|---|---|---|
| `source` | yes | Path to a list: `deals.items` (the result of Find records) or any list field, **or** `{"expression": "…"}` — an expression that returns a list |
| `as` | yes | Name of the current item variable (variable name rules; not `object`, `object_before`, `sys`, `config`; not declared before) |
| `loop_var` | no | Name of the iteration data variable, `<as>_loop` by default |
| `module` | no | CRM module of the items — for `save_object` of an item. By default the module of the source variable (known for a Find records list) |
| `where` | no | Conditions in the [launch condition](triggers-and-conditions.md) format, checked for every item; false — the item is skipped |
| `max_items` | no | Most items, an integer `1..LOOP_MAX_ITEMS` (`LOOP_MAX_ITEMS` by default, 500) |
| `on_overflow` | no | `fail` (default) — more items than `max_items` → the `foreach` step fails; `truncate` — process the first `max_items` |
| `on_item_error` | no | `stop` (default) — an error in the body finishes the execution; `continue` — the iteration is cut short and the loop goes to the next item |
| `actions` | yes | The loop body; may contain blocks, another `foreach` included |

The block also takes the common step fields `id`, `name`, `on_error` (see [Nested blocks and step fields](#nested-blocks-and-step-fields)).

### Variables in the body

- `<as>` — a **copy** of the current item. A CRM record (an object) is copied as is; a scalar (string, number) becomes
  an object `{"value": …}`: `{{ tag.value }}`. The module of the variable is `module` or the module of the source.
- `<loop_var>` — `{"index": 0, "number": 1, "count": 12, "first": true, "last": false}`, **read-only**: `modify` /
  `set_variable` / `save_object` on it are rejected (`Variable "deal_loop" is read-only`).
- Variables created in the body (`modify` of a new variable, `set_variable`, Find records) **stay** between iterations
  and after the loop — that is how sums are counted. After the loop they may be undefined (the loop may run zero times):
  reading them gives the "may be undefined here" warning.
- After the loop `<as>` and `<loop_var>` are removed: using them after the block is a save error
  `Variable "deal" is not defined at this point`.

> **Changes of an item must be saved.** `modify deal.…` changes the copy, not the CRM record and not the item of the
> source list. To get a change to the CRM, the body needs `save_object` with `target: "deal"`. Iterations with unsaved
> changes are counted in `unsaved_items` of the loop totals — a frequent author mistake.

### How it works

1. **Source.** The path is not found or empty (`null`) — an empty list (`source_missing: true`), the loop is skipped. A
   value that is not a list (an object, a string) fails the step: `foreach: source "object" is not a list`.
2. **Limit.** More items than `max_items`: `fail` — error `foreach: 750 items exceed max_items (500)`; `truncate` — the
   first `max_items` are taken, the totals have `truncated: true`.
3. **Snapshot.** The items are saved once at the start of the loop to a separate Redis key; the loop then goes over the
   snapshot. Changes of the source variable in the body do not affect it; after a worker crash the loop continues from
   the same item over the same set.
4. **Every item:** `<as>` and `<loop_var>` are set, `where` is checked (false — the item is only counted in
   `skipped_by_where`, no audit records), the body runs.
5. **A step error in the body.** The `on_error` of the step itself acts first ([Step errors](#step-errors-on_error-and-retries)).
   Not handled:
   - `on_item_error: stop` — the execution is `failed`, the remaining steps of the iteration and the steps after the
     loop are `skipped`, the `foreach` totals record is `failed` with `failed_at_index`;
   - `on_item_error: continue` — the rest of the iteration is neither run nor recorded, the loop goes to the next item.
     The record of the failed step gets `handled = item_continue`, the error goes to `sys.last_error` (with an
     `iteration` field), the execution ends `completed` (with `handled_errors`), and the loop totals have
     `failed_items` and up to 50 `failed_indexes`.

   In nested loops the error is taken by the nearest enclosing loop with `continue`; the inner loops are closed.
6. **Leaving early.** `stop_process` in the body finishes the **whole** execution (loop totals with `stopped: true`);
   `on_error: {"goto": …}` leaves the loop for a top-level step (totals `failed`, `left_by: "goto"`). There is no
   `break` / `continue`: skip items with `where` or a `condition` in the body.
7. **A pause in the body.** A [Delay](#delay) up to `WAIT_INLINE_MAX_SECONDS` runs in place; a longer one makes the
   execution wait: the list snapshot moves to PostgreSQL with the state and is restored after the due time, the loop
   continues from the same item.
8. **`start_process` in the body** starts one child process per iteration.
9. The instruction limit of an execution (`EXECUTION_MAX_INSTRUCTIONS`, 5000) applies to loops too.

**Audit.** The `foreach` step has **one** record: at the start — `request_data = {source, as, max_items, on_overflow,
on_item_error, has_where}`, `result_data = {count, truncated, source_missing}`; when the loop ends, the same record is
overwritten with the totals: `count`, `processed`, `skipped_by_where`, `failed_items`, `failed_indexes`,
`unsaved_items`, `truncated`, `source_missing`, `where_traces` (the `where` trace of the first 20 skips), on failure
`failed_at_index`; `duration_ms` is the duration of the whole loop. Body steps are ordinary records with the iteration
number (`iteration`: `0`, `1`, …; `2/0` in nested loops). See [Executions and audit](executions-and-audit.md).

### Example: deals of a new customer

The account became a customer (`account_type` → `Customer`): every open deal of it gets a next step, the sum of the open
deals is accumulated in a process variable and written to the account.

```json
{
  "object_type": "Accounts",
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.account_type", "operator": "changed_to", "value_type": "static", "value": "Customer" } ],
    "actions": [
      { "type": "find_records", "var": "deals", "related_to": { "record": "object", "link": "opportunities" } },
      { "type": "set_variable", "var": "sum", "fields": [
        { "name": "total", "change_type": "set_value", "value": "0" },
        { "name": "n", "change_type": "set_value", "value": "0" } ] },
      { "id": "each_deal", "type": "foreach", "source": "deals.items", "as": "deal",
        "where": [ { "field": "deal.sales_stage", "operator": "not_in", "value_type": "static", "value": ["Closed Won", "Closed Lost"] } ],
        "actions": [
          { "type": "modify", "target": "deal.next_step", "change_type": "template", "value": "Customer confirmed: agree on the quote ({{ object.name | truncate:20 }})" },
          { "type": "save_object", "target": "deal" },
          { "type": "set_variable", "var": "sum", "fields": [
            { "name": "total", "change_type": "calculate", "value": "sum.total + deal.amount" },
            { "name": "n", "change_type": "calculate", "value": "sum.n + 1" } ] }
        ] },
      { "type": "modify", "target": "object.description", "change_type": "template", "value": "Open deals: {{ sum.n }} for {{ sum.total }}" },
      { "type": "save_object", "target": "object", "module": "Accounts" }
    ]
  }
}
```

Result on SuiteCRM 8 (case `.tehnical/suiteCRM8/processes/cases/4.11-foreach.json`, texts in Russian): the account has
3 deals — `Prospecting` for 1000, `Negotiation/Review` for 2500.50 and a won one for 4000. Loop totals —
`processed: 2`, `skipped_by_where: 1`, `unsaved_items: 0`; the two open deals got the next step in the CRM, the won one
is unchanged; the account description is "Open deals: 2 for 3500.5". `save_object deal` has no `module`: the module
`Opportunities` is known from the Find records list.

### Save errors

| Error | Field |
|---|---|
| `List source is required (e.g. deals.items)`, `Variable "v1" is not defined at this point` | `…source` |
| an expression parse error, `Expression is required` | `…source.expression` |
| `Variable name is required (letters, digits, "_"; e.g. item)`, `Name "sys" is reserved`, `Variable "deal" is already defined` | `…as`, `…loop_var` |
| `"loop_var" must differ from "as"` | `…loop_var` |
| `max_items must be an integer between 1 and 500` | `…max_items` |
| `Must be one of: fail, truncate`, `Must be one of: stop, continue` | `…on_overflow`, `…on_item_error` |
| `Invalid CRM module` | `…module` |
| `Must be an array or a condition group`, condition errors | `…where`, `…where[n]…` |
| `Must be an array` | `…actions` |
| `Variable "deal_loop" is read-only` | `…target` of a body step |

**Run-time errors:** `foreach: source "…" is not a list`, `foreach: N items exceed max_items (M)`,
`Loop items snapshot is lost; the execution cannot be resumed` (the Redis snapshot is gone — e.g. Redis was flushed
during the execution).

## Condition (if / else)

The `condition` block chooses which actions to run with the same conditions as [launch conditions](triggers-and-conditions.md):
one process with branches instead of several processes with different launch conditions and the same preload.

### Format

```json
{ "type": "condition", "id": "deal_size", "name": "Deal size",
  "branches": [
    { "name": "Over 1M",
      "conditions": [ { "field": "object.amount", "operator": "greater_than", "cast": "decimal", "value": "1000000" } ],
      "actions":    [ { "type": "modify", "target": "object.next_step", "change_type": "set_value", "value": "A" } ] },
    { "name": "Over 100K or VIP",
      "conditions": { "logical_operator": "OR", "conditions": [
        { "field": "object.amount", "operator": "greater_than", "cast": "decimal", "value": "100000" },
        { "field": "object.description", "operator": "contains", "value": "VIP" } ] },
      "actions":    [ { "type": "modify", "target": "object.next_step", "change_type": "set_value", "value": "B" } ] }
  ],
  "else": [ { "type": "modify", "target": "object.next_step", "change_type": "set_value", "value": "C" } ] }
```

| Field | Required | Description |
|---|---|---|
| `branches` | yes | 1–20 branches; several branches are "else if" |
| `branches[k].conditions` | no | Format of launch conditions: a flat list (AND) or a group tree `{logical_operator, conditions, groups}`; every operator and `cast` |
| `branches[k].actions` | no | Actions of the branch (may be empty, may contain nested blocks) |
| `branches[k].name` | no | Branch label for the audit, up to 255 characters |
| `else` | no | Actions when no branch matches |

### How it works

1. Branches are checked strictly in order; the **first** true one runs, the rest are not checked.
2. None matches → `else`; no `else` → the execution goes to the step after the block.
3. A branch **without conditions** (`"conditions": []` or no field) is always true — this is the default branch.
   If other branches or `else` follow it, saving returns a warning: they are unreachable.
4. A missing field in a branch condition is "false", as in launch conditions. A variable must be declared before the
   block: branch conditions see the variables known **before** the block (a variable from an earlier branch is not
   visible in the conditions of the next ones).
5. A cast error with `"on_cast_error": "error"` fails the `condition` step; its `on_error` applies (`continue` goes to
   the step after the whole block, no branch runs).
6. After a branch the control goes to the step after the block — there is no fall-through to the next branch.
7. Blocks are nested in each other and in branches up to `PROGRAM_MAX_DEPTH` levels (5 by default); they work in child
   processes too.

In the audit the block gives one `condition` record: the chosen branch and the trace of the checked branches
(see [Executions and audit](executions-and-audit.md#the-record-of-a-condition-block)). Steps of branches not chosen are
not written to the audit.

### Example: different actions for big and small deals

A process on new deals (`trigger_on: ["create"]`) — each deal goes through three branches:

| Deal | Branch | Result |
|---|---|---|
| 2,500,000 | 0 "Over 1M" | `next_step = "A: agree with the manager"`, task "Big deal for 2 500 000" |
| 5,000, "VIP" in the description | 1 "Over 100K or VIP" (OR group) | `next_step = "B: regular process"` |
| 5,000 | 2 "Others" (no conditions) | `next_step = "C: automatic handling"`, no task |

The full definition is in the [recipes](recipes.md#26-different-actions-for-big-and-small-deals).

### Save errors and warnings

| Situation | Message (field) |
|---|---|
| No branches | `At least one branch is required` (`…branches`) |
| More than 20 branches | `Too many branches (max 20)` (`…branches`) |
| A branch is not an object | `Branch must be an object` (`…branches[k]`) |
| `conditions` is neither a list nor a group | `Must be an array or a condition group` (`…branches[k].conditions`) |
| An error in a condition row | as for launch conditions, with the path `…branches[k].conditions[n].field` etc. |
| `actions` of a branch or `else` is not a list | `Must be an array` |
| Branch `name` is not a string or longer than 255 | `Branch name must be a string up to 255 characters` |
| A branch without conditions is not the last one | **warning** `Branch k has no conditions: the following branches are never reached` |
| A branch without conditions and `else` exists | **warning** `Branch k has no conditions: "else" is never reached` |

Warnings come in the `warnings` field of the response and do not block saving.

## Stop the process

The `stop_process` block ends the **whole** execution early — successfully or with an error and a message — from any
place: the top level, a branch of a [condition](#condition-if--else), a nested block. Handy for checks like "not enough
data — stop with a clear reason" and "the record is already processed — do nothing".

```json
{ "type": "stop_process", "status": "failed", "code": "NO_CONTACTS",
  "message": "Lead {{ object.first_name }} {{ object.last_name }} has no e-mail and no phone" }
```

| Field | Required | Description |
|---|---|---|
| `status` | no | `completed` (default) or `failed` |
| `message` | yes for `failed` | Text up to 1000 characters; a [template](change-types-and-expressions.md#template) (`{{ … }}`, a missing path gives an empty value) |
| `code` | no | Machine code `^[A-Z][A-Z0-9_]{0,63}$` — for filters, diagnostics and metrics |

### How it works

1. The step writes an audit record `stop_process` with status `success` (the step itself ran): `result_data` —
   `stopped: true`, `status`, `code`, `message` (the message after substitution).
2. `completed` — the execution finishes `completed`, `error_message` is empty; `failed` — the execution is `failed`,
   `error_message = "[CODE] message"` (without a code — just the message).
3. The remaining steps of the current list and of every enclosing list up to the top level get `skipped` with the
   reason `Not executed: the process was stopped by stop_process` — the same for both statuses.
4. The execution snapshot gets `context.stop = { status, code, message }`.
5. The whole execution stops, from inside a branch as well; loop frames are dropped.
6. [`on_error`](#step-errors-on_error-and-retries) does **not** catch `stop_process` — it is an intended end, not a step
   error; `on_error` on the step itself is forbidden.
7. Child processes already started (`start_process` above) keep running — they are independent.
8. A failed substitution in the message template does not prevent the stop: the raw `message` text is used and
   `message_error` is written to `result_data`.
9. The `info` log `Process stopped` (`execution_id`, `status`, `code`) and the metric `wps_process_stopped_total`
   (see [Monitoring](../04-operations/monitoring.md)).

### Example: do not process a lead without contacts

A process for new leads checks the contacts with a `condition` block; no contacts — the execution is `failed` with the
code `NO_CONTACTS`; a lead already in status Dead — a successful end without actions:

```json
{ "id": "check", "type": "condition", "branches": [
    { "name": "no contacts",
      "conditions": [ { "field": "object.email1", "operator": "is_empty" }, { "field": "object.phone_work", "operator": "is_empty" } ],
      "actions": [ { "type": "stop_process", "status": "failed", "code": "NO_CONTACTS",
                     "message": "Lead {{ object.first_name }} {{ object.last_name }} has no e-mail and no phone" } ] },
    { "name": "declined",
      "conditions": [ { "field": "object.status", "operator": "equals", "value": "Dead" } ],
      "actions": [ { "type": "stop_process", "status": "completed", "code": "LEAD_DEAD", "message": "The lead is Dead — nothing to do" } ] }
] }
```

| Lead | Outcome |
|---|---|
| no e-mail and no phone | `failed`, `error_message = "[NO_CONTACTS] Lead Oleg … has no e-mail and no phone"`, the next step `skipped` |
| status Dead, with a phone | `completed`, `context.stop.code = LEAD_DEAD`, nothing written to the CRM |
| with an e-mail | `completed`, every step ran |

The full definition is in the [recipes](recipes.md#27-do-not-process-a-lead-without-contacts-stop_process).

### Save errors and warnings

| Situation | Message (field) |
|---|---|
| `status` is not `completed` / `failed` | `Status must be "completed" or "failed"` (`…status`) |
| `status: failed` without `message` | `Message is required for status "failed"` (`…message`) |
| `message` is not a string or longer than 1000 | `Message must be a string up to 1000 characters` (`…message`) |
| A template error in `message` | the template error (`Unknown template filter "foo"`, `Variable "v9" is not defined at this point` …) |
| `code` does not match the pattern | `Code must match ^[A-Z][A-Z0-9_]{0,63}$` (`…code`) |
| `on_error` set on the step | `on_error is not allowed for stop_process` (`…on_error`) |
| Steps after `stop_process` in the same list | **warning** `Steps after stop_process in the same list are never executed` |

## Delay

The `delay` step pauses the execution for a period ("in 2 days") or until a moment ("30 days before the contract
ends"), then the next steps run. A short pause waits in the coroutine, a long one **parks** the execution: the worker is
released, the state is kept in PostgreSQL, and the wait survives a backend restart and a Redis loss
([Reliability → Waiting executions](../04-operations/reliability.md#waiting-executions)).

```json
{ "id": "wait", "type": "delay", "mode": "duration", "duration": "P2D" }
{ "id": "remind", "type": "delay", "mode": "until", "until": "object.end_date", "cast": "date", "time": "09:00", "offset": "-P30D" }
```

### Fields

| Field | Required | Description |
|---|---|---|
| `mode` | yes | `duration` (for a period) or `until` (until a moment) |
| `duration` | for `duration` — yes (or `duration_expression`) | ISO 8601 (`PT30M`, `P3D`, `P1M`) or a short form `30s`, `15m`, `2h`, `3d`, `1w`. `P1M`/`P1Y` are calendar months and years with end-of-month clipping (31 January + `P1M` = 28/29 February). A bare number is not accepted |
| `duration_expression` | no | An [expression](change-types-and-expressions.md) returning a duration in the same format, e.g. `concat(object.days, 'd')` |
| `until` | for `until` — yes (or `until_expression`) | A field path (`object.date_end`, `v1.end_date`) — CRM values are **UTC**; or a date literal `2026-12-31 09:00` — in `WPS_TIMEZONE` or the `timezone` field |
| `until_expression` | no | An expression returning a date |
| `cast` | no | `date` — take the date only (midnight), `datetime` — as is |
| `time` | no | `HH:MM` — time of day in `WPS_TIMEZONE`, applied after `cast` |
| `offset` | no | A signed shift in the same formats: `-P30D`, `-3d`, `PT10S` |
| `timezone` | no | Time zone of an `until` literal (`Europe/Warsaw`) |
| `if_past` | no | The moment has already passed: `continue` (default) — go on without waiting, `fail` — the step fails |
| `on_invalid` | no | The date cannot be resolved: `fail` (default) or `continue` — the step is `skipped` with `Delay skipped: …` |
| `max_wait` | no | The maximum pause for this step, at most `DELAY_MAX` (`P400D`) |

### How it works

1. The due time is computed when the step runs; `request_data` holds the settings, `due_at` (UTC) and `due_at_local`
   (in `WPS_TIMEZONE`).
2. The moment has passed: with `if_past: continue` the step is `success` with `result_data = { waited: false, past_due: true }`;
   with `fail` — error `delay: the moment … has already passed`.
3. A pause up to `WAIT_INLINE_MAX_SECONDS` (5 s) waits in the coroutine: `result_data = { waited: true, waited_seconds, inline: true }`.
4. A longer pause parks the execution: the step gets the `waiting` status, the execution becomes `waiting` with
   `waiting_until = due_at` and `wait_reason: delay`. Waiting executions are listed by `GET /api/executions?status=waiting`
   ([Executions and audit](executions-and-audit.md#waiting-executions)).
5. After the due time the execution wakes up (precision — `WAIT_POLL_INTERVAL_MS`, 1 s), the step becomes `success` with
   `result_data = { waited: true, lateness_ms, waited_seconds }`, and the next steps run.
6. `object` after the pause is the same snapshot from the event and may be outdated. If fresh data matters, re-read the
   record with a [preload](#preload-inside-actions-api-only) after the pause.
7. Deactivating the process does not stop waiting executions. Deleting a process with waiting executions is rejected
   (`409 PROCESS_HAS_WAITING_EXECUTIONS`); `DELETE /api/processes/{id}?force=true` cancels them (status `cancelled`).

### Example: a call after a pause

Case `.tehnical/suiteCRM8/processes/cases/4.7-delay.json` (verified on SuiteCRM 8): a short `2s` pause runs in the
coroutine, a pause until "creation date −1 day" has already passed (`past_due: true`), a pause until "creation date + 10
seconds" parks the execution, and after the wake-up a call "Перезвонить {{ object.first_name }}" is created.

```json
"actions": [
  { "id": "short", "type": "delay", "mode": "duration", "duration": "2s" },
  { "id": "past", "type": "delay", "mode": "until", "until": "object.date_entered", "offset": "-P1D" },
  { "id": "until_created", "type": "delay", "mode": "until", "until": "object.date_entered", "offset": "PT10S" },
  { "type": "modify", "target": "call.name", "change_type": "template", "value": "Перезвонить {{ object.first_name }}" },
  { "type": "save_object", "target": "call", "module": "Calls" }
]
```

Ready-made processes are in recipes [28](recipes.md#28-remind-30-days-before-the-contract-ends-delay) and
[29](recipes.md#29-call-back-in-2-days-delay).

### Save errors

| Situation | Message (field) |
|---|---|
| `mode` missing or wrong | `Delay mode must be one of: duration, until` (`…mode`) |
| Neither `duration` nor `duration_expression` | `Duration is required` |
| Both are set | `Use either duration or duration_expression` |
| Invalid duration | `Invalid duration "3x" (use ISO 8601 like P3D or 3d, 2h, 30m)` (`…duration`) |
| Duration above the limit | `Delay exceeds the limit P400D` (`…duration`) |
| No `until` / `until_expression` | `Date is required` (`…until`) |
| Invalid date literal | `Invalid date "31.02.2026"` (`…until`) |
| Invalid offset | `Invalid offset "-3x"` (`…offset`) |
| Invalid time | `Time must be HH:MM` (`…time`) |
| Invalid `cast` | `Cast must be "date" or "datetime"` (`…cast`) |
| Unknown time zone | `Unknown timezone "…"` (`…timezone`) |
| `if_past` / `on_invalid` not in the list | `Must be "continue" or "fail"` |
| `max_wait` above `DELAY_MAX` | `Must not exceed P400D` (`…max_wait`) |

### Runtime errors

| Situation | `error_message` |
|---|---|
| The date field is empty or not a date | `delay: cannot resolve a date from object.date_end (value "")` |
| The due time is beyond the limit | `delay: wait of 500 days exceeds the limit P400D` |
| The moment has passed with `if_past: fail` | `delay: the moment 2026-01-01T00:00:00+00:00 has already passed` |
| The expression returned an invalid duration | `delay: invalid duration "soon"` |
| The execution snapshot is too large to park | `Execution state is too large to wait (N bytes, limit M)` |

Date errors with `on_invalid: continue` do not stop the execution: the step is `skipped` with `Delay skipped: …`.

## Nested blocks and step fields

Besides actions, the `actions` list may contain **blocks** — control-flow constructs with nested action lists. The
[`condition`](#condition-if--else), [`foreach`](#loop-for-each-item) and [`stop_process`](#stop-the-process) blocks are available now; blocks are set through the API, the editor keeps them
unchanged. Blocks can be nested up to `PROGRAM_MAX_DEPTH` levels (5 by default).

Any step (action or block) on any level has optional fields:

| Field | Format | Purpose |
|---|---|---|
| `id` | `^[a-z][a-z0-9_]{0,63}$`, unique in the process (nested blocks included) | Reference to the step (jump on error) and a readable audit (`step_id`) |
| `name` | string up to 255 characters | Step label for the audit and the UI |
| `on_error` | `"stop"`, `"continue"`, `{ "goto": "<id>" }` | What to do when the step fails (see [Step errors](#step-errors-on_error-and-retries)) |
| `retry` | object | Retry profile of the step (same section) |

**Variable visibility.** A variable created in **every** branch of a block with `else` is declared after the block.
A variable created only in some branches (or in a block without `else`) can still be read after the block, but saving
the process returns a warning in the `warnings` field of the response:

```json
"warnings": [ { "field": "definition.actions[1].value", "message": "Variable \"task\" may be undefined here: it is set only in some branches" } ]
```

The warning does not block saving: at run time such a variable behaves like a not-found one (a condition on it is
false, an expression gets an empty value, `save_object` is `skipped`).

Save errors: `Step id must match ^[a-z][a-z0-9_]{0,63}$`, `Step id "x" is already used at …`,
`Step name must be a string up to 255 characters`, `Nesting is too deep (max 5)`, `Unknown action type` (for an
unknown type in a nested list); errors of the block itself are in [Condition](#save-errors-and-warnings).

## Step errors: on_error and retries

When a step fails, the engine first **retries** it according to the `retry` profile; if the retries do not help, it
follows the `on_error` **policy**. Both fields can be set on any step in `definition.actions` (actions and `condition`
blocks, at any nesting level). `on_error` is not supported in the `preload` block: errors of lazy preload are handled
before the execution starts.

```json
{ "id": "sync_user", "type": "save_object", "target": "usr", "module": "Users",
  "on_error": { "goto": "manual_check" },
  "retry": { "max_attempts": 3, "base_delay": "1s", "max_delay": "10m", "backoff": 2,
             "jitter": "full", "retry_on": ["5xx"], "respect_retry_after": true } }
```

### The `on_error` policy

| Value | Behaviour |
|---|---|
| `"stop"` (default) | As before: the step is `failed`, the remaining steps are `skipped`, the execution is `failed` |
| `"continue"` | The step is `failed` and marked `handled: "continue"`, the execution goes on with the next step. For a failed `condition` block (an error in branch conditions) — with the step **after the whole block**: no branch runs |
| `{ "goto": "<id>" }` | The step is `failed` with `handled: "goto"`, the execution jumps to the step with this `id` |

`goto` restrictions (checked on save):

- the target is a step with an `id` **at the top level** of `definition.actions`;
- the target comes **after** the top-level step that contains the failing one (forward only, so `goto` cannot make a
  loop);
- a jump from a `condition` branch leaves the branch (and all enclosing blocks); the steps between the error and the
  target do not run and **are not written to the audit** — like branches that were not chosen.

If all errors were handled (`continue` / `goto`) and the rest succeeded, the execution is `completed` and
`handled_errors` holds the number of handled errors. There is no separate "completed with errors" status. There is no
process-level error handler either: a `goto` to a handler step plays its role.

### The `retry` profile

Without `retry` the global `ACTION_RETRY_*` settings apply (see [Configuration](../04-operations/configuration.md)).

| Field | Values | Default | Meaning |
|---|---|---|---|
| `max_attempts` | integer 1–20 | `ACTION_RETRY_MAX + 1` (4) | Attempts in total, the first one included; `1` — no retries |
| `base_delay` | duration | `ACTION_RETRY_BASE_DELAY_MS` (1 s) | Delay before the 2nd attempt |
| `backoff` | number 1–10 | `ACTION_RETRY_BACKOFF` (2) | Delay multiplier |
| `max_delay` | duration, at most `P7D`, at least `base_delay` | `ACTION_RETRY_MAX_DELAY` (`1h`) | Upper bound of one delay. A delay longer than `WAIT_INLINE_MAX_SECONDS` (5 s) parks the execution (`waiting`, `wait_reason: retry`) |
| `jitter` | `none`, `full`, `equal` | `ACTION_RETRY_JITTER` (`full`) | Random spread of the delay (see [Reliability](../04-operations/reliability.md#retries-of-a-step)) |
| `retry_on` | non-empty list of classes | `["transient"]` | Which errors are retried |
| `respect_retry_after` | `true` / `false` | `true` | Honour the `Retry-After` response header (capped by `max_delay`) |

**Durations**: `500ms`, `2s`, `10m`, `1h`, `1d`, `1w`, combinations (`1h30m`), ISO 8601 (`PT5M`, `P7D`; months and
years are not allowed) or an integer number of milliseconds.

**`retry_on` classes** (dictionary `GET /api/dictionaries/retry-classes`):

| Class | What is retried |
|---|---|
| `transient` | Temporary errors: network, timeout, HTTP `5xx`, `408`, `425`, `429`, Redis, lost DB connection |
| `5xx` / `4xx` | Any HTTP response of this range |
| `"409"` and other 3-digit codes | A response with this code |
| `any` | Any error |

**Never retried**, whatever `retry_on` says: configuration errors (`CONFIG`: invalid module, unknown function …),
`CRM_CAPABILITY_MISSING` (the CRM plugin lacks a feature) and `UNKNOWN_OUTCOME` (unknown outcome of a side effect with
the `at_most_once` policy, see [Reliability](../04-operations/reliability.md)). `on_error` still applies to them.

### The `sys` variable: the last error

After a handled error (`continue` / `goto`) expressions and templates can read the system variable `sys` (read-only,
the name is reserved):

| Path | Value |
|---|---|
| `sys.last_error.step_id` | `id` of the failed step (or empty) |
| `sys.last_error.step_path` | path of the step in the definition (`actions[1]`) |
| `sys.last_error.action_type` | step type |
| `sys.last_error.code` | error code: `HTTP_405`, `HTTP_503`, `TIMEOUT`, `TRANSPORT`, `CONFIG`, `UNKNOWN_OUTCOME` … |
| `sys.last_error.message` | error message (secrets masked) |
| `sys.last_error.http_status` | HTTP status, if any |
| `sys.last_error.attempts` | how many attempts were made |
| `sys.last_error.at` | time (ISO 8601, UTC) |
| `sys.errors_count` | number of handled errors in the execution |

`sys.last_error` is overwritten by the next handled error and is not reset by successful steps. Before the first
handled error the paths are not found (`''` in an expression, empty in a template).

### Example: the save failed → a "check manually" task

A process on leads updates a CRM user (the `Users` module is read-only in the plugin API — response `405`). A `4xx`
error is not in `retry_on: ["5xx"]` and is not retried; `goto` leads to creating a task with the error code and text,
and the second identical error is handled with `continue`:

```json
{ "trigger_on": ["create"],
  "definition": { "actions": [
    { "type": "modify", "target": "usr.description", "change_type": "set_value", "value": "lead" },
    { "id": "sync_user", "type": "save_object", "target": "usr", "module": "Users",
      "on_error": { "goto": "manual_check" }, "retry": { "max_attempts": 3, "base_delay": "1s", "retry_on": ["5xx"] } },
    { "type": "modify", "target": "object.description", "change_type": "set_value", "value": "never runs", "save_to_crm": true },
    { "id": "manual_check", "type": "modify", "target": "task.name", "change_type": "template",
      "value": "Check manually: {{ sys.last_error.code }} at step {{ sys.last_error.step_id }}" },
    { "type": "modify", "target": "task.description", "change_type": "template",
      "value": "{{ sys.last_error.message | truncate:200 }} (attempts: {{ sys.last_error.attempts }})" },
    { "type": "modify", "target": "task.parent_type", "change_type": "set_value", "value": "Leads" },
    { "type": "modify", "target": "task.parent_id", "change_type": "set_from_variable", "value": "object.id" },
    { "type": "save_object", "target": "task", "module": "Tasks" },
    { "type": "save_object", "target": "usr", "module": "Users", "on_error": "continue", "retry": { "max_attempts": 1 } },
    { "type": "modify", "target": "object.description", "change_type": "template",
      "value": "Errors handled: {{ sys.errors_count }}", "save_to_crm": true } ] } }
```

Result: the execution is `completed`, `handled_errors = 2`; step `sync_user` is `failed`, `error_code: HTTP_405`,
`attempts: 1`, `handled: goto`; the third step did not run; the CRM has the task "Check manually: HTTP_405 at step
sync_user" and the lead description "Errors handled: 2".

### Save errors

| Error | Field |
|---|---|
| `Must be "stop", "continue" or {"goto": "<step id>"}` | `…on_error` |
| `Step "x" not found` | `…on_error.goto` |
| `Step "x" must be a top-level step after the failing step` | `…on_error.goto` (nested or earlier target) |
| `Not supported in the preload block` | `definition.preload[i].on_error` |
| `Must be an object`, `Unknown retry option` | `…retry`, `…retry.<field>` |
| `Must be an integer between 1 and 20` | `…retry.max_attempts` |
| `Invalid duration (e.g. 2s, 10m, 1h; at most 7 days)`, `Must be greater than or equal to base_delay` | `…retry.base_delay`, `…retry.max_delay` |
| `Must be a number between 1 and 10` | `…retry.backoff` |
| `Must be one of: none, full, equal` | `…retry.jitter` |
| `Must be a non-empty list`, `Unknown retry class "6xx"` | `…retry.retry_on`, `…retry.retry_on[i]` |
| `Must be true or false` | `…retry.respect_retry_after` |

## Credentials of external systems

Actions that call external systems get passwords and tokens through a reference `secret://name` to a secret the
administrator created; the value gets neither into the definition nor into the audit. Details — [Secrets](secrets.md).

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
