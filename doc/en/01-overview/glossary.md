# Glossary

Terms used across the WPS documentation and UI, with their Russian equivalents and the names used in code.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/01-overview/glossary.md)
<!-- /lang-switch -->

The documentation uses these terms consistently in both languages. Technical names in the "In code" column (fields,
tables, API values) are never translated.

## Process and event

| Term (EN) | Term (RU) | In code | Definition |
|---|---|---|---|
| process | процесс | `processes` | A configured automation rule: object type, event type, conditions, preload, actions and constants. Stored as a JSON `definition`. |
| process definition | определение процесса | `definition` | JSON with the keys `conditions`, `preload`, `actions`, `constants`. Stored in PostgreSQL as `jsonb`, so the key order of objects is not kept — ordered data is written as lists. |
| process version | версия процесса | `version` | Save counter of a process. An execution remembers the version it started with. |
| top-level process | процесс верхнего уровня | `is_child: false` | A process started directly by CRM events of its object type. |
| child process | ведомый процесс | `is_child: true` | A process started only by the `start_process` action of another process. Never matched to stream events directly. |
| object type (CRM module) | тип объекта (модуль CRM) | `object_type` | The CRM module a process reacts to, e.g. `Deals`, `Accounts`, `Leads`. Case-sensitive. |
| priority | приоритет | `priority` | Order of the processes of one object type for one event: a lower value runs first. |
| event | событие | stream message | A message in the Redis Stream `crm_events` saying that a CRM record was created, updated or deleted. Carries the record fields and the service fields of contract 2.0. |
| event type | тип события | `event_type`: `create` / `update` / `delete` | What happened to the record. An event of an old plugin without a type is `create` if `wps_is_update = "0"`, otherwise `update`. |
| run on | запускать при | `trigger_on` | The list of event types a process reacts to. Default `["create", "update"]`; `[]` — manual runs only. Set through the API. |
| contract 2.0 | контракт 2.0 | `contract_version` | The WPS ↔ CRM plugin exchange format: event type, `before`, `changed_fields`, `wps_origin`, filters and relationships in the `/api/v9` API. |
| CRM capabilities | возможности CRM | `crm-capabilities` | What the installed CRM plugin supports (the `nin` filter, relationships, counts…). An action that lacks a capability fails with `CRM_CAPABILITY_MISSING`. |
| event object / source object | объект события / исходный объект | `object` | The record from the event. Available in a process as the reserved variable `object` (`object.status`). |
| values before (old values) | значения до (старые значения) | `object_before` | Old values of the **changed** fields from a contract 2.0 event. Read-only; a field that did not change is absent; `null` — the old values are unknown. |
| changed fields | изменённые поля | `changed_fields`, `sys.changed_fields` | Fields changed by the save, as reported by the CRM plugin. `null` — unknown, `[]` — the save changed nothing. |
| event info | сведения о событии | `event_info` | Event type, changed fields, whether `before` is present, the origin (`wps_origin`) and the old-format flag. Kept in the execution context, not in `object`. |
| origin | происхождение изменения | `wps_origin`, `X-WPS-*` headers | Marks an event if the record was saved by WPS itself: execution id, process id and depth. |

## Conditions

| Term (EN) | Term (RU) | In code | Definition |
|---|---|---|---|
| condition | условие | `conditions[]` | A "field — operator — value" row that must be true for the process to start. Rows of a list are combined with AND. |
| condition group | группа условий | `{logical_operator, conditions, groups}` | The advanced form of conditions: AND / OR and nested groups. Set through the API. |
| comparison operator | оператор сравнения | `operator` | How a condition compares: `equals`, `greater_than`, `between`, `in`, `contains_any`, `is_empty`, `changed_to` and others (25 in total). |
| compare with (value type) | зона сравнения (вид правой части) | `value_type`: `static` / `from_object_field` / `expression` | What the field is compared with: a value, another field or the result of an expression. |
| cast | приведение типа | `cast`, `cast_options` | How values are interpreted before the comparison: `string`, `int`, `decimal`, `bool`, `date`, `datetime`, `list`, with options (case, spaces, format). |
| strict cast | строгий режим приведения | `on_cast_error: "error"` | A value that cannot be cast fails the check instead of making the row false. |
| relative date | относительная дата | `today+30d`, `now-24h`, `start_of_quarter` | A date computed from the current moment in `WPS_TIMEZONE`. No business days. |
| lazy preload | ленивая предзагрузка | | The conditions on `object.*` are checked first; preload runs only when they hold and there are conditions on variables. |
| condition trace | трасса условий | `ConditionTrace`, `context.conditions` | The result of every row: actual and expected value, cast values, `result`. |

## Actions and data

