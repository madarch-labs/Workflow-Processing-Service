# Тестирование

Как устроены интеграционные тесты бэкенда и проверки фронтенда, как их запускать и как добавить новую проверку.

<!-- lang-switch -->
[🇬🇧 English](../../en/07-developer-guide/testing.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Обзор

| Часть | Инструмент | Команда |
|---|---|---|
| API бэкенда | `back/tests/IntegrationTest.php` — чистый PHP, HTTP-запросы к запущенному серверу | `docker compose exec backend php tests/IntegrationTest.php` |
| Типы фронтенда | `vue-tsc` | `npm run typecheck` |
| Переводы фронтенда | `scripts/i18n-check.mjs` | `npm run i18n:check` |
| Сборка фронтенда | проверка i18n + typecheck + `vite build` | `npm run build` |

Фреймворка для unit-тестов нет: бэкенд тестируется сквозным образом через API — на настоящем PostgreSQL, обоих
экземплярах Redis и мок-CRM.

## Интеграционные тесты бэкенда

### Запуск

```bash
docker compose up -d --build backend     # the code is baked into the image — rebuild first
docker compose exec backend php tests/IntegrationTest.php
```

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

См. [Выполнения и аудит](../03-user-guide/executions-and-audit.md).

<!-- footer-nav -->
---

← Назад: [Соглашения о коде](coding-conventions.md) | [Оглавление](../README.md) | [Новый тип действия](extending/new-action-type.md) Далее →
<!-- /footer-nav -->
