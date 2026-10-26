# Glossary

Terms used across the WPS documentation and UI, with their Russian equivalents and the names used in code.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/01-overview/glossary.md)
<!-- /lang-switch -->

The documentation uses these terms consistently in both languages. Technical names in the last column (fields, tables,
API values) are never translated.

| Term (EN) | Term (RU) | In code | Definition |
|---|---|---|---|
| process | процесс | `processes` | A configured automation rule: object type, conditions, preload and actions, stored as JSON `definition`. |
| top-level process | процесс верхнего уровня | `is_child: false` | A process started directly by CRM events of its object type. |
| child process | ведомый процесс | `is_child: true` | A process started only by the `start_process` action of another process; it is never matched to stream events directly. |
| object type (CRM module) | тип объекта (модуль CRM) | `object_type` | The CRM module a process reacts to, e.g. `Deals`, `Accounts`, `Leads`. |
| event | событие | stream message | A message in the Redis Stream `crm_events` saying that a CRM object was saved; it carries the object fields. |
| event object / source object | объект события / исходный объект | `object` | The object from the event, available in a process as the reserved variable `object` (`object.status`). |
| values before (old values) | значения до (старые значения) | `object_before` | Old values of the **changed** fields of a contract 2.0 event. A read-only variable; a field that did not change is absent; `null` — old values are unknown (an old-format event). |
| changed fields | изменённые поля | `changed_fields`, `event_info.changed_fields` | Fields changed by the save, as reported by the CRM plugin. `null` — unknown, `[]` — the save changed nothing. |
| event info | сведения о событии | `event_info`, `EventInfo` | The event type (`create` / `update` / `delete`), changed fields, whether `before` came, the origin (`wps_origin`) and the old-format flag (`legacy`). Kept in the execution context, not in `object`. |
| condition | условие | `conditions[]` | A row "field — operator — value" that must be true for the process to start; all rows are combined with AND. |
| operator (comparison) | оператор сравнения | `operator` | How a condition compares values: `equals`, `not_equals`, `contains`, `not_contains`. |
| preload | предзагрузка | `preload` | Loading an object from the CRM into a variable, by `id` or by another field. |
| variable | переменная | `v1`, `task` | A named object in the process memory, created by preload or by a `modify` that writes to a new name. |
| action | действие | `actions[]` | One step of a process: `preload`, `modify`, `save_object` or `start_process`; actions run sequentially. |
| modify (change) | изменение | `modify` | An action that sets a field of the object or of a variable; the CRM is not touched unless saving is requested. |
| change type | тип изменения | `change_type` | How a `modify` computes the new value: `set_value`, `set_from_variable`, `calculate`. |
| expression | выражение | `calculate` | A formula for the `calculate` change type, e.g. `round(object.amount * 0.9, 2)`. |
| save to CRM | сохранение в CRM | `save_object`, `save_to_crm` | Sending the changed fields of a variable to the CRM (PUT, or POST for a new object). |
| start next process | запуск следующего процесса | `start_process` | An action that asynchronously starts one or more child processes and passes them the event and all variables. |
| execution (run) | выполнение (запуск) | `executions` | One run of one process for one event, with status `running`, `retrying`, `completed` or `failed`. |
| step | шаг | `execution_actions` | The audit record of one action within an execution: request, result, attempts, duration, status. |
| trigger (source of a run) | источник запуска | `trigger`: `stream` / `manual` / `child` | What started an execution: a CRM event, a manual run from the UI, or a parent process. |
| manual run | ручной запуск | `POST /api/processes/{id}/run` | Running a process from the UI with a JSON object you enter, optionally skipping the conditions (`force`). |
| run once per record | однократный запуск для записи | `run_once` | A process flag: the process runs at most once for each CRM record (`object_type` + `id`). |
| run-once mark | отметка однократного запуска | `process_object_runs` | The record "process P has already run for record X"; it blocks further runs until it is reset. |
| retry | повтор | `retry_count` | Repeating a failed step after a recoverable error, with exponential backoff. |
| recoverable / non-recoverable error | восстановимая / невосстановимая ошибка | `RecoverableException` / `NonRecoverableException` | A temporary failure (timeout, CRM 5xx) that is retried / a permanent one (CRM 4xx, bad configuration) that fails the execution at once. |
| dead-letter queue | очередь недоставленных сообщений (DLQ) | `crm_events:dlq` | A stream where invalid events and events that failed delivery too many times are moved. |
| priority | приоритет | `priority` | The order of processes of one object type for the same event: a lower value runs earlier. |
| audit | аудит | | The stored history of executions and steps, shown in the execution log and on the execution page. |

<!-- footer-nav -->
---

← Previous: [Overview](index.md) | [Contents](../README.md) | [Requirements](../02-getting-started/requirements.md) Next →
<!-- /footer-nav -->
