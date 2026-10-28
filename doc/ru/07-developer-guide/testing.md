# Тестирование

Как устроены юнит-тесты, интеграционные тесты бэкенда, кейсы на стенде SuiteCRM 8 и проверки фронтенда, как их запускать и как добавить новую проверку.

<!-- lang-switch -->
[🇬🇧 English](../../en/07-developer-guide/testing.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Обзор

| Часть | Инструмент | Команда |
|---|---|---|
| Логика бэкенда (юнит) | PHPUnit, `back/tests/Unit` | `docker build -f back/Dockerfile --target test -t wps-backend-test . && docker run --rm wps-backend-test --testsuite unit` |
| API бэкенда | `back/tests/IntegrationTest.php` — чистый PHP, HTTP-запросы к запущенному серверу на mock-CRM | `docker compose exec -T backend php tests/IntegrationTest.php` |
| Сценарии на настоящей CRM | `.tehnical/suiteCRM8/scripts/run_case.php` + кейсы `processes/cases/*.json` | `php .tehnical/suiteCRM8/scripts/run_case.php <кейс.json>` |
| Логика формы редактора (юнит) | vitest, `front/src/**/*.spec.ts` | `npm test` |
| Типы фронтенда | `vue-tsc` | `npm run typecheck` |
| Переводы фронтенда | `scripts/i18n-check.mjs` | `npm run i18n:check` |
| Сборка фронтенда | проверка i18n + typecheck + `vite build` | `npm run build` |

Чистая логика (сравнения, выражения, условия, контекст выполнения) проверяется юнит-тестами за секунды, без поднятого
стека. Сценарии целиком — интеграционными тестами через API на настоящем PostgreSQL, обоих экземплярах Redis и мок-CRM.
Бизнес-сценарий каждой задачи дополнительно проверяется кейсом на стенде SuiteCRM 8 с настоящим плагином.

Порядок для новой функции (из `rules_agent.md`): код → юнит-тесты → блок интеграционного теста на mock → кейс SuiteCRM 8 до
`PASSED` → документация. Перед итоговым отчётом прогоняются все кейсы и все интеграционные тесты.

Последние результаты на момент написания: юнит — `OK (809 tests, 2357 assertions)`, интеграция — `Passed: 156, Failed: 0`.

## Юнит-тесты бэкенда

### Запуск

Юнит-тесты собираются в отдельной стадии `test` образа бэкенда: в ней есть dev-зависимости (PHPUnit), в
production-стадии `prod` их нет.

```bash
# из корня репозитория
docker build -q -f back/Dockerfile --target test -t wps-backend-test .
docker run --rm wps-backend-test --testsuite unit
docker run --rm wps-backend-test --filter ExpressionEngineTest              # один класс
docker run --rm wps-backend-test --filter 'ListFunctionsTest::testToList'   # один метод
docker run --rm wps-backend-test tests/Unit/Expression                      # один каталог
```

Образ `wps-backend-test` содержит **копию** кода на момент сборки: после правки кода (и тестов) его нужно пересобрать — сборка
с кэшем слоёв занимает секунды, `composer install` повторяется, только если изменился `composer.lock`.

Без пересборки образа (код примонтирован, `back/vendor` создаётся на хосте и игнорируется git и Docker):

```bash
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD/back":/app -w /app \
  phpswoole/swoole:6.0-php8.3 sh -c "composer install -q && vendor/bin/phpunit --testsuite unit"
```

### Структура

```
back/
  phpunit.xml.dist          набор unit = tests/Unit (failOnWarning, failOnRisky)
  tests/
    IntegrationTest.php     интеграционные тесты (не PHPUnit, в набор unit не входят)
    Unit/
      bootstrap.php         автозагрузка + часовой пояс UTC
      Actions/              типы изменения и действия: Aggregate, Assign, DateShift, Delay, LinkRecords, SetVariable,
                            SimpleChangeTypes, UpdateRecords
      Comparators/          операторы: базовые, порядка, between, in/not_in, списки, presence, changed*, starts/ends_with
      Context/              ExecutionContext, SystemVariables (sys.*, config.*)
      Crm/                  контракт CRM: CrmFilter, FilterTranslator (фильтр find_records)
      Events/               EventEnvelope, типы событий
      Expression/           язык выражений: движок, условные, даты, числа, Decimal, строки, списки, типы/JSON
      Http/                 исходящий HTTP (EgressGuard, авторизация, извлечение ответа)
      Mail/                 send_email: получатели, ошибки SMTP
      Notify/               каналы уведомлений
      Persistence/          сущность Process
      Program/              компилятор, интерпретатор, блоки condition / stop_process / foreach; fixtures/
      Secrets/              шифрование, маски, ссылки secret://
      Services/             ConditionEngineService, выражение в правой части, DefinitionScope, on_error / retry
      SideEffects/          SideEffectLedger
      Support/              InMemorySideEffectStore (фейк), JsonPath
      Template/             TemplateEngine
      Time/                 DateMath
      Types/                TypeCaster, RelativeDate, регистр и пробелы
```

Пространство имён тестов — `Wps\Tests\` (`autoload-dev` в `back/composer.json`).

### Правила

- Один класс `*Test` на тестируемый класс; таблицы «вход → ожидание» — через `#[DataProvider]`.
- Юнит-тест не открывает сетевых соединений: `Database`, `RedisConnection`, `ExternalApiAdapterService` подменяются
  фейками.
- Чистая логика не использует Swoole-корутины; если без них не обойтись — тест оборачивается в
  `Swoole\Coroutine\run()`.
- Часовой пояс тестов — UTC. Текущее время берётся из `Wps\Time\Clock`; в тестах — `FrozenClock` с фиксированным моментом
  (например, `RelativeDateTest`, `DateFunctionsTest`). Сервисы, которым нужно «сейчас», получают его параметром
  (`ConditionEngineService::evaluate(..., now)`), а не хранят в поле.
- `ExternalApiAdapterService` объявлен `final`: в юнит-тестах его экземпляр создаётся через
  `(new \ReflectionClass(ExternalApiAdapterService::class))->newInstanceWithoutConstructor()` (там, где вызовы CRM не нужны),
  а выполнение с CRM проверяется интеграционно на mock.
- Журнал побочных эффектов в тестах — `Wps\Tests\Unit\Support\InMemorySideEffectStore`.
- `definition` в БД — `jsonb`, порядок ключей объектов не сохраняется: в тестах сравнивайте объекты после `ksort`, а
  упорядоченные данные задавайте списками.
- Новое поведение сначала закрепляется тестом; регрессионные тесты фиксируют фактическое поведение, даже неочевидное
  (например, `'' + 5` в выражении даёт строку `'5'`).

## Интеграционные тесты бэкенда

### Запуск

Тесты работают только на mock-CRM. Корневой `./.env` может направлять бэкенд в SuiteCRM 8 (`CRM_API_BASE_URL=http://suitecrm8/api/v9`),
поэтому режим mock задаётся переменными окружения **в той же команде** (окружение между отдельными вызовами shell не сохраняется):

```bash
export CRM_API_BASE_URL=http://mock:9502 CRM_API_STATIC_KEY=mySecretKey MOCK_SERVICE_URL=http://mock:9502 \
       TELEGRAM_API_BASE=http://mock:9502/mock/telegram
docker compose up -d --build backend mock      # the code is baked into the image — rebuild first
docker compose ps                              # mock must be running, otherwise "Could not resolve host: mock"
docker compose exec -T backend php tests/IntegrationTest.php
```

| Переменная | Зачем |
|---|---|
| `CRM_API_BASE_URL=http://mock:9502`, `CRM_API_STATIC_KEY=mySecretKey` | API CRM — мок (ключ совпадает с `MOCK_CRM_STATIC_KEY` сервиса `mock`) |
| `MOCK_SERVICE_URL=http://mock:9502` | справочник модулей — из мока |
| `TELEGRAM_API_BASE=http://mock:9502/mock/telegram` | блок `[notify]`: Telegram — на мок, а не на api.telegram.org (иначе тест падает) |

Чтобы вернуть бэкенд на SuiteCRM 8 после тестов: `unset CRM_API_BASE_URL CRM_API_STATIC_KEY MOCK_SERVICE_URL TELEGRAM_API_BASE;
docker compose up -d backend`.

Ожидаемый конец вывода:

```
[run_once]
  …
Passed: 156, Failed: 0, Skipped: 0
```

Код возврата `0`, если ничего не упало, и `1` в противном случае, так что команду можно использовать в CI.

> **Внимание**
> Тестам нужна **мок-CRM**: они создают объекты через `http://mock:9502/mock/objects/...` и проверяют, что там
> сохранилось. Mock хранит состояние между прогонами, поэтому id тестовых объектов должны быть уникальными (`random_bytes`,
> `random_int`). Вместо переключения основного стенда можно поднять отдельный стенд с моком
> (см. [Среда разработки](dev-environment.md#второй-изолированный-стенд)).

### Окружение

Внутри контейнера `backend` значения по умолчанию уже подходят:

| Переменная | По умолчанию | Для чего |
|---|---|---|
| `API_BASE_URL` | `http://localhost:9501` | API WPS (тест работает в том же контейнере, что и сервер) |
| `CRM_API_BASE_URL` | `http://localhost:9502` | вспомогательные запросы мок-CRM `/mock/objects/{module}/{id}` |
| `DB_POSTGRES_*` / `DB_*` | из Compose | прямая очистка тестовых данных |
| `REDIS_STREAM_*` | из Compose | тест stream публикует событие через `XADD` |

### Режимы мок-CRM

Мок реализует контракт CRM 2.0 ([Интеграция с CRM](../04-operations/crm-integration.md#вариант-1-мок-по-умолчанию-для-разработки)).
Два режима переключаются на лету, без перезапуска контейнера (хранятся в Redis):

```bash
curl -s -X PUT http://localhost:9502/mock/config -d '{"contract": "1.0"}'          # старый плагин: без capabilities, только равенство
curl -s -X PUT http://localhost:9502/mock/config -d '{"publish_on_write": true}'   # события v2 на POST/PUT/DELETE через API
curl -s http://localhost:9502/mock/config                                          # текущие режимы
```

Значения по умолчанию задают переменные сервиса `mock` в `docker-compose.yml`: `MOCK_CRM_CONTRACT` (`2.0`) и
`MOCK_PUBLISH_ON_WRITE` (`0`). Тест, который меняет режим, возвращает его в `finally` (см. `testCrmContract()`); после смены
контракта сбросьте кэш возможностей WPS: `POST /api/dictionaries/modules/refresh`.

Данные для поиска (`find_records`, блок `[find_records]`) тест кладёт в мок напрямую: `PUT /mock/objects/{module}/{id}`
(запись) и `PUT /mock/relationships/{module}/{id}/{link}/{related_id}` с `{"related_module": "Contacts"}` (связь). Мок
поддерживает все операторы фильтра контракта, включая `nin`.

Блок `[foreach]` так же готовит контакты `LoopContacts` и проверяет: смену ответственного у всех контактов (одна
запись `foreach`, записи тела с `iteration` 0..2), `on_item_error: continue` / `stop` на контакте с id `fail500-loop`
(мок отвечает 500 на его сохранение), паузу 6 с внутри цикла (выполнение `waiting`, ключей `exec:{id}*` в Redis нет,
после пробуждения цикл продолжается по снимку) и валидацию блока. Юнит-тесты цикла — `tests/Unit/Program/ForeachTest.php`
(хранилище снимков в памяти, восстановление посреди цикла).

Блок `[update_records]` проверяет: ответственный контрагента → контактам по связи (`updated: 2`, `unchanged: 1`, новые
значения в списке контекста); запись, которой нет в мок-CRM (404), при `only_changed: false` и `item_var: c` — остальные
записи обновлены, шаг `failed` («1 of 4 records failed») обработан `on_error: continue`; валидацию; пустой список →
`skipped`. Юнит-тесты — `tests/Unit/Actions/UpdateRecordsTest.php` (правила валидации, временная переменная записи, в
том числе при ошибке).

Блок `[aggregate]` проверяет на мок-CRM: `sum`, `count` через `meta.total` (один запрос), `avg`, `max` даты, `count` по
связи, `on_limit: partial` с `truncated`, пустое значение фильтра (без запроса), `on_limit: error`, справочник
`aggregate-functions` и валидацию. Юнит-тесты — `tests/Unit/Actions/AggregateTest.php` (свёртки, ошибка приведения,
пустой фильтр и пустой id связи без запроса, правила валидации).

Блок `[notify]` использует эндпоинты мессенджеров мок-CRM: `POST /mock/slack/{id}` (id `fail429…` — первый ответ 429,
`gone…` — 404), `POST /mock/teams/{id}`, `POST /mock/telegram/bot{token}/sendMessage` (чат `forbidden` — 403); полученные
сообщения — `GET /mock/notify`, очистка — `DELETE /mock/notify`. Проверяется: `crm_alert` + Telegram + Slack + Teams
одним шагом и формат сообщений; токен и URL вебхука не попадают в аудит; 429 → повтор шага, первое сообщение из журнала
(`replayed`), без дублей; запрещённый чат → `failed` при `fail_on: any` и `completed` при `all`; `POST /api/notify/test`,
справочник `notify-channels`, проверка видов секретов. Юнит-тесты — `tests/Unit/Notify/NotifyTest.php` (экранирование,
обрезка текста, ссылки по профилям, тела Teams, поля `Alerts`, получатели, итог по `fail_on`, валидация).

Блок `[assign]` готовит в мок-CRM пользователей (u4 — неактивный, u1 — руководитель u2 и u3) и группу безопасности и
проверяет: `round_robin` по группе — u1, u2, u3, снова u1, неактивный пропущен; сохранение падает один раз
(`fail1x500`) → две попытки, `reused_decision: true`, очередь сдвинулась один раз; `least_busy` (открытых 2/1/0 → u3);
`manager` с `save_to_crm`; `fallback: keep`; справочник `assign-strategies`; валидацию. Юнит-тесты —
`tests/Unit/Actions/AssignTest.php` (активность пользователя, круг с пропуском неактивного и отсутствующего, `manager`
и `fallback`, лимит пула `least_busy`, правила валидации).

Блок `[starts_with]` проверяет справочник операторов, ручной запуск (условие `ends_with @gmail.com` выполнено для
настоящего адреса и не выполнено для `…@gmail.com.evil.ru`) и `422` для `cast: int` и пустого префикса. Юнит-тесты —
`tests/Unit/Comparators/StartsEndsWithTest.php` (`+7`, домен почты, кириллица с `case_sensitive: false`, пустое и
отсутствующее поле, число, перевод в `like` фильтра CRM).

Блок `[between]` проверяет справочник (`operands: range`), ручной запуск (50 000 в диапазоне `[10000, 50000]` — да,
50 001 — нет) и правила валидации (тип, пара, пустая граница, приведение, `от > до`). Юнит-тесты —
`tests/Unit/Comparators/BetweenTest.php` (включение границ, пустое и отсутствующее поле, даты в разных форматах, границы
из полей, `range_inverted`, отсутствующая граница, ленивая предзагрузка, перевод в `gte` + `lte`).

Блок `[relative dates]` проверяет справочник `relative-dates`, ручной запуск (`expected_resolved` в трассе; запись
старше суток не проходит `now-24h`) и ошибки валидации в условии, в границе `between` и в фильтре `find_records`.
Юнит-тесты — `tests/Unit/Types/RelativeDateTest.php`: разбор и ошибки, вычисление около полуночи в `Europe/Moscow`,
конец февраля и 31.01 + 1 мес, начало недели, квартал, переход на летнее время в `Europe/Berlin` (`+1d` и `+24h`),
условия с фиксированными часами (`FrozenClock`), значение из поля не интерпретируется, относительная дата в фильтре CRM.

Блок `[case_sensitive]` проверяет справочник `casts`, строгое и нестрогое условие на «  МОСКВА », `actual_cast` и
`cast_options` в трассе и отказ для плоского `case_sensitive` и для `trim` у `int`. Юнит-тесты —
`tests/Unit/Types/CaseInsensitiveTest.php` («МОСКВА » ≡ « Москва» с неразрывным пробелом, `Straße` ≠ `STRASSE`, `ё` ≠
`е`, BOM, мультисписок; операторы `equals`, `contains`, `in`, `changed`).

Блок `[list operators]` проверяет справочник, ручные запуски на мультисписке (`^VIP^,^gold^` — да; `^vipclient^` — нет;
`^vip^,^spam^` при `contains_none` — нет) и валидацию типа и пустого списка. Юнит-тесты —
`tests/Unit/Comparators/ListOperatorsTest.php` (пересечение и подмножество, нет совпадения подстроки, пустое и
отсутствующее поле, регистр, строка справа, `matched`, правая часть из поля и пустая).

Блок `[expression value]` проверяет справочник `value-types`, процесс с предзагрузкой контрагента и условием-выражением
(90 000 — да, `expected = 80000`; 70 000 — нет) и правила валидации. Юнит-тесты —
`tests/Unit/Services/ConditionExpressionValueTest.php` (`v1.credit_limit * 0.8`, ленивая предзагрузка, `coalesce`, строка
`'1 200,50'` с `decimal`, `lower()`, деление на ноль — ложь и `expression_error`, в строгом режиме исключение,
отсутствующее поле, пара выражений для `between`).

Блок `[change types 5.1.3-5.1.6]` проверяет справочник типов изменения, семь шагов на одной записи (`increment` → `5`,
`increment` дробный → `80.25`, `clear` → `''`, история через `append`, мультисписок `+vip` / `−cold`, `map` → `High`) и
правила валидации. Юнит-тесты — `tests/Unit/Actions/SimpleChangeTypesTest.php` (`increment` / `decrement` со всеми
режимами, `clear`, `append` / `prepend` с разделителем и `max_length`, списки с `unique` и `remove`, `map` с регистром,
`keep`, диапазонами, шаблоном и ключом `""`, правила валидации).

### Как устроен файл тестов

`IntegrationTest` — один класс (`md/doc/concept_apitest_back.md`):

- `run()` вызывает группы тестов по порядку (44 блока): `[cleanup]` → `[health]` → `[auth]` → `[users]` → `[dictionaries]` →
  `[processes]` → `[validation]` → `[rbac]` → `[manual run]` → `[executions]` → `[stream]` → `[delete]` → `[run_once]` →
  `[typed conditions]` → `[program blocks]` → `[secrets]` → `[side effects]` → `[crm contract]` → `[event format]` →
  `[event types]` → `[change operators]` → `[on_error]` → `[send_email]` → `[http_request]` → `[link_records]` →
  `[find_records]` → `[foreach]` → `[update_records]` → `[aggregate]` → `[notify]` → `[assign]` → `[starts_with]` →
  `[between]` → `[relative dates]` → `[case_sensitive]` → `[list operators]` → `[expression value]` →
  `[change types 5.1.3-5.1.6]` → `[condition branches]` → `[stop_process]` → `[set_variable]` → `[waiting]` → `[expressions]` →
  `[system variables]`.
  Поздние группы используют данные ранних (`$this->adminToken`, `$this->createdProcesses['top']`, `$this->executionId`).
- Группа — это метод `private function testXxx(): void`: он печатает заголовок и вызывает `$this->test('name', fn)` для
  каждой проверки. Проверка пройдена, если замыкание вернуло управление; провалена при любом `Throwable`; пропускается
  через `$this->skip('reason')`.
- Вспомогательные методы:

| Метод | Назначение |
|---|---|
| `request($method, $path, $body, $token, $expectedStatus)` | JSON-запрос к WPS; бросает исключение, если статус другой; возвращает разобранный конверт |
| `raw($method, $path, $body, $token, $base)` | любой HTTP-запрос, возвращает `{status, body}` (например, к моку с `base: $this->crmUrl`) |
| `waitForExecution($id, $timeout = 10)` | опрашивает `GET /api/executions/{id}`, пока статус не станет `completed`/`failed` |
| `waitForExecutions($processId, $objectId, $count)` | ждёт выполнений, запущенных из stream |
| `seedAccount()`, `crmObject($module, $id)` | записать / прочитать объект в мок-CRM |
| `executionsOf($processId, $objectId)`, `parallelRuns($processId, $body, $count)` | выполнения процесса для записи; параллельные ручные запуски (проверка гонок) |
| `xadd($event)`, `redis()`, `redisData()`, `pdo()` | опубликовать событие в stream; прямой доступ к Redis и PostgreSQL |
| `requireValue($v)`, `skip($reason)` | пропустить проверку, если нет данных из предыдущей |
| `assertTrue`, `assertFalse`, `assertEquals` (строгое), `assertNotNull`, `assertIsArray`, `assertArrayHasKey`, `assertArrayNotHasKey` | проверки |

- Тестовые данные называются `TEST …` (процессы), `…@test.local` (пользователи) и `test_…` (секреты); `cleanupTestData()`
  удаляет их (вместе с выполнениями и отметками) в начале каждого прогона, поэтому тесты можно повторять на той же базе.

### Добавление проверки

1. Напишите метод группы рядом с остальными:

```php
private function testMyFeature(): void
{
    echo "[my feature]\n";
    $this->test('Operators dictionary contains starts_with', function () {
        $res = $this->request('GET', '/api/dictionaries/operators', token: $this->viewerToken, expectedStatus: 200);
        $this->assertTrue(in_array('starts_with', array_column($res['data'], 'value'), true));
    });
}
```

2. Вызовите его из `run()` (после `testLogin()`, если нужны токены) — новые блоки добавляются в конец списка:

```php
$this->testSystemVariables();
$this->testMyFeature();
```

3. Правила из `md/doc/concept_apitest_back.md`: всегда передавайте `expectedStatus`; явно проверяйте `success`/`data`;
   называйте созданные процессы `TEST …`; сохраняйте созданные id в `$this->created*`, чтобы ими могли пользоваться
   следующие проверки; для защищённых эндпоинтов проверяйте: без токена → 401, чужая роль → 403.
4. Пересоберите бэкенд и запустите файл. Каждая инструкция в разделе [Расширение WPS](extending/new-action-type.md)
   заканчивается готовой проверкой для своего расширения.

> **Примечание**
> Добавляя элемент справочника, обновите и существующие проверки на точное совпадение: например,
> *"Operators contain the four comparison types"* сравнивает полный список кодов операторов.

## Кейсы SuiteCRM 8

Кейс — исполняемый JSON-сценарий реального бизнес-процесса на стенде SuiteCRM 8 с плагином WPS: он создаёт процессы в WPS,
меняет записи через API плагина, ждёт выполнений и проверяет данные в CRM, трассу условий и аудит WPS. Каталог `.tehnical/`
не входит в git, но есть в рабочей копии ([Структура проекта](project-structure.md#tehnical)).

### Запуск

```bash
# 1. backend on SuiteCRM 8 (values from the root .env): drop the mock-mode overrides
unset CRM_API_BASE_URL CRM_API_STATIC_KEY MOCK_SERVICE_URL TELEGRAM_API_BASE
docker compose up -d backend
# 2. the SuiteCRM 8 stand must be running (wps-suitecrm8-web-1, wps-suitecrm8-db-1)
docker ps --format '{{.Names}}' | grep suitecrm8
# 3. run a case on the host (PHP ≥ 8.1, no extensions)
php .tehnical/suiteCRM8/scripts/run_case.php .tehnical/suiteCRM8/processes/cases/5.2.9-system-variables.json
php .tehnical/suiteCRM8/scripts/run_case.php <case.json> --keep     # leave the case processes active
# regression of all cases
for f in .tehnical/suiteCRM8/processes/cases/*.json; do php .tehnical/suiteCRM8/scripts/run_case.php "$f" || echo "FAILED: $f"; done
```

Раннер читает параметры из `.tehnical/suiteCRM8/.env` (переопределяются окружением): `WPS_API_URL`, `WPS_API_EMAIL`,
`WPS_API_PASSWORD`, `WPS_API_STATIC_KEY`, `SUITECRM8_HTTP_PORT` (API CRM — `http://localhost:<port>/api/v9`) или `CRM_API_URL`,
`MAILPIT_URL`. Запросы к API плагина подписываются заголовком `X-Auth-Key: <WPS_API_STATIC_KEY><Y.m.d H UTC>`. Итог —
строка `PASSED: N step(s) passed, T s` или `FAILED: …`, перед ним — `FAIL: <причина>` упавшего шага; код возврата `0` / `1`.

### Как работает раннер

1. Процессы из `deactivate` (например, демонстрационные процессы стенда) выключаются на время прогона и потом
   восстанавливаются.
2. Выполняются шаги `setup`.
3. Процессы кейса импортируются в WPS по имени (`POST` или `PUT /api/processes`, сначала ведомые) и включаются; они доступны
   как `{{process.<индекс>.id}}`.
4. Выполняются `steps` по порядку.
5. Всегда (даже после ошибки) процессы кейса снова выключаются (кроме `--keep`), выполняются шаги `teardown` (их ошибки только
   печатаются) и включаются процессы из `deactivate`.

### Формат кейса

```json
{
  "id": "5.2.9",
  "title": "Короткое описание бизнес-сценария",
  "description": "Что проверяется",
  "deactivate": ["suiteCRM8"],
  "setup": [ … ],
  "processes": [ { "name": "case 5.2.9 — sys и config", "object_type": "Accounts", "is_child": false,
                   "trigger_on": ["update"], "definition": { … } } ],
  "steps": [
    {"title": "аккаунт", "crm.create": {"module": "Accounts", "attributes": {"name": "S529 {{case.run}}"}}, "as": "a1"},
    {"crm.update": {"module": "Accounts", "id": "{{a1.id}}", "attributes": {"phone_office": "+7 999"}}},
    {"wps.wait": {"process": "case 5.2.9 — sys и config", "object_id": "{{a1.id}}", "status": "completed", "timeout": 60}, "as": "e1"},
    {"assert": {"actual": "{{e1.context.system.trigger}}", "equals": "stream"}},
    {"wps.api": {"method": "POST", "path": "/api/processes", "body": { … }, "expect": 422}, "as": "err"}
  ],
  "teardown": [ {"crm.api": {"method": "DELETE", "path": "Accounts/{{a1.id}}", "expect": [200, 404]}} ]
}
```

Каждый шаг — объект с одним ключом действия, необязательными `as` (имя для результата) и `title`:

| Шаг | Что делает |
|---|---|
| `crm.create` / `crm.update` / `crm.get` | создать / изменить / прочитать запись через API плагина (`module`, `id`, `attributes`) |
| `crm.find` | поиск: `module`, `filter` (равенство или операторы контракта 2.0: `{"amount": {"gte": "1000"}}`), `size` |
| `crm.api` | любой вызов API плагина: `method`, `path`, `body`, `expect` (статус или список) |
| `wps.wait` | дождаться выполнения процесса для записи: `process`, `object_id`, `status`, `timeout`, `count` (N-е выполнение) |
| `wps.none` | убедиться, что выполнения нет: `process`, `object_id`, `wait` |
| `wps.run` | ручной запуск: `process`, `object`, `force` |
| `wps.get` | дождаться статуса выполнения по id: `execution_id`, `status`, `timeout` |
| `wps.api` | любой вызов API WPS; результат — `data` (или `error` при ожидаемом статусе ≥ 400) |
| `wps.restart` | перезапуск backend (`docker compose restart`), `kill: true` — жёсткий, `env` — переменные на время перезапуска |
| `assert` | `actual` + один оператор: `equals`, `not_equals`, `contains`, `not_contains`, `matches` (регулярное выражение), `empty`, `not_empty`, `count` |
| `sleep` | пауза в секундах |
| `mail.find` | письмо в Mailpit по `subject` / `subject_contains`, `timeout` |
| `http.get` | JSON по любому URL (`method` — необязательно), например `http://localhost:9502/mock/echo-last?key=x` |
| `script` | фикстура из `scripts/` стенда, например `{"script": {"name": "seed_case_users.sh"}}` |

Плейсхолдеры: `{{имя.путь}}` — результат предыдущего шага (`length` — размер списка); `{{case.run}}` — уникальный маркер прогона
(им помечаются тестовые записи); `{{case.date+N}}` / `{{case.date-N}}` — дата относительно дня прогона (UTC, `Y-m-d`);
`{{env.NAME}}` — переменная окружения. Строка из одного плейсхолдера сохраняет тип значения. `\{{` — буквальные `{{` (чтобы
передать шаблон WPS в теле шага). Полезные пути: трасса условий — `context.conditions`, шаг — `actions.N.result_data`,
`actions.N.request_data`, `actions.N.error_code`, `actions.N.handled`, системные переменные — `context.system`.

### Требования к кейсу

- Реальный бизнес-сценарий: данные в CRM проверяются после выполнения, проверяются трасса или аудит WPS и хотя бы одна
  ошибка валидации (`422`) через `wps.api`.
- Тестовые записи помечаются `{{case.run}}` и удаляются в `teardown`.
- Пользователей CRM API плагина не создаёт (`Users` только для чтения) — используйте фикстуру `seed_case_users.sh`.
- Описание кейса — `md/tasks_list/cases/<id>-suitecrm8.md` (что сделано, проверка с цифрами, таблица «данные → результат»,
  выдержка вывода раннера).
- Ограничения SuiteCRM, с которыми сталкивались (длина `name` у `Tasks` / `Meetings` — 50, вырезание HTML, relate-поля без
  колонки), собраны в `legend_project_agent.md`.

## Проверки фронтенда

```bash
cd front
npm ci
npm test             # vitest: src/**/*.spec.ts
npm run typecheck    # vue-tsc --noEmit
npm run i18n:check   # translations
npm run build        # i18n:check + typecheck + vite build → dist/
```

`npm test` проверяет функции формы редактора (`src/utils/processForm.ts`): процесс, открытый и сохранённый без
правок, должен совпасть с исходным — на всех демо-процессах `doc/_meta/demo_processes/*.json` и на отдельных случаях
(константы, `cast`, списки значений, порядок `start_process`, нередактируемые строки). Нужен Node 18+.

`npm run i18n:check` сообщает, например:

```
✗ de.json: нет ключа nav.functions
```

Скрипт проверяет, что во всех `src/locales/*.json` те же ключи, что в `ru.json`, что каждый ключ `t('…')`/`te('…')`
из кода существует и что в файлах `.vue`/`.ts` нет кириллицы вне комментариев. Docker-образ фронтенда выполняет тот же
`npm run build`, поэтому сломанный перевод ломает и `docker compose up --build frontend`.

## Ручная сквозная проверка

1. `curl -g 'http://localhost:9502/test-event?module=Deals&set[status]=Active'` — опубликуйте событие.
2. Откройте процесс в UI → журнал выполнений → новое выполнение → шаги и контекст.
3. `curl http://localhost:9501/metrics | grep wps_` — счётчики изменились.

### Рестарт во время ожидания

1. Создайте процесс с шагом `{"type": "delay", "mode": "duration", "duration": "30s"}` перед `modify` + `save_object`
   и опубликуйте событие (как выше).
2. `curl -s 'http://localhost:9501/api/executions?status=waiting' -H "Authorization: Bearer $TOKEN"` — выполнение в
   статусе `waiting`, `waiting_until` через 30 с; `wps_executions_waiting` в `/metrics` равен 1.
3. Посреди паузы убейте backend: `docker compose kill -s KILL backend && docker compose up -d backend`. Для проверки
   потери Redis дополнительно выполните `docker compose exec redis-data redis-cli FLUSHALL`.
4. После срока выполнение продолжается: шаг `delay` — `success` с `waited: true` и `lateness_ms`, выполнение —
   `completed`, в логах `Execution woken`. Повторно оно не просыпается (`wps_executions_woken_total` вырос на 1).

Тот же сценарий автоматически проверяет кейс `.tehnical/suiteCRM8/processes/cases/7.2-waiting-executions.json`.

См. [Выполнения и аудит](../03-user-guide/executions-and-audit.md).

<!-- footer-nav -->
---

← Назад: [Соглашения о коде](coding-conventions.md) | [Оглавление](../README.md) | [Новый тип действия](extending/new-action-type.md) Далее →
<!-- /footer-nav -->