| Term (EN) | Term (RU) | In code | Definition |
|---|---|---|---|
| action (step) | действие (шаг) | `actions[]`, `type` | An element of the process program: `modify`, `save_object`, `send_email`, `foreach` … Run in order. |
| block | блок | `condition`, `foreach`, `stop_process` | An action with nested action lists or one that controls the flow of the execution. |
| preload | предзагрузка | `preload` | Loading a CRM record into a variable — by `id` or by another field. |
| variable | переменная | `v1`, `task`, `deals` | A named value in the process memory. Created by preload, by `modify` into a new name, by `set_variable`, `find_records`, `http_request` or a loop. |
| list variable | переменная-список | `{items, count, has_more, ids}` | The result of `find_records`: the found records. Read-only. |
| modify (change) | изменение | `modify` | An action that sets a field of the object or of a variable. The CRM is not touched until a save is requested. |
| change type | тип изменения | `change_type` | How `modify` computes the new value: `set_value`, `set_from_variable`, `calculate`, `template`, `date_shift`, `increment`, `decrement`, `clear`, `append`, `prepend`, `remove`, `map`, `aggregate`. |
| expression | выражение | `calculate`, `value_type: expression`, `{{= … }}` | A formula of the WPS expression language, e.g. `round(object.amount * 0.9, 2)`. |
| template | шаблон | `template`, `{{ … }}` | Text with substitutions `{{ object.name }}`, filters `{{ x \| default:'—' }}` and expressions `{{= … }}`. Used in e-mails, notifications, HTTP requests. |
| system variables | системные переменные | `sys.*` | A read-only virtual variable: `sys.execution_id`, `sys.process_name`, `sys.trigger`, `sys.now`, `sys.changed_fields`, `sys.last_error` … |
| process constants | константы процесса | `definition.constants`, `config.*` | Values declared once in the definition and read as `config.<name>`. Read-only. |
| save to CRM | сохранение в CRM | `save_object`, `save_to_crm` | Sending the changed fields of a variable to the CRM (PUT; POST for a new record). |
| start next process | запуск следующего процесса | `start_process` | An action that starts child processes asynchronously and passes them the event and all variables. |
| secret | секрет | `secret://name` | Credentials of an external system (SMTP, token, webhook) encrypted in WPS. A process stores only the reference. |
| notification channel | канал уведомления | `crm_alert`, `telegram`, `slack`, `teams` | Where `notify` delivers a message. |
| assign strategy | стратегия назначения | `round_robin`, `least_busy`, `random`, `manager`, `user` | How `assign` picks an owner from a user pool. |

## Execution and audit

| Term (EN) | Term (RU) | In code | Definition |
|---|---|---|---|
| execution (run) | выполнение (запуск) | `executions` | One run of one process for one event. Statuses: `running`, `retrying`, `waiting`, `completed`, `failed`, `cancelled`. |
| waiting execution | ожидающее выполнение | `status: waiting`, `execution_waits` | An execution paused by a `delay` or a long retry delay until `waiting_until`. Stored in PostgreSQL; the worker is free. |
| cancelled execution | отменённое выполнение | `status: cancelled` | A waiting execution cancelled by deleting the process with `force=true`. |
| step (audit record) | шаг аудита | `execution_actions` | A record about one action inside an execution: request, result, attempts, duration, status. Statuses: `success`, `failed`, `skipped`, `waiting`. |
| process program | программа процесса | `pc`, `step_path` | The definition compiled into a flat list of instructions. `pc` — the instruction number, `step_path` — the place of the step in the definition (`actions[1].branches[0].actions[0]`). |
| iteration | итерация | `iteration` | Zero-based item number of a `foreach` loop; in nested loops — `"2/0"`. |
| trigger (source of a run) | источник запуска | `trigger`: `stream` / `manual` / `child` | What started the execution: a CRM event, a manual run or a parent process. Not to be confused with `trigger_on`. |
| manual run | ручной запуск | `POST /api/processes/{id}/run` | Running a process with an entered JSON object, optionally without the condition check (`force`). |
| run once per record | однократный запуск для записи | `run_once` | Process flag: the process runs at most once per CRM record (`object_type` + `id`). |
| run-once mark | отметка однократного запуска | `process_object_runs` | A "process P already ran for record X" entry. Blocks further runs until it is reset. |
| retry | повтор | `retry`, `retry_count`, `attempts` | Re-running a failed step after a recoverable error, with exponential backoff. The profile is set on the step (`max_attempts`, `base_delay`, `max_delay`, `retry_on`). |
| error policy | политика ошибки шага | `on_error`: `stop` / `continue` / `goto` | What to do when a step fails: stop the execution, continue or jump to the step with an `id`. Handled errors are counted in `handled_errors`. |
| recoverable / non-recoverable error | восстановимая / невосстановимая ошибка | `RecoverableException` / `NonRecoverableException` | A temporary failure (timeout, 5xx, 429) that is retried / a permanent one (4xx, configuration error) that fails the step at once. |
| side effect | побочный эффект | `step_side_effects` | An external call of a step: an e-mail, an HTTP request, a notification. Journaled so that a repeated step does not send it twice. |
| delivery policy | политика доставки | `delivery`: `at_most_once` / `at_least_once` | What to do when the outcome of an external call is unknown: do not repeat (`UNKNOWN_OUTCOME`) or repeat. |
| idempotency key | ключ идемпотентности | `Idempotency-Key`, `idempotency_key` | A stable key `{execution_id}:{step position}` by which an external system recognises a repeated request. |
| dead-letter queue (DLQ) | очередь недоставленных сообщений | `crm_events:dlq` | A stream that receives malformed events and events that failed too many times. |
| audit | аудит | | The stored history of executions and steps, shown by the execution log and the execution page. |
| execution context | контекст выполнения | `context` | A snapshot of the event, variables, condition trace and `sys.*` of an execution; while it runs — the live context from Redis. |

## UI and access

| Term (EN) | Term (RU) | In code | Definition |
|---|---|---|---|
| role | роль | `admin` / `editor` / `viewer` | Administrator, Editor, Viewer — a set of allowed operations. |
| process card | карточка процесса | `/processes/{id}` | The process page: properties, conditions, preload, actions, execution log, run-once records. |
| process editor | редактор процесса | `/processes/{id}/edit` | The form with the "Process configuration", "Block 1. Trigger conditions", "Block 2. Actions" blocks and the "Variables" panel. |
| read-only (passthrough) row | нередактируемая строка | `passthrough` | An action the editor cannot edit (e.g. `send_email`): shown as a grey row and saved unchanged. |

<!-- footer-nav -->
---

← Previous: [Overview](index.md) | [Contents](../README.md) | [Requirements](../02-getting-started/requirements.md) Next →
<!-- /footer-nav -->
