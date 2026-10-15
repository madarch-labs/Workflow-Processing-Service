# Quick start

Start WPS with the mock CRM, sign in, create a process and watch it run on a test event, in about 10 minutes.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/02-getting-started/quick-start.md)
<!-- /lang-switch -->

## 1. Choose the CRM: mock or real

The backend takes the CRM connection from `./.env`:

| Variable | Mock CRM (default in `docker-compose.yml`) | Meaning |
|---|---|---|
| `CRM_API_BASE_URL` | `http://mock:9502` | CRM REST API base URL; `/api/v8` is appended unless the URL already ends with `/api/vN` |
| `CRM_API_STATIC_KEY` | `mySecretKey` | Static part of the `X-Auth-Key` header |
| `MOCK_SERVICE_URL` | `http://mock:9502` | Source of the CRM modules dictionary; an **empty** value means "load it from the CRM API" |

> **Warning**
> The `./.env` in the repository may point to a real SuiteCRM 8, for example `CRM_API_BASE_URL=http://suitecrm8/api/v9` with
> an empty `MOCK_SERVICE_URL`. If you have not started SuiteCRM (see [CRM integration](../04-operations/crm-integration.md)),
> switch to the mock first. Set these lines in `./.env`:
>
> ```dotenv
> CRM_API_BASE_URL=http://mock:9502
> CRM_API_STATIC_KEY=mySecretKey
> MOCK_SERVICE_URL=http://mock:9502
> ```
>
> Or delete the three lines: then the defaults from `docker-compose.yml` (the mock) apply.

Check the host ports in `./.env` as well (`WPS_UI_PORT`, `WPS_API_PORT`, …), see [Requirements](requirements.md#ports).

## 2. Start the stack

```bash
docker compose up -d --build
```

The first build takes a few minutes. The backend applies the database migrations on start and creates the default
administrator. Check that it is healthy:

```bash
curl http://localhost:9501/api/health
```

```json
{"success":true,"data":{"status":"ok","checks":{"postgres":{"status":"ok","latency_ms":1},"redis_data":{"status":"ok","latency_ms":1},"redis_stream":{"status":"ok","latency_ms":0}},"stream":{"stream":"crm_events","group":"wps_workers","length":0,"lag":0,"pending":0,"consumers":2},"time":"…"},"error":null}
```

`status` must be `ok`. If it is `degraded`, see [Troubleshooting](../04-operations/troubleshooting.md).

| What | Address |
|---|---|
| Web UI | `http://localhost:<WPS_UI_PORT>` (3000 by default, 3080 with the repository `.env`) |
| API | `http://localhost:9501/api/...` |
| Metrics | `http://localhost:9501/metrics` |
| Mock CRM and test events | `http://localhost:9502/test-event?module=Deals` |

## 3. Sign in

Open the UI and sign in as `admin@wps.local` with password `admin`. You can switch the interface language in the top-right
corner, or open the page with `?lang=en`.

![Sign-in page](../../images/screenshots/en/01-login.png)
*The "Sign in" page of Workflow Processing Service.*

> **Warning**
> Change the administrator password after the first sign-in (**Users** → edit `admin@wps.local`).
> See [Users and roles](../03-user-guide/users-and-roles.md).

## 4. Create a process

You can build processes in the UI (step-by-step tutorial: [Your first process](first-process.md)). For a quick check,
create one through the API instead. Get a token first:

```bash
TOKEN=$(curl -s -X POST http://localhost:9501/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@wps.local","password":"admin"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["access_token"])')
```

Then create a process: for every saved deal with status `Active`, write a note to its `description` and save it to the CRM:

```bash
curl -s -X POST http://localhost:9501/api/processes \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
  "name": "Quick start: active deal",
  "object_type": "Deals",
  "is_child": false,
  "is_active": true,
  "definition": {
    "conditions": [{ "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }],
    "preload": [],
    "actions": [{ "type": "modify", "target": "object.description", "change_type": "set_value", "value": "Checked by WPS", "save_to_crm": true }]
  }
}'
```

The response contains the new process with its `id` and `"version": 1`. The process now appears in the list:

![Process list](../../images/screenshots/en/02-processes-list.png)
*The "Processes" page. The screenshot shows the demo processes from the [Recipes](../03-user-guide/recipes.md).*

## 5. Send a test event

The mock generates a deal, stores it in the mock CRM and publishes the event to the stream:

```bash
curl -g "http://localhost:9502/test-event?module=Deals&set[status]=Active"
```

> **Note**
> The `-g` flag is required: without it `curl` treats `[` `]` as a URL pattern.

The answer shows the published message and the generated object, for example `"id":"96544"`.

## 6. See the execution

Open the process in the UI and scroll to the **Execution log**. A new row with trigger "CRM event" and status "Completed"
appears within a second. Click it to see each step, the request sent to the CRM and the result.

You can also check it through the API, and look at the record in the mock CRM:

```bash
curl -s "http://localhost:9501/api/processes/<id>/executions" -H "Authorization: Bearer $TOKEN"
curl -s http://localhost:9502/mock/objects/Deals/<object id>   # "description":"Checked by WPS"
```

A deal with another status, for example `set[status]=Closed`, does not start the process. The event is still read and
acknowledged, but no execution is created.

## Stop and clean up

```bash
docker compose down        # stop, keep the data
docker compose down -v     # stop and delete the volumes (database, Redis)
```

## Next steps

- [Your first process](first-process.md): build a process with preload in the UI.
- [User guide](../03-user-guide/login-and-ui.md): every screen and setting.
- [Recipes](../03-user-guide/recipes.md): ready-made scenarios.

<!-- footer-nav -->
---

← Previous: [Requirements](requirements.md) | [Contents](../README.md) | [Your first process](first-process.md) Next →
<!-- /footer-nav -->
