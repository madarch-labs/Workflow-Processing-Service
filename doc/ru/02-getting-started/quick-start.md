# Быстрый старт

Запуск WPS с мок-CRM, вход, создание процесса и проверка на тестовом событии — примерно за 10 минут.

<!-- lang-switch -->
[🇬🇧 English](../../en/02-getting-started/quick-start.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## 1. Выберите CRM: мок или настоящая

Подключение к CRM backend берёт из `./.env`:

| Переменная | Мок CRM (значение по умолчанию в `docker-compose.yml`) | Смысл |
|---|---|---|
| `CRM_API_BASE_URL` | `http://mock:9502` | Базовый URL REST API CRM. Если URL не заканчивается на `/api/vN`, к нему добавляется `/api/v8` |
| `CRM_API_STATIC_KEY` | `mySecretKey` | Статическая часть заголовка `X-Auth-Key` |
| `MOCK_SERVICE_URL` | `http://mock:9502` | Откуда брать справочник модулей CRM. **Пустое** значение — «загружать из API CRM» |

> **Внимание**
> `./.env` из репозитория может указывать на настоящую SuiteCRM 8, например `CRM_API_BASE_URL=http://suitecrm8/api/v9`
> с пустым `MOCK_SERVICE_URL`. Если SuiteCRM не запущена (см. [Интеграция с CRM](../04-operations/crm-integration.md)),
> сначала переключитесь на мок. Задайте в `./.env`:
>
> ```dotenv
> CRM_API_BASE_URL=http://mock:9502
> CRM_API_STATIC_KEY=mySecretKey
> MOCK_SERVICE_URL=http://mock:9502
> ```
>
> Или удалите эти три строки — тогда действуют значения по умолчанию из `docker-compose.yml`, то есть мок.

Заодно проверьте порты в `./.env` (`WPS_UI_PORT`, `WPS_API_PORT`, …), см. [Требования](requirements.md#порты).

## 2. Запустите стек

```bash
docker compose up -d --build
```

Первая сборка занимает несколько минут. При старте backend применяет миграции базы и создаёт администратора по
умолчанию. Проверьте, что сервис здоров:

```bash
curl http://localhost:9501/api/health
```

```json
{"success":true,"data":{"status":"ok","checks":{"postgres":{"status":"ok","latency_ms":1},"redis_data":{"status":"ok","latency_ms":1},"redis_stream":{"status":"ok","latency_ms":0}},"stream":{"stream":"crm_events","group":"wps_workers","length":0,"lag":0,"pending":0,"consumers":2},"time":"…"},"error":null}
```

`status` должен быть `ok`. Если там `degraded`, смотрите [Решение проблем](../04-operations/troubleshooting.md).

| Что | Адрес |
|---|---|
| Веб-интерфейс | `http://localhost:<WPS_UI_PORT>` (по умолчанию 3000, с `.env` из репозитория — 3080) |
| API | `http://localhost:9501/api/...` |
| Метрики | `http://localhost:9501/metrics` |
| Мок CRM и тестовые события | `http://localhost:9502/test-event?module=Deals` |
| Mailpit (письма процессов) | `http://localhost:8025` |

Состояние контейнеров — `docker compose ps`: у `backend`, `frontend`, `mock`, `postgres`, `redis-stream`, `redis-data`,
`mailpit` должен быть статус `Up` (у `postgres` и `mailpit` — `healthy`).

## 3. Войдите

Откройте интерфейс и войдите как `admin@wps.local` с паролем `admin`. Язык интерфейса переключается в правом верхнем
углу. Можно также открыть страницу с `?lang=ru`.

![Страница входа](../../images/screenshots/ru/01-login.png)
*Страница входа Workflow Processing Service.*

> **Внимание**
> После первого входа смените пароль администратора (**Пользователи** → редактировать `admin@wps.local`).
> См. [Пользователи и роли](../03-user-guide/users-and-roles.md).

## 4. Создайте процесс

Процессы собираются в интерфейсе (пошагово — в разделе [Первый процесс](first-process.md)). Для быстрой проверки
создадим процесс через API. Сначала получите токен:

```bash
TOKEN=$(curl -s -X POST http://localhost:9501/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@wps.local","password":"admin"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["access_token"])')
```

Затем создайте процесс. Для каждой сохранённой сделки со статусом `Active` он пишет пометку в `description` и сохраняет
её в CRM:

```bash
curl -s -X POST http://localhost:9501/api/processes \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
  "name": "Quick start: active deal",
  "object_type": "Deals",
  "is_child": false,
  "is_active": true,
  "definition": {
    "conditions": [{ "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }],
    "preload": [],
    "actions": [{ "type": "modify", "target": "object.description", "change_type": "set_value", "value": "Checked by WPS", "save_to_crm": true }]
  }
}'
```

В ответе — новый процесс с его `id` и `"version": 1`. Процесс появляется в списке:

![Список процессов](../../images/screenshots/ru/02-processes-list.png)
*Страница «Процессы». На скриншоте — демо-процессы из раздела [Рецепты](../03-user-guide/recipes.md).*

## 5. Отправьте тестовое событие

Мок генерирует сделку, сохраняет её в мок-CRM и публикует событие в стрим:

```bash
curl -g "http://localhost:9502/test-event?module=Deals&set[status]=Active"
```

> **Примечание**
> Флаг `-g` обязателен: без него `curl` воспринимает `[` `]` как шаблон URL.

В ответе — опубликованное сообщение и сгенерированный объект, например `"id":"96544"`.

## 6. Посмотрите выполнение

Откройте процесс в интерфейсе и прокрутите до вкладки **Журнал выполнения**. Через секунду там появится строка с
источником «Событие CRM» и статусом «Завершено». Нажмите на неё: откроются шаги, запрос к CRM и результат.

То же можно проверить через API и посмотреть запись в мок-CRM:

```bash
curl -s "http://localhost:9501/api/processes/<id>/executions" -H "Authorization: Bearer $TOKEN"
curl -s http://localhost:9502/mock/objects/Deals/<id объекта>   # "description":"Checked by WPS"
```

Сделка с другим статусом, например `set[status]=Closed`, процесс не запускает. Событие всё равно прочитано и
подтверждено, но выполнение не создаётся.

## 7. Что ещё попробовать

| Что | Как |
|---|---|
| Запустить процесс без события | На карточке процесса **Выполнить (Run)** → введите JSON объекта → **Запустить**. См. [Ручной запуск](../03-user-guide/manual-run-and-reset.md) |
| Отправить письмо | Создайте процесс из рецепта [«Приветственное письмо»](../03-user-guide/recipes.md#20-приветственное-письмо-новому-лиду-send_email) и откройте `http://localhost:8025` — письмо будет в Mailpit |
| Посмотреть подробный аудит | Откройте выполнение и раскройте строку шага: **Запрос (request_data)** и **Результат (result_data)** |
| Попробовать сложный сценарий | Возьмите JSON из [Рецептов](../03-user-guide/recipes.md) (циклы, паузы, HTTP-запросы, уведомления) и создайте процесс через API |
| Убрать тестовый процесс | На карточке процесса **Удалить** (роль `admin`) или `curl -X DELETE http://localhost:9501/api/processes/<id> -H "Authorization: Bearer $TOKEN"` |

## Если что-то не так

| Симптом | Причина и решение |
|---|---|
| `curl: (7) Failed to connect to localhost port 9501` | Backend ещё стартует или упал: `docker compose ps`, `docker compose logs backend --tail 50` |
| `/api/health` отвечает `degraded` | Недоступен PostgreSQL или один из Redis — смотрите `checks` в ответе и логи соответствующего контейнера |
| Страница входа открывается, но **Сервер недоступен. Проверьте подключение.** | Фронтенд не достучался до `/api`: проверьте, что `backend` запущен и порт `WPS_API_PORT` не переопределён без пересборки фронтенда |
| **Неверный email или пароль** для `admin@wps.local` | Пароль уже сменили. Сброс — через базу или новый том (`docker compose down -v` удаляет все данные) |
| `test-event` отвечает `Unknown module` | Мок знает модули `Accounts`, `Contacts`, `Leads`, `Deals`, `Invoices`, `Orders`, `Payments`, `Tasks`, `Calls`, `Meetings`, `Tickets` и др. — регистр важен |
| Событие отправлено, а выполнения нет | Процесс неактивен, другой тип объекта или условия не выполнены — проверьте [ручным запуском](../03-user-guide/manual-run-and-reset.md), см. [Частые вопросы](../08-faq.md#процесс-не-запустился-что-проверить) |
| Выполнение есть, но со статусом **Ошибка** | Откройте его: баннер **Ошибка выполнения** и строка шага объясняют причину, см. [Разбор ошибки](../03-user-guide/executions-and-audit.md#разбор-ошибки-выполнения) |

## Остановка и очистка

```bash
docker compose down        # остановить, данные сохраняются
docker compose down -v     # остановить и удалить тома (база, Redis)
```

## Что дальше

- [Первый процесс](first-process.md) — процесс с предзагрузкой, собранный в интерфейсе.
- [Руководство пользователя](../03-user-guide/login-and-ui.md) — все экраны и настройки.
- [Рецепты](../03-user-guide/recipes.md) — готовые сценарии.
- [Обзор](../01-overview/index.md#что-настраивается-в-редакторе-а-что-через-api) — что настраивается в редакторе, а что
  через API.

<!-- footer-nav -->
---

← Назад: [Требования](requirements.md) | [Оглавление](../README.md) | [Первый процесс](first-process.md) Далее →
<!-- /footer-nav -->
