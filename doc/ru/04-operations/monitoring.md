# Мониторинг

Проверка состояния, метрики Prometheus, логи и команды Redis, которые показывают состояние стрима событий.

<!-- lang-switch -->
[🇬🇧 English](../../en/04-operations/monitoring.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Проверка состояния: `GET /api/health`

Публичный эндпоинт (без токена). Проверяет PostgreSQL (`SELECT 1`), Redis для данных и Redis стрима (`PING`) и показывает
состояние группы потребителей.

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

| Поле | Значение |
|---|---|
| `status` | `ok` — все проверки прошли, HTTP `200`. `degraded` — хотя бы у одной проверки `status: "error"` (с текстом в `error`), HTTP **`503`** |
| `stream.length` | Сообщений в стриме (`XLEN`). WPS стрим не обрезает, ограничивать его должен издатель через `MAXLEN` |
| `stream.lag` | Сообщения, ещё не выданные группе. Растущий lag — WPS не успевает или не читает стрим |
| `stream.pending` | Выданные, но не подтверждённые сообщения (обрабатываются сейчас или остались от упавшего воркера) |
| `stream.consumers` | Потребители группы: каждый HTTP-воркер каждого экземпляра backend, включая устаревших от прошлых запусков |

`stream` равен `null`, если Redis стрима недоступен. Для liveness/readiness-проб и оповещений ориентируйтесь на HTTP-код.

> **Примечание**
> Даже при `503` в ответе остаётся `"success": true`; проверяйте `data.status` или HTTP-статус.

## Метрики: `GET /metrics`

Публичный эндпоинт, текстовый формат Prometheus (`text/plain; version=0.0.4`). Значения хранятся в таблице в общей памяти
одного процесса backend: они общие для всех его воркеров, **обнуляются при перезапуске** и считаются отдельно для каждого
экземпляра.

| Метрика | Тип | Значение |
|---|---|---|
| `wps_events_received_total` | counter | Прочитано сообщений из стрима |
| `wps_events_processed_total` | counter | События, которые запустили хотя бы один процесс |
| `wps_events_skipped_total` | counter | События без подходящих процессов или все процессы которых пропущены (условия, дубликаты) |
| `wps_events_invalid_total` | counter | Некорректные события, перенесённые в DLQ |
| `wps_processes_started_total` | counter | Созданные выполнения (стрим, ручной запуск, ведомые) |
| `wps_executions_completed_total` | counter | Выполнения со статусом `completed` |
| `wps_executions_failed_total` | counter | Выполнения со статусом `failed`, включая потерянные |
| `wps_execution_duration_seconds` | summary (`_sum`, `_count`) | Длительность выполнения |
| `wps_action_duration_seconds` | summary (`_sum`, `_count`) | Длительность шага (каждого записанного в аудит, включая пропущенные) |
| `wps_retry_count_total` | counter | Повторы шагов после восстановимых ошибок |
| `wps_step_errors_handled_total` | counter | Ошибки шагов, обработанные `on_error` (`continue` / `goto`): выполнение продолжилось. Рост означает, что процессы «успешны», хотя внешние системы отвечают ошибками |
| `wps_run_once_skipped_total` | counter | Запуски, заблокированные отметкой однократного запуска |
| `wps_run_once_no_id_total` | counter | Однократный запуск не применён: у объекта нет `id` |
| `wps_condition_cast_errors_total` | counter | Ошибки приведения типа в условиях (`cast`): значение не приводится к типу строки условия |
| `wps_condition_change_info_missing_total` | counter | Операторы изменения (`changed`, `changed_to` …) на событии без сведений об изменениях: старый формат, мок, ручной запуск без `changed_fields`. Рост — повод обновить плагин CRM до 1.2.0 |
| `wps_program_compile_total` | counter | Компиляции определений процессов в программу без попадания в кеш (растёт при первом запуске процесса в воркере и после правок) |
| `wps_program_resume_mismatch_total` | counter | Восстановления, отклонённые из-за несовпадения хеша программы после обновления; выполнение помечается `failed` |
| `wps_instructions_executed_total` | counter | Выполненные инструкции программ (действия, ветвления, переходы) |
| `wps_secret_resolve_total` | counter | Разрешённые ссылки `secret://` в шагах |
| `wps_secret_resolve_errors_total` | counter | Ошибки разрешения ссылок: секрета или поля нет, ключ не задан или не подходит |
| `wps_side_effects_done_total` | counter | Внешние вызовы выполнены и записаны в журнал побочных эффектов |
| `wps_side_effects_failed_total` | counter | Внешние вызовы с окончательной ошибкой |
| `wps_side_effects_replayed_total` | counter | Повтор шага после сбоя: ответ взят из журнала без повторного вызова |
| `wps_side_effects_unknown_total` | counter | Неизвестный исход внешнего вызова (`UNKNOWN_OUTCOME`) — требует внимания оператора |
| `wps_template_render_errors_total` | counter | Ошибки подстановки шаблонов во время выполнения (отсутствующее значение при `missing: error`, ошибка фильтра, слишком длинный результат) |
| `wps_emails_sent_total` | counter | Отправлено писем `send_email` (повтор шага из журнала не считается) |
| `wps_emails_failed_total` | counter | Ошибки транспорта при отправке писем (каждая попытка) |
| `wps_emails_rate_limited_total` | counter | Получатели, пропущенные из-за лимита `MAILER_RATE_PER_RECIPIENT_HOUR` |
| `wps_http_requests_total` | counter | Успешно завершённые шаги `http_request` (включая ответы из журнала) |
| `wps_crm_link_total` | counter | Выполненные шаги `link_records` / `unlink_records` (запросы к CRM — в метриках CRM) |
| `wps_condition_branch_total` | counter | Выполненные блоки `condition` (выбор ветки); выбранная ветка — в логе `debug` `Branch selected` и в аудите |
| `wps_process_stopped_total` | counter | Выполнения, завершённые шагом `stop_process` (`completed` и `failed`); статус и код — в логе `info` `Process stopped` и в `context.stop` |
| `wps_loop_items_total` | counter | Элементы, обработанные циклами `foreach` (итоги цикла — в логе `info` `Loop finished`) |
| `wps_loop_item_failures_total` | counter | Элементы `foreach` с ошибкой при `on_item_error: continue` (лог `warning` `Loop item failed, the loop continues`) |
| `wps_loop_overflow_total` | counter | Циклы `foreach`, в которых элементов больше `max_items` (и `on_overflow: fail`) |
| `wps_update_records_items_total` | counter | Записи CRM, обновлённые шагами `update_records` (неизменённые и упавшие не считаются; сводка шага — в `result_data`) |
| `wps_aggregate_records_scanned` | summary | Записи CRM, прочитанные одним агрегатом (`aggregate`); `0` — `count` через `meta.total`. Рост — признак широких фильтров (см. [Нагрузка на CRM](crm-integration.md#нагрузка-на-crm-поиск-агрегаты-массовые-изменения)) |
| `wps_notifications_sent_total` | counter | Доставленные уведомления `notify` — по доставкам (получатель колокольчика, чат, вебхук), включая взятые из журнала при повторе |
| `wps_notifications_failed_total` | counter | Каналы `notify` с ошибкой (лог `info` `Notification sent` — `delivered`, `failed`) |
| `wps_assign_total` | counter | Выполненные шаги `assign` (включая `skipped` по `fallback` и повторы с `reused_decision`); выбор и причина — в `result_data.reason` шага |
| `wps_condition_expression_errors_total` | counter | Ошибки вычисления выражений в условиях (`value_type: expression`): деление на ноль, неверный аргумент; строка условия при этом ложна, подробности — в логе `warning` `Condition expression failed` (`process_id`, `field`, `error`) и в трассе (`expression_error`) |
| `wps_events_legacy_format_total` | counter | События старого формата (без `event_type`, издатель контракта 1.0): `object_before` для них неизвестен |
| `wps_events_malformed_fields_total` | counter | Отброшенные служебные поля события неверного типа (`before`, `changed_fields`, `wps_origin`); событие обрабатывается, в лог — `Invalid service fields in event` |
| `wps_events_skipped_by_type_total` | counter | События, для модуля которых есть процессы, но ни один не подписан на тип события (`trigger_on`) |
| `wps_events_unsupported_type_total` | counter | События с неподдерживаемым `event_type` (`link`, `unlink`, опечатка): подтверждены без запуска процессов, в лог (`info`) — `Unsupported event_type, no process is started` |
| `wps_executions_parked_total` | counter | Выполнения, переведённые в ожидание (`waiting`): пауза `delay` или длинная задержка повтора |
| `wps_executions_woken_total` | counter | Ожидающие выполнения, продолженные после срока |
| `wps_wait_resume_failed_total` | counter | Неудачные попытки продолжить ожидающее выполнение (в лог — `Resume attempt failed`) |
| `wps_wait_lateness_seconds` | summary | Опоздание пробуждения относительно срока, с (`_sum` / `_count`) |
| `wps_executions_waiting` | gauge | Сколько выполнений сейчас ждёт (`COUNT(*)` из `execution_waits`) |
| `wps_redis_stream_lag` | gauge | То же, что `stream.lag` в проверке состояния |
| `wps_redis_stream_pending` | gauge | То же, что `stream.pending` |

Тексты `# HELP` — на русском. Пример настройки сбора в Prometheus:

```yaml
scrape_configs:
  - job_name: wps
    metrics_path: /metrics
    static_configs:
      - targets: ['wps-backend:9501']
```

Полезные выражения:

| Что | PromQL |
|---|---|
| Доля ошибок | `rate(wps_executions_failed_total[5m]) / rate(wps_processes_started_total[5m])` |
| Среднее время выполнения | `rate(wps_execution_duration_seconds_sum[5m]) / rate(wps_execution_duration_seconds_count[5m])` |
| Нестабильность CRM | `rate(wps_retry_count_total[5m]) > 0` |
| Очередь растёт | `wps_redis_stream_lag > 100` |
| Некорректные события | `increase(wps_events_invalid_total[1h]) > 0` |
| Ожидания продолжаются с опозданием | `rate(wps_wait_lateness_seconds_sum[5m]) / rate(wps_wait_lateness_seconds_count[5m]) > 30` |
| Ожидания не удаётся продолжить | `increase(wps_wait_resume_failed_total[15m]) > 0` |
| Неизвестный исход внешних вызовов | `increase(wps_side_effects_unknown_total[1h]) > 0` |
| Ошибки, «съеденные» `on_error` | `rate(wps_step_errors_handled_total[15m]) > 0` |
| Плагин CRM старше 1.2.0 | `increase(wps_events_legacy_format_total[1h]) > 0` |

Пример правил оповещений Prometheus:

```yaml
groups:
  - name: wps
    rules:
      - alert: WpsDown
        expr: up{job="wps"} == 0
        for: 2m
      - alert: WpsStreamLag
        expr: wps_redis_stream_lag > 100
        for: 5m
      - alert: WpsFailureRate
        expr: rate(wps_executions_failed_total[10m]) / clamp_min(rate(wps_processes_started_total[10m]), 0.001) > 0.1
        for: 10m
      - alert: WpsUnknownOutcome
        expr: increase(wps_side_effects_unknown_total[1h]) > 0
      - alert: WpsInvalidEvents
        expr: increase(wps_events_invalid_total[1h]) > 0
```

Проверку `/api/health` удобно делать отдельно (blackbox exporter или проба оркестратора): `503` означает, что
недоступен PostgreSQL или один из Redis.

## Логи

Backend пишет по одному JSON-объекту в строке: уровни ниже `error` — в stdout, `error` и выше — в stderr.

```json
{"timestamp":"2026-10-27T19:28:46.333Z","level":"warning","message":"Step failed","context":{"execution_id":"0f1be6e5-9152-4b6c-a4e8-7cd2b4651d4f","process_id":3972,"pc":2,"seq":2,"action":"delay","attempts":1,"code":"ERROR","recoverable":false,"error":"delay: cannot resolve a date from object.no_such_date (value \"\")"},"pid":14}
{"timestamp":"2026-10-27T19:28:46.333Z","level":"warning","message":"Step error handled","context":{"execution_id":"0f1be6e5-9152-4b6c-a4e8-7cd2b4651d4f","process_id":3972,"pc":2,"policy":"continue","target":null,"code":"ERROR"},"pid":14}
{"timestamp":"2026-10-27T19:27:32.013Z","level":"info","message":"Process completed","context":{"execution_id":"17ab587a-8751-4ec6-aff2-86829ee3c18d","process_id":3786,"event_id":"manual-784844a3-…","object_type":"Tasks","pc":3,"seq":3,"duration_ms":45,"error":null},"pid":14}
```

Поля строки: `timestamp` (UTC, миллисекунды), `level`, `message` (постоянный текст — по нему удобно искать),
`context` (данные события), `pid` (процесс воркера: `1` — мастер, остальные — HTTP-воркеры). Секреты в `context` не
попадают: тексты ошибок шагов проходят через маскер секретов.

`LOG_LEVEL` (по умолчанию `info`) задаёт минимальный уровень. На `debug` в лог попадает каждый запрос к CRM со статусом и
длительностью и каждое обработанное событие. Исключения в `context` сокращаются до `class`, `message`, `file`.

```bash
docker compose logs -f backend
docker compose logs backend | grep '"level":"error"'
docker compose logs backend | grep '<id выполнения>'
```

Сообщения, на которые стоит настроить оповещения или поиск (тексты в логе на английском):

| Сообщение | Уровень | Значение |
|---|---|---|
| `Process started` / `Process completed` / `Process failed` | info | Жизненный цикл выполнения: `execution_id`, `process_id`, `event_id`, `duration_ms`, `error` |
| `Step failed, retrying` | warning | Восстановимая ошибка, с `attempt` и `delay_ms` |
| `Step failed` | warning | Окончательная ошибка шага; `recoverable` показывает причину |
| `Invalid stream event` / `Message moved to the dead-letter stream` | warning | Событие отклонено в DLQ |
| `Re-processing pending stream message` | info | Забрано сообщение, оставленное упавшим потребителем |
| `Resuming interrupted execution` | info | `RecoveryJob` продолжает выполнение |
| `Execution state lost` | warning | Незавершённое выполнение без состояния в Redis помечено ошибочным |
| `Stream message processing failed` | error | Ошибка инфраструктуры; сообщение остаётся в pending и будет обработано позже |
| `Process skipped: run_once` | info | Запуск заблокирован отметкой однократного запуска |
| `run_once mark reset` | info | Пользователь снял отметку (в контексте — его e-mail) |

Полный перечень сообщений backend по областям (текст — постоянная часть `message`):

| Область | Сообщения |
|---|---|
| Старт | `Business time zone` (info), `Consumer group created` (info), строки `[migrate] …` и `[WPS] listening on 0.0.0.0:9501` (не JSON) |
| Стрим | `Event handled` (debug), `Invalid stream event`, `Message moved to the dead-letter stream`, `Invalid service fields in event` (warning), `Unsupported event_type, no process is started`, `Re-processing pending stream message` (info), `Stream message processing failed`, `Failed to write to the dead-letter stream` (error) |
| Выполнение | `Process started`, `Process completed`, `Process failed`, `Process stopped`, `Process skipped: run_once` (info), `Process failed on conditions` (error), `run_once ignored: object has no id` (warning), `run_once mark could not be released` (error), `Branch selected` (debug), `Loop finished` (info), `Loop item failed, the loop continues` (warning) |
| Шаги и ошибки | `Step failed, retrying`, `Step failed, retry parked`, `Step failed`, `Step error handled` (warning), `Long retry delay runs inside the worker until waiting executions are available` (warning) |
| Условия | `Condition cast failed` (warning, `error` при `on_cast_error: error`), `Condition expression failed`, `Change operators used but the event has no change info` (warning) |
| Ведомые процессы | `Child process handled` (info), `Child process crashed` (error) |
| Восстановление | `Resuming interrupted execution` (info), `Execution state lost` (warning), `Execution recovery failed`, `Execution interrupted`, `Program changed after an upgrade` (error) |
| Ожидания | `Execution parked`, `Execution woken` (info), `Resume attempt failed`, `Execution is no longer running, it was not parked` (warning), `Execution interrupted after waking` (error) |
| Внешние вызовы | `Email sent`, `HTTP request done`, `Notification sent`, `Side effect replayed` (info), `Email recipients skipped`, `Email history was not saved to the CRM`, `Side effect outcome unknown` (warning) |
| Журнал побочных эффектов | `Side-effect journal purged` (info, раз в час), `Side-effect journal purge failed` (warning) |
| CRM и кэши | `CRM request` (debug: метод, URL, статус, длительность), `CRM capabilities cache unavailable`, `CRM capabilities cache write failed`, `Modules dictionary source unavailable`, `Modules dictionary could not be loaded`, `Process cache unavailable, reading from database`, `Process cache invalidation failed` (warning) |
| Секреты | `Secret created`, `Secret updated`, `Secret deleted`, `Secrets re-encrypted` (info; имя и id пользователя, без значения) |
| HTTP API | `Request failed` (error: исключение в контроллере, `uri` и класс исключения), `Unhandled request error` (error) |

Пример: `{"level":"error","message":"Request failed","context":{"uri":"/api/processes","error":{"class":"Wps\\Exceptions\\NonRecoverableException","message":"Secret \"smtp_support\" not found",…}}}` — процесс
ссылается на секрет, которого нет (сохранение отклонено).

История выполнений и аудит шагов есть также в UI и в API — см. [Выполнения и аудит](../03-user-guide/executions-and-audit.md).

## Просмотр стрима через `redis-cli`

Внутри Docker Compose:

```bash
R="docker compose exec -T redis-stream redis-cli"
```

| Задача | Команда |
|---|---|
| Состояние группы (lag, pending, последний выданный id) | `$R XINFO GROUPS crm_events` |
| Потребители и время их простоя | `$R XINFO CONSUMERS crm_events wps_workers` |
| Сводка и детали pending | `$R XPENDING crm_events wps_workers` / `$R XPENDING crm_events wps_workers - + 10` |
| Последние события | `$R XREVRANGE crm_events + - COUNT 5` |
| Размер и содержимое DLQ | `$R XLEN crm_events:dlq` / `$R XRANGE crm_events:dlq - + COUNT 10` |
| Удалить потребителей от старых контейнеров | `$R XGROUP DELCONSUMER crm_events wps_workers <имя>` (только с `pending` 0) |

Запись DLQ содержит `original_id`, `reason`, исходные поля `fields` в виде JSON и время `at`:

```text
1) "original_id"  2) "1792051399203-0"
3) "reason"       4) "Event object has no \"id\""
5) "fields"       6) "{\"data\":\"{\\\"module\\\":\\\"Deals\\\",\\\"status\\\":\\\"Active\\\"}\"}"
7) "at"           8) "2026-10-15T08:03:19+00:00"
```

Пример реального ответа `XINFO GROUPS crm_events`:

```text
name               wps_workers
consumers          231
pending            0
last-delivered-id  1793129318815-0
entries-read       2955
lag                0
```

> **Примечание**
> Каждый перезапуск backend добавляет новых потребителей (`{hostname}-{pid}`), старые остаются в группе — отсюда
> большое `consumers`. На доставку это не влияет; потребителей без pending-сообщений можно удалить:
>
> ```bash
> R="docker compose exec -T redis-stream redis-cli"
> $R --raw XINFO CONSUMERS crm_events wps_workers | paste - - - - - - - - | \
>   awk '$4 == 0 && $6 > 3600000 {print $2}' | xargs -r -n1 $R XGROUP DELCONSUMER crm_events wps_workers
> ```
>
> (поля строки: `name`, `pending`, `idle` (мс), `inactive`; удаляются потребители без pending, простаивающие больше часа).

DLQ ограничена примерно 10 000 записями. WPS её не перечитывает: исправьте издателя и при необходимости отправьте событие заново.

Ключи Redis для данных (`docker compose exec -T redis-data redis-cli`):

| Ключ | Содержимое |
|---|---|
| `exec:<uuid>` | Живое состояние выполнения (hash: статус, текущий шаг, событие, переменные …) |
| `lock:exec:<uuid>` | Блокировка выполняющегося выполнения |
| `idem:<event_id>:<process_id>` | Id выполнения или `skipped` для уже обработанного события |
| `exec:<uuid>:loop:<pc>` | Снимок элементов цикла `foreach` |
| `idem:<parent_execution_id>.<seq>:<child_process_id>` | Идемпотентность ведомого процесса |
| `proc_cache:<тип объекта>` | Кэш списка активных процессов верхнего уровня |
| `dict:modules` | Кэш справочника модулей |
| `crm:capabilities` | Кэш возможностей CRM (`DICT_CACHE_TTL`) |
| `side_effects:purge` | Отметка «журнал побочных эффектов уже чистился в этот час» (TTL 1 ч) |
| `mail:rate:<hash транспорта>:<минута>` | Счётчик писем транспорта за минуту (`MAILER_RATE_PER_MINUTE`, TTL 120 с) |
| `mail:rcpt:<sha1 адреса>:<час>` | Счётчик писем получателю за час (`MAILER_RATE_PER_RECIPIENT_HOUR`, TTL 2 ч) |
| `oauth:token:<sha1>` / `…:lock` | Кэш токена OAuth2 `http_request` (на срок `expires_in`) и блокировка его получения (15 с) |
| `assign:rr:<queue_key>` | Последний назначенный в очереди `round_robin` |
| `assign:last:<queue_key>:<user>` | Когда пользователь назначался (`least_busy`, 30 дней) |
| `assign:decided:<execution>:<pc>:<iteration>` | Решение шага `assign` для повтора |
| `assign:pool:<sha1>` | Кэш состава группы безопасности (`ASSIGN_POOL_CACHE_TTL`) |

Пример: живое состояние идущего выполнения и его блокировка.

```bash
D="docker compose exec -T redis-data redis-cli"
$D --scan --pattern 'exec:*' | head
$D HMGET exec:<uuid> status current_step seq retry_count
$D TTL lock:exec:<uuid>
```

## Запросы к PostgreSQL для мониторинга

```bash
docker compose exec -T postgres psql -U wps -d wps
```

| Что | SQL |
|---|---|
| Выполнения по статусам за час | `SELECT status, count(*) FROM executions WHERE started_at > now() - interval '1 hour' GROUP BY 1;` |
| Самые частые ошибки за сутки | `SELECT error_code, action_type, count(*) FROM execution_actions a JOIN executions e ON e.id = a.execution_id WHERE a.status = 'failed' AND e.started_at > now() - interval '1 day' GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 10;` |
| Зависшие выполнения | `SELECT id, process_id, status, started_at FROM executions WHERE status IN ('running','retrying') AND started_at < now() - interval '10 minutes';` |
| Ожидающие и просроченные ожидания | `SELECT execution_id, reason, due_at, attempts, last_error FROM execution_waits ORDER BY due_at LIMIT 20;` |
| Неизвестный исход внешних вызовов | `SELECT execution_id, effect_key, action_type, started_at FROM step_side_effects WHERE status = 'started' AND started_at < now() - interval '10 minutes';` |
| Обработанные ошибки (`on_error`) | `SELECT process_id, sum(handled_errors) FROM executions WHERE started_at > now() - interval '1 day' GROUP BY 1 HAVING sum(handled_errors) > 0;` |

Колонки `started_at` в `executions` и `execution_actions` — `TIMESTAMP` в UTC (сессии `migrate.php` и backend
работают в UTC); если ваш клиент psql в другом поясе, сравнивайте с `now() AT TIME ZONE 'UTC'`.

<!-- footer-nav -->
---

← Назад: [Интеграция с CRM](crm-integration.md) | [Оглавление](../README.md) | [Надёжность](reliability.md) Далее →
<!-- /footer-nav -->
