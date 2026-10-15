# Frontend architecture

Structure of the Vue 3 single-page application: bootstrap, routing and access guards, state, the API client, localisation and delivery through nginx.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/05-architecture/frontend.md)
<!-- /lang-switch -->

## Overview

```mermaid
flowchart TB
    main["main.ts<br/>bootstrap"] --> app["App.vue<br/>el-config-provider, AppLayout"]
    main --> router["router/index.ts<br/>routes + beforeEach guard"]
    main --> i18n["i18n/<br/>vue-i18n, Element Plus locales"]
    router --> views["views/*.vue"]
    views --> components["components/*.vue"]
    views --> stores["stores/<br/>auth, processes (Pinia)"]
    components --> stores
    stores --> api["api/*.ts<br/>auth, processes, executions,<br/>dictionaries, users"]
    views --> api
    api --> client["api/client.ts<br/>Axios: Bearer, Accept-Language,<br/>refresh on 401, error envelope"]
    client -- "/api/*" --> backend["backend :9501<br/>through nginx or the Vite proxy"]
    router --> stores
```

`main.ts` installs Pinia, vue-i18n, the router and Element Plus, registers the Element Plus icons, sets the "session
expired" handler of the API client and loads the language **before** the first render (`setLocale(detectLocale())`),
so texts do not flicker. Views talk to the backend only through the modules in `src/api/`, which all use one Axios
instance. Shared types are in `src/types/index.ts`.

## Routing and access

| Path | View | Roles |
|---|---|---|
| `/login` | `LoginView.vue` | public |
| `/processes` | `ProcessesView.vue` | all |
| `/processes/create` | `ProcessEditView.vue` | admin, editor |
| `/processes/:id` | `ProcessDetailView.vue` | all |
| `/processes/:id/edit` | `ProcessEditView.vue` | admin, editor |
| `/executions/:id` | `ExecutionDetailView.vue` | all |
| `/users` | `UsersView.vue` | admin |

The global `beforeEach` guard initialises the auth store, sends unauthenticated users to `/login?redirect=…`, loads the
current user (`GET /api/auth/me`) when only a token is known and redirects to `/processes` when the role in
`meta.roles` does not match. Page titles come from `meta.titleKey` (a translation key) and are updated when the
language changes. The UI checks are a convenience only — the backend enforces the same rules in `AccessPolicy`.

## Views and components

```mermaid
flowchart LR
    layout["AppLayout<br/>header, menu"] --> lang1["LanguageSwitcher"]
    login["LoginView"] --> lang2["LanguageSwitcher"]
    list["ProcessesView<br/>list, filters"]
    detail["ProcessDetailView<br/>card, execution log,<br/>run-once marks, manual run"] --> je["JsonEditor"]
    detail --> jv1["JsonView"]
    edit["ProcessEditView<br/>editor form"] --> header["ProcessHeader"]
    edit --> cond["ConditionsPanel"]
    edit --> pre["PreloadPanel"]
    edit --> mod["ModifyPanel<br/>modify + save rows"]
    edit --> start["StartProcessPanel"]
    edit --> side["VariableSidebar"]
    edit --> jv2["JsonView"]
    cond --> path["PathInput"]
    pre --> path
    mod --> path
    exec["ExecutionDetailView<br/>steps, children, context,<br/>live polling"] --> jv3["JsonView"]
    users["UsersView<br/>CRUD dialog"]
```

The editor splits `definition.actions` into panels: `formFromProcess()` in `stores/processes.ts` distributes the
actions by type, and `definitionFromForm()` assembles them back: first the modify/save rows in their order, then one
`start_process` action per selected child process. Action types the editor does not know (for example `preload` inside
`actions`) are kept unchanged, and a condition tree (`logical_operator`) is kept as is. The editor writes only the fields
it shows, so optional keys set through the API (`modify.module`, `save_object.mode`, `preload.strategy`) are dropped when
the process is saved from the UI.
`VariableSidebar` shows the variables known at each point (the event object, preload variables, variables created
by `modify`) with their fields, using `utils/variables.ts`. `ExecutionDetailView` polls the execution while its status
is `running` or `retrying`. `ConditionsPanel`, `PreloadPanel` and `ModifyPanel` use the `PathInput` component to suggest
field paths.

## State

| Store | Content |
|---|---|
| `auth` | `accessToken`, `refreshToken` (persisted in `localStorage` as `wps_access_token`, `wps_refresh_token`), `user`, computed `role`, `isAdmin`, `canEdit`; `login()`, `refresh()`, `fetchMe()`, `logout()`, `hasRole()` |
| `processes` | Process list with pagination and filters, loading one process, `saveProcess()`; form helpers `emptyForm()`, `formFromProcess()`, `definitionFromForm()`, `payloadFromForm()` |

Other data (executions, dictionaries, users) is loaded by the views directly through `src/api/`.

## API client

`src/api/client.ts` creates an Axios instance with `baseURL: '/api'` and a 30 s timeout:

- the request interceptor adds `Authorization: Bearer <access token>` (except for `/auth/login` and `/auth/refresh`) and
  `Accept-Language` with the current UI language;
- on `401` the response interceptor calls `POST /api/auth/refresh` once — parallel requests wait for the same refresh
  promise — and repeats the original request; if refresh fails, the user is logged out and sent to `/login` with a
  `redirect` back;
- errors are normalised to `ApiRequestError { status, code, message, details }` from the backend envelope
  (`NETWORK_ERROR` when there is no response) and shown with `ElMessage` unless the request is marked `silent`.

## Localisation

| Part | Where |
|---|---|
| Messages | `src/locales/ru.json` (reference and fallback), `src/locales/en.json` |
| Setup, `setLocale()`, `detectLocale()` | `src/i18n/index.ts` |
| Supported languages | `src/i18n/locales.ts` (`SUPPORTED_LOCALES`, `LOCALE_META`) |
| Element Plus locales | `src/i18n/element-plus.ts`, applied by `el-config-provider` in `App.vue` |
| Check | `scripts/i18n-check.mjs` (`npm run i18n:check`, part of `npm run build`) |

The language is chosen from `?lang=` → `localStorage['wps_locale']` → browser languages → `ru`. Russian is bundled,
other languages are loaded as separate chunks. Labels of backend dictionaries (operators, change types, preload
sources) are translated in the UI by their `value` (`dict.*` keys); module names are shown as the CRM returns them.
How to add a language: [New language](../07-developer-guide/extending/new-language.md).

## Build and delivery

```mermaid
flowchart LR
    dev["npm run dev<br/>Vite :3000"] -- "proxy /api → VITE_API_TARGET<br/>default localhost:9501" --> be1["backend"]
    build["npm run build<br/>i18n check, vue-tsc, vite build"] --> dist["dist/"]
    dist --> nginx["nginx in the frontend image"]
    browser["Browser"] --> nginx
    nginx -- "/api/ → http://backend:9501" --> be2["backend"]
    nginx -- "/assets/ → cache 1 year" --> static["hashed static files"]
    nginx -- "everything else → index.html" --> spa["SPA fallback"]
```

In development Vite serves the app on port 3000 and proxies `/api` to the backend. In Docker the image builds `dist/`
and serves it with nginx (`front/nginx.conf`): `/api/` is proxied to `backend:9501` with a 60 s read timeout and a
10 MB body limit, `/assets/` files are cached for a year, and every other path falls back to `index.html` so that the
router can handle deep links.

<!-- footer-nav -->
---

← Previous: [Data model](data-model.md) | [Contents](../README.md) | [Architecture decisions](decisions.md) Next →
<!-- /footer-nav -->
