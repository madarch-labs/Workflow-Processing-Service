# Screenshots manifest

All screenshots: 1440×900, light theme, scale 100 %, headless Google Chrome via puppeteer-core
(`doc/_meta/tools/shoot.mjs`). Every screen exists in `doc/images/screenshots/en/` (`?lang=en`) and
`doc/images/screenshots/ru/` (`?lang=ru`) with the same file name. No real secrets: the demo stack uses the default
`admin@wps.local` / `admin` and mock data only.

## How to retake

1. Start the isolated demo stack on the mock CRM (does not touch the main stack):
   ```bash
   WPS_UI_PORT=3081 WPS_API_PORT=9511 WPS_MOCK_PORT=9512 WPS_PG_PORT=55433 WPS_REDIS_STREAM_PORT=6389 WPS_REDIS_DATA_PORT=6390 \
   CRM_API_BASE_URL=http://mock:9502 CRM_API_STATIC_KEY=mySecretKey MOCK_SERVICE_URL=http://mock:9502 \
   docker compose -p wpsdoc up -d --build
   ```
2. On an **empty** database, create the demo processes in this order (the ids 1–6 are used by the script;
   `06-lead-run-once.json` references child process id 4):
   `01-deal-vip`, `02-invoice-discount`, `03-contact-account-name`, `04-child-follow-up-task`, `05-payment-failure-demo`,
   `06-lead-run-once` from `doc/_meta/demo_processes/` with `POST /api/processes`.
3. Create users `editor@example.com` (editor) and `viewer@example.com` (viewer).
4. Send events (`curl -g`): `Deals&set[status]=Active` ×2, `Deals&set[status]=Closed`, `Invoices&set[status]=Active`,
   `Contacts&set[status]=New`, `Leads&set[status]=New`, `Leads&set[status]=Inactive`, `Payments` — all to
   `http://localhost:9512/test-event?module=...`.
5. Block a run once: `POST /api/processes/6/run` with the `id` of the lead from step 4 (`{"object":{"id":"<lead id>","module":"Leads","status":"New","email":"lead@example.com"}}`).
6. `cd <dir with puppeteer-core installed> && node shoot.mjs <outDir>` (Node ≥ 18, `npm i puppeteer-core@23.11.1`,
   `PUPPETEER_SKIP_DOWNLOAD=1`). Optional env: `UI` (default `http://localhost:3081`), `API` (`http://localhost:9511`).
7. Copy `<outDir>/{en,ru}` to `doc/images/screenshots/` (files > 200 KB were quantized to 256 colours).
8. Stop: `docker compose -p wpsdoc down -v`.

## List

| File | URL | Data needed | What it shows | Used in |
|---|---|---|---|---|
| `01-login.png` | `/login` | — | Sign-in page with language switcher | 03 login-and-ui, 02 quick-start |
| `02-processes-list.png` | `/processes` | demo processes | Process list, filters, row actions, run-once badge `1×` | 03 processes, 02 quick-start |
| `03-process-detail.png` | `/processes/1` | process 1 | Process card: properties, conditions, preload, actions | 03 processes |
| `04-process-edit-header.png` | `/processes/1/edit` | process 1 | Edit form: configuration block, conditions, preload, variables sidebar | 03 processes, 02 first-process |
| `05-process-edit-conditions.png` | `/processes/3/edit` (scrolled to conditions) | process 3 | Two condition rows, one on a preloaded variable `v1.status` | 03 triggers-and-conditions |
| `06-process-edit-preload.png` | `/processes/3/edit` (scrolled to actions) | process 3 | Preload row `v1` from `object.account_id`, modify `set_from_variable` | 03 actions |
| `07-process-edit-modify.png` | `/processes/2/edit` (actions, modify expanded) | process 2 | Two `calculate` rows and a "Save to CRM" row | 03 change-types-and-expressions, 03 actions |
| `08-process-edit-start-process.png` | `/processes/6/edit` (actions) | process 6 | Conditions with `contains`, "Start next process" row pointing to #4 | 03 subprocesses |
| `09-process-create-validation.png` | `/processes/create` + Save on an empty form | — | Validation errors banner and highlighted fields | 03 processes |
| `10-process-executions.png` | `/processes/1` (scrolled to the log) | executions of process 1 | Execution log with filters | 03 executions-and-audit |
| `11-execution-detail-success.png` | `/executions/<latest of process 1>` | | Completed execution, steps preload + modify | 03 executions-and-audit |
| `12-execution-detail-failed.png` | `/executions/<execution of process 5>` | | Failed execution: error banner, 4 attempts (3 retries), skipped step | 03 executions-and-audit, 04 troubleshooting |
| `13-execution-detail-parent-child.png` | `/executions/<execution of process 6>` | | `start_process` step and the "Child executions" table | 03 subprocesses |
| `14-manual-run-dialog.png` | `/processes/1` → "Run" | | Manual run dialog with JSON editor and "Skip condition check (force)" | 03 manual-run-and-reset |
| `15-process-run-once-marks.png` | `/processes/6` → "Run-once records" tab | blocked run from step 5 | Run-once marks table with blocked counter | 03 manual-run-and-reset |
| `16-users.png` | `/users` | users from step 3 | User list with roles | 03 users-and-roles |
| `17-language-switcher.png` | `/processes` + open the language menu | | Language dropdown in the header | 03 login-and-ui |
| `18-process-detail-child.png` | `/processes/4` | process 4 | Child process card: "Started from" parent link, 4 actions | 03 subprocesses |

## Placeholders

None: all planned screenshots were captured.
