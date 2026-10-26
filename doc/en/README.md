# WPS documentation

Table of contents of the Workflow Processing Service documentation.

🇬🇧 English | [🇷🇺 Русский](../ru/README.md)

Start with [Quick start](02-getting-started/quick-start.md) if you want to see WPS running in ten minutes, or pick the part for your role: **users** — sections 1–3, **operators** — section 4, **developers** — sections 5–7.

## 1. Overview

- [Overview](01-overview/index.md) — What Workflow Processing Service (WPS) is, what problems it solves and where to start reading.
- [Glossary](01-overview/glossary.md) — Terms used across the WPS documentation and UI, with their Russian equivalents and the names used in code.

## 2. Getting started

- [Requirements](02-getting-started/requirements.md) — What you need on your machine to run WPS, and which ports it uses.
- [Quick start](02-getting-started/quick-start.md) — Start WPS with the mock CRM, sign in, create a process and watch it run on a test event, in about 10 minutes.
- [Your first process](02-getting-started/first-process.md) — A step-by-step tutorial: build the process "Active deal → mark account as VIP" in the UI and test it with a mock event.

## 3. User guide

- [Sign-in and the interface](03-user-guide/login-and-ui.md) — How to sign in to the WPS web interface, find your way around it and switch the interface language.
- [Processes](03-user-guide/processes.md) — How to find, create, edit, clone and delete processes, and what every field of the process configuration means.
- [Trigger conditions](03-user-guide/triggers-and-conditions.md) — When WPS starts a process and how to write the conditions in **Block 1. Trigger conditions**.
- [Actions](03-user-guide/actions.md) — What a process does once its conditions are met: preload related objects, change fields, save to the CRM, find records and loop over them, send e-mails, call external APIs, branch, wait and start child processes.
- [Change types and expressions](03-user-guide/change-types-and-expressions.md) — How a **Modify object / variable** row computes the new value: a fixed value, a copy of another field, or a calculated expression.
- [Child processes](03-user-guide/subprocesses.md) — How to split a scenario into a parent and child processes, what a child receives and how it is started.
- [Secrets](03-user-guide/secrets.md) — How an administrator stores credentials of external systems in WPS and how a process refers to them without seeing the value.
- [Executions and audit](03-user-guide/executions-and-audit.md) — Where to see every run of a process, how to read an execution page and how to find the cause of an error.
- [Manual run and run-once reset](03-user-guide/manual-run-and-reset.md) — How to run a process with a test object without the CRM, and how to lift the "run once per record" restriction.
- [Users and roles](03-user-guide/users-and-roles.md) — Who can do what in WPS, how an administrator manages users and why to change the default password first.
- [Recipes](03-user-guide/recipes.md) — Eight ready-made end-to-end scenarios: what each process does, how to set it up, its JSON and how to test it with the mock CRM.

## 4. Operations

- [Deployment](04-operations/deployment.md) — How to deploy, update and scale WPS with Docker Compose, and what to change before going to production.
- [Configuration](04-operations/configuration.md) — Every environment variable and configuration key of WPS, with defaults and meaning.
- [CRM integration](04-operations/crm-integration.md) — How WPS talks to a CRM in both directions: events into the stream and calls to the CRM API; the mock, SuiteCRM 7 and SuiteCRM 8.
- [Monitoring](04-operations/monitoring.md) — Health check, Prometheus metrics, logs and the Redis commands that show the state of the event stream.
- [Reliability](04-operations/reliability.md) — How WPS avoids losing and duplicating work: delivery guarantees, idempotency, retries, recovery after crashes and run once per record.
- [Security](04-operations/security.md) — Authentication, roles and access rules of the API, secrets to change, and what is exposed without a token.
- [Troubleshooting](04-operations/troubleshooting.md) — Typical problems, how to recognise them and how to fix them.

## 5. Architecture

