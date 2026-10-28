# Конфигурация

Все переменные окружения и ключи конфигурации WPS: значения по умолчанию и назначение.

<!-- lang-switch -->
[🇬🇧 English](../../en/04-operations/configuration.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Откуда берутся значения

| Уровень | Файл | Кто читает |
|---|---|---|
| Порты на хосте и подключение к CRM | `./.env` в корне репозитория | `docker compose` (подстановка переменных в `docker-compose.yml`) |
| Окружение контейнеров | секции `environment:` в `docker-compose.yml` | backend, mock, postgres |
| Значения из файла backend | `back/.env` (если его нет, entrypoint копирует `back/.env.example`) | `back/server.php`, `back/migrations/migrate.php` |
| Значения в коде | `back/src/Config/AppConfig.php`, `back/server.php` | если переменная нигде не задана |
| Конфигурация фреймворка | `back/config/config.json` | SwooleApp: контроллеры, middleware, циклические задачи |

Порядок внутри backend (`Wps\Kernel\Env`): **реальная переменная окружения важнее `back/.env`**, а `back/.env` важнее значения в
коде. `back/.env` никогда не перезаписывает переменную, которая уже есть в окружении процесса, даже пустую. Значения читаются
один раз при старте: после изменения перезапустите backend.

> **Примечание**
> `back/.env` исключён из контекста сборки Docker (`.dockerignore`), поэтому контейнер всегда стартует с копией
> `back/.env.example`. Backend в контейнере настраивайте через `docker-compose.yml` / `./.env`, а не через `back/.env`.

### Как разбираются значения

`Wps\Kernel\Env` и `AppConfig::fromEnv()` разбирают строки окружения по одним правилам:

| Вид значения | Правило | Пример |
|---|---|---|
| Строка в `back/.env` | `KEY=value`; строки с `#` в начале и без `=` пропускаются; кавычки `"…"` / `'…'` вокруг значения снимаются | `MAILER_FROM_NAME="WPS bot"` |
| Целое (`Env::int`) | не число или пусто — значение по умолчанию; затем применяются границы (`max(…)`, `min(…)`) из `AppConfig` | `WAIT_POLL_INTERVAL_MS=50` → `100` |
| Дробное (`Env::float`) | так же | `CRM_API_TIMEOUT=2.5` |
| Логическое (`Env::bool`) | `1`, `true`, `yes`, `on` (без учёта регистра) — истина, иное непустое — ложь | `APP_DEBUG=on` |
| Флаги `HTTP_ALLOW_PLAIN_HTTP`, `HTTP_TLS_VERIFY` | выключаются **только** строкой `false`; любое другое значение — включено | `HTTP_TLS_VERIFY=0` — проверка **остаётся включённой** |
| Списки (`HTTP_*_HOSTS`, `HTTP_*_PORTS`, `HTTP_ALLOW_PRIVATE_CIDRS`, `MAILER_ALLOWED_FROM`) | через запятую, пробелы обрезаются, приводятся к нижнему регистру, пустые элементы отбрасываются; в списках портов нечисловые элементы игнорируются | `HTTP_ALLOW_HOSTS=api.example.com, *.erp.local` |
| Перечисления (`ACTION_RETRY_JITTER`, `CRM_ALERT_PROFILE`) | неизвестное значение молча заменяется значением по умолчанию | `ACTION_RETRY_JITTER=random` → `full` |
| Длительности (`ACTION_RETRY_MAX_DELAY`, `DELAY_MAX`) | короткая форма (`500ms`, `30s`, `10m`, `1h`, `2d`, `1w`, можно сочетать: `1h30m`) или ISO 8601 (`PT5M`, `P400D`); для `ACTION_RETRY_MAX_DELAY` число без единицы — миллисекунды, месяцы и годы не допускаются, неразбираемое значение — `1h` | `ACTION_RETRY_MAX_DELAY=15m` |
| `DB_POSTGRES_*` и синонимы `DB_*` | берётся первое **непустое** значение из пары | `DB_HOST=db` работает, если `DB_POSTGRES_HOST` не задан |

Значения, которые проверяются при старте и останавливают сервис: `WPS_TIMEZONE` (неизвестный пояс) и
`WPS_SECRETS_KEY` / `WPS_SECRETS_KEY_PREVIOUS` (не base64 от 32 байт). Остальные ошибки молча исправляются границами —
проверяйте действующие значения командами из раздела [Проверка действующей конфигурации](#проверка-действующей-конфигурации).

## Корневой `./.env` (Docker Compose)

| Переменная | По умолчанию в `docker-compose.yml` | Описание |
|---|---|---|
| `WPS_UI_PORT` | `3000` | Порт UI на хосте (в этом репозитории — `3080`) |
| `WPS_API_PORT` | `9501` | Порт API backend на хосте |
| `WPS_MOCK_PORT` | `9502` | Порт мок-сервиса на хосте |
| `WPS_PG_PORT` | `5432` | Порт PostgreSQL на хосте (в этом репозитории — `55432`) |
| `WPS_REDIS_STREAM_PORT` | `6379` | Порт Redis шины событий на хосте |
| `WPS_REDIS_DATA_PORT` | `6380` | Порт Redis для данных на хосте |
| `WPS_MAILPIT_PORT` | `8025` | Порт веб-интерфейса и API Mailpit на хосте |
| `CRM_API_BASE_URL` | `http://mock:9502` | Передаётся в backend: базовый адрес API CRM |
| `CRM_API_STATIC_KEY` | `mySecretKey` | Передаётся в backend: префикс ключа API CRM |
| `MOCK_SERVICE_URL` | `http://mock:9502` | Передаётся в backend; **пустое** значение — справочник модулей загружается из CRM |

Кроме перечисленных, `docker-compose.yml` пробрасывает в backend из `./.env` или командной строки (со значением по
умолчанию после `:-`): `WPS_TIMEZONE` (`UTC`), `WPS_SECRETS_KEY` (тестовый ключ), `WPS_SECRETS_KEY_PREVIOUS` (пусто),
`MAILER_DSN` (`smtp://mailpit:1025`), `MAILER_FROM` (`wps@wps.local`), `MAILER_FROM_NAME` (`WPS`),
`HTTP_ALLOW_PRIVATE_CIDRS` (`172.16.0.0/12,192.168.0.0/16`), `HTTP_DENY_HOSTS`
(`localhost,metadata.google.internal,postgres,redis-stream,redis-data`), `RECOVERY_STALE_SECONDS` (`300`),
`TELEGRAM_API_BASE` (`https://api.telegram.org`), `CRM_UI_BASE_URL` (пусто), `CRM_ALERT_PROFILE` (`suitecrm8`).
Любую другую переменную backend добавьте в секцию `environment:` (или в `docker-compose.override.yml`) — сама по себе
переменная из `./.env` в контейнер не попадает.

> **Внимание**
> `./.env` этого репозитория сейчас направляет backend на локальную SuiteCRM 8 (`CRM_API_BASE_URL=http://suitecrm8/api/v9`,
> `MOCK_SERVICE_URL=` пустой). Чтобы работать с моком, удалите три строки `CRM_*`/`MOCK_*` из `./.env` или переопределите их
> в командной строке. См. [Интеграция с CRM](crm-integration.md).

## Переменные backend

### Сервер и Swoole (`back/server.php`)

| Переменная | По умолчанию | Описание |
|---|---|---|
| `APP_DEBUG` | `false` (`config.json`) | `true` — в ответах `500` возвращается текст внутреннего исключения. В продакшене оставьте `false` |
| `SERVER_HOST` | `0.0.0.0` | Адрес, на котором слушает сервер |
| `SERVER_PORT` | `9501` | Порт |
| `SERVER_MODE` | `PROCESS` | Режим сервера Swoole (`PROCESS` / `BASE`) |
| `SWOOLE_WORKER_NUM` | `2` | HTTP-воркеры; каждый ещё и потребитель стрима |
| `SWOOLE_TASK_WORKER_NUM` | `1` | Task-воркеры (циклические задачи в них не запускаются) |
| `SWOOLE_MAX_COROUTINE` | `3000` | Максимум корутин на воркер |
| `LOG_LEVEL` | `info` | `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` |
| `WPS_TIMEZONE` | `UTC` | Часовой пояс бизнес-логики (имя IANA, например `Europe/Moscow`): в него переводятся даты со временем перед сравнением с `cast: date` (см. [Тип сравнения (cast)](../03-user-guide/triggers-and-conditions.md#тип-сравнения-cast)); в нём работают функции дат в выражениях — `today()`, `date()`, календарные дни `add_days`/`add_months`, `date_diff(…, 'days')`, границы периодов `start_of_*`/`end_of_*`, части даты, `is_weekend`, `format_date` по умолчанию; тип изменения `date_shift` — база `today`, календарные сдвиги (дни, недели, месяцы), `set_time` и `result: date` (см. [Сдвиг даты](../03-user-guide/change-types-and-expressions.md#сдвиг-даты)); якоря [относительных дат](../03-user-guide/triggers-and-conditions.md#относительные-даты) в условиях и фильтрах (`today`, `start_of_month`, …); дата без времени там, где нужен момент, — полночь в этом поясе (см. [Даты и время](../03-user-guide/change-types-and-expressions.md#даты-и-время)). Даты из CRM считаются UTC, функции возвращают дату-время тоже в UTC. Неверное имя — сервис не стартует (`WPS_TIMEZONE: unknown time zone …`); при старте значение пишется в лог (`Business time zone`, уровень info). Рабочие дни и праздники не учитываются. В `docker-compose.yml` передаётся как `${WPS_TIMEZONE:-UTC}` |

### Redis — шина событий

| Переменная | По умолчанию | Описание |
|---|---|---|
| `REDIS_STREAM_HOST` | `127.0.0.1` (в compose — `redis-stream`) | Хост |
| `REDIS_STREAM_PORT` | `6379` | Порт |
| `REDIS_STREAM_PASSWORD` | пусто | Пароль (`AUTH`) |
| `REDIS_STREAM_NAME` | `crm_events` | Имя стрима; стрим недоставленных сообщений — `<имя>:dlq` |
| `REDIS_STREAM_CONSUMER_GROUP` | `wps_workers` | Группа потребителей (создаётся автоматически с id `0`) |
| `REDIS_STREAM_BATCH` | `10` | Сообщений за один `XREADGROUP` |
| `REDIS_STREAM_BLOCK_MS` | `2000` | Время ожидания `XREADGROUP BLOCK` |
| `REDIS_STREAM_CLAIM_IDLE_MS` | `300000` (не меньше 1000) | Неподтверждённые сообщения, простоявшие дольше, забираются заново (`XAUTOCLAIM`) |

### Redis — данные

| Переменная | По умолчанию | Описание |
|---|---|---|
| `REDIS_DATA_HOST` | `127.0.0.1` (в compose — `redis-data`) | Хост |
| `REDIS_DATA_PORT` | `6379` в коде, `6380` в `.env.example` (в compose — `6379`) | Порт |
| `REDIS_DATA_PASSWORD` | пусто | Пароль |
| `REDIS_DATA_DB` | `0` | Номер базы |

### PostgreSQL

| Переменная | По умолчанию | Описание |
|---|---|---|
| `DB_POSTGRES_HOST` (синоним `DB_HOST`) | `127.0.0.1` (в compose — `postgres`) | Хост |
| `DB_POSTGRES_PORT` (синоним `DB_PORT`) | `5432` | Порт |
| `DB_POSTGRES_DB` (синоним `DB_NAME`) | `wps` | База данных |
| `DB_POSTGRES_USER` (синоним `DB_USER`) | `wps` | Пользователь |
| `DB_POSTGRES_PASSWORD` (синоним `DB_PASSWORD`) | пусто в коде, `secret` в `.env.example` и compose | Пароль |
| `DB_POOL_SIZE` | `16` (не меньше 2) | Размер пула соединений на воркер |
| `DB_WAIT_SECONDS` | `60` | Сколько `migrate.php` ждёт базу при старте |

### CRM

| Переменная | По умолчанию | Описание |
|---|---|---|
| `CRM_API_BASE_URL` | `http://127.0.0.1:9502` | Базовый адрес API CRM. К нему дописывается `/api/v8`, если адрес уже не заканчивается на `/api/vN` |
| `CRM_API_STATIC_KEY` | пусто | Префикс ключа в заголовке `X-Auth-Key` |
| `CRM_API_TIMEOUT` | `10` | Таймаут HTTP, секунды |
| `MOCK_SERVICE_URL` | не задан | Если задан, справочник модулей сначала загружается из `<url>/modules`, затем из CRM |

### Выполнение

| Переменная | По умолчанию | Описание |
|---|---|---|
| `PROCESS_CACHE_TTL` | `60` с | Кэш активных процессов по типу объекта (`proc_cache:<тип>`); сбрасывается при сохранении процесса |
| `DICT_CACHE_TTL` | `300` с | Кэш справочника модулей (`dict:modules`) |
| `ACTION_RETRY_MAX` | `3` | Повторы шага после восстановимой ошибки |
| `ACTION_RETRY_BASE_DELAY_MS` | `1000` | Задержка перед первым повтором |
| `ACTION_RETRY_BACKOFF` | `2` (не меньше 1) | Множитель задержки: `задержка = base × backoff^(попытка−1)` |
| `ACTION_RETRY_MAX_DELAY` | `1h` | Предел одной задержки повтора (длительность: `30s`, `10m`, `1h`, `PT5M`); профиль `retry.max_delay` шага переопределяет |
| `ACTION_RETRY_JITTER` | `full` | Случайный разброс задержек: `none` (как раньше, без разброса), `full` (в `[0, d]`), `equal` (`d/2 + [0, d/2]`); см. [Надёжность](reliability.md#повторы-шага) |
| `EXECUTION_TTL_HOURS` | `24` | TTL живого состояния выполнения `exec:<uuid>` в Redis |
| `EXECUTION_FINISHED_TTL_SECONDS` | `3600` | TTL состояния после завершения выполнения |
| `IDEMPOTENCY_TTL_HOURS` | `24` | TTL ключей `idem:<event_id>:<process_id>` |
| `EXECUTION_LOCK_TTL` | `30` с (не меньше 5) | Блокировка выполняющегося выполнения; продлевается перед каждой попыткой шага |
| `MAX_CHILD_DEPTH` | `10` | Максимальная вложенность ведомых процессов |
| `RECOVERY_STALE_SECONDS` | `300` (не меньше 30; в compose — `${RECOVERY_STALE_SECONDS:-300}`) | Незавершённые выполнения старше этого срока `RecoveryJob` продолжает или помечает ошибочными. В compose значение можно переопределить при запуске (`RECOVERY_STALE_SECONDS=30 docker compose up -d backend`) — так кейс 4.2 проверяет восстановление после падения воркера |
| `PROGRAM_MAX_DEPTH` | `5` (не меньше 1) | Наибольшая глубина вложенности блоков (`condition` и др.) в определении процесса; глубже — ошибка сохранения `Nesting is too deep` |
| `EXECUTION_MAX_INSTRUCTIONS` | `5000` (не меньше 10) | Наибольшее число инструкций программы в одном выполнении; больше — выполнение `failed` с `LIMIT_EXCEEDED` (защита от бесконечных переходов) |

### Секреты

| Переменная | По умолчанию | Описание |
|---|---|---|
| `WPS_SECRETS_KEY` | пусто (в `docker-compose.yml` — тестовый ключ) | Ключ хранилища секретов: base64 от 32 байт (`php bin/secrets-key.php`). Пусто — секреты недоступны, сервис работает; неверный формат — сервис не стартует. См. [Безопасность → Секреты процессов](security.md#секреты-процессов) |
| `WPS_SECRETS_KEY_PREVIOUS` | пусто | Прежний ключ на время ротации: строки, зашифрованные им, ещё расшифровываются; после `POST /api/secrets/rotate` уберите |
| `SECRETS_CACHE_TTL` | `60` с (не меньше 0) | Сколько расшифрованное значение хранится в памяти воркера; столько же длится переход на новое значение после изменения секрета |

### Журнал побочных эффектов

| Переменная | По умолчанию | Описание |
|---|---|---|
| `SIDE_EFFECTS_RETENTION_DAYS` | `30` (не меньше 1) | Через сколько дней записи журнала внешних вызовов `step_side_effects` удаляются (очистка раз в час в `RecoveryJob`). См. [Надёжность → Побочные эффекты и повторы](reliability.md#побочные-эффекты-и-повторы) |
| `WPS_MAIL_MESSAGE_ID_DOMAIN` | `wps.local` | Домен в `Message-ID` писем: `<{execution_id}.{effect_key}@домен>` — по нему почтовые системы отсекают повторную отправку |

### Почта (`send_email`)

| Переменная | По умолчанию | Описание |
|---|---|---|
| `MAILER_DSN` | пусто (в `docker-compose.yml` — `smtp://mailpit:1025`) | Транспорт по умолчанию для [`send_email`](../03-user-guide/actions.md#отправить-письмо): `smtp://user:pass@host:587`, `smtps://user:pass@host:465`, параметр `?timeout=`. Пусто — процесс с `send_email` без поля `transport` не сохраняется. Пароль маскируется в аудите и логах |
| `MAILER_FROM` | пусто (compose: `wps@wps.local`) | Отправитель по умолчанию; пусто — в действии обязательно поле `from` |
| `MAILER_FROM_NAME` | пусто (compose: `WPS`) | Имя отправителя по умолчанию |
| `MAILER_ALLOWED_FROM` | пусто (любые) | Разрешённые отправители через запятую: адреса или домены `@company.ru`. Другой `from` не сохраняется (`Sender address is not allowed`) |
| `MAILER_TIMEOUT` | `15` с (не меньше 1) | Таймаут SMTP-соединения; держите меньше `EXECUTION_LOCK_TTL` |
| `MAILER_MAX_RECIPIENTS` | `50` (не меньше 1) | Предел получателей одного письма (`to` + `cc` + `bcc`); больше — шаг завершается ошибкой |
| `MAILER_RATE_PER_MINUTE` | `60` (`0` — без лимита) | Писем в минуту на один транспорт; сверх лимита шаг повторяется (`MAIL_RATE_LIMIT`) |
| `MAILER_RATE_PER_RECIPIENT_HOUR` | `10` (`0` — без лимита) | Писем в час одному получателю; сверх лимита получатель пропускается (`rate_limited`) |
| `MAILPIT_API_URL` | `http://mailpit:8025` (compose) | Адрес API Mailpit для интеграционных тестов; кодом сервиса не используется |

### Исходящие HTTP-запросы (`http_request`)

| Переменная | По умолчанию | Описание |
|---|---|---|
| `HTTP_ALLOW_PLAIN_HTTP` | `true` | `false` — запросы по `http://` запрещены (только `https`); рекомендуется для прода |
| `HTTP_DENY_PORTS` | `22,25,3306,5432,6379,6380,9200,11211` | Запрещённые порты |
| `HTTP_ALLOW_PORTS` | пусто (любые, кроме запрещённых) | Если задан — разрешены только эти порты |
| `HTTP_ALLOW_HOSTS` | пусто (любые публичные) | Белый список хостов через запятую, `*.company.ru` — поддомены |
| `HTTP_DENY_HOSTS` | `localhost, metadata.google.internal, postgres, redis-stream, redis-data, mock` + хост CRM (compose стенда: без `mock` и хоста CRM) | Всегда запрещённые хосты; заданное значение заменяет список целиком |
| `HTTP_ALLOW_PRIVATE_CIDRS` | пусто (compose стенда: `172.16.0.0/12,192.168.0.0/16`) | Частные сети, в которые можно ходить (внутренние ERP и т. п.). На стенде — чтобы тесты вызывали mock в сети docker; **в проде не копируйте** |
| `HTTP_TLS_VERIFY` | `true` | Проверка сертификата по имени хоста; `false` — только для отладки |
| `HTTP_CA_FILE` | пусто (системные CA) | Свой файл корневых сертификатов |
| `HTTP_MAX_TIMEOUT` | `30` с | Верхняя граница `timeout` действия; держите меньше `EXECUTION_LOCK_TTL` |
| `HTTP_MAX_RESPONSE_BYTES` | `5242880` (5 МБ) | Предел ответа; считается при чтении, превышение — `Response is too large` |
| `HTTP_MAX_REQUEST_BYTES` | `1048576` (1 МБ) | Предел тела запроса |
| `HTTP_RETRY_AFTER_MAX` | `60` с | Верхняя граница задержки по `Retry-After` |
| `HTTP_MAX_STORED_BODY_BYTES` | `65536` (64 КБ) | Тело ответа больше этого в переменную не кладётся (`body_truncated: true`) |

Подробно — [Безопасность → Исходящие HTTP-запросы](security.md#исходящие-http-запросы-ssrf-защита).

### Уведомления (`notify`)

| Переменная | По умолчанию | Описание |
|---|---|---|
| `CRM_ALERT_PROFILE` | `suitecrm8` | Набор полей записи `Alerts` канала `crm_alert` и шаблон ссылки на запись по умолчанию: `suitecrm8` (поля SuiteCRM 8, ссылка `#/{module_kebab}/record/{id}`), `suitecrm7` (ссылка `index.php?module={module}&action=DetailView&record={id}`), `custom` (поля только из `fields` канала, ссылка — только из `CRM_RECORD_URL_TEMPLATE`) |
| `CRM_RECORD_URL_TEMPLATE` | пусто — по профилю | Шаблон ссылки на запись: `{module}`, `{module_lower}` (`Leads` → `leads`), `{module_kebab}` (`AOS_Contracts` → `aos-contracts`), `{id}`. Относительный (`#/…`) или абсолютный (`https://crm.company.ru/…`) |
| `CRM_UI_BASE_URL` | пусто — `CRM_API_BASE_URL` без `/api/…` | Адрес интерфейса CRM для абсолютных ссылок «Открыть» в мессенджерах (колокольчик CRM получает относительную ссылку). Задайте, если API CRM и её интерфейс на разных адресах |
| `TELEGRAM_API_BASE` | `https://api.telegram.org` | Адрес Bot API. Меняется для тестов (`http://mock:9502/mock/telegram`) или собственного Bot API-сервера |

Запросы к Telegram, Slack и Teams проходят защиту исходящих HTTP-запросов (таблица выше): при заданном
`HTTP_ALLOW_HOSTS` добавьте `api.telegram.org`, `hooks.slack.com` и хост вебхука Teams. См.
[Действия → Уведомление](../03-user-guide/actions.md#уведомление).

### Назначение ответственного (`assign`)

| Переменная | По умолчанию | Описание |
|---|---|---|
| `ASSIGN_POOL_CACHE_TTL` | `60` с (`0` — без кэша) | Сколько кэшируется состав группы безопасности (`pool.security_group`). Новый сотрудник группы попадает в распределение не позже чем через это время |
| `ASSIGN_LEAST_BUSY_MAX_POOL` | `50` (1..200) | Наибольший пул стратегии `least_busy`: на каждого пользователя — отдельный запрос подсчёта в CRM. Больше — ошибка шага `Pool is too large for least_busy` |

Очереди `round_robin` и `least_busy` хранятся в redis-data: `assign:rr:{queue_key}` — последний назначенный,
`assign:last:{queue_key}:{user}` — когда пользователь назначался (30 дней), `assign:decided:{execution}:{pc}:{iteration}` —
решение шага для повторов (сутки). Сбросить очередь, чтобы распределение началось с первого по id:

```bash
docker compose exec redis-data redis-cli DEL assign:rr:<queue_key>
```

Ключ очереди по умолчанию — `p{id процесса}:{id шага или pc}` (виден в `request_data.queue_key` шага). См.
[Действия → Назначить ответственного](../03-user-guide/actions.md#назначить-ответственного).

### Связи записей (`link_records`, `unlink_records`)

| Переменная | По умолчанию | Описание |
|---|---|---|
| `LINK_RECORDS_MAX` | `500` (не меньше 1) | Сколько id связанных записей допускается в одном шаге; больше — ошибка шага `Too many related ids (N > 500)`. Размер пакета запроса к CRM задаёт `max_link_batch` из возможностей плагина. См. [Действия → Связать / отвязать записи](../03-user-guide/actions.md#связать--отвязать-записи) |

### Поиск, изменение и агрегаты записей (`find_records`, `update_records`, `aggregate`)

| Переменная | По умолчанию | Описание |
|---|---|---|
| `FIND_RECORDS_MAX` | `500` (не меньше 1) | Верхняя граница `limit` шага; при сохранении процесса больший `limit` отклоняется |
| `FIND_RECORDS_SCAN_MAX` | `1000` (не меньше 1) | Режим связи (`related_to`): сколько связанных записей шаг читает и проверяет локальным фильтром; дальше — `has_more: true` и предупреждение в аудите |
| `FIND_RECORDS_MAX_BYTES` | `1048576` (1 МБ, не меньше 10000) | Предел размера найденного списка в JSON; больше — ошибка шага `result is too large`, сузьте `fields` или `limit` |
| `UPDATE_RECORDS_MAX` | `200` (1..5000) | [Изменить записи списка](../03-user-guide/actions.md#изменить-записи-списка): наибольшее число записей одного шага `update_records` и верхняя граница его `max`; больше — шаг падает до изменений |
| `AGGREGATE_MAX_RECORDS` | `5000` (1..50000) | [Агрегат по записям](../03-user-guide/change-types-and-expressions.md#агрегат-по-записям): наибольшее число записей, которое читает один агрегат, и верхняя граница его `limit` (по умолчанию `limit` = 1000) |

См. [Действия → Найти записи](../03-user-guide/actions.md#найти-записи).

### Циклы (`foreach`)

| Переменная | По умолчанию | Описание |
|---|---|---|
| `LOOP_MAX_ITEMS` | `500` (1..5000) | [Цикл `foreach`](../03-user-guide/actions.md#цикл-для-каждого-элемента): наибольшее число элементов одного цикла и верхняя граница `max_items`. Снимок элементов хранится в отдельном ключе Redis `exec:{id}:loop:{pc}`, а не в хэше выполнения |

### Ожидающие выполнения и пауза (`delay`)

| Переменная | По умолчанию | Описание |
|---|---|---|
| `WAIT_POLL_INTERVAL_MS` | `1000` (не меньше 100) | Период `WaitResumeJob`: как часто воркер ищет наступившие ожидания |
| `WAIT_BATCH` | `20` | Сколько ожиданий воркер забирает за одну итерацию |
| `WAIT_LEASE_SECONDS` | `120` (не меньше 10) | Аренда строки `execution_waits` на время пробуждения; по истечении другой воркер может взять её снова |
| `WAIT_MAX_ATTEMPTS` | `10` | Попыток пробуждения, после которых выполнение завершается `failed` |
| `WAIT_INLINE_MAX_SECONDS` | `5` | Паузы и задержки повтора не длиннее этого ждут в корутине, длиннее — паркуют выполнение (`waiting`) |
| `WAIT_STATE_MAX_BYTES` | `1048576` | Наибольший размер снимка состояния для парковки; больше — шаг завершается ошибкой |
| `DELAY_MAX` | `P400D` | Наибольшая длительность одной паузы `delay` (ISO 8601 или короткая форма); поле `max_wait` шага может только уменьшить |

Подробно — [Надёжность → Ожидающие выполнения](reliability.md#ожидающие-выполнения).

### Аутентификация и HTTP

| Переменная | По умолчанию | Описание |
|---|---|---|
| `JWT_SECRET` | `insecure-dev-secret` (compose задаёт `change-me-in-production-0123456789abcdef`) | Ключ HMAC-SHA256 для токенов. **Обязательно смените** |
| `JWT_ACCESS_TTL` | `900` с (не меньше 60) | Время жизни access-токена |
| `JWT_REFRESH_TTL` | `604800` с (не меньше 300) | Время жизни refresh-токена (7 дней) |
| `CORS_ALLOW_ORIGIN` | `*` | Значение `Access-Control-Allow-Origin` |
| `API_BASE_URL` | `http://localhost:9501` (compose) | Задаётся в compose; кодом backend не читается |

## Переменные мок-сервиса

| Переменная | По умолчанию | Описание |
|---|---|---|
| `MOCK_REDIS_HOST` | `127.0.0.1` (в compose — `redis-stream`) | Redis, куда публикуются события и где хранятся объекты мок-CRM |
| `MOCK_REDIS_PORT` | `6379` | Порт |
| `MOCK_REDIS_PASSWORD` | пусто | Пароль |
| `MOCK_REDIS_STREAM` | `crm_events` | Имя стрима |
| `MOCK_CRM_STATIC_KEY` | пусто (в compose — `mySecretKey`) | Ожидаемый префикс `X-Auth-Key`; пустое значение отключает проверку. Должен совпадать с `CRM_API_STATIC_KEY` |
| `MOCK_CRM_CONTRACT` | `2.0` (compose) | Версия контракта API мока: `2.0` или `1.0` (эмуляция старого плагина: `404` на `capabilities`, поиск только по равенству, без связей и удаления). Меняется на лету: `PUT http://localhost:9502/mock/config {"contract": "1.0"}` |
| `MOCK_PUBLISH_ON_WRITE` | `0` (compose) | `1` — мок публикует событие v2 (с `before` и `wps_origin`) на каждый `POST`, `PUT`, `DELETE` через API, как плагин CRM. На лету: `PUT /mock/config {"publish_on_write": true}` |

## Контейнер PostgreSQL

Стандартные переменные образа `postgres`, заданы в `docker-compose.yml`. Держите их согласованными с `DB_POSTGRES_*`.

| Переменная | Значение в compose | Описание |
|---|---|---|
| `POSTGRES_DB` | `wps` | База, создаваемая при первом запуске (= `DB_POSTGRES_DB`) |
| `POSTGRES_USER` | `wps` | Пользователь-владелец (= `DB_POSTGRES_USER`) |
| `POSTGRES_PASSWORD` | `secret` | Пароль (= `DB_POSTGRES_PASSWORD`) |

## `back/config/config.json`

Конфигурация SwooleApp. `server.php` переопределяет `server.*` и `SWOOLE.*` из окружения и всегда выставляет
`SWOOLE.hook_flags = SWOOLE_HOOK_ALL` (PDO, phpredis, сокеты и `sleep` внутри корутин становятся неблокирующими).

| Ключ | Значение | Смысл |
|---|---|---|
| `APP_DEBUG` | `false` | Значение по умолчанию для `APP_DEBUG` |
| `server.host`, `server.port`, `server.mode` | `0.0.0.0`, `9501`, `PROCESS` | Значения по умолчанию для `SERVER_*` |
| `SWOOLE.worker_num`, `task_worker_num`, `max_coroutine` | `2`, `1`, `3000` | Значения по умолчанию для `SWOOLE_*` |
| `SWOOLE.enable_coroutine` | `true` | Корутины в обработчиках запросов |
| `SWOOLE.package_max_length` | `4194304` | Максимальный размер запроса, 4 МБ |
| `SWOOLE.http_parse_post` | `false` | Тело разбирают как JSON сами контроллеры |
| `controllers` | `["Wps\\Controllers"]` | Пространство имён, где ищутся контроллеры с `#[Route]` |
| `globalMiddlewares` | `["Wps\\Middleware\\AuthMiddleware"]` | JWT и проверка роли для каждого запроса |
| `CyclicJobs` | `StreamConsumerJob`, `RecoveryJob`, `WaitResumeJob` | Фоновые циклы (0,5 с, 30 с и `WAIT_POLL_INTERVAL_MS`) |
| `cyclic_jobs.strategy` | `ALL_WORKERS` | Задачи работают в каждом HTTP-воркере |
| `cyclic_jobs.monitoring_interval` | `60` | Интервал проверки состояния циклических задач фреймворком, секунды (при `APP_DEBUG` ещё и пишет их статистику в лог) |
| `StateContainerInitiation` | `["Wps\\State\\StateInitiation"]` | Создаёт `AppConfig` и общую таблицу метрик до форка воркеров |
| `events` | `[]` | События фреймворка (не используются) |

## Проверка действующей конфигурации

```bash
# что compose подставит в контейнеры (после ./.env и переменных командной строки)
docker compose config | sed -n '/backend:/,/ports:/p'

# окружение работающего backend
docker compose exec backend env | sort | grep -E '^(CRM_|MOCK_|WPS_|HTTP_|MAILER_|REDIS_|DB_)'

# часовой пояс бизнеса, записанный при старте
docker compose logs backend | grep 'Business time zone'
# {"level":"info","message":"Business time zone","context":{"WPS_TIMEZONE":"UTC"},...}

# возможности CRM, которую видит backend (токен любой роли)
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:9501/api/dictionaries/crm-capabilities
```

Ошибки конфигурации при старте (сервис не поднимается, текст — в `docker compose logs backend`):

| Сообщение | Причина |
|---|---|
| `WPS_TIMEZONE: unknown time zone "MSK" (use an IANA name, e.g. Europe/Moscow)` | Неизвестное имя часового пояса |
| `WPS_SECRETS_KEY: expected base64 of 32 bytes (generate with: php bin/secrets-key.php)` | Ключ секретов не base64 или не 32 байта (так же для `WPS_SECRETS_KEY_PREVIOUS`) |
| `[migrate] database is not reachable: …` | PostgreSQL недоступен дольше `DB_WAIT_SECONDS` или неверные `DB_POSTGRES_*` |
| `[migrate] 0NN_… failed: …` | Миграция упала; транзакция откатана, следующий старт попробует снова |

## Пример: настройки для продакшена

```yaml
# docker-compose.override.yml
services:
  backend:
    environment:
      JWT_SECRET: "<64 случайных символа>"
      CORS_ALLOW_ORIGIN: "https://wps.example.com"
      LOG_LEVEL: warning
      SWOOLE_WORKER_NUM: "4"
      CRM_API_TIMEOUT: "5"
```

<!-- footer-nav -->
---

← Назад: [Развёртывание](deployment.md) | [Оглавление](../README.md) | [Интеграция с CRM](crm-integration.md) Далее →
<!-- /footer-nav -->
