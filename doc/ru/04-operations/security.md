# Безопасность

Аутентификация, роли и правила доступа к API, секреты, которые нужно сменить, и что доступно без токена.

<!-- lang-switch -->
[🇬🇧 English](../../en/04-operations/security.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Аутентификация: JWT

- `POST /api/auth/login` с e-mail и паролем (хранится как bcrypt-хеш, проверяется `password_verify`) возвращает
  **access-токен** и **refresh-токен**.
- Токены — JWT HS256, подписанные `JWT_SECRET`. Claims: `sub` (id пользователя), `email`, `role`, `type` (`access`/`refresh`),
  `iat`, `exp`, `jti`.
- Каждый запрос к API передаёт `Authorization: Bearer <access-токен>`. Refresh-токен не принимается вместо access-токена
  (`INVALID_TOKEN`, «Wrong token type»), и наоборот.
- Время жизни access-токена `JWT_ACCESS_TTL` (900 с), refresh-токена — `JWT_REFRESH_TTL` (7 дней). Получив `401`, UI вызывает
  `POST /api/auth/refresh` и повторяет запрос.
- При обновлении токена пользователь заново читается из базы: удалённый пользователь не получит новый токен, а изменённая
  роль вступит в силу со следующим токеном.

> **Примечание**
> Токены не хранятся на сервере: выхода на стороне сервера и отзыва токенов нет. Access-токен действует до истечения срока
> (не дольше `JWT_ACCESS_TTL`), даже если пользователя удалили или сменили ему роль. Держите `JWT_ACCESS_TTL` коротким.
> Смена `JWT_SECRET` сразу делает недействительными все токены.

UI хранит оба токена в `localStorage` (`wps_access_token`, `wps_refresh_token`).

## Роли

| Роль | Процессы | Выполнения / аудит | Ручной запуск, клонирование | Удаление процессов | Сброс отметок однократного запуска | Обновление модулей | Пользователи |
|---|---|---|---|---|---|---|---|
| `admin` | чтение, создание, изменение | да | да | да | да | да | полное управление |
| `editor` | чтение, создание, изменение | да | да | нет | да | да | нет |
| `viewer` | чтение | да | нет | нет | нет | нет | нет |

Управление пользователями описано в разделе [Пользователи и роли](../03-user-guide/users-and-roles.md).

## Правила доступа (`Wps\Auth\AccessPolicy`)

Глобальный middleware `Wps\Middleware\AuthMiddleware` (подключён в `config.json` → `globalMiddlewares`) срабатывает на каждый
запрос. Применяется первое подходящее правило:

| Метод | Путь (regex) | Доступ |
|---|---|---|
| любой | `^/api/health$` | публичный |
| любой | `^/metrics$` | публичный |
| `POST` | `^/api/auth/(login\|refresh)$` | публичный |
| любой | `^/api/users(/.*)?$` | admin |
| `DELETE` | `^/api/processes/[^/]+/runs/[^/]+/[^/]+$` | admin, editor |
| `DELETE` | `^/api/processes/[^/]+$` | admin |
| `POST` | `^/api/processes$` | admin, editor |
| `PUT` | `^/api/processes/[^/]+$` | admin, editor |
| `POST` | `^/api/processes/[^/]+/(clone\|run)$` | admin, editor |
| `POST` | `^/api/dictionaries/modules/refresh$` | admin, editor |
| `GET` | `^/api/.*$` | admin, editor, viewer |
| прочие | не перечислены | `GET` — любая роль, остальные методы — admin, editor |

Ответы: заголовка нет или он некорректен — `401 UNAUTHORIZED`; неверная подпись или тип токена — `401 INVALID_TOKEN`; срок
истёк — `401 TOKEN_EXPIRED`; роль не подходит — `403 FORBIDDEN`. На `OPTIONS` (CORS preflight) сервер отвечает до маршрутизации.
Правила проверяют только URL; новый эндпоинт защищается правилом по умолчанию, пока вы не добавите явное
(см. [Новый эндпоинт API](../07-developer-guide/extending/new-api-endpoint.md)).

## Публичные эндпоинты

| Эндпоинт | Что раскрывает |
|---|---|
| `GET /api/health` | Состояние PostgreSQL и Redis, тексты ошибок проверок, имя стрима, группу, счётчики |
| `GET /metrics` | Счётчики событий и выполнений |
| `POST /api/auth/login`, `POST /api/auth/refresh` | — |

Если это недопустимо, не публикуйте порт backend и закройте `/metrics` и `/api/health` на reverse proxy.

## Секреты и значения по умолчанию, которые нужно сменить

| Что | По умолчанию | Где поменять |
|---|---|---|
| Ключ подписи JWT | `change-me-in-production-0123456789abcdef` в `docker-compose.yml` (запасное значение в коде — `insecure-dev-secret`) | `JWT_SECRET` |
| Администратор | `admin@wps.local` / `admin` (миграция `002_seed_admin.sql`) | Смените пароль в UI («Пользователи») или создайте другого администратора и удалите этого |
| Пароль PostgreSQL | `secret` | `POSTGRES_PASSWORD` и `DB_POSTGRES_PASSWORD` |
| Redis | без пароля | `requirepass` в Redis + `REDIS_STREAM_PASSWORD`, `REDIS_DATA_PASSWORD`, `MOCK_REDIS_PASSWORD` |
| Ключ API CRM | `mySecretKey` (мок) | `CRM_API_STATIC_KEY` в WPS и такой же ключ в плагине CRM (`WPS_API_STATIC_KEY`) |

Не коммитьте настоящие секреты: `./.env` и `.tehnical/*/.env` — локальные файлы.

> **Внимание**
> Любой, кто может писать в `crm_events`, может заставить WPS запускать процессы и, значит, менять записи CRM. Закройте Redis
> стрима паролем и держите его в закрытой сети.

## Ключ CRM

WPS авторизуется в CRM заголовком `X-Auth-Key = CRM_API_STATIC_KEY . gmdate("Y.m.d H")`. Значение меняется каждый час, но
вычисляется из постоянного секрета: если CRM не в той же закрытой сети, используйте HTTPS (`CRM_API_BASE_URL=https://…`).
Плагин на стороне CRM выполняет все изменения от имени пользователя `WPS_API_USER_ID` — дайте ему только те права, которые
нужны процессам.

## CORS

В каждом ответе API есть `Access-Control-Allow-Origin: <CORS_ALLOW_ORIGIN>` (по умолчанию `*`), разрешённые методы
`GET, POST, PUT, DELETE, OPTIONS` и заголовки `Authorization, Content-Type`. Встроенный UI обращается к API через прокси nginx с
того же адреса и CORS не требует. Укажите в `CORS_ALLOW_ORIGIN` адрес своего UI, если другим веб-приложениям нельзя вызывать API.

## Прочее

- `APP_DEBUG=true` возвращает тексты внутренних исключений в ответах `500`; оставьте `false`.
- Пароль пользователя — не короче 6 символов. API никогда не возвращает хеши паролей.
- Последнего администратора нельзя удалить или понизить (`409 LAST_ADMIN`); пользователь не может удалить сам себя (`409 CANNOT_DELETE_SELF`).
- Выражения `calculate` вычисляются собственным парсером WPS (без `eval`), поэтому редакторы процессов не могут выполнить код на сервере.
- Редакторы процессов могут читать и менять любые записи CRM, доступные пользователю API в CRM. Роль `editor` давайте только доверенным людям.

<!-- footer-nav -->
---

← Назад: [Надёжность](reliability.md) | [Оглавление](../README.md) | [Решение проблем](troubleshooting.md) Далее →
<!-- /footer-nav -->
