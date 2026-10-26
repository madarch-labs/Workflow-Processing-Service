# Monitoring

Health check, Prometheus metrics, logs and the Redis commands that show the state of the event stream.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/04-operations/monitoring.md)
<!-- /lang-switch -->

## Health check: `GET /api/health`

Public (no token). Checks PostgreSQL (`SELECT 1`), the data Redis and the stream Redis (`PING`) and reports the consumer group.

```bash
curl -s http://localhost:9501/api/health
```

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "checks": {
      "postgres":     { "status": "ok", "latency_ms": 0 },
      "redis_data":   { "status": "ok", "latency_ms": 0 },
      "redis_stream": { "status": "ok", "latency_ms": 0 }
    },
    "stream": { "stream": "crm_events", "group": "wps_workers", "length": 15, "lag": 0, "pending": 0, "consumers": 2 },
    "time": "2026-10-15T08:03:03+00:00"
  },
  "error": null
}
```

| Field | Meaning |
|---|---|
| `status` | `ok` — all checks passed, HTTP `200`. `degraded` — at least one check has `status: "error"` (with an `error` message), HTTP **`503`** |
| `stream.length` | Messages in the stream (`XLEN`). WPS does not trim the stream; the publisher should use `MAXLEN` |
| `stream.lag` | Messages not yet delivered to the group. Growing lag = WPS does not keep up or does not consume |
| `stream.pending` | Delivered but not acknowledged messages (being processed, or left by a crashed worker) |
| `stream.consumers` | Consumers in the group: every HTTP worker of every backend instance, including stale ones from earlier runs |

`stream` is `null` when the stream Redis is unavailable. Use the HTTP code for liveness/readiness probes and alerts.

> **Note**
> The response keeps `"success": true` even with `503`; check `data.status` or the HTTP status.

## Metrics: `GET /metrics`

Public, Prometheus text format (`text/plain; version=0.0.4`). Values are kept in a shared-memory table of one backend
process: they are common for all workers of that process, **reset on restart**, and are per instance.

| Metric | Type | Meaning |
|---|---|---|
| `wps_events_received_total` | counter | Stream messages read |
| `wps_events_processed_total` | counter | Events that started at least one process |
| `wps_events_skipped_total` | counter | Events with no matching process, or whose processes all were skipped (conditions, duplicates) |
| `wps_events_invalid_total` | counter | Invalid events moved to the DLQ |
| `wps_processes_started_total` | counter | Executions created (stream, manual, child) |
| `wps_executions_completed_total` | counter | Executions finished with `completed` |
| `wps_executions_failed_total` | counter | Executions finished with `failed`, including lost ones |
| `wps_execution_duration_seconds` | summary (`_sum`, `_count`) | Execution duration |
| `wps_action_duration_seconds` | summary (`_sum`, `_count`) | Step duration (every audited step, including skipped) |
| `wps_retry_count_total` | counter | Step retries after recoverable errors |
| `wps_step_errors_handled_total` | counter | Step errors handled by `on_error` (`continue` / `goto`): the execution went on. Growth means processes "succeed" while external systems answer with errors |
| `wps_run_once_skipped_total` | counter | Starts blocked by a run-once mark |
| `wps_run_once_no_id_total` | counter | Run once not applied because the object had no `id` |
| `wps_condition_cast_errors_total` | counter | Cast errors in conditions (`cast`): a value cannot be cast to the type of the condition row |
| `wps_condition_change_info_missing_total` | counter | Change operators (`changed`, `changed_to` …) on an event without change info: old format, mock, a manual run without `changed_fields`. Growth means the CRM plugin should be upgraded to 1.2.0 |
| `wps_program_compile_total` | counter | Compilations of process definitions into a program that missed the cache (grows on the first run of a process in a worker and after edits) |
| `wps_program_resume_mismatch_total` | counter | Recoveries refused because the program hash changed after an upgrade; the execution is marked `failed` |
| `wps_instructions_executed_total` | counter | Executed program instructions (actions, branches, jumps) |
| `wps_secret_resolve_total` | counter | Resolved `secret://` references in steps |
| `wps_secret_resolve_errors_total` | counter | Failed references: no such secret or field, the key is missing or does not match |
| `wps_side_effects_done_total` | counter | External calls made and recorded in the side-effect journal |
| `wps_side_effects_failed_total` | counter | External calls with a final error |
| `wps_side_effects_replayed_total` | counter | Step repeated after a crash: the response came from the journal without calling again |
| `wps_side_effects_unknown_total` | counter | Unknown outcome of an external call (`UNKNOWN_OUTCOME`) — needs an operator |
| `wps_template_render_errors_total` | counter | Template substitution errors at run time (missing value with `missing: error`, a failing filter, a too long result) |
| `wps_emails_sent_total` | counter | Letters sent by `send_email` (a step replayed from the journal is not counted) |
| `wps_emails_failed_total` | counter | Transport errors while sending letters (every attempt) |
| `wps_emails_rate_limited_total` | counter | Recipients skipped by the limit `MAILER_RATE_PER_RECIPIENT_HOUR` |
| `wps_http_requests_total` | counter | Successfully finished `http_request` steps (including responses from the journal) |
| `wps_crm_link_total` | counter | Executed `link_records` / `unlink_records` steps (the CRM requests are counted by the CRM metrics) |
| `wps_condition_branch_total` | counter | Executed `condition` blocks (branch choice); the chosen branch is in the `debug` log `Branch selected` and in the audit |
| `wps_process_stopped_total` | counter | Executions ended by a `stop_process` step (`completed` and `failed`); the status and the code are in the `info` log `Process stopped` and in `context.stop` |
| `wps_loop_items_total` | counter | Items processed by `foreach` loops (loop totals are in the `info` log `Loop finished`) |
| `wps_loop_item_failures_total` | counter | `foreach` items that failed with `on_item_error: continue` (the `warning` log `Loop item failed, the loop continues`) |
| `wps_loop_overflow_total` | counter | `foreach` loops with more items than `max_items` (and `on_overflow: fail`) |
| `wps_update_records_items_total` | counter | CRM records updated by `update_records` steps (unchanged and failed ones are not counted; the step summary is in `result_data`) |
| `wps_aggregate_records_scanned` | summary | CRM records read by one aggregate (`aggregate`); `0` — `count` via `meta.total`. Growth means wide filters (see [Load on the CRM](crm-integration.md#load-on-the-crm-search-aggregates-bulk-updates)) |
| `wps_notifications_sent_total` | counter | Delivered `notify` notifications — per delivery (a bell recipient, a chat, a webhook), including those taken from the journal on a repeat |
| `wps_notifications_failed_total` | counter | `notify` channels that failed (the `info` log `Notification sent` — `delivered`, `failed`) |
| `wps_assign_total` | counter | Executed `assign` steps (including `skipped` by `fallback` and repeats with `reused_decision`); the choice and the reason are in `result_data.reason` of the step |
| `wps_condition_expression_errors_total` | counter | Evaluation errors of expressions in conditions (`value_type: expression`): division by zero, a wrong argument; the condition row is then false, details are in the `warning` log `Condition expression failed` (`process_id`, `field`, `error`) and in the trace (`expression_error`) |
| `wps_events_legacy_format_total` | counter | Old-format events (no `event_type`, a contract 1.0 publisher): `object_before` is unknown for them |
| `wps_events_malformed_fields_total` | counter | Dropped event service fields of a wrong type (`before`, `changed_fields`, `wps_origin`); the event is processed, the log gets `Invalid service fields in event` |
| `wps_events_skipped_by_type_total` | counter | Events whose module has processes, but none subscribes to the event type (`trigger_on`) |
| `wps_events_unsupported_type_total` | counter | Events with an unsupported `event_type` (`link`, `unlink`, a typo): acknowledged without starting processes, `info` log `Unsupported event_type, no process is started` |
| `wps_executions_parked_total` | counter | Executions put into waiting (`waiting`): a `delay` pause or a long retry delay |
| `wps_executions_woken_total` | counter | Waiting executions resumed after their due time |
| `wps_wait_resume_failed_total` | counter | Failed attempts to resume a waiting execution (log `Resume attempt failed`) |
| `wps_wait_lateness_seconds` | summary | How late a wake-up was relative to the due time, s (`_sum` / `_count`) |
| `wps_executions_waiting` | gauge | Executions waiting right now (`COUNT(*)` from `execution_waits`) |
| `wps_redis_stream_lag` | gauge | Same as `stream.lag` of the health check |
| `wps_redis_stream_pending` | gauge | Same as `stream.pending` |

The `# HELP` texts are in Russian. Example Prometheus scrape config:

```yaml
scrape_configs:
  - job_name: wps
    metrics_path: /metrics
    static_configs:
      - targets: ['wps-backend:9501']
```

Useful expressions:

| What | PromQL |
|---|---|
| Failure rate | `rate(wps_executions_failed_total[5m]) / rate(wps_processes_started_total[5m])` |
| Mean execution time | `rate(wps_execution_duration_seconds_sum[5m]) / rate(wps_execution_duration_seconds_count[5m])` |
| CRM instability | `rate(wps_retry_count_total[5m]) > 0` |
| Backlog | `wps_redis_stream_lag > 100` |
| Bad events | `increase(wps_events_invalid_total[1h]) > 0` |
| Waits resume late | `rate(wps_wait_lateness_seconds_sum[5m]) / rate(wps_wait_lateness_seconds_count[5m]) > 30` |
| Waits cannot be resumed | `increase(wps_wait_resume_failed_total[15m]) > 0` |

## Logs

The backend writes one JSON object per line: levels below `error` to stdout, `error` and above to stderr.

```json
{"timestamp":"2026-10-15T08:03:19.096Z","level":"warning","message":"Step failed","context":{"execution_id":"b74defa3-…","process_id":14,"step":0,"action":"modify","attempts":1,"recoverable":false,"error":"CRM PUT http://mock:9502/api/v8/Deals/70001 returned HTTP 404"},"pid":14}
```

`LOG_LEVEL` (default `info`) sets the minimum level; `debug` adds every CRM request with status and duration and every handled
event. Exceptions in `context` are reduced to `class`, `message`, `file`.

```bash
docker compose logs -f backend
docker compose logs backend | grep '"level":"error"'
docker compose logs backend | grep '<execution id>'
```

Messages worth an alert or a search:

| Message | Level | Meaning |
|---|---|---|
| `Process started` / `Process completed` / `Process failed` | info | Execution lifecycle with `execution_id`, `process_id`, `event_id`, `duration_ms`, `error` |
| `Step failed, retrying` | warning | Recoverable error, with `attempt` and `delay_ms` |
| `Step failed` | warning | Final failure of a step, `recoverable` shows why |
| `Invalid stream event` / `Message moved to the dead-letter stream` | warning | Event rejected to the DLQ |
| `Re-processing pending stream message` | info | A message left by a crashed consumer was re-claimed |
| `Resuming interrupted execution` | info | `RecoveryJob` continues an execution |
| `Execution state lost` | warning | Unfinished execution without Redis state, marked failed |
| `Stream message processing failed` | error | Infrastructure error; the message stays pending and is retried later |
| `Process skipped: run_once` | info | Start blocked by a run-once mark |
| `run_once mark reset` | info | A user removed a mark (with the user e-mail) |

Execution history and the step audit are also available in the UI and via the API — see
[Executions and audit](../03-user-guide/executions-and-audit.md).

## Inspecting the stream with `redis-cli`

Inside Docker Compose:

```bash
R="docker compose exec -T redis-stream redis-cli"
```

| Task | Command |
|---|---|
| Consumer group state (lag, pending, last delivered id) | `$R XINFO GROUPS crm_events` |
| Consumers and their idle time | `$R XINFO CONSUMERS crm_events wps_workers` |
| Pending summary / details | `$R XPENDING crm_events wps_workers` / `$R XPENDING crm_events wps_workers - + 10` |
| Last events | `$R XREVRANGE crm_events + - COUNT 5` |
| Dead-letter queue size and content | `$R XLEN crm_events:dlq` / `$R XRANGE crm_events:dlq - + COUNT 10` |
| Remove consumers left by old containers | `$R XGROUP DELCONSUMER crm_events wps_workers <name>` (only those with `pending` 0) |

A DLQ entry contains `original_id`, `reason`, the original `fields` as JSON and `at`:

```text
1) "original_id"  2) "1792051399203-0"
3) "reason"       4) "Event object has no \"id\""
5) "fields"       6) "{\"data\":\"{\\\"module\\\":\\\"Deals\\\",\\\"status\\\":\\\"Active\\\"}\"}"
7) "at"           8) "2026-10-15T08:03:19+00:00"
```

The DLQ is capped at about 10 000 entries. WPS never re-reads it: fix the publisher, then re-send the event if needed.

Data Redis keys (`docker compose exec -T redis-data redis-cli`):

| Key | Content |
|---|---|
| `exec:<uuid>` | Live state of an execution (hash: status, current step, event, variables …) |
| `lock:exec:<uuid>` | Lock of a running execution |
| `idem:<event_id>:<process_id>` | Execution id or `skipped` for an already handled event |
| `proc_cache:<object type>` | Cached list of active top-level processes |
| `dict:modules` | Cached modules dictionary |

<!-- footer-nav -->
---

← Previous: [CRM integration](crm-integration.md) | [Contents](../README.md) | [Reliability](reliability.md) Next →
<!-- /footer-nav -->