- [Architecture overview](05-architecture/overview.md) — The big picture of WPS: the systems around it, the containers it consists of and the technology stack.
- [Backend architecture](05-architecture/backend.md) — How the PHP/Swoole backend is organised: layers, components, the process model and the extension points.
- [Event flow](05-architecture/event-flow.md) — The path of one CRM event: from the save of a record to the Redis Stream, the execution of the matching processes, the audit and the acknowledgement.
- [Execution engine](05-architecture/execution-engine.md) — How `ExecutionEngineService` runs one process for one event: the execution context, statuses, retries, idempotency and recovery after a crash.
- [Data model](05-architecture/data-model.md) — PostgreSQL tables, the structure of a process definition and the Redis keys used by WPS.
- [Frontend architecture](05-architecture/frontend.md) — Structure of the Vue 3 single-page application: bootstrap, routing and access guards, state, the API client, localisation and delivery through nginx.
- [Architecture decisions](05-architecture/decisions.md) — The key design decisions of WPS in a short ADR format: context, decision, consequences. The reasons are taken from code comments, `md/doc/maiInfo.md` (customer answers, numbered) and the plans in `md/`.

## 6. API reference

- [API reference: basics](06-api-reference/index.md) — Base URL, authentication flow, response envelope, pagination, roles and error codes of the WPS REST API.
- [API reference: endpoints](06-api-reference/endpoints.md) — All 28 endpoints of the WPS API with roles, parameters, request and response examples.

## 7. Developer guide

- [Development environment](07-developer-guide/dev-environment.md) — How to run WPS locally while you change the code: rebuilding the backend, the frontend dev server, logs and debugging.
- [Project structure](07-developer-guide/project-structure.md) — An annotated map of the repository: where each part of WPS lives and what to change for a typical task.
- [Coding conventions](07-developer-guide/coding-conventions.md) — Rules the existing code follows. New code should look the same so it fits next to it.
- [Testing](07-developer-guide/testing.md) — How the backend integration tests and the frontend checks work, how to run them and how to add a new check.

### Extending WPS

- [New action type](07-developer-guide/extending/new-action-type.md) — How to add a new kind of step to process definitions, using a verified `log_message` action ("Write to log") as the example.
- [New block type](07-developer-guide/extending/new-block-type.md) — How to add a control-flow construct with nested action lists, using the built-in `condition` block as the example.
- [New comparison operator](07-developer-guide/extending/new-comparator.md) — How to add an operator for process conditions, using the built-in `starts_with` ("Starts with") operator as the example.
- [New change type](07-developer-guide/extending/new-change-type.md) — How to add a way of computing the new value in a `modify` action, using a teaching `append_note` change type ("Append a note").
- [New load strategy](07-developer-guide/extending/new-load-strategy.md) — How to add a way of loading a CRM object into a preload variable, using a verified `by_id_or_field` strategy as the example.
- [Expression functions](07-developer-guide/extending/expression-functions.md) — How to add a function to `calculate` expressions, using `repeat(s, n)` as the example.
- [New API endpoint](07-developer-guide/extending/new-api-endpoint.md) — How to add a REST endpoint, using a tutorial `GET /api/dictionaries/function-categories` as the example.
- [New CRM adapter](07-developer-guide/extending/new-crm-adapter.md) — How to connect WPS to a CRM other than the mock or SuiteCRM 7/8, using a verified bridge service as the example.
- [New notification channel](07-developer-guide/extending/new-notify-channel.md) — How to add a channel to the `notify` action ("Notification") — on a teaching example of a Discord webhook.
- [New UI screen](07-developer-guide/extending/new-ui-screen.md) — How to add a page to the web UI — view, route, menu item, store, API module and translations — using a verified "System status" screen as the example.
- [New UI language](07-developer-guide/extending/new-language.md) — How to add a language to the web UI, using a verified German (`de`) locale as the example.

- [Contributing](07-developer-guide/contributing.md) — How to propose a change: branches, commits, the checks to run before a pull request, and the review checklist.

## 8. FAQ

- [FAQ](08-faq.md) — Short answers to frequent questions about WPS, with links to the detailed pages.

---

← [All languages](../README.md)
