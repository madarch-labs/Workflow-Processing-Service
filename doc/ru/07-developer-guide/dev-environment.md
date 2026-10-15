# Среда разработки

Как запустить WPS локально во время работы над кодом: пересборка бэкенда, dev-сервер фронтенда, логи и отладка.

<!-- lang-switch -->
[🇬🇧 English](../../en/07-developer-guide/dev-environment.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Что понадобится

| Инструмент | Версия | Для чего |
|---|---|---|
| Docker + Docker Compose v2 | любая актуальная | весь стенд (`docker-compose.yml`) |
| Node.js | ≥ 18 (рекомендуется 22) | dev-сервер и сборка фронтенда (`front/`) |
| PHP на хосте | не нужен | PHP работает только внутри образа `backend` (`phpswoole/swoole:6.0-php8.3`) |

> **Примечание**
> Если системный Node.js ниже 18-й версии, переключите его для текущей оболочки: `source ~/.nvm/nvm.sh && nvm use 22`.

## Запуск стенда

```bash
docker compose up -d --build
curl -s http://localhost:9501/api/health
```

Порты на хосте берутся из `./.env` (`WPS_UI_PORT`, `WPS_API_PORT`, `WPS_MOCK_PORT`, `WPS_PG_PORT`, `WPS_REDIS_STREAM_PORT`,
`WPS_REDIS_DATA_PORT`). Для разработки держите бэкенд на мок-CRM:

```dotenv
CRM_API_BASE_URL=http://mock:9502
CRM_API_STATIC_KEY=mySecretKey
MOCK_SERVICE_URL=http://mock:9502
```

Все переменные описаны в разделе [Конфигурация](../04-operations/configuration.md).

### Второй, изолированный стенд

Чтобы что-то попробовать, не трогая основной стенд (базу, Redis, контейнеры), запустите отдельный проект Compose
с другими портами. Значения из командной строки важнее, чем `./.env`:

```bash
WPS_UI_PORT=3091 WPS_API_PORT=9521 WPS_MOCK_PORT=9522 WPS_PG_PORT=55434 \
WPS_REDIS_STREAM_PORT=6399 WPS_REDIS_DATA_PORT=6400 \
CRM_API_BASE_URL=http://mock:9502 CRM_API_STATIC_KEY=mySecretKey MOCK_SERVICE_URL=http://mock:9502 \
docker compose -p wpsext up -d --build

docker compose -p wpsext down -v   # remove it together with its volumes
```

Именно так проверялись примеры из раздела [Расширение WPS](extending/new-action-type.md).

## Бэкенд: изменение → пересборка

Код бэкенда **вшит в образ** (`back/Dockerfile` копирует `back/` и выполняет `composer install` и
`composer dump-autoload`). Bind mount нет, поэтому простой перезапуск ваши изменения не подхватит:

```bash
docker compose up -d --build backend
docker compose logs -f backend
```

При каждом старте `back/docker/entrypoint.sh`:

1. копирует `.env.example` в `.env`, если `.env` нет (настоящие переменные окружения из Compose всё равно важнее, см. `Wps\Kernel\Env`);
2. выполняет `php migrations/migrate.php` — применяет новые `migrations/*.sql` по порядку имён, каждую один раз (учёт в `schema_migrations`);
3. запускает `php server.php`.

Реестры (компараторы, действия, типы изменений, стратегии загрузки, функции выражений) строятся в каждом воркере
в `Wps\Kernel\Container`, поэтому любое расширение требует пересборки и перезапуска бэкенда.

### Новые классы и автозагрузка

`back/composer.json` отображает `Wps\` на `back/src/` (PSR-4). Новый класс в нужной папке находится автоматически после
пересборки (образ выполняет `composer dump-autoload --optimize`). Контроллеры регистрировать не нужно: SwooleApp сканирует
пространство имён `Wps\Controllers` из `config/config.json` (вместе с вложенными) и читает атрибут `#[Route]`.

### Обновление фреймворка SwooleApp

Фреймворк — обычная зависимость Composer `sidalex/swoole-app` (`^0.3.0`, версия зафиксирована в `back/composer.lock`).
Обновляйте только его запись в lock-файле и делайте это в том же образе, в котором собирается бэкенд:

```bash
cd back
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app \
  phpswoole/swoole:6.0-php8.3 composer update sidalex/swoole-app
rm -rf vendor                          # vendor lives only in the image
cd .. && docker compose up -d --build backend
docker compose exec backend php tests/IntegrationTest.php
```

Новая мажорная или минорная версия (0.4, 1.x) указывается в `back/composer.json`. Сверьте её changelog с двумя обходными
решениями, которые WPS реализует через наследование: точное сопоставление маршрутов в `WpsRoutesCollectionBuilder`
и применение глобальных middleware в `BaseController`.

## Dev-сервер фронтенда

```bash
cd front
npm install
npm run dev            # http://localhost:3000, /api is proxied to http://localhost:9501
VITE_API_TARGET=http://localhost:9521 npm run dev   # another backend
```

Горячая перезагрузка работает для `.vue`, `.ts` и `locales/*.json`. UI в Docker (сервис `frontend`) — это production-сборка
под nginx; когда нужно проверить контейнер, пересоберите его командой `docker compose up -d --build frontend`.

## Логи

Бэкенд пишет структурированные JSON-строки в stdout/stderr (`Wps\Support\Logger`):

```json
{"timestamp":"2026-10-15T08:06:27.619Z","level":"info","message":"Process completed","context":{"execution_id":"…","process_id":13,"step":3},"pid":14}
```

```bash
docker compose logs -f backend | grep '"level":"error"'
docker compose logs -f backend | grep '<execution id>'
```

Записи о выполнениях содержат `execution_id`, `process_id`, `event_id`, `step`, поэтому один `grep` показывает весь запуск.

## Советы по отладке

| Что нужно | Как сделать |
|---|---|
| Больше строк в логе (запросы к CRM с длительностью, обработанные события) | `LOG_LEVEL=debug` в окружении бэкенда |
| Настоящий текст исключения в ответах `500 INTERNAL_ERROR` | `APP_DEBUG=true` (никогда в production) |
| Сгенерировать событие | `curl -g 'http://localhost:9502/test-event?module=Deals&set[status]=Active'` |
| Посмотреть объект CRM в моке | `curl http://localhost:9502/mock/objects/Accounts/<id>` |
| Сымитировать сбои CRM | id, начинающиеся с `fail500` → HTTP 500 (повторы), `slow` → задержка 3 с |
| Посмотреть живой контекст выполнения | `GET /api/executions/{id}` (во время работы читается из Redis `exec:{uuid}`) |
| Заглянуть в stream | `docker compose exec redis-stream redis-cli XINFO GROUPS crm_events`, DLQ: `XRANGE crm_events:dlq - +` |
| Проверить определение процесса без UI | `POST /api/processes` возвращает `422` с путями в `details[].field` |

> **Внимание**
> Без ключа `-g` `curl` считает `set[status]` glob-шаблоном и ничего не отправляет.

## Связанные страницы

- [Структура проекта](project-structure.md)
- [Тестирование](testing.md)
- [Решение проблем](../04-operations/troubleshooting.md)

<!-- footer-nav -->
---

← Назад: [Справочник API: эндпоинты](../06-api-reference/endpoints.md) | [Оглавление](../README.md) | [Структура проекта](project-structure.md) Далее →
<!-- /footer-nav -->
