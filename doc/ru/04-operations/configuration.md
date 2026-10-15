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

## Корневой `./.env` (Docker Compose)

| Переменная | По умолчанию в `docker-compose.yml` | Описание |
|---|---|---|
| `WPS_UI_PORT` | `3000` | Порт UI на хосте (в этом репозитории — `3080`) |
| `WPS_API_PORT` | `9501` | Порт API backend на хосте |
| `WPS_MOCK_PORT` | `9502` | Порт мок-сервиса на хосте |
| `WPS_PG_PORT` | `5432` | Порт PostgreSQL на хосте (в этом репозитории — `55432`) |
| `WPS_REDIS_STREAM_PORT` | `6379` | Порт Redis шины событий на хосте |
| `WPS_REDIS_DATA_PORT` | `6380` | Порт Redis для данных на хосте |
| `CRM_API_BASE_URL` | `http://mock:9502` | Передаётся в backend: базовый адрес API CRM |
| `CRM_API_STATIC_KEY` | `mySecretKey` | Передаётся в backend: префикс ключа API CRM |
| `MOCK_SERVICE_URL` | `http://mock:9502` | Передаётся в backend; **пустое** значение — справочник модулей загружается из CRM |

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
| `EXECUTION_TTL_HOURS` | `24` | TTL живого состояния выполнения `exec:<uuid>` в Redis |
| `EXECUTION_FINISHED_TTL_SECONDS` | `3600` | TTL состояния после завершения выполнения |
| `IDEMPOTENCY_TTL_HOURS` | `24` | TTL ключей `idem:<event_id>:<process_id>` |
| `EXECUTION_LOCK_TTL` | `30` с (не меньше 5) | Блокировка выполняющегося выполнения; продлевается перед каждой попыткой шага |
| `MAX_CHILD_DEPTH` | `10` | Максимальная вложенность ведомых процессов |
| `RECOVERY_STALE_SECONDS` | `300` (не меньше 30) | Незавершённые выполнения старше этого срока `RecoveryJob` продолжает или помечает ошибочными |

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
| `CyclicJobs` | `StreamConsumerJob`, `RecoveryJob` | Фоновые циклы (0,5 с и 30 с) |
| `cyclic_jobs.strategy` | `ALL_WORKERS` | Задачи работают в каждом HTTP-воркере |
| `cyclic_jobs.monitoring_interval` | `60` | Интервал проверки состояния циклических задач фреймворком, секунды (при `APP_DEBUG` ещё и пишет их статистику в лог) |
| `StateContainerInitiation` | `["Wps\\State\\StateInitiation"]` | Создаёт `AppConfig` и общую таблицу метрик до форка воркеров |
| `events` | `[]` | События фреймворка (не используются) |

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
