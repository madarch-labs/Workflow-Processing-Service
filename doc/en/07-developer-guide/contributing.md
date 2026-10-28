# Contributing

How to propose a change: branches, commits, the checks to run before a pull request, and the review checklist.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/07-developer-guide/contributing.md)
<!-- /lang-switch -->

## Repository layout in git

| Tracked | Not tracked (`.gitignore`) |
|---|---|
| `back/`, `front/`, `mock/`, `docker-compose.yml`, `.env`, `README.md`, `doc/` | `md/` (specification and implementation notes), `.tehnical/` (SuiteCRM 7/8 stands and the plugin), `front/node_modules`, `front/dist` |

> **Warning**
> `./.env` is tracked. It holds host ports and the CRM connection (`CRM_API_BASE_URL`, `CRM_API_STATIC_KEY`).
> Do not commit a real CRM key or a local CRM address there — keep such changes local
> (`git update-index --skip-worktree .env`, or pass the variables on the command line).

## Branches

- The default branch is the integration branch; keep it buildable and green.
- One topic per branch, created from the default branch: `feature/<short-name>`, `fix/<short-name>`, `docs/<short-name>`.
- Rebase on the default branch before opening a pull request; resolve conflicts in `front/src/locales/*.json` by keeping
  **both** sides' keys (the key sets must stay identical).

## Commits

- Short imperative subject in English, lower case, no trailing period — like the existing history
  (`add multi-language`). Up to ~72 characters; details in the body.
- One logical change per commit. Do not mix formatting of untouched code with behaviour changes.
- Never commit generated or local files: `front/dist`, `node_modules`, `vendor`, `*.tsbuildinfo`, screenshots taken for
  yourself, local `.env` changes.

## Before you open a pull request

Run the same checks the reviewer will run.

**Backend** (needs the mock CRM — see [Testing](testing.md)):

```bash
# unit tests
docker build -q -f back/Dockerfile --target test -t wps-backend-test . && docker run --rm wps-backend-test --testsuite unit
# integration tests on the mock CRM (export in the same shell)
export CRM_API_BASE_URL=http://mock:9502 CRM_API_STATIC_KEY=mySecretKey MOCK_SERVICE_URL=http://mock:9502 \
       TELEGRAM_API_BASE=http://mock:9502/mock/telegram
docker compose up -d --build backend mock && docker compose exec -T backend php tests/IntegrationTest.php
# … Passed: 156, Failed: 0, Skipped: 0   (more if you added checks)
# SuiteCRM 8 cases (backend back on SuiteCRM 8)
unset CRM_API_BASE_URL CRM_API_STATIC_KEY MOCK_SERVICE_URL TELEGRAM_API_BASE; docker compose up -d backend
for f in .tehnical/suiteCRM8/processes/cases/*.json; do php .tehnical/suiteCRM8/scripts/run_case.php "$f" || echo "FAILED: $f"; done
```

**Frontend:**

```bash
cd front
npm ci
npm run build        # i18n:check + vue-tsc --noEmit + vite build
```

**Whole stack:** `docker compose up -d --build`, then go through the changed screens in both languages
(`?lang=en`, `?lang=ru`) and publish a test event:

```bash
curl -g 'http://localhost:9502/test-event?module=Deals&set[status]=Active'
```

## What to update together with the code

| You changed | Also update |
|---|---|
| An API endpoint, request/response field, error code | `back/API.md`, [API reference](../06-api-reference/endpoints.md) (en + ru), a check in `IntegrationTest.php` |
| Database schema | A **new** numbered file `back/migrations/00N_<name>.sql`. Applied files are recorded in `schema_migrations` and never run again — do not edit them |
| An environment variable | `back/.env.example`, `docker-compose.yml` if it needs a default, [Configuration](../04-operations/configuration.md) |
| An action, operator, change type, load strategy, expression function | The registry (`Container`), a `dict.*` translation, [user guide](../03-user-guide/actions.md), an extension check in `IntegrationTest.php` |
| Business behaviour of a task from `md/tasks_list/` | Unit tests, a `[name]` block in `IntegrationTest.php`, a case `.tehnical/suiteCRM8/processes/cases/<id>-*.json` until `PASSED`, the description `md/tasks_list/cases/<id>-suitecrm8.md`, closing with `md/tasks_list/tools/complete_task.py` (see [Testing](testing.md#suitecrm-8-cases)) |
| A dictionary (`/api/dictionaries/*`) | `back/API.md`, the [API reference](../06-api-reference/endpoints.md), the `dict.<kind>.<value>` translation in `front/src/locales/*.json` if the UI shows it |
| A UI text | Both `front/src/locales/ru.json` and `en.json` |
| A screen | [User guide](../03-user-guide/login-and-ui.md) pages and the screenshots listed in `doc/_meta/screenshots_manifest.md` |
| Behaviour described in the docs | The page in `doc/en/` **and** the same file in `doc/ru/` |

Documentation rules: the `en/` and `ru/` trees have the same files and headings; terms follow the
[glossary](../01-overview/glossary.md); UI labels are quoted verbatim from `locales/*.json`. The language switch line and
the footer navigation are generated — after adding or renaming a page, update the page list in `doc/_meta/tools/nav.py`
and run, from the repository root:

```bash
python3 doc/_meta/tools/nav.py          # language switch + footer navigation
python3 doc/_meta/tools/check_links.py  # en/ru trees identical, no broken links or anchors
```

## Pull request

- Title in the commit style; the description says **what** changed, **why**, and **how it was checked**
  (test output, screenshots of changed screens in both languages).
- Link the requirement or issue it implements.
- Mark breaking changes explicitly: renamed codes in process definitions (`operator`, `change_type`, action `type`,
  `strategy`), changed API fields, new required environment variables.

## Review checklist

- [ ] `IntegrationTest.php` passes (`Failed: 0`); new behaviour has a check
- [ ] Unit tests pass (`OK`); the SuiteCRM 8 case of the task is `PASSED`
- [ ] `npm run build` passes (translations, types, build)
- [ ] No hard-coded UI text, no Cyrillic in `.vue`/`.ts` outside comments; new keys in both locales
- [ ] PHP: `declare(strict_types=1)`, `final` classes, dependencies through the constructor, wiring in `Wps\Kernel\Container`
- [ ] Controllers are thin, one per endpoint; responses use the envelope; errors via `ApiException` with an `UPPER_SNAKE_CASE` code
- [ ] Access to new endpoints is defined in `Wps\Auth\AccessPolicy::RULES`
- [ ] Action errors use the right class: `RecoverableException` (retry) or `NonRecoverableException` / `ConfigurationException`
- [ ] Static mistakes in a process definition are caught by `validate()` on save, with a `definition.…` field path
- [ ] Codes stored in process definitions are unchanged, or a migration path is described
- [ ] Schema changes only in a new migration file
- [ ] No secrets, tokens or personal data in code, logs, fixtures or screenshots
- [ ] `back/API.md` and `doc/en` + `doc/ru` updated; `check_links.py` reports OK

See also [Coding conventions](coding-conventions.md) and [Testing](testing.md).

<!-- footer-nav -->
---

← Previous: [New UI language](extending/new-language.md) | [Contents](../README.md) | [FAQ](../08-faq.md) Next →
<!-- /footer-nav -->
