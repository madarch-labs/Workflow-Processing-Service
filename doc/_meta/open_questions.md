# Open questions

Discrepancies between the code, the requirements (`md/doc/*`) and the documentation, found while writing `doc/`.
The code was treated as the source of truth; each item says how the docs describe the current behaviour.

## Overview, getting started, FAQ

1. The repository `.env` points the backend to a real SuiteCRM 8 (`CRM_API_BASE_URL=http://suitecrm8/api/v9`, empty
   `MOCK_SERVICE_URL`), while `README.md` says "the mock is used by default". A fresh clone without SuiteCRM therefore does
   not work with the mock until `.env` is edited. Documented as a warning in quick-start; consider shipping `.env` with the
   mock values (or `.env.example` + `.gitignore` for `.env`).
2. Server-side validation messages are English only (`ProcessValidatorService`, `DefinitionScope`), while the backend
   dictionary labels (`label()` of comparators, change types, actions, preload sources) are Russian only; the UI translates
   dictionaries by `value` but shows server validation details as is. Documented in the FAQ.
3. There is no retention/cleanup for `executions` / `execution_actions`; the audit grows without limit. Documented in the FAQ.

## User guide — login, processes, conditions, actions, expressions

1. **Tab name mismatch (EN UI).** `processHeader.runOnceHint` in `front/src/locales/en.json` refers to the “Run-once marks” tab, but the tab label is `processDetail.runs.tab` = “Run-once records”. RU is consistent («Однократные запуски»). Docs use the real tab name.
2. **Clone suffix is always Russian.** `ProcessService::clone()` appends ` (копия)` regardless of the UI language (`back/src/Services/ProcessService.php`). The English docs state this as is.
3. **Modify on a not-found preload variable creates a new record.** When a preload finds nothing (`v1 = null`), a later `modify` on `v1.<field>` turns `v1` into `{<field>: …}` without `id` (`ExecutionContext::set`), and `save_object` / `save_to_crm` then POSTs a **new** record in the preload module. Verified on the demo stack (variable became `{"rating":"VIP"}`). Documented as a warning; confirm whether this is intended or the save should be skipped.
4. **Missing field is false even for negative operators.** `object.x not_equals "y"` is false when `x` is absent (customer answer #10, `ConditionEngineService::evaluateLeaf`). Verified and documented; may surprise users.
5. **Server validation messages are English only.** The UI shows them in the error banner untranslated in the RU interface (`ProcessValidatorService`, handlers' `validate()`). Docs quote them verbatim.
6. **Options available only through the API.** `save_object.mode` (`changed`/`full`), `preload.strategy`, preload rows inside `actions[]`, nested AND/OR conditions — no UI controls. Documented as API-only.
7. **Extra function arguments are ignored silently.** `upper('a', 'b')` does not fail (PHP closures accept extra args); only too few arguments raise `Wrong number of arguments…`. Not documented as a feature.

## User guide — subprocesses, executions, manual run, users, recipes

1. **Untranslated column label.** The execution log table on the process page has the column `label="Retry"` hard-coded
   in `front/src/views/ProcessDetailView.vue` (the `retry_count` column), so it shows "Retry" in the Russian UI too.
   The docs quote it as is. Suggest a locale key (e.g. `processDetail.executions.retry`).
2. **`modify` on an empty preloaded variable creates a CRM record.** If a preload finds nothing, the variable is `null`;
   a following `modify` of `v2.field` creates a new object, and `save_to_crm: true` sends `POST` → a new, almost empty
   record (verified on the mock: recipe 8 without the guard condition created `Contacts` 982766). `save_object` for a
   `null` variable is `skipped`, but `modify` + `save_to_crm` is not. The docs warn about it and recommend a guard
   condition (`v2.id` not_equals ""). Is this intended (TS: "a variable that is not in the preload is created as a new
   object"), or should `modify` skip variables that were preloaded as `null`?
3. **Manual run ignores `is_active` and `is_child`.** `POST /api/processes/{id}/run` runs inactive processes and child
   processes (a child then has no parent variables). Documented as the current behaviour; confirm it is intended.
4. **Whitespace `id` in a manual run.** `RunProcessController` rejects only an empty `id`; `"id": " "` passes, and then
   `run_once` is not applied (the engine trims the id). The UI handles it with "Run once not applied: the object has no id".
   Minor.
5. **Side effects of verification on the `wpsdoc` stack.** While checking the run-once reset I ran process 9 (created
   by another fork) once with `force` (one extra `manual` execution; the mark was removed afterwards) and process 4
   once manually (one extra `manual` execution of the child). Processes 7, 8 (recipes 7, 8), 15 (run-once test) and
   16–19 (cycle / inactive child tests) were created by this fork; the mock CRM got contact `c-706`.

## Architecture

1. **UI editor drops optional definition keys.** `front/src/stores/processes.ts` `definitionFromForm()` writes only the
   fields shown in the form: `modify.module`, `modify.expression`, `save_object.mode`, `preload.strategy` set through the
   API are lost after saving the process in the UI; a `modify.value` that was a number becomes a string; `start_process`
   actions are moved after all modify/save rows, and `process_ids: [a, b]` becomes two `start_process` actions.
   Documented as behaviour in `05-architecture/frontend.md`. Is this intended?
2. **Backend dictionary labels are Russian only** (`label()` of comparators, change types, actions,
   `PreloadHandler::SOURCES`, `ConditionEngineService::VALUE_TYPES`). The UI translates them by `value`, but API clients
   always get Russian labels regardless of `Accept-Language` (the header is sent by the UI but not used by the backend).
3. **`execution_actions` for a failed step stores the step configuration as `request_data`**, not what was actually sent
   to the CRM (the request built by the handler is lost on exception). Documented as is.
4. Minor: in a headless check `document.title` on `/login?lang=en` was still Russian right after load
   (observed by the parent while debugging screenshots); not verified further.

## Developer guide — CRM adapter, UI screen, language, contributing

1. **No CRM adapter abstraction.** `Wps\Services\ExternalApiAdapterService` is `final`, has no interface, and its
   concrete type is used in `PreloadHandler`, `CrmObjectSaver`, `DictionaryService` and `LoadStrategyInterface::load()`.
   A different CRM API can only be connected through a bridge service or a CRM-side plugin that speaks the WPS contract,
   or by editing the core class. `04-operations/crm-integration.md` and `new-crm-adapter.md` document the bridge way. Consider a `CrmApiInterface` + `Container::crm()`
   binding if other CRMs are planned.
2. **`md/` and `.tehnical/` are in `.gitignore`.** The specification, the implementation notes and the SuiteCRM 7/8
   stands with the plugin (`.tehnical/suiteCRM7/plugin`) are not in the repository, while `README.md` and the docs refer
   to them. A fresh clone does not contain the SuiteCRM integration.
3. **`.env` is tracked** and currently contains a CRM static key (`CRM_API_STATIC_KEY`) and a SuiteCRM 8 address
   (see also F1 #1). Documented as a warning in `contributing.md`; consider tracking only an example file.
4. **`/api/health` returns 503 with a normal `data` body.** `api/client.ts` turns any non-2xx response without `error`
   into `HTTP_503`, so a UI page has to call the raw `http` instance with `validateStatus` (done in the `new-ui-screen.md`
   example). Minor.
5. **No CI configuration** in the repository (no workflow files); `contributing.md` lists the checks to run by hand.
6. **No frontend unit tests**; the only automated frontend checks are `i18n:check`, `vue-tsc` and `vite build`.
## API reference (curl verification)

1. **A JSON array body is not rejected as `400 BAD_REQUEST`.** Only invalid JSON or a JSON scalar gives 400; an empty body
   is treated as `{}`, and an array passes parsing and fails later with `422 VALIDATION_ERROR`. The docs describe it as is.
