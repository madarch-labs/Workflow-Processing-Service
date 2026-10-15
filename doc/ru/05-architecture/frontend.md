# Архитектура frontend

Устройство одностраничного приложения на Vue 3: запуск, маршрутизация и проверка доступа, состояние, клиент API, локализация и доставка через nginx.

<!-- lang-switch -->
[🇬🇧 English](../../en/05-architecture/frontend.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Обзор

```mermaid
flowchart TB
    main["main.ts<br/>запуск"] --> app["App.vue<br/>el-config-provider, AppLayout"]
    main --> router["router/index.ts<br/>маршруты + guard beforeEach"]
    main --> i18n["i18n/<br/>vue-i18n, локали Element Plus"]
    router --> views["views/*.vue"]
    views --> components["components/*.vue"]
    views --> stores["stores/<br/>auth, processes (Pinia)"]
    components --> stores
    stores --> api["api/*.ts<br/>auth, processes, executions,<br/>dictionaries, users"]
    views --> api
    api --> client["api/client.ts<br/>Axios: Bearer, Accept-Language,<br/>refresh при 401, конверт ошибки"]
    client -- "/api/*" --> backend["backend :9501<br/>через nginx или прокси Vite"]
    router --> stores
```

`main.ts` подключает Pinia, vue-i18n, роутер и Element Plus, регистрирует иконки Element Plus, задаёт клиенту API
обработчик «сессия истекла» и загружает язык **до** первого рендера (`setLocale(detectLocale())`), чтобы тексты не
мигали. Представления обращаются к backend только через модули `src/api/`, которые используют один экземпляр Axios.
Общие типы лежат в `src/types/index.ts`.

## Маршрутизация и доступ

| Путь | Представление | Роли |
|---|---|---|
| `/login` | `LoginView.vue` | публичный |
| `/processes` | `ProcessesView.vue` | все |
| `/processes/create` | `ProcessEditView.vue` | admin, editor |
| `/processes/:id` | `ProcessDetailView.vue` | все |
| `/processes/:id/edit` | `ProcessEditView.vue` | admin, editor |
| `/executions/:id` | `ExecutionDetailView.vue` | все |
| `/users` | `UsersView.vue` | admin |

Глобальный guard `beforeEach` инициализирует хранилище авторизации, отправляет неавторизованных пользователей на
`/login?redirect=…`, загружает текущего пользователя (`GET /api/auth/me`), если известен только токен, и
перенаправляет на `/processes`, если роль не подходит под `meta.roles`. Заголовок страницы берётся из `meta.titleKey`
(ключ перевода) и обновляется при смене языка. Проверки в интерфейсе нужны только для удобства — те же правила backend
проверяет в `AccessPolicy`.

## Представления и компоненты

```mermaid
flowchart LR
    layout["AppLayout<br/>шапка, меню"] --> lang1["LanguageSwitcher"]
    login["LoginView"] --> lang2["LanguageSwitcher"]
    list["ProcessesView<br/>список, фильтры"]
    detail["ProcessDetailView<br/>карточка, журнал выполнений,<br/>отметки однократного запуска, ручной запуск"] --> je["JsonEditor"]
    detail --> jv1["JsonView"]
    edit["ProcessEditView<br/>форма редактора"] --> header["ProcessHeader"]
    edit --> cond["ConditionsPanel"]
    edit --> pre["PreloadPanel"]
    edit --> mod["ModifyPanel<br/>строки modify и сохранения"]
    edit --> start["StartProcessPanel"]
    edit --> side["VariableSidebar"]
    edit --> jv2["JsonView"]
    cond --> path["PathInput"]
    pre --> path
    mod --> path
    exec["ExecutionDetailView<br/>шаги, дочерние выполнения,<br/>контекст, опрос в реальном времени"] --> jv3["JsonView"]
    users["UsersView<br/>диалог CRUD"]
```

Редактор раскладывает `definition.actions` по панелям: `formFromProcess()` в `stores/processes.ts` распределяет
действия по типам, а `definitionFromForm()` собирает их обратно — сначала строки изменения и сохранения в их порядке,
затем по одному действию `start_process` на каждый выбранный ведомый процесс. Действия неизвестных редактору типов
(например, `preload` внутри `actions`) сохраняются без изменений, дерево условий (`logical_operator`) тоже остаётся как
есть. Редактор записывает только те поля, которые показывает, поэтому необязательные ключи, заданные через API
(`modify.module`, `save_object.mode`, `preload.strategy`), при сохранении процесса из интерфейса теряются.
`VariableSidebar` показывает переменные, доступные в каждой точке процесса (объект события, переменные предзагрузки,
переменные, созданные `modify`), с их полями — для этого используется `utils/variables.ts`. `ExecutionDetailView`
опрашивает выполнение, пока его статус `running` или `retrying`. Для подсказки путей полей `ConditionsPanel`,
`PreloadPanel` и `ModifyPanel` используют компонент `PathInput`.

## Состояние

| Хранилище | Содержимое |
|---|---|
| `auth` | `accessToken`, `refreshToken` (хранятся в `localStorage` как `wps_access_token`, `wps_refresh_token`), `user`, вычисляемые `role`, `isAdmin`, `canEdit`; `login()`, `refresh()`, `fetchMe()`, `logout()`, `hasRole()` |
| `processes` | Список процессов с пагинацией и фильтрами, загрузка одного процесса, `saveProcess()`; вспомогательные функции формы `emptyForm()`, `formFromProcess()`, `definitionFromForm()`, `payloadFromForm()` |

Остальные данные (выполнения, справочники, пользователи) представления загружают напрямую через `src/api/`.

## Клиент API

`src/api/client.ts` создаёт экземпляр Axios с `baseURL: '/api'` и таймаутом 30 с:

- перехватчик запроса добавляет `Authorization: Bearer <access token>` (кроме `/auth/login` и `/auth/refresh`) и
  `Accept-Language` с текущим языком интерфейса;
- при ответе `401` перехватчик один раз вызывает `POST /api/auth/refresh` — параллельные запросы ждут того же
  обновления — и повторяет исходный запрос; если обновить токен не удалось, пользователь выходит из системы и попадает
  на `/login` с `redirect` обратно;
- ошибки приводятся к `ApiRequestError { status, code, message, details }` из конверта backend (`NETWORK_ERROR`, если
  ответа нет) и показываются через `ElMessage`, если запрос не помечен как `silent`.

## Локализация

| Часть | Где |
|---|---|
| Сообщения | `src/locales/ru.json` (эталон и запасной язык), `src/locales/en.json` |
| Настройка, `setLocale()`, `detectLocale()` | `src/i18n/index.ts` |
| Поддерживаемые языки | `src/i18n/locales.ts` (`SUPPORTED_LOCALES`, `LOCALE_META`) |
| Локали Element Plus | `src/i18n/element-plus.ts`, применяются через `el-config-provider` в `App.vue` |
| Проверка | `scripts/i18n-check.mjs` (`npm run i18n:check`, входит в `npm run build`) |

Язык выбирается так: `?lang=` → `localStorage['wps_locale']` → языки браузера → `ru`. Русский входит в основной бандл,
остальные языки загружаются отдельными чанками. Подписи справочников backend (операторы, типы изменений, источники
предзагрузки) интерфейс переводит по их `value` (ключи `dict.*`); названия модулей показываются так, как их отдаёт CRM.
Как добавить язык: [Новый язык](../07-developer-guide/extending/new-language.md).

## Сборка и доставка

```mermaid
flowchart LR
    dev["npm run dev<br/>Vite :3000"] -- "прокси /api → VITE_API_TARGET<br/>по умолчанию localhost:9501" --> be1["backend"]
    build["npm run build<br/>проверка i18n, vue-tsc, vite build"] --> dist["dist/"]
    dist --> nginx["nginx в образе frontend"]
    browser["Браузер"] --> nginx
    nginx -- "/api/ → http://backend:9501" --> be2["backend"]
    nginx -- "/assets/ → кэш на 1 год" --> static["статика с хешами в именах"]
    nginx -- "всё остальное → index.html" --> spa["SPA fallback"]
```

При разработке Vite отдаёт приложение на порту 3000 и проксирует `/api` на backend. В Docker образ собирает `dist/` и
отдаёт его через nginx (`front/nginx.conf`): `/api/` проксируется на `backend:9501` с таймаутом чтения 60 с и лимитом
тела 10 МБ, файлы `/assets/` кэшируются на год, а любой другой путь отдаёт `index.html`, чтобы глубокие ссылки
обрабатывал роутер.

<!-- footer-nav -->
---

← Назад: [Модель данных](data-model.md) | [Оглавление](../README.md) | [Архитектурные решения](decisions.md) Далее →
<!-- /footer-nav -->
