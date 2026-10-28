# Решение проблем

Типичные проблемы: как их распознать и как исправить.

<!-- lang-switch -->
[🇬🇧 English](../../en/04-operations/troubleshooting.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## С чего начать

1. **Страница выполнения** в UI: баннер с ошибкой, шаги с данными `request`/`result` и контекст (событие, переменные,
   трассировка условий). См. [Выполнения и аудит](../03-user-guide/executions-and-audit.md).
2. **`GET /api/health`** — доступны ли PostgreSQL и оба Redis, есть ли у стрима lag или pending-сообщения.
3. **Логи backend** — `docker compose logs -f backend` (строки JSON; с `LOG_LEVEL=debug` виден каждый запрос к CRM).
4. **DLQ** — `docker compose exec -T redis-stream redis-cli XRANGE crm_events:dlq - + COUNT 10`.

![Выполнение с ошибкой: баннер, четыре попытки шага предзагрузки, следующий шаг пропущен](../../images/screenshots/ru/12-execution-detail-failed.png)
*Ошибка вызова CRM: CRM ответила HTTP 500, шаг повторён 3 раза (4 попытки), следующий шаг пропущен.*

## Симптом → причина → решение

| # | Симптом | Вероятная причина | Решение |
|---|---|---|---|
| 1 | Событие отправлено, а выполнения нет | Для этого `module` нет **активного процесса верхнего уровня**; процесс ведомый; тип объекта отличается регистром (`deals` ≠ `Deals`) | Проверьте фильтры списка процессов «Тип объекта», «Тип процесса», «Активность». Ведомые процессы от событий не запускаются |
| 2 | Выполнения нет, процесс настроен верно | Условия не выполнены. Неудачная проверка выполнения не создаёт (так задумано) | Сделайте [ручной запуск](../03-user-guide/manual-run-and-reset.md) с тем же объектом: в диалоге видна трассировка условий с `actual` / `expected` |
| 3 | Выполнения нет, условия выполнены | Однократный запуск: у записи уже есть отметка. В логе — `Process skipped: run_once` | Карточка процесса → «Однократные запуски» → снимите отметку или отключите «Однократный запуск для записи» |
| 4 | Ничего не обрабатывается, `stream.lag` растёт | Backend не читает стрим: он остановлен или подключён к другому Redis / стриму | `docker compose ps`, `docker compose logs backend`; проверьте `REDIS_STREAM_HOST`, `REDIS_STREAM_NAME`, группу в `XINFO GROUPS crm_events` |
| 5 | События пропадают, растёт `wps_events_invalid_total` | Некорректное событие → DLQ: `Event has no "module"/"object_type"`, `Event object has no "id"`, `Field "data" is not a JSON object` | Прочитайте `reason` в `crm_events:dlq` и исправьте издателя (см. [формат события](crm-integration.md#формат-события-crm--wps)) |
| 6 | Шаг падает с `CRM GET … returned HTTP 500` примерно через 7 с, `attempts: 4` | CRM отвечает ошибкой; WPS повторил запрос 3 раза | Исправьте CRM, затем запустите процесс вручную. Если CRM нужно больше времени, настройте `ACTION_RETRY_*` |
| 7 | Шаг падает с `CRM … : connection failed` / `request timeout` | Неверный `CRM_API_BASE_URL`, контейнер CRM не в сети WPS или отвечает дольше `CRM_API_TIMEOUT` | Проверьте доступность из контейнера backend: `docker compose exec backend curl -s -o /dev/null -w '%{http_code}' <базовый адрес CRM>/modules`; подключите CRM к сети WPS; увеличьте `CRM_API_TIMEOUT` |
| 8 | Шаг падает с `CRM … returned HTTP 401` | Неверный `CRM_API_STATIC_KEY` или часы серверов расходятся больше чем на час | Используйте один ключ в WPS и в CRM (`MOCK_CRM_STATIC_KEY` / `WPS_API_STATIC_KEY`); синхронизируйте часы (NTP, UTC) |
| 9 | Шаг падает с `CRM PUT …/{id} returned HTTP 404` | `save_object`/`save_to_crm` для записи, которой нет в CRM (событие составлено вручную или мок потерял данные) | Сначала загрузите запись предзагрузкой или убедитесь, что `id` события — настоящий id CRM |
| 10 | Запросы уходят на `/api/v8/...`, а CRM обслуживает `/api/v9` | `CRM_API_BASE_URL` не заканчивается на `/api/vN`, и WPS дописывает `/api/v8` | Задайте `CRM_API_BASE_URL=http://<crm>/api/v9` |
| 11 | После переключения на SuiteCRM в списке «Тип объекта» модули мока | `MOCK_SERVICE_URL` всё ещё задан или справочник закэширован (300 с) | Задайте `MOCK_SERVICE_URL=` (пусто), перезапустите backend, нажмите обновление рядом с «Тип объекта» или выполните `POST /api/dictionaries/modules/refresh` |
| 12 | Переменная предзагрузки равна `null`, следующие шаги ничего не пишут или `save_object` пропущен (`skipped`) | Запись не найдена (`404` или пустой поиск) или значение для поиска пустое — это не ошибка | Посмотрите результат шага предзагрузки (`found: false`, `lookup_value`). Исправьте путь (`object.account_id`) или `by_field` |
| 13 | Сохранение пропущено с `No changed fields to save` | `save_object` отправляет только поля, изменённые в этом выполнении | Измените поле перед сохранением или укажите `"mode": "full"` в JSON действия |
| 14 | Сохранение падает: `Cannot save "x": CRM module is unknown` | У новой переменной (созданной через `modify`) нет модуля | Укажите «Модуль» в строке сохранения (`module` в действии) |
| 15 | Сохранение процесса: `422 VALIDATION_ERROR`, `Variable "v1" is not defined at this point` | Переменная используется раньше своей строки предзагрузки, или такой строки нет | Поменяйте строки местами; в ведомых процессах переменные родителя разрешены |
| 16 | Сохранение процесса: `CYCLE_DETECTED` | `start_process` образует цикл А → Б → А | Уберите одну из ссылок; цепочка указана в `details.path` ошибки |
| 17 | Сохранение: `Process N "…" is not a child process` | `start_process` запускает только ведомые процессы | Отметьте целевой процесс как «Ведомый процесс» |
| 18 | Удаление процесса: `409 PROCESS_IN_USE` | Его запускает другой процесс | Уберите ссылку `start_process` в процессах, перечисленных в `details` |
| 19 | Процесс запускается снова и снова | Он сохраняет запись, которая его запустила → новый `after_save` → новое событие | Включите «Однократный запуск для записи» или добавьте условие на поле-маркер, которое процесс выставляет |
| 20 | Выполнение зависло в `running` | Упал воркер. `RecoveryJob` продолжит выполнение через `RECOVERY_STALE_SECONDS` (5 мин) или пометит его ошибочным («Execution context expired or lost») | Дождитесь восстановления; ищите в логе `Resuming interrupted execution` |
| 21 | Скрипты получают `401 TOKEN_EXPIRED` | Access-токен живёт 15 минут | Вызовите `POST /api/auth/refresh` или войдите заново |
| 22 | `403 FORBIDDEN` | Роль не разрешает операцию (например, viewer запускает процесс, editor удаляет) | См. [таблицу ролей](security.md#роли) |
| 23 | `/api/health` отвечает `503` | Недоступен PostgreSQL, `redis-data` или `redis-stream`; причина — в `checks.<имя>.error` | Запустите упавший сервис; проверьте переменные хоста, порта и пароля |
| 24 | Backend при старте завершается с `[migrate] database is not reachable` | PostgreSQL не готов за `DB_WAIT_SECONDS` (60) или неверные `DB_POSTGRES_*` | Проверьте контейнер postgres и учётные данные; увеличьте `DB_WAIT_SECONDS` |
| 25 | Изменения в `back/` не применяются | Код вшит в образ | `docker compose up -d --build backend` |
| 26 | `+` в `calculate` склеивает текст (`100 USD5`) или возвращает один операнд (`1000`, если второе поле пустое) | `+` выполняет конкатенацию, если хотя бы один операнд не число (пустое или отсутствующее поле — пустая строка) | Убедитесь, что оба поля — числа; `- * / %` считают пустое значение равным `0`, а на тексте падают с `Operator "*" requires numbers, got "…"` |
| 27 | Шаг падает с `Division by zero in expression` | Поле-делитель равно `0` или пустое | Защитите делитель: `max(object.qty, 1)` или условие |
| 28 | Интерфейс не на том языке | Язык выбирается по `?lang=`, затем по сохранённому выбору, затем по языку браузера | Переключите язык в шапке или добавьте `?lang=ru` |
| 29 | Шаг `send_email` падает с `Mail transport: Connection could not be established…` (код `SMTP_CONNECTION`), повторы исчерпаны | Неверный хост/порт в `MAILER_DSN` или секрете, SMTP-сервер недоступен из контейнера backend | Проверьте настройки запросом `POST /api/mail/test` (администратор): ответ `sent: false` содержит текст ошибки SMTP. Проверьте сеть: `docker compose exec backend php -r 'var_dump(fsockopen("host", 587));'` |
| 30 | `Mail transport authentication failed` (`SMTP_AUTH`) | Неверный логин или пароль SMTP (ответ 535), сервер требует TLS (`smtps://` или порт 587 со STARTTLS) | Исправьте значение секрета `smtp_dsn` или `MAILER_DSN`; повторов не будет — это ошибка конфигурации |
| 31 | `send_email` завершается ошибкой `SMTP_550` / `SMTP_553` / `SMTP_554` | Постоянный отказ сервера: нет ящика, отправитель не разрешён, письмо отвергнуто как спам | Проверьте адрес получателя и `from`; отправитель должен быть разрешён SMTP-сервером (SPF/DKIM настраиваются на нём) |
| 32 | Шаг повторяется с `SMTP_421` / `SMTP_451` или `Mail rate limit exceeded` (`MAIL_RATE_LIMIT`) | Временный отказ сервера или превышен `MAILER_RATE_PER_MINUTE` | Ничего: шаг повторится по профилю `retry`. При постоянных отказах увеличьте `retry.max_delay` или лимит |
| 33 | `send_email` — `UNKNOWN_OUTCOME` | Соединение оборвалось **после** передачи письма (`at_most_once`): письмо могло уйти | Проверьте ящик получателя или Mailpit по `Message-ID` из результата шага; при необходимости отправьте письмо вручную. Повтор шага по умолчанию не делается намеренно |
| 34 | Письмо ушло не всем, в результате шага `invalid_recipients` / `rate_limited` | Адрес из поля пустой или неверный; получателю за час уже ушло `MAILER_RATE_PER_RECIPIENT_HOUR` писем | Исправьте данные в CRM; проверьте, не шлёт ли процесс письмо при каждом сохранении (`trigger_on`, `changed_to`) |
| 35 | Письма не видны в почте на стенде | На стенде `MAILER_DSN=smtp://mailpit:1025`: письма не доставляются | Откройте Mailpit `http://localhost:8025` |
| 36 | `http_request` — `Destination address is not allowed (private network)` | Хост резолвится в частный адрес (внутренняя сеть, `localhost`, метаданные облака) — SSRF-защита | Если это ваша внутренняя система, добавьте её подсеть в `HTTP_ALLOW_PRIVATE_CIDRS`; иначе исправьте URL |
| 37 | `http_request` — `Host "x" is not allowed` / `… is not in HTTP_ALLOW_HOSTS` / `Port N is not allowed` | Хост в `HTTP_DENY_HOSTS` (по умолчанию — CRM, `postgres`, `redis-*`, `mock`), не в белом списке или запрещённый порт | Для CRM используйте действия CRM, а не `http_request`; иначе поправьте `HTTP_ALLOW_HOSTS` / `HTTP_DENY_PORTS` |
| 38 | `http_request` — `Secret "x" is not allowed for host "y"` | У секрета задан `allowed_hosts`, а запрос идёт на другой хост | Проверьте URL или добавьте хост в `allowed_hosts` секрета |
| 39 | `http_request` — `Response is too large` (`RESPONSE_TOO_LARGE`) | Ответ больше `HTTP_MAX_RESPONSE_BYTES` | Запрашивайте меньше данных (фильтры, страницы API) или увеличьте лимит |
| 40 | `http_request` — `UNKNOWN_OUTCOME` | `500`/`502`/`504` или обрыв после отправки при `at_most_once` без `Idempotency-Key`: запрос мог выполниться | Сверьте с внешней системой (`GET /api/executions/{id}/side-effects`). Чтобы такие шаги повторялись, используйте `Idempotency-Key` на стороне сервера или `delivery: at_least_once` |
| 41 | Сохранение процесса: `Name "sys" is reserved` (или `config`, `object_before`) | С задачи 5.2.9 `sys`, `config`, `object_before`, `object` — зарезервированные имена | Переименуйте переменную (`var`, `as`, `target`); проверка всех процессов — запрос из [Развёртывание → Миграции](deployment.md#миграции) |
| 42 | Сохранение: `Variable "object_before" is read-only` | Попытка изменить или сохранить переменную только для чтения (`object_before`, `sys`, `config`) | Пишите в `object` или свою переменную; константы меняются в `definition.constants` |
| 43 | Сохранение: `Constant "api_token" looks like a secret; use a secret reference (secret://name)` | Константа с именем вроде `password`, `secret`, `token`, `api_key` | Заведите [секрет](../03-user-guide/secrets.md) и ссылайтесь на него в полях учётных данных |
| 44 | Сохранение: `Nesting is too deep (max 5)` | Блоки вложены глубже `PROGRAM_MAX_DEPTH` | Упростите процесс (вынесите часть в ведомый процесс) или увеличьте `PROGRAM_MAX_DEPTH` |
| 45 | Выполнение `failed`: `LIMIT_EXCEEDED: more than 5000 instructions in one execution` | Слишком длинный цикл или переходы `goto` по кругу | Сузьте источник `foreach`, проверьте `goto`; при необходимости увеличьте `EXECUTION_MAX_INSTRUCTIONS` |
| 46 | Шаг: `CRM does not support "relationships" (contract 1.0); update the CRM plugin` (`CRM_CAPABILITY_MISSING`) | Плагин CRM старше 1.2.0 или мок в режиме `contract: 1.0` | Обновите плагин ([Интеграция с CRM](crm-integration.md#установка-плагина)); проверка — `GET /api/dictionaries/crm-capabilities`. После обновления сбросьте кэш: `POST /api/dictionaries/modules/refresh` |
| 47 | Операторы `changed*` всегда ложны, в логе `Change operators used but the event has no change info` | Событие без `before`/`changed_fields` (плагин 1.1.0, ручной запуск без `changed_fields`); растёт `wps_condition_change_info_missing_total` | Обновите плагин до 1.2.0; при ручном запуске передайте `event_type`, `before`, `changed_fields` в объекте |
| 48 | Событие пришло, процесс не запустился, растёт `wps_events_skipped_by_type_total` | Тип события не входит в `trigger_on` процесса (например, `delete` при `["create","update"]`) | Добавьте тип в «Запускать при» (`trigger_on`) |
| 49 | Шаг `delay`: `delay: wait of 500 days exceeds the limit P400D` | Пауза длиннее `DELAY_MAX` или `max_wait` шага | Сократите паузу или увеличьте `DELAY_MAX` |
| 50 | Шаг: `Execution state is too large to wait (N bytes, limit 1048576)` | Снимок выполнения (переменные, списки `find_records`) больше `WAIT_STATE_MAX_BYTES` | Уменьшите данные до паузы (`fields`, `limit`), очистите ненужные переменные или увеличьте лимит |
| 51 | Ожидающее выполнение стало `failed`: `Could not resume waiting execution: …` | `WAIT_MAX_ATTEMPTS` неудачных пробуждений (обычно Redis или PostgreSQL недоступны) | Ищите в логе `Resume attempt failed` с причиной; устраните её и запустите процесс заново |
| 52 | Удаление процесса: `409 PROCESS_HAS_WAITING_EXECUTIONS` («The process has N waiting executions; delete with force=true to cancel them») | У процесса есть ожидающие выполнения | Дождитесь их или удалите с `?force=true` (admin) — они станут `cancelled` |
| 53 | Шаг `foreach`: `foreach: 800 items exceed max_items (500)` | Список больше `max_items` при `on_overflow: fail` | Сузьте источник, увеличьте `max_items` (≤ `LOOP_MAX_ITEMS`) или `on_overflow: truncate` |
| 54 | Шаг: `find_records: result is too large (1.4 MB); narrow "fields" or "limit"` | Список найденных записей больше `FIND_RECORDS_MAX_BYTES` | Укажите `fields` и меньший `limit` |
| 55 | Шаг: `aggregate: more than 1000 records of Opportunities; narrow the filter or set "on_limit": "partial"` | Записей больше `limit` агрегата | Сузьте фильтр, увеличьте `limit` (≤ `AGGREGATE_MAX_RECORDS`) или `on_limit: partial` |
| 56 | Шаг: `Secret "smtp_support" not found` / `Secrets are not available: WPS_SECRETS_KEY is not configured` | Секрет не заведён или не задан ключ хранилища | Создайте секрет (admin); задайте `WPS_SECRETS_KEY` и перезапустите backend. API секретов без ключа отвечает `503 SECRETS_KEY_NOT_CONFIGURED` |
| 57 | Выполнение `failed`: `Program changed after an upgrade; the execution cannot be resumed safely` | Выполнение прервано, а после обновления backend компилятор строит другую программу | Запустите процесс заново; перед обновлениями дожидайтесь завершения `running` выполнений |
| 58 | Шаг: `Regular expression failed: backtrack limit exceeded` | Регулярное выражение слишком дорогое для строки ([SafeRegex](security.md#регулярные-выражения-saferegex)) | Упростите шаблон (без вложенных `(a+)+`), ограничьте входную строку |
| 59 | Шаг `start_process`: `Maximum child process depth (10) exceeded` | Цепочка ведомых процессов глубже `MAX_CHILD_DEPTH` | Проверьте цепочку на лишние уровни; при необходимости увеличьте `MAX_CHILD_DEPTH` |
| 60 | `XINFO GROUPS` показывает сотни `consumers` | Каждый перезапуск backend добавляет потребителей `{hostname}-{pid}` | Не ошибка; очистка — команда из [Мониторинг](monitoring.md#просмотр-стрима-через-redis-cli) |
| 61 | Backend не стартует: `WPS_TIMEZONE: unknown time zone "MSK"` / `WPS_SECRETS_KEY: expected base64 of 32 bytes …` | Неверная конфигурация | Имя IANA (`Europe/Moscow`); ключ — `php bin/secrets-key.php`. См. [Конфигурация](configuration.md#проверка-действующей-конфигурации) |
| 62 | Константы процесса (`config.*`) пропали после сохранения в визуальном редакторе | Редактор формы собирает `definition` только из `conditions`, `preload`, `actions` | Меняйте процессы с `constants` через JSON / API (`PUT /api/processes/{id}`) |

## Полезные команды

```bash
# отправить тестовое событие через мок
curl -sg 'http://localhost:9502/test-event?module=Deals&set[status]=Active'

# посмотреть объект в хранилище мок-CRM
curl -s http://localhost:9502/mock/objects/Accounts/<account_id>

# повторить запуск с тестовым объектом (токен editor или admin)
curl -s -X POST http://localhost:9501/api/processes/1/run \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"object":{"id":"<deal id>","module":"Deals","status":"Active","account_id":"<account_id>"}}'

# pending-сообщения и потребители
docker compose exec -T redis-stream redis-cli XPENDING crm_events wps_workers
docker compose exec -T redis-stream redis-cli XINFO CONSUMERS crm_events wps_workers
```

Идентификаторы в примерах — заглушки: возьмите `id` и `account_id` из `event` в ответе `test-event`.

> **Внимание**
> Если аккаунта нет в CRM, процесс 1 не падает: предзагрузка возвращает `null`, а шаг `modify` с
> **Сохранить в CRM** создаёт новый аккаунт (`POST`). См. [Действия](../03-user-guide/actions.md).

Если проблемы нет в списке, посмотрите точные правила повторов и восстановления в разделе [Надёжность](reliability.md) и
[FAQ](../08-faq.md).

<!-- footer-nav -->
---

← Назад: [Безопасность](security.md) | [Оглавление](../README.md) | [Обзор архитектуры](../05-architecture/overview.md) Далее →
<!-- /footer-nav -->
