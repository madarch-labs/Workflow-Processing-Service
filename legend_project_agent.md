# WPS — выжимка для нового агента

Правила работы — в [rules_agent.md](rules_agent.md). Здесь: что за проект, что сделано, где что лежит, с чем
сталкивались и как продолжать. Состояние на 2026-10-26.

## 1. Проект

WPS (Workflow Process Service) — движок бизнес-процессов для CRM (цель — не уступать SuiteCRM AOW Workflow и
бизнес-процессам Битрикс24). CRM (SuiteCRM 7/8 через свой плагин) публикует события сохранения записей в Redis Stream;
WPS проверяет условия процессов и выполняет действия (изменение и сохранение записей, письма, HTTP, уведомления,
циклы, ожидания…) через API плагина `/api/v9`.

- **back/** — PHP 8.3 + Swoole 6 + SwooleApp (`sidalex/swoole-app`), PostgreSQL (процессы, выполнения, аудит,
  ожидания, журнал побочных эффектов), Redis data (состояние выполнения `exec:{uuid}`, блокировки, идемпотентность,
  очереди assign), Redis Stream (события).
- **front/** — Vue (редактор процессов, журнал выполнений). Задачи — только бэкенд; фронт правился минимально
  (статусы `waiting` / `cancelled`).
- **mock/** — mock CRM (`mock_server.php`, `MockCrmStorage.php`) с контрактом 2.0, вспомогательные эндпоинты
  `/mock/objects/…`, `/mock/relationships/…`, `/mock/echo`, мессенджеры `/mock/slack|teams|telegram`, `/mock/notify`.
- **doc/ru, doc/en** — пользовательская и техническая документация (синхронные деревья), `doc/_meta/tools/`
  (`check_links.py`, `nav.py`). `back/API.md` — справочник API.
- **md/** — планирование: `md/plane_updationg funktionzlity.md` (исходный список функций), `md/tasks_list/` — планы.
- **.tehnical/suiteCRM8**, **.tehnical/suiteCRM7** — docker-стенды CRM. Плагины — вне репозитория:
  `/home/sidalex/dev/Tasks/SuiteCRM8`, `/home/sidalex/dev/Tasks/suiteCRM7` (версия 1.2.0, не выпущена, не закоммичена).

## 2. Планирование задач

| Где | Что |
|---|---|
| `md/tasks_list/README.md` | сводная таблица: id, приоритет, этап, зависимости, состояние, план, кейс |
| `md/tasks_list/<id>-*.md` | планы невыполненных задач; `done/` — выполненных; `_excluded/` — 6.3, 6.4 (расписание, вне P1/P2) |
| `md/tasks_list/00-architecture.md` | общие решения (владельцы файлов, L.11 normalizeDefinition, L.14 jsonb, J — время) |
| `md/tasks_list/01-questions.md` | вопросы; решаются по рекомендациям |
| `md/tasks_list/cases/` | описания кейсов SuiteCRM 8 (`<id>-suitecrm8.md`) и `cases/README.md` — их таблица |
| `md/tasks_list/tools/complete_task.py` | закрытие задачи (перенос в done/, ✅, ссылки) |

### Сделано (✅, 42 задачи)

0.1–0.4 (компилятор плоской программы, контракт CRM 2.0, юнит-тесты, журнал побочных эффектов), 3.0–3.10 (приведение
типов, операторы порядка, пустота/null, changed*, in/not_in, starts/ends_with, between, относительные даты, регистр,
операторы списков, выражение в правой части), 4.1–4.3, 4.6–4.14 (send_email, http_request, condition, update_records,
delay, notify, assign, find_records, foreach, link/unlink, stop_process, set_variable), 5.1.1–5.1.7 (шаблоны,
date_shift, increment/decrement, clear, append/prepend/remove, map, aggregate), 5.2.1–5.2.3 (условные функции, даты,
decimal), 6.1, 6.2 (формат события, trigger_on), 7.1, 7.2, 7.4 (on_error/retry, ожидающие выполнения, секреты).

5.1.3–5.1.6 закрыты 2026-10-26: общий кейс `5.1.3-change-types.json`, описание в `cases/5.1.3-suitecrm8.md`
(5.1.4–5.1.6 ссылаются на него).

### Осталось (P2)

Этап 2: **5.2.5** (функции списков) → **5.2.4** (строковые, зависит от 5.2.5) → **5.2.6** (типы, JSON) → **5.2.9**
(`sys.*`, `config.*`) → **3.11** (источники `current_user` / `system` / `security_group`, зависит от 5.2.9) → **6.6**
(политика повторного запуска) → **7.5** (лимиты, защита от каскадов). Этап 3: **6.5** (входящий вебхук), **7.3**
(отмена/возобновление), **7.6** (dry-run, нужен `simulate()` в действиях с побочными эффектами), **7.7**
(версионирование). P3 и `_excluded` не делать без указания.

## 3. Где что в коде (back/src)

| Каталог / файл | Назначение |
|---|---|
| `Kernel/Container.php` | сборка всех сервисов, реестров действий, типов изменения, блоков, каналов |
| `Config/AppConfig.php`, `Kernel/Env.php` | конфигурация из окружения (`Env::get/int/bool`) |
| `Services/ExecutionEngineService.php` | выполнение: идемпотентность, условия + ленивая предзагрузка, программа, повторы, `on_error`, ожидания, циклы, `finish`, `resume` |
| `Program/` | компилятор (`ProgramCompiler`, `ProgramEmitter`), `Interpreter`, инструкции, блоки `Blocks/{Condition,StopProcess,Foreach}Block`, `LoopRunner`, `LoopItemsStore` |
| `Services/ConditionEngineService.php` | условия: приведение типов, виды операндов (value, list, range, change, presence), относительные даты, выражения, трасса |
| `Comparators/` | операторы условий (+ `ComparatorRegistry::default()`) |
| `Types/` | `TypeCaster`, `CastOptions`, `CastType`, `RelativeDate` |
| `Actions/` | обработчики действий; `ChangeTypes/` — типы изменения modify; `Support/RelatedIdsResolver` |
| `Crm/` | `CrmFilter` (eq…nin), `FilterTranslator` (строки условий → фильтр CRM), `FilterRulesValidator`, `CrmPage`, возможности CRM |
| `Services/ExternalApiAdapterService.php` | клиент API плагина (поиск, связи, count, CRUD) |
| `Services/Validation/DefinitionScope.php`, `Services/ProcessValidatorService.php` | валидация определения при сохранении (области видимости переменных, fork/merge веток) |
| `Services/Wait*`, `Persistence/ExecutionWaitRepository`, `CyclicJobs/WaitResumeJob` | ожидающие выполнения (7.2) |
| `SideEffects/` | журнал побочных эффектов, `DeliveryPolicy` (0.4) |
| `Notify/` | каналы уведомлений (crm_alert, telegram, slack, teams) |
| `Services/Assign/` | пулы пользователей и стратегии назначения |
| `Expression/`, `Template/`, `Time/`, `Mail/`, `Http/Outbound/`, `Secrets/` | выражения, шаблоны, время/даты, почта, исходящий HTTP (SSRF), секреты |
| `Controllers/` | REST (атрибут `#[Route]`), справочники в `Controllers/Dictionaries/` |
| `back/migrations/` | SQL-миграции (последняя — 009 ожидания) |
| `back/tests/Unit/`, `back/tests/IntegrationTest.php` | юнит-тесты (PHPUnit), интеграционный скрипт (блоки `[имя]`) |

## 4. Стенды и инструменты

- **WPS**: `docker compose` в корне (backend :9501, mock :9502, Mailpit :8025, postgres :55432, redis-data :6380).
  Режим mock — экспорт `CRM_API_BASE_URL=http://mock:9502 CRM_API_STATIC_KEY=mySecretKey MOCK_SERVICE_URL=http://mock:9502 TELEGRAM_API_BASE=http://mock:9502/mock/telegram`;
  режим SuiteCRM 8 — без экспорта (значения в корневом `.env`: `CRM_API_BASE_URL=http://suitecrm8/api/v9`).
- **SuiteCRM 8**: контейнеры `wps-suitecrm8-web-1` / `-db-1` (MariaDB, клиент `mariadb`), UI http://localhost:8801,
  API `http://localhost:8801/api/v9`. Ключ запроса к API плагина — заголовок `X-Auth-Key: <WPS_API_STATIC_KEY><Y.m.d H UTC>`
  (ключ + текущий час). `.tehnical/suiteCRM8/.env` — `WPS_API_STATIC_KEY`, `SUITECRM_ADMIN_*`, `WPS_EVENT_MODULES`
  (добавлены `AOS_Contracts`, `AOS_Quotes`; после изменения — переустановить плагин).
- **SuiteCRM 7**: `.tehnical/suiteCRM7`, порт 8701 — держать остановленным (`docker compose stop`), запускать для проверок.
- **Плагин**: переустановка `bin/install.sh --docker <container> --env-file <stand>/.env --apache-conf /etc/apache2/sites-available/000-default.conf --force`;
  проверка `bin/contract_check.sh http://localhost:8801/api/v9 <key> swoole_worckflow-redis-stream-1` (базовый URL с `/api/v9`).
- **Раннер кейсов** `.tehnical/suiteCRM8/scripts/run_case.php`: секции `deactivate`, `setup`, `processes`, `steps`,
  `teardown`; шаги `crm.create/update/get/find/api`, `wps.wait` (`count` — N-е выполнение), `wps.none`, `wps.run`,
  `wps.get`, `wps.api`, `wps.restart` (`kill`, `env`), `assert` (equals, not_equals, contains, not_contains, matches,
  empty, not_empty, count), `sleep`, `mail.find`, `http.get` (`method`), `script` (фикстура из `scripts/`);
  `expect` — статус или список; плейсхолдеры `{{case.run}}`, `{{case.date±N}}`, `{{env.X}}`, `\{{` — литерал;
  трасса условий — `context.conditions`, шаг — `actions.N.result_data/request_data/error_code/handled`.
  Фикстура `scripts/seed_case_users.sh` — пользователи `wps-case-u1..u4`, группа `wps-case-sales`.
- **Зависимости composer**: `docker run --rm -v $PWD:/app -w /app --entrypoint composer wps-backend-test require … --ignore-platform-req=ext-swoole` (в `back/`; vendor не в git).

## 5. Нюансы, с которыми сталкивались

**SuiteCRM и плагин**
- `Tasks.name`, `Meetings.name` — 50 символов, CRM молча обрезает; HTML-теги из текстовых полей вырезаются.
- Тело и адреса `Emails` в отдельной таблице — плагин их не видит; `email1` людей/компаний поддержан плагином.
- `Users` в API только для чтения (405) — тестовых пользователей создавать прямо в БД стенда.
- relate-поля без колонки (`Opportunities.account_id`, `account_name`) нет в событиях, фильтр по ним — 422. Контрагента
  сделки искать по связи `Accounts.opportunities` или по своему полю.
- `Leads.calls` — приглашённые в звонок (таблица `calls_leads`), а не звонки с `parent_id`; звонки «по родителю» ищутся
  режимом модуля `Calls parent_id = …`.
- `AOS_Contracts.end_date` при создании через API заполняется автоматически (+1 год).
- Единственный стандартный мультисписок — `AOS_Quotes.template_ddown_c`; `AOS_Quotes.term_conditions` плагин не отдаёт.
- `null` в атрибутах очищает поле (доработано в плагинах); для Opportunities id назначается до `before_save` (событие
  `create` доработано).
- Контракт: один ключ query на оператор поля — поэтому `not_in` реализован новым оператором `nin` (возможность
  `filter_nin`), а не несколькими `neq`; `between` в фильтре CRM — `gte` + `lte`.
- Колокольчик: `Alerts` (`assigned_user_id`, `is_read`, `url_redirect`), SuiteCRM 7 отбрасывает `snooze`, `date_start`,
  `status`. Проверка — `index.php?module=Alerts&action=get` в сессии.

**Движок**
- `processes.definition` — jsonb: порядок ключей объектов не сохраняется → упорядоченное (поля `set_variable`, `ranges`)
  задавать списками; в тестах сравнивать объекты с `ksort`.
- Сервисы общие для корутин Swoole — не хранить состояние вызова в полях сервиса (поэтому «сейчас» условий передаётся
  параметром `evaluate(..., now)`).
- Запись аудита цикла перезаписывается итогами (`InstructionOutcome::overwrite` не сдвигает `seq`); снимок элементов
  цикла — отдельный ключ Redis, при ожидании уходит в `execution_waits.state.loop_items`.
- Повтор шага восстанавливает снимок контекста (`restoreState`); побочные эффекты защищены журналом 0.4
  (ключ `{pc}@{iteration}#{sub}`); решение `assign` запоминается в Redis на шаг.
- Отсутствующее поле никогда не удовлетворяет условию (ответ заказчика); пустая строка ≠ отсутствие.
- `DateMath`: календарные операции — в `WPS_TIMEZONE`, часы/минуты — точное время (DST: `+1d` ≠ `+24h`).
- `MetricsService` без меток — метрики «по статусам» сводятся к одному счётчику.
- `ExternalApiAdapterService` — `final`: в юнит-тестах создаётся через `newInstanceWithoutConstructor()`, выполнение
  проверяется интеграционно на mock.
- Интеграционные тесты делить состоянием mock между прогонами — делать уникальные id.

**Процесс**
- Лимиты сессии/недели обрывают фоновых агентов: после сброса — SendMessage тому же агенту «продолжи».
- Документатор иногда пишет рецепты с пользовательскими полями, отличными от кейса, — просить оговорку или правку.

## 6. Как продолжать

1. Открыть `md/tasks_list/README.md` и сверить состояние.
2. Брать следующую задачу по порядку из раздела 2 «Осталось», читать план и `00-architecture.md`.
3. Выполнять по [rules_agent.md](rules_agent.md): код → юнит → интеграция (mock) → кейс SuiteCRM 8 → описание кейса →
   документация (агент) → `complete_task.py` + `cases/README.md`.
4. Перед итоговым отчётом — прогнать все кейсы `.tehnical/suiteCRM8/processes/cases/*.json` и интеграционные тесты.

Последние результаты: юнит `OK (809 tests, 2357 assertions)`, интеграция `Passed: 156, Failed: 0`, кейсы SuiteCRM 8
всех выполненных задач — PASSED на момент закрытия каждой.
