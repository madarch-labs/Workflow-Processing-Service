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
- Короткий docblock класса объясняет, *зачем* класс нужен, часто со ссылкой на ТЗ (`TS §5.4`) или ответ заказчика
  (`customer answer #18`). Плотность комментариев держите такой же, как в окружающем коде.
- Для массивов — PHPDoc с формой массива (`@param array<string, mixed> $config`, `@return list<array{value: string, label: string}>`).

### Связывание

- DI-фреймворка нет. `Wps\Kernel\Container` — сервис-локатор на уровне воркера: по одному методу на сервис, объект
  создаётся лениво через `share()`. Контейнер строится после fork воркера, поэтому у каждого воркера свои пулы БД и Redis.
- Точки расширения — это **реестры** (`ComparatorRegistry`, `ActionHandlerRegistry`, `ChangeTypeRegistry`,
  `LoadStrategyRegistry`, таблица функций `ExpressionEngine`). Плагин = класс, реализующий интерфейс, + одна строка
  `register()`. Неизвестный код приводит к `ConfigurationException`.
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
- Правила доступа собраны в одном месте, `Wps\Auth\AccessPolicy::RULES`, а не в контроллерах.

### Ошибки выполнения

- Внутри действий бросайте `RecoverableException` (временная ошибка: будет повтор с нарастающей задержкой) или
  `NonRecoverableException` (постоянная ошибка: выполнение сразу завершается с ошибкой). Ошибки конфигурации, найденные
  во время выполнения, бросают `ConfigurationException`.
- `ActionHandlerInterface::validate()` должен ловить при сохранении всё, что можно проверить статически, чтобы
  некорректные определения вообще не доходили до движка.
- Никогда не глушите исключения молча: пишите в лог с контекстом (`execution_id`, `process_id`, `step`, `error`).

### Логирование и данные

- Используйте PSR-3 логгер из контейнера (`Container::get()->logger()`): сообщение на английском, данные в массиве контекста.
- SQL живёт в `Persistence/*Repository`; время хранится в UTC (колонки `TIMESTAMP`).
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
