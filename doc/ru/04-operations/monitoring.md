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
| `wps_run_once_skipped_total` | counter | Запуски, заблокированные отметкой однократного запуска |
| `wps_run_once_no_id_total` | counter | Однократный запуск не применён: у объекта нет `id` |
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

## Логи

Backend пишет по одному JSON-объекту в строке: уровни ниже `error` — в stdout, `error` и выше — в stderr.

```json
{"timestamp":"2026-10-15T08:03:19.096Z","level":"warning","message":"Step failed","context":{"execution_id":"b74defa3-…","process_id":14,"step":0,"action":"modify","attempts":1,"recoverable":false,"error":"CRM PUT http://mock:9502/api/v8/Deals/70001 returned HTTP 404"},"pid":14}
```

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

DLQ ограничена примерно 10 000 записями. WPS её не перечитывает: исправьте издателя и при необходимости отправьте событие заново.

Ключи Redis для данных (`docker compose exec -T redis-data redis-cli`):

| Ключ | Содержимое |
|---|---|
| `exec:<uuid>` | Живое состояние выполнения (hash: статус, текущий шаг, событие, переменные …) |
| `lock:exec:<uuid>` | Блокировка выполняющегося выполнения |
| `idem:<event_id>:<process_id>` | Id выполнения или `skipped` для уже обработанного события |
| `proc_cache:<тип объекта>` | Кэш списка активных процессов верхнего уровня |
| `dict:modules` | Кэш справочника модулей |

<!-- footer-nav -->
---

← Назад: [Интеграция с CRM](crm-integration.md) | [Оглавление](../README.md) | [Надёжность](reliability.md) Далее →
<!-- /footer-nav -->
