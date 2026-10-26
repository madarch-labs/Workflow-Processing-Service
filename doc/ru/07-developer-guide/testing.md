# Тестирование

Как устроены юнит-тесты и интеграционные тесты бэкенда, проверки фронтенда, как их запускать и как добавить новую проверку.

<!-- lang-switch -->
[🇬🇧 English](../../en/07-developer-guide/testing.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Обзор

| Часть | Инструмент | Команда |
|---|---|---|
| Логика бэкенда (юнит) | PHPUnit, `back/tests/Unit` | `docker build -f back/Dockerfile --target test -t wps-backend-test . && docker run --rm wps-backend-test --testsuite unit` |
| API бэкенда | `back/tests/IntegrationTest.php` — чистый PHP, HTTP-запросы к запущенному серверу | `docker compose exec backend php tests/IntegrationTest.php` |
| Типы фронтенда | `vue-tsc` | `npm run typecheck` |
| Переводы фронтенда | `scripts/i18n-check.mjs` | `npm run i18n:check` |
| Сборка фронтенда | проверка i18n + typecheck + `vite build` | `npm run build` |

Чистая логика (сравнения, выражения, условия, контекст выполнения) проверяется юнит-тестами за секунды, без поднятого
стека. Сценарии целиком — интеграционными тестами через API на настоящем PostgreSQL, обоих экземплярах Redis и мок-CRM.

## Юнит-тесты бэкенда

### Запуск

Юнит-тесты собираются в отдельной стадии `test` образа бэкенда: в ней есть dev-зависимости (PHPUnit), в
production-стадии `prod` их нет.

```bash
# из корня репозитория
docker build -f back/Dockerfile --target test -t wps-backend-test .
docker run --rm wps-backend-test --testsuite unit
docker run --rm wps-backend-test --filter ExpressionEngineTest   # один класс
```

Без пересборки образа (код примонтирован, `back/vendor` создаётся на хосте и игнорируется git и Docker):

```bash
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD/back":/app -w /app \
  phpswoole/swoole:6.0-php8.3 sh -c "composer install -q && vendor/bin/phpunit --testsuite unit"
```

### Структура

```
back/
  phpunit.xml.dist          набор unit = tests/Unit
  tests/
    IntegrationTest.php     интеграционные тесты (не PHPUnit, в набор unit не входят)
    Unit/
      bootstrap.php         автозагрузка + часовой пояс UTC
      Comparators/          операторы сравнения
      Context/              ExecutionContext
      Crm/                  контракт CRM: CrmFilter, FilterTranslator (фильтр find_records)
      Expression/           язык выражений
      Services/             ConditionEngineService и другие сервисы с чистой логикой
      Support/              фейки и вспомогательные классы для тестов
```

Пространство имён тестов — `Wps\Tests\` (`autoload-dev` в `back/composer.json`).

### Правила

- Один класс `*Test` на тестируемый класс; таблицы «вход → ожидание» — через `#[DataProvider]`.
- Юнит-тест не открывает сетевых соединений: `Database`, `RedisConnection`, `ExternalApiAdapterService` подменяются
  фейками.
- Чистая логика не использует Swoole-корутины; если без них не обойтись — тест оборачивается в
  `Swoole\Coroutine\run()`.
- Часовой пояс тестов — UTC. Текущее время берётся из часов, которые в тестах фиксируются (`Wps\Time\Clock`, появится
  вместе с функциями дат).
- Новое поведение сначала закрепляется тестом; регрессионные тесты фиксируют фактическое поведение, даже неочевидное
  (например, `'' + 5` в выражении даёт строку `'5'`).

## Интеграционные тесты бэкенда

### Запуск

```bash
export TELEGRAM_API_BASE=http://mock:9502/mock/telegram   # блок [notify]: Telegram — на мок, а не на api.telegram.org
docker compose up -d --build backend     # the code is baked into the image — rebuild first
docker compose exec backend php tests/IntegrationTest.php
```

Без `TELEGRAM_API_BASE` на мок тест Telegram в блоке `[notify]` обращается к настоящему Bot API и падает. Переменная
действует только в этом shell; для работы с настоящим Telegram перезапустите бэкенд без неё.

Ожидаемый конец вывода:

```
[run_once]
  …
Passed: 67, Failed: 0, Skipped: 0
```

Код возврата `0`, если ничего не упало, и `1` в противном случае, так что команду можно использовать в CI.

> **Внимание**
> Тестам нужна **мок-CRM**: они создают объекты через `http://mock:9502/mock/objects/...` и проверяют, что там
> сохранилось. Если `./.env` направляет бэкенд в настоящую SuiteCRM, запускайте тесты на отдельном стенде с моком
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

- `run()` вызывает группы тестов по порядку: очистка → health → auth → users → справочники → процессы → валидация → RBAC →
  ручной запуск → выполнения → stream → правила удаления → однократный запуск. Поздние группы используют данные ранних
  (`$this->adminToken`, `$this->createdProcesses['top']`, `$this->executionId`).
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
| `assertTrue`, `assertFalse`, `assertEquals` (строгое), `assertNotNull`, `assertIsArray`, `assertArrayHasKey`, `assertArrayNotHasKey` | проверки |

- Тестовые данные называются `TEST …` (процессы) и `…@test.local` (пользователи); `cleanupTestData()` удаляет остатки
  в начале каждого прогона, поэтому тесты можно повторять на той же базе.

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

2. Вызовите его из `run()` (после `testLogin()`, если нужны токены):

```php
$this->testRunOnce();
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

## Проверки фронтенда

```bash
cd front
npm ci
npm run typecheck    # vue-tsc --noEmit
npm run i18n:check   # translations
npm run build        # i18n:check + typecheck + vite build → dist/
```

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
