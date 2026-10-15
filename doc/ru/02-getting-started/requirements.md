# Требования

Что нужно на машине для запуска WPS и какие порты он занимает.

<!-- lang-switch -->
[🇬🇧 English](../../en/02-getting-started/requirements.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Для запуска WPS (Docker)

| Требование | Пояснение |
|---|---|
| Docker Engine с плагином Compose | Весь стек описан в `docker-compose.yml` и запускается командой `docker compose up -d --build`. |
| Доступ в интернет при первой сборке | Образы скачиваются с Docker Hub (`postgres:15-alpine`, `redis:7-alpine`, `phpswoole/swoole:6.0-php8.3`, `node:22-alpine` и `nginx:1.27-alpine` для фронтенда). Composer скачивает PHP-пакеты с Packagist, npm — пакеты фронтенда. |
| Свободные порты на хосте | См. таблицу ниже. Любой порт меняется в `./.env`. |
| Современный браузер | Chrome, Firefox, Edge или Safari для веб-интерфейса (Vue 3 + Element Plus). |
| `curl` | В примерах через него вызывается API и отправляются тестовые события. |

Ставить на хост PHP, Composer, PostgreSQL и Redis **не нужно**: всё это работает внутри контейнеров.

## Порты

| Сервис | Переменная в `./.env` | По умолчанию | Назначение |
|---|---|---|---|
| `frontend` | `WPS_UI_PORT` | `3000` | Веб-интерфейс (nginx), проксирует `/api/` на backend |
| `backend` | `WPS_API_PORT` | `9501` | REST API `/api/*`, проверка здоровья `/api/health`, метрики `/metrics` |
| `mock` | `WPS_MOCK_PORT` | `9502` | Мок API CRM и генератор тестовых событий `/test-event` |
| `postgres` | `WPS_PG_PORT` | `5432` | PostgreSQL (`wps` / `wps` / `secret`) |
| `redis-stream` | `WPS_REDIS_STREAM_PORT` | `6379` | Redis со стримом событий `crm_events` |
| `redis-data` | `WPS_REDIS_DATA_PORT` | `6380` | Redis для кэшей и состояния выполнений |

> **Примечание**
> В `./.env` из репозитория заданы `WPS_UI_PORT=3080` и `WPS_PG_PORT=55432`: на машине автора порты 3000 и 5432 были
> заняты. Поэтому интерфейс открывается на `http://localhost:3080`. Поменяйте значения под свою машину.

## Для разработки

| Требование | Зачем |
|---|---|
| Node.js ≥ 18 (рекомендуется 22) и npm | Запуск фронтенда без Docker: `cd front && npm install && npm run dev` (порт 3000, `/api` проксируется на `localhost:9501`). |
| Git | Работа с репозиторием. |
| PHP 8.3 со Swoole 6 (необязательно) | Только если хотите запускать backend без Docker. Штатный способ — образ `backend`: после правок кода его пересобирают (`docker compose up -d --build backend`). |

Подробности — в разделе [Окружение разработчика](../07-developer-guide/dev-environment.md).

## Для настоящей CRM (необязательно)

Из коробки WPS работает со встроенным моком. Чтобы подключить SuiteCRM 7 или 8, используйте готовые Docker-окружения
и плагин WPS из `.tehnical/suiteCRM7` и `.tehnical/suiteCRM8`. См. [Интеграция с CRM](../04-operations/crm-integration.md).

<!-- footer-nav -->
---

← Назад: [Глоссарий](../01-overview/glossary.md) | [Оглавление](../README.md) | [Быстрый старт](quick-start.md) Далее →
<!-- /footer-nav -->
