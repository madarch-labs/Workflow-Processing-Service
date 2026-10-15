# Workflow Processing Service (WPS)

Сервис автоматизации бизнес-процессов. Внешняя CRM публикует сохранения объектов в Redis Stream.
WPS подбирает для события активные процессы верхнего уровня и проверяет их условия (И).
Затем выполняет цепочку действий: предзагрузку объектов из CRM, изменение полей, сохранение в CRM
и асинхронный запуск ведомых процессов. Каждый запуск и каждый шаг пишутся в аудит.

## Documentation / Документация

- 🇬🇧 [English documentation](doc/en/README.md)
- 🇷🇺 [Документация на русском](doc/ru/README.md)

Требования: `md/doc/*` (ТЗ — `technical_specification.md`, ответы заказчика — `maiInfo.md`),
`md/back_stap_1.md`, `md/back_stap_2.md`, `md/front_stap_1.md`, `md/moc_eventService.md`.

```
back/    PHP 8.3 + Swoole 6 + SwooleApp (пакет sidalex/swoole-app из Packagist, версия зафиксирована в composer.lock)
front/   Vue 3 + TypeScript + Vite 5 + Element Plus + Pinia + Vue Router + Axios
mock/    Mock Event Service: генератор событий в стрим + эмуляция CRM API v8
docker-compose.yml   postgres, redis-stream (шина), redis-data (кэш/состояния), backend, mock, frontend
```

## Запуск

```bash
docker compose up -d --build
```

| Что | Адрес |
|---|---|
| UI | http://localhost:3080 (порт задаётся `WPS_UI_PORT` в `./.env`; 3000 на этой машине занят) |
| API | http://localhost:9501/api/health, метрики — http://localhost:9501/metrics |
| Mock CRM / события | http://localhost:9502/test-event?module=Deals&set[status]=Active |

Вход: `admin@wps.local` / `admin`. Пользователь создаётся миграцией `002_seed_admin.sql`.

CRM для backend задаётся в `./.env`: `CRM_API_BASE_URL`, `CRM_API_STATIC_KEY`, `MOCK_SERVICE_URL`.
По умолчанию используется мок. Настоящие SuiteCRM 7 и 8 с плагином интеграции разворачиваются из
`.tehnical/suiteCRM7` и `.tehnical/suiteCRM8`, порядок описан в их README.

Тесты API: 67 проверок, чистый PHP по `md/doc/concept_apitest_back.md`. Код вшит в образ,
поэтому после правок пересоберите backend:

```bash
docker compose up -d --build backend
docker compose exec backend php tests/IntegrationTest.php
```

Фронтенд без Docker: `cd front && npm install && npm run dev`. Нужен Node ≥ 18. Dev-сервер
работает на порту 3000 и проксирует `/api` на `localhost:9501`.

## Сборка бэкенда

