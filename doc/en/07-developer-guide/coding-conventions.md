# Coding conventions

Rules the existing code follows. New code should look the same so it fits next to it.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/07-developer-guide/coding-conventions.md)
<!-- /lang-switch -->

## Backend (PHP)

### Files and classes

- Every file starts with `declare(strict_types=1);`. Namespace `Wps\…` mirrors the folder under `back/src/` (PSR-4).
- Classes are `final` unless they are meant to be extended. The only non-final classes are the exception hierarchy
  (`ApiException`, `RecoverableException`, `NonRecoverableException`) and the abstract bases (`BaseController`, `ExecutionException`).
- Dependencies come through **constructor promotion with `private readonly`**; value objects use `public readonly`
  properties (`AppConfig`, `ActionResult`, `StepRecord`).
- A short class docblock explains *why* the class exists, often with a reference to the specification
  (`TS §5.4`) or a customer answer (`customer answer #18`). Keep the comment density of the surrounding code.
- PHPDoc array shapes for arrays (`@param array<string, mixed> $config`, `@return list<array{value: string, label: string}>`).

### Wiring

- No DI framework. `Wps\Kernel\Container` is a per-worker service locator: one method per service, created lazily with
  `share()`. Built after the worker fork, so every worker has its own DB/Redis pools.
- Extension points are **registries** (`ComparatorRegistry`, `ActionHandlerRegistry`, `ChangeTypeRegistry`,
  `LoadStrategyRegistry`, the function table of `ExpressionEngine`). A plugin = a class implementing the interface + one
  `register()` line. Unknown codes throw `ConfigurationException`.
- Every registry item exposes a stable code (`name()`/`type()`) stored in process definitions, and a `label()` for the
  dictionary endpoints. Codes are `snake_case` and must never change once released — existing processes refer to them.

### HTTP layer

- **One controller class per endpoint**, `#[Route(uri: '/api/…/{id}', method: 'GET')]`, extending
  `Wps\Controllers\BaseController` and implementing `handle(): Response`. Controllers are thin: parse input, call a
  service, return `$this->ok(...)`.
- Responses always use the envelope from `Wps\Http\JsonResponder`:
  `{ "success": true, "data": …, "error": null, "meta"? }` / `{ "success": false, "data": null, "error": { code, message, details? } }`.
- Errors are thrown as `Wps\Http\ApiException` (`badRequest`, `unauthorized`, `forbidden`, `notFound`, `conflict`,
  `validation`) — `BaseController` renders them. Error `code` values are `UPPER_SNAKE_CASE` and documented in `back/API.md`.
- Validation errors are a list of `{ field, message }` where `field` is a path into the request
  (`definition.actions[2].value`). Messages are in English; the UI maps known codes to translated texts.
- Access rules live in one place, `Wps\Auth\AccessPolicy::RULES`, not in controllers.

### Execution errors

- Inside actions throw `RecoverableException` (temporary: will be retried with backoff) or `NonRecoverableException`
  (permanent: the execution fails at once). Configuration mistakes found at run time throw `ConfigurationException`.
- `ActionHandlerInterface::validate()` must catch at save time everything that can be checked statically, so bad
  definitions never reach the engine.
- Never swallow exceptions silently: log with context (`execution_id`, `process_id`, `step`, `error`).

### Logging and data

- Use the PSR-3 logger from the container (`Container::get()->logger()`), message in English, data in the context array.
- SQL lives in `Persistence/*Repository`; timestamps are stored in UTC (`TIMESTAMP` columns).
- Database changes only through a new numbered file in `back/migrations/` — never edit an applied migration.
- The SwooleApp framework is not patched. Fix framework behaviour by extension in `back/src` (see `WpsRoutesCollectionBuilder`).

## Frontend (Vue / TypeScript)

- Vue 3 `<script setup lang="ts">`, Composition API, Pinia stores, Element Plus components; `@/` alias for `src/`.
- All API calls go through `src/api/*` (`apiGet`, `apiGetPage`, `apiPost`, …) which unwrap the envelope and refresh tokens.
- Types for API data in `src/types/index.ts`. Unknown action types are kept as `UnknownAction` and saved unchanged.
- **No hard-coded UI text.** Every string is a key in `src/locales/ru.json` **and** `src/locales/en.json`:
  - in components `const { t } = useI18n()` and `t('section.key', { param })`; outside components `t` from `@/i18n`;
  - nested `camelCase` keys grouped by screen/section (`processEdit.*`, `modify.help.*`);
  - `@ { } | $` are special in messages: a literal `@` is written `{'@'}`; plurals with `|`
    (Russian has three forms: `{n} запись | {n} записи | {n} записей`);
  - form rules (`FormRules`) with messages are wrapped in `computed`, translated strings are not cached in constants;
  - dictionary items from the API are translated by value: `dict.<kind>.<value>` (`utils/format.ts → localizeDict`).
- `npm run i18n:check` (part of `npm run build`) fails on: missing keys in any locale, keys used in code but absent in
  `ru.json`, and **Cyrillic characters in `.vue`/`.ts` outside comments** (the only exception is `src/i18n/locales.ts`).
- Comments in the frontend code are mostly Russian — follow the file you are editing.

## Documentation

- Behaviour visible to users or operators changes → update `doc/en` **and** `doc/ru` (same file names),
  `back/API.md` for the API, and the screenshots listed in `doc/_meta/screenshots_manifest.md` if a screen changed.
  See [Contributing](contributing.md).

<!-- footer-nav -->
---

← Previous: [Project structure](project-structure.md) | [Contents](../README.md) | [Testing](testing.md) Next →
<!-- /footer-nav -->
