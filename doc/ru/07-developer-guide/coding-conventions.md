# Соглашения о коде

Правила, которым следует существующий код. Новый код должен выглядеть так же, чтобы органично встать рядом.

<!-- lang-switch -->
[🇬🇧 English](../../en/07-developer-guide/coding-conventions.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Бэкенд (PHP)

### Файлы и классы

- Каждый файл начинается с `declare(strict_types=1);`. Пространство имён `Wps\…` повторяет папку внутри `back/src/` (PSR-4).
- Классы объявляются `final`, если они не предназначены для наследования. Не-`final` только иерархия исключений
  (`ApiException`, `RecoverableException`, `NonRecoverableException`) и абстрактные базовые классы (`BaseController`, `ExecutionException`).
- Зависимости передаются через **constructor promotion с `private readonly`**; объекты-значения используют свойства
  `public readonly` (`AppConfig`, `ActionResult`, `StepRecord`).
- Короткий docblock класса объясняет, *зачем* класс нужен, со ссылкой на план задачи `md/tasks_list/done/<id>-….md` (иногда
  с разделом: `md/tasks_list/done/4.2-http-request.md §5`), на ТЗ (`TS §5.4`) или ответ заказчика (`customer answer #18`).
  На `doc/*/…` из PHP-кода не ссылаются. Плотность комментариев держите такой же, как в окружающем коде.
- Код, комментарии в коде, сообщения API и логов — на английском. Русский допустим только в подписях справочников (`label`)
  и в текстах, которые видит пользователь CRM (тестовое письмо, уведомление).
- Для массивов — PHPDoc с формой массива (`@param array<string, mixed> $config`, `@return list<array{value: string, label: string}>`).

### Связывание

- DI-фреймворка нет. `Wps\Kernel\Container` — сервис-локатор на уровне воркера: по одному методу на сервис, объект
  создаётся лениво через `share()`. Контейнер строится после fork воркера, поэтому у каждого воркера свои пулы БД и Redis.
- Точки расширения — это **реестры**: `ActionHandlerRegistry` (`Container::actions()`), `ChangeTypeRegistry`
  (`Container::changeTypes()`), `ComparatorRegistry` (`ComparatorRegistry::default()`, `Container::comparators()`),
  `BlockTypeRegistry` (`Container::blocks()`), `ChannelRegistry` (`Container::notifyChannels()`), `LoadStrategyRegistry` и
  таблица функций `ExpressionEngine` (`registerFunction()` / `registerSpecialForm()` с `FunctionSpec`). Расширение = класс,
  реализующий интерфейс, + одна строка `register()` в `back/src/Kernel/Container.php`. Неизвестный код приводит к
  `ConfigurationException`.
- **Сервисы общие для всех корутин воркера.** Не храните состояние одного вызова (текущее выполнение, «сейчас», временные
  списки) в полях сервиса — передавайте его параметрами (`ConditionEngineService::evaluate(..., now)`) или держите в
  `ExecutionContext`. Поля сервиса — только зависимости и неизменяемая конфигурация.
- Каждый элемент реестра отдаёт стабильный код (`name()`/`type()`), который хранится в определениях процессов, и `label()`
  для эндпоинтов справочников. Коды пишутся в `snake_case` и после релиза не меняются никогда — на них ссылаются
  существующие процессы.

### HTTP-слой

- **Один класс контроллера на эндпоинт**: `#[Route(uri: '/api/…/{id}', method: 'GET')]`, наследование от
  `Wps\Controllers\BaseController` и реализация `handle(): Response`. Контроллеры тонкие: разобрать вход, вызвать
  сервис, вернуть `$this->ok(...)`.
- Ответы всегда в конверте из `Wps\Http\JsonResponder`:
  `{ "success": true, "data": …, "error": null, "meta"? }` / `{ "success": false, "data": null, "error": { code, message, details? } }`.
- Ошибки выбрасываются как `Wps\Http\ApiException` (`badRequest`, `unauthorized`, `forbidden`, `notFound`, `conflict`,
  `validation`) — их отрисовывает `BaseController`. Значения `code` пишутся в `UPPER_SNAKE_CASE` и описаны в `back/API.md`.
- Ошибки валидации — список `{ field, message }`, где `field` — путь внутри запроса
  (`definition.actions[2].value`). Сообщения на английском; известные коды UI заменяет переведёнными текстами.
- Новый эндпоинт отражается в `back/API.md` и в [справочнике API](../06-api-reference/endpoints.md) (ru + en); новый
  справочник — класс в `Controllers/Dictionaries/` с `value` / `label`.
- Правила доступа собраны в одном месте, `Wps\Auth\AccessPolicy::RULES`, а не в контроллерах.

### Ошибки выполнения

- Внутри действий бросайте `RecoverableException` (временная ошибка: будет повтор с нарастающей задержкой) или
  `NonRecoverableException` (постоянная ошибка: выполнение сразу завершается с ошибкой). Ошибки конфигурации, найденные
  во время выполнения, бросают `ConfigurationException`.
- `ActionHandlerInterface::validate()` должен ловить при сохранении всё, что можно проверить статически, чтобы
  некорректные определения вообще не доходили до движка. Ошибки пишутся в `DefinitionScope` с путём поля; области видимости
  переменных (ветки, циклы, «задана не во всех ветках» — предупреждение) проверяет тот же `DefinitionScope`.
- Действия с внешними эффектами (`send_email`, `http_request`, `notify`) выполняют вызов через журнал побочных эффектов
  (`SideEffectLedger`, ключ `{pc}@{iteration}#{sub}`) и поддерживают `delivery` (`HasDeliveryPolicy`), чтобы повтор шага не
  дублировал письмо или вебхук. Повтор шага восстанавливает снимок контекста — шаг не должен зависеть от собственных
  промежуточных изменений контекста.
- Никогда не глушите исключения молча: пишите в лог с контекстом (`execution_id`, `process_id`, `step`, `error`).

### Логирование и данные

- Используйте PSR-3 логгер из контейнера (`Container::get()->logger()`): сообщение на английском, данные в массиве контекста.
- SQL живёт в `Persistence/*Repository`; время хранится в UTC (колонки `TIMESTAMP`).
- `processes.definition` и снимки — `jsonb`: порядок ключей объекта не сохраняется. Упорядоченные данные (поля
  `set_variable`, диапазоны `map`) задаются списками, а не объектами.
- Новая настройка — свойство `AppConfig` (через `Env::get/int/bool`, с границами `max()` / `min()`), строка с комментарием в
  `back/.env.example`, при необходимости — значение для стенда в `docker-compose.yml` и описание в
  [Конфигурации](../04-operations/configuration.md).
- Время берётся из `Wps\Time\Clock` (в тестах — `FrozenClock`); календарная арифметика — `DateMath` в `WPS_TIMEZONE`.
- Структура БД меняется только новым пронумерованным файлом в `back/migrations/` — применённую миграцию не редактируют.
- Фреймворк SwooleApp не патчится. Поведение фреймворка исправляется наследованием в `back/src` (см. `WpsRoutesCollectionBuilder`).

## Фронтенд (Vue / TypeScript)

- Vue 3 `<script setup lang="ts">`, Composition API, хранилища Pinia, компоненты Element Plus; алиас `@/` для `src/`.
- Все вызовы API идут через `src/api/*` (`apiGet`, `apiGetPage`, `apiPost`, …): они разворачивают конверт и обновляют токены.
- Типы данных API — в `src/types/index.ts`. Неизвестные типы действий сохраняются как `UnknownAction` и записываются без изменений.
- **Никакого захардкоженного текста UI.** Каждая строка — ключ в `src/locales/ru.json` **и** `src/locales/en.json`:
  - в компонентах `const { t } = useI18n()` и `t('section.key', { param })`; вне компонентов — `t` из `@/i18n`;
  - вложенные ключи в `camelCase`, сгруппированные по экранам и разделам (`processEdit.*`, `modify.help.*`);
  - символы `@ { } | $` в сообщениях специальные: буквальный `@` пишется как `{'@'}`; множественное число через `|`
    (в русском три формы: `{n} запись | {n} записи | {n} записей`);
  - правила форм (`FormRules`) с сообщениями оборачиваются в `computed`, переведённые строки не кэшируются в константах;
  - элементы справочников из API переводятся по значению: `dict.<kind>.<value>` (`utils/format.ts → localizeDict`).
- `npm run i18n:check` (входит в `npm run build`) падает, если: в какой-то локали нет ключа; в коде используется ключ,
  которого нет в `ru.json`; в `.vue`/`.ts` есть **кириллица вне комментариев** (единственное исключение — `src/i18n/locales.ts`).
- Комментарии в коде фронтенда в основном на русском — следуйте стилю файла, который правите.

## Документация

- Изменилось поведение, видимое пользователям или операторам → обновите `doc/en` **и** `doc/ru` (одинаковые имена файлов),
  `back/API.md` для API и скриншоты из `doc/_meta/screenshots_manifest.md`, если изменился экран.
  См. [Участие в разработке](contributing.md).

<!-- footer-nav -->
---

← Назад: [Структура проекта](project-structure.md) | [Оглавление](../README.md) | [Тестирование](testing.md) Далее →
<!-- /footer-nav -->