Фреймворк [SwooleApp](https://github.com/SwooleApp/SwooleApp) подключается как обычная composer-зависимость
`sidalex/swoole-app` из Packagist (`back/composer.json`: `^0.3.0`, точная версия и коммит зафиксированы в
`back/composer.lock`). Образ `back/Dockerfile` (`phpswoole/swoole:6.0-php8.3`) выполняет `composer install`
по lock-файлу. Локальная копия фреймворка для сборки не нужна.

Все пакеты ставятся из архивов (`config.preferred-install: dist` в `back/composer.json`, `--prefer-dist` в
Dockerfile). Git-репозитории не клонируются. Архивы Composer скачивает по ссылкам, которые даёт Packagist;
для SwooleApp это zip релиза на GitHub.

Обновить фреймворк — обновить только его запись в lock-файле, в том же образе, что и сборка:

```bash
cd back
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app \
  phpswoole/swoole:6.0-php8.3 composer update sidalex/swoole-app
rm -rf vendor                          # vendor живёт только в образе
cd .. && docker compose up -d --build backend
docker compose exec backend php tests/IntegrationTest.php
```

Новую мажорную или минорную версию (0.4, 1.x) задайте в `back/composer.json` и сверьте её changelog с обходными
решениями из раздела «Решения по противоречиям», пункт 2.
## Бэкенд — устройство

- **StreamConsumerJob** (CyclicJob, каждые 0.5 с, в каждом воркере). Читает стрим через
  `XREADGROUP` в группе `wps_workers`. `XACK` делается только после полной обработки, в том числе
  когда ни один процесс не подошёл. Некорректные сообщения уходят в `crm_events:dlq`.
- **RecoveryJob**. Забирает через `XAUTOCLAIM` сообщения, которые упавший консьюмер не подтвердил.
  Возобновляет незавершённые выполнения с `current_step` по контексту в Redis.
  Проверено вручную: SIGKILL бэкенда посреди retry, затем выполнение доведено до конца.
- **ExecutionEngineService** выполняет шаги так:
  - идемпотентность через `SET NX idem:{event_id}:{process_id}`;
  - шаги по порядку: preload, затем actions;
  - после каждого шага контекст пишется в Redis Hash `exec:{uuid}`, а шаг — в `execution_actions`;
  - retry только для recoverable-ошибок (таймаут или 5xx CRM, сеть, Redis): 3 повтора с задержкой 1 с, 2 с, 4 с;
  - после ошибки шага остальные шаги не выполняются и помечаются `skipped`;
  - `start_process` запускает дочерний процесс в отдельной корутине и не ждёт его.
- **Однократный запуск (`run_once`)** — флаг процесса. Процесс запускается для записи CRM не больше одного раза.
  - Отметка «процесс + запись» хранится в PostgreSQL (`process_object_runs`).
  - Отметка ставится атомарно (`INSERT … ON CONFLICT`) при старте и только если условия выполнены.
  - Повторные события записи процесс не запускают. Снять отметку можно в UI (вкладка «Однократные запуски»)
    или через `DELETE /api/processes/{id}/runs/{type}/{object_id}`.
  - Ограничение: работает по `id` записи из события. Без `id` флаг не применяется (`md/idempotentie_plan.md`).
    На практике событие без `id` уходит в DLQ, а ручной запуск без `object.id` отклоняется.
- **Точки расширения** (добавляются с рестартом сервиса). Реестры собраны в `src/Kernel/Container.php`:
  - `ComparatorInterface` — операторы условий;
  - `ActionHandlerInterface` — типы действий, вместе с валидацией их конфигурации;
  - `ChangeTypeInterface` — способы вычисления значения в modify;
  - `LoadStrategyInterface` — способы загрузки объекта.
  Движок условий уже принимает вложенные группы `{logical_operator, conditions, groups}`,
  то есть OR и скобки в будущем добавляются только в UI.
- **`calculate`** использует собственный безопасный парсер выражений, без `eval`:
  `+ - * / %`, скобки, `concat/upper/lower/trim/len/substr/round/abs/min/max`.
- **Валидация при сохранении**:
  - пути и переменные;
  - операторы;
  - синтаксис выражений;
  - `start_process` ссылается только на ведомые процессы;
  - циклы запуска (А → Б → А): ответ `422 CYCLE_DETECTED` с путём цикла.

Контракт API описан в `back/API.md`.

## Решения по противоречиям (согласованы)

1. Вызовы CRM и PostgreSQL идут через **корутины** (Coroutine HTTP client, PDO pgsql с runtime hooks), а не через Task workers из ТЗ.
2. **SwooleApp не изменялся** (используется опубликованный пакет `sidalex/swoole-app` 0.3.0). Две проблемы
   фреймворка обойдены расширением в `back/src`:
   - сопоставление маршрутов по префиксу исправлено в `WpsRoutesCollectionBuilder`;
   - неприменявшиеся `globalMiddlewares` подключаются в `BaseController`. Для этого в `config.json` их нужно указывать строками.
3. **Удалять процессы может только admin.** Editor может создавать, редактировать, клонировать и запускать.
4. **Условия по переменным (`v1.x`) — ленивая предзагрузка.** Сначала проверяются условия по `object.*`.
   Если они прошли, выполняется preload, затем остальные условия. Шаги preload попадают в аудит
   и повторно не выполняются.

## Что добавлено сверх документов

- В mock эмулируется CRM API v8 (`GET/PUT/POST /api/v8/{module}…`) с проверкой `X-Auth-Key`,
  чтобы preload и save работали без SuiteCRM. Объекты хранятся в Redis.
  Id вида `fail500…` и `slow…` имитируют сбои для проверки retry.
- Процессы удаляются мягко (`deleted_at`), чтобы не терять аудит. Процесс, который запускается
  другими процессами, удалить нельзя: ответ `409 PROCESS_IN_USE`.
- Ручной запуск: если условия не выполнены, возвращается разбор каждой строки условий.
  Флаг `force` запускает процесс без проверки условий.
- Дополнительные эндпоинты: `GET /api/auth/me` (нужен фронту), `/api/executions/{id}/actions`,
  справочники `change-types` и `preload-sources`, `POST /api/dictionaries/modules/refresh`, `/metrics`.
