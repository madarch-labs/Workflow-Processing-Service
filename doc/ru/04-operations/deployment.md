# Развёртывание

Как развернуть, обновить и масштабировать WPS в Docker Compose и что поменять перед запуском в продакшен.

<!-- lang-switch -->
[🇬🇧 English](../../en/04-operations/deployment.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Сервисы

Весь стек описан в `docker-compose.yml` в корне репозитория. Все сервисы находятся в одной docker-сети
(`<проект>_default`, например `swoole_worckflow_default`) и обращаются друг к другу по имени сервиса.

| Сервис | Образ / сборка | Порт в контейнере | Порт на хосте (переменная, по умолчанию) | Том | Назначение |
|---|---|---|---|---|---|
| `postgres` | `postgres:15-alpine` | 5432 | `WPS_PG_PORT` (5432) | `pgdata` | Процессы, выполнения, аудит шагов, пользователи, отметки однократного запуска |
| `redis-stream` | `redis:7-alpine`, `--appendonly yes` | 6379 | `WPS_REDIS_STREAM_PORT` (6379) | `redis-stream` | Шина событий: стрим `crm_events`, группа `wps_workers`, стрим недоставленных сообщений `crm_events:dlq` |
| `redis-data` | `redis:7-alpine`, `--appendonly yes` | 6379 | `WPS_REDIS_DATA_PORT` (6380) | `redis-data` | Кэши, живое состояние выполнений, блокировки, ключи идемпотентности |
| `backend` | `back/Dockerfile` (`phpswoole/swoole:6.0-php8.3` + расширения `bcmath`, `intl`) | 9501 | `WPS_API_PORT` (9501) | — | REST API, чтение стрима, восстановление выполнений |
| `mock` | `mock/Dockerfile` | 9502 | `WPS_MOCK_PORT` (9502) | — | Генератор событий и эмуляция API CRM (разработка и демо) |
| `mailpit` | `axllent/mailpit:v1.21` | 1025 (SMTP), 8025 (веб и API) | `WPS_MAILPIT_PORT` (8025) | — | Тестовый SMTP-сервер: письма `send_email` не доставляются, а показываются в веб-интерфейсе и API `/api/v1/messages`. Backend использует его через `MAILER_DSN=smtp://mailpit:1025`. В продакшене замените `MAILER_DSN` на настоящий SMTP и уберите сервис |
| `frontend` | `front/Dockerfile` (сборка на Node, раздача через nginx) | 80 | `WPS_UI_PORT` (3000) | — | Одностраничный UI; nginx проксирует `/api/` на `backend:9501` |

```mermaid
flowchart LR
    user([Браузер]) -->|":WPS_UI_PORT"| fe[frontend<br/>nginx]
    fe -->|"/api/ → :9501"| be[backend<br/>Swoole]
    crm[(CRM или мок)] -->|XADD crm_events| rs[(redis-stream)]
    be -->|XREADGROUP / XACK| rs
    be --> rd[(redis-data)]
    be --> pg[(postgres)]
    be -->|"HTTP, X-Auth-Key"| crm
    be -->|"SMTP :1025"| mp[mailpit]
```

Backend стартует, когда PostgreSQL прошёл healthcheck (`depends_on: condition: service_healthy`) и оба Redis запущены.
Стрим событий и Redis для данных намеренно разнесены: шиной событий может владеть сторона CRM, а кэши и состояние
выполнений остаются внутри WPS.

> **Примечание**
> В небольшой установке оба Redis можно заменить одним сервером: укажите в `REDIS_STREAM_*` и `REDIS_DATA_*` один хост и
> другой номер базы в `REDIS_DATA_DB`. Ключи не пересекаются: стрим — `crm_events*`, данные — `proc_cache:*`,
> `dict:modules`, `crm:capabilities`, `exec:*`, `lock:exec:*`, `idem:*`, `assign:*`, `mail:*`, `oauth:token:*`,
> `side_effects:purge`. Мок хранит свои объекты в redis-stream (`mock_crm:*`, `mock_crm_rel:*`, `mock:*`).

## Первый запуск

```bash
docker compose up -d --build
docker compose ps
curl -s http://localhost:9501/api/health
```

Что происходит при старте backend (`back/docker/entrypoint.sh`):

1. Если в контейнере нет `/srv/back/.env`, он копируется из `.env.example`. Переменные окружения из `docker-compose.yml`
   всегда важнее этого файла (см. [Конфигурация](configuration.md)).
2. `php migrations/migrate.php` ждёт базу (до `DB_WAIT_SECONDS`, 60 с) и применяет новые файлы `migrations/*.sql`
   в порядке имён. Применённые файлы записываются в таблицу `schema_migrations`, поэтому перезапуск безопасен.
3. `php server.php` запускает HTTP-сервер Swoole. Строка лога `[WPS] listening on 0.0.0.0:9501` означает, что API поднялся.

Администратор по умолчанию `admin@wps.local` / `admin` создаётся миграцией `002_seed_admin.sql`.

## Образы

| Образ | Dockerfile | Основа | Что внутри |
|---|---|---|---|
| backend, стадия `prod` | `back/Dockerfile` (контекст — корень репозитория) | `phpswoole/swoole:6.0-php8.3` + `bcmath`, `intl` (`libicu-dev`) | `composer install --no-dev`, оптимизированный autoload, `ENTRYPOINT docker/entrypoint.sh`, порт 9501. Именно эту стадию собирает `docker-compose.yml` (`target: prod`) |
| backend, стадия `test` | то же | та же база | Зависимости для разработки и `ENTRYPOINT vendor/bin/phpunit` |
| mock | `mock/Dockerfile` | `phpswoole/swoole:6.0-php8.3` (уже с `ext-redis`) | Встроенный сервер PHP `php -S 0.0.0.0:9502 mock_server.php` |
| frontend | `front/Dockerfile` | `node:22-alpine` (сборка) → `nginx:1.27-alpine` | `npm ci && npm run build`, `dist/` и `front/nginx.conf` |

`bcmath` нужен для точной арифметики и сравнения `decimal`; `intl` — для `format_date_locale()` (без него функция не
регистрируется). Юнит-тесты:

```bash
docker build -q -f back/Dockerfile --target test -t wps-backend-test .
docker run --rm wps-backend-test --testsuite unit
```

`vendor/` в репозиторий не входит: зависимости ставятся при сборке образа. Добавить пакет:
`docker run --rm -v $PWD/back:/app -w /app --entrypoint composer wps-backend-test require <пакет> --ignore-platform-req=ext-swoole`.

## Миграции

`back/migrations/migrate.php` выполняется при каждом старте контейнера backend (`entrypoint.sh`) до запуска сервера:

1. ждёт PostgreSQL до `DB_WAIT_SECONDS` (строки `[migrate] waiting for database...` каждые 2 с);
2. ставит сессии `SET TIME ZONE 'UTC'` и создаёт таблицу `schema_migrations (version, applied_at)`;
3. применяет файлы `*.sql`, которых нет в `schema_migrations`, **по порядку имён**, каждый в своей транзакции; при
   ошибке — откат файла, `[migrate] <версия> failed: …` и выход с кодом 1 (backend не стартует);
4. печатает `[migrate] applied <версия>` на каждый файл и итог `[migrate] done, N new migration(s)`.

Отката (down-миграций) нет; все файлы идемпотентны (`IF NOT EXISTS`, `ON CONFLICT DO NOTHING`).

| Файл | Что делает |
|---|---|
| `001_create_tables.sql` | Таблицы `users`, `processes`, `executions`, `execution_actions` и их индексы |
| `002_seed_admin.sql` | Администратор `admin@wps.local` / `admin` (если такого e-mail ещё нет) |
| `003_run_once.sql` | `processes.run_once`, таблица отметок `process_object_runs` |
| `004_program_audit.sql` | Колонки аудита плоской программы `execution_actions.pc`, `step_path`, `iteration`, `step_id`; `pc = step_index` для старых строк; индекс `(execution_id, pc)` |
| `005_secrets.sql` | Таблица `secrets` (зашифрованные секреты) |
| `006_side_effects.sql` | Журнал побочных эффектов `step_side_effects` |
| `007_trigger_on.sql` | `processes.trigger_on TEXT[]` (по умолчанию `{create,update}`) и `CHECK` допустимых типов |
| `008_step_error_handling.sql` | `executions.handled_errors`, `execution_actions.error_code`, `execution_actions.handled` |
| `009_execution_waits.sql` | `executions.waiting_until`, `wait_reason`, таблица `execution_waits`, частичный индекс ожидающих |
| `010_reserved_names_check.sql` | Схему не меняет: **отчёт** о процессах, которые используют имена `sys`, `config`, `object_before` как обычные переменные (с задачи 5.2.9 они зарезервированы) |

> **Внимание**
> Миграция 010 сообщает о найденных процессах через `RAISE NOTICE`, а `migrate.php` (PDO) уведомления PostgreSQL не
> печатает — в логе backend будет только `[migrate] applied 010_reserved_names_check`. Найденные процессы продолжают
> выполняться (по своему снимку), но **не сохранятся** после правки, пока переменные не переименованы. Проверьте их
> вручную тем же условием:
>
> ```bash
> docker compose exec -T postgres psql -U wps -d wps -c "SELECT id, name FROM processes
>   WHERE definition::text ~ '\"(var|as|item_var)\"\s*:\s*\"(sys|config|object_before)\"'
>      OR definition::text ~ '\"(target|record)\"\s*:\s*\"(sys|config|object_before)(\.|\")'"
> ```

Состояние миграций: `docker compose exec -T postgres psql -U wps -d wps -c 'SELECT * FROM schema_migrations ORDER BY 1'`.
Применить миграции без перезапуска сервера: `docker compose exec backend php migrations/migrate.php`.

## Резервное копирование

```bash
# PostgreSQL: процессы, выполнения, аудит, ожидания, секреты (зашифрованные), пользователи
docker compose exec -T postgres pg_dump -U wps -Fc wps > wps-$(date +%F).dump
# восстановление в пустую базу
docker compose exec -T postgres pg_restore -U wps -d wps --clean --if-exists < wps-2026-10-27.dump
```

Вместе с дампом храните `WPS_SECRETS_KEY`: без него таблица `secrets` не расшифровывается. Redis-данные копировать не
обязательно (кэши и состояние идущих выполнений); `redis-stream` содержит ещё не обработанные события — при остановке
CRM перед обслуживанием дождитесь `lag = 0` и `pending = 0` в `/api/health`.

## Порты

Пользователям нужен только порт UI. Остальное может оставаться внутри docker-сети:

| Порт | Кому нужен |
|---|---|
| UI (`WPS_UI_PORT`) | Пользователям |
| API (`WPS_API_PORT`) | Скриптам и интеграциям, которые вызывают REST API напрямую; Prometheus для `/metrics` |
| `redis-stream` | CRM (она публикует события). Если CRM в той же docker-сети, порт публиковать не нужно |
| Mailpit (`WPS_MAILPIT_PORT`) | Разработчикам и тестировщикам: просмотр писем стенда. В продакшене сервиса нет |
| `postgres`, `redis-data`, `mock` | В продакшене никому; публикуйте только для отладки |

Порты на хосте меняются в `./.env` (переменные `WPS_*_PORT`). В `.env` этого репозитория UI уже перенесён на 3080, а
PostgreSQL — на 55432: на машине разработчика порты 3000 и 5432 были заняты.

## Обновление

Код backend вшит в образ. После обновления кода пересоберите изменённые сервисы:

```bash
git pull
docker compose up -d --build backend frontend
```

Миграции применяются автоматически при старте backend. Данные хранятся в именованных томах `pgdata`, `redis-stream` и
`redis-data`: `docker compose down` их сохраняет, `docker compose down -v` удаляет.

PHP-фреймворк SwooleApp подключён как обычная composer-зависимость (`sidalex/swoole-app`, версия зафиксирована в
`back/composer.lock`). Как обновить только фреймворк — раздел «Сборка бэкенда» в корневом `README.md`.

## Несколько стендов рядом

Имя проекта Compose изолирует стенды. Так снимались скриншоты документации, не затрагивая основной стенд:

```bash
WPS_UI_PORT=3081 WPS_API_PORT=9511 WPS_MOCK_PORT=9512 WPS_PG_PORT=55433 \
WPS_REDIS_STREAM_PORT=6389 WPS_REDIS_DATA_PORT=6390 \
CRM_API_BASE_URL=http://mock:9502 CRM_API_STATIC_KEY=mySecretKey MOCK_SERVICE_URL=http://mock:9502 \
docker compose -p wpsdoc up -d --build
```

Переменные из командной строки важнее значений в `./.env`.

## Масштабирование

- **Воркеры внутри одного backend.** HTTP-воркеров `SWOOLE_WORKER_NUM` (по умолчанию 2). Циклические задачи работают со
  стратегией `ALL_WORKERS`, поэтому каждый воркер — отдельный потребитель группы `wps_workers` и читает свою часть стрима.
  У каждого воркера свой пул соединений PostgreSQL (`DB_POOL_SIZE`, 16) и пулы Redis. Шаги внутри воркера выполняются в
  корутинах, поэтому медленный ответ CRM не блокирует другие выполнения.
- **Несколько контейнеров backend.** Поддерживается: имя потребителя — `<hostname>-<pid>`, все экземпляры входят в одну
  группу, и Redis распределяет сообщения между ними. Ключи идемпотентности и блокировки выполнений лежат в общем
  `redis-data`, поэтому сообщение, повторно доставленное другому экземпляру, не выполнится дважды
  (см. [Надёжность](reliability.md)). Для API поставьте экземпляры за балансировщик; UI нужен только прокси `/api/`.
- **Метрики** хранятся в общей памяти одного процесса backend и обнуляются при перезапуске. При нескольких экземплярах
  собирайте метрики с каждого.

```bash
docker compose up -d --scale backend=2   # сначала уберите фиксированный порт backend на хосте, иначе порты конфликтуют
```

> **Внимание**
> При `--scale` проброс `ports: "9501:9501"` у backend конфликтует. Уберите проброс порта (UI обращается к backend по
> docker-сети) или поставьте перед экземплярами reverse proxy.

## Чек-лист для продакшена

- [ ] Задайте длинный случайный `JWT_SECRET` в `docker-compose.yml` (значение `change-me-in-production-…` из репозитория публично).
- [ ] Войдите и смените пароль `admin@wps.local` или создайте своего администратора и удалите стандартного.
- [ ] Смените `POSTGRES_PASSWORD` / `DB_POSTGRES_PASSWORD` (по умолчанию `secret`).
- [ ] Укажите в `CORS_ALLOW_ORIGIN` адрес UI вместо `*`, если API доступен с других адресов.
- [ ] Направьте `CRM_API_BASE_URL` / `CRM_API_STATIC_KEY` на настоящую CRM, очистите `MOCK_SERVICE_URL` и не запускайте сервис `mock`.
- [ ] Укажите в `MAILER_DSN` настоящий SMTP-сервер (или секрет вида `smtp_dsn` в действиях), задайте `MAILER_FROM` и `MAILER_ALLOWED_FROM`, не запускайте сервис `mailpit`.
- [ ] Публикуйте только порт UI (и порт API, если он нужен интеграциям); PostgreSQL и Redis держите внутри сети или закройте паролями (`REDIS_*_PASSWORD`).
- [ ] Оставьте `APP_DEBUG=false`: при `true` клиентам возвращаются тексты внутренних ошибок.
- [ ] Настройте резервное копирование тома `pgdata`. В томах Redis только кратковременное состояние и кэши, но в `redis-stream` лежат ещё не обработанные события.
- [ ] Собирайте `/metrics` и настройте оповещение, если `/api/health` отвечает `503` (см. [Мониторинг](monitoring.md)).
- [ ] Проверьте раздел [Безопасность](security.md).

<!-- footer-nav -->
---

← Назад: [Готовые сценарии](../03-user-guide/recipes.md) | [Оглавление](../README.md) | [Конфигурация](configuration.md) Далее →
<!-- /footer-nav -->
