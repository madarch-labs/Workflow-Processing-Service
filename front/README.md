# WPS Frontend

SPA для Workflow Processing Service: настройка процессов (условия, предзагрузка, изменения, запуск ведомых процессов), ручной запуск, журнал и аудит выполнений, управление пользователями.

Стек: Vue 3 + TypeScript, Vite 5, Element Plus, Pinia, Vue Router, Axios.

## Требования

Node.js 18+ (рекомендуется 22). Если системная версия старше:

```bash
source ~/.nvm/nvm.sh && nvm use 22
```

## Установка

```bash
cd front
npm install
```

## Разработка

```bash
npm run dev
# → http://localhost:3000
# /api проксируется на http://localhost:9501
# другой адрес backend:
VITE_API_TARGET=http://192.168.1.10:9501 npm run dev
```

## Сборка и проверка типов

```bash
npm run typecheck   # vue-tsc --noEmit
npm run build       # проверка типов + сборка в dist/
npm run preview     # локальный просмотр собранной версии
npm run i18n:check  # проверка переводов (см. «Локализация»)
```

## Локализация

Интерфейс мультиязычный: `vue-i18n` (Composition API) + `@intlify/unplugin-vue-i18n` (сообщения прекомпилируются при сборке).
Языки по умолчанию — русский (`ru`, основной и fallback) и английский (`en`).

| Что | Где |
|---|---|
| Переводы | `src/locales/ru.json` (эталон), `src/locales/en.json` |
| Инициализация, `setLocale()`, определение языка | `src/i18n/index.ts` |
| Реестр языков | `src/i18n/locales.ts` |
| Локали Element Plus | `src/i18n/element-plus.ts` (подключаются через `el-config-provider` в `App.vue`) |
| Переключатель | `src/components/LanguageSwitcher.vue` (шапка и страница входа) |

**Выбор языка** (по приоритету): параметр `?lang=en` в URL → сохранённый выбор (`localStorage['wps_locale']`) →
язык браузера → `ru`. Язык загружается до первого рендера; `ru` входит в основной бандл, остальные — отдельными чанками.
Текущий язык также уходит на backend в заголовке `Accept-Language`.

**Добавить строку:** ключ в `ru.json` и `en.json` (вложенные `camelCase`-ключи по разделам), в компоненте —
`const { t } = useI18n()` и `t('section.key', { param })`; вне компонентов — `t` из `@/i18n`.
Схема ключей описана по `ru.json` (`src/i18n/types.d.ts`) — даёт автодополнение; несуществующий ключ ловит `npm run i18n:check` (входит в `npm run build`).
В сообщениях символы `@ { } | $` служебные: литерал `@` пишется как `{'@'}`. Плюралы — через `|`
(в русском три формы: `{n} запись | {n} записи | {n} записей`).
Правила форм (`FormRules`) с сообщениями оборачивать в `computed`, переведённые строки не кешировать в константах.

**Добавить язык:** `src/locales/<code>.json` с тем же набором ключей, код в `SUPPORTED_LOCALES` и `LOCALE_META`
(`src/i18n/locales.ts`), загрузчики в `messageLoaders` (`src/i18n/index.ts`) и в `src/i18n/element-plus.ts`.

`npm run i18n:check` проверяет совпадение ключей во всех языках, наличие используемых в коде ключей
и отсутствие кириллицы в `.vue/.ts` вне комментариев.

## Docker

Образ собирает статику и отдаёт её через nginx (порт 80). Запросы `/api/` проксируются на `http://backend:9501`
(имя сервиса backend в docker-compose), для остальных путей работает SPA fallback на `index.html`.

```bash
docker build -t wps-front ./front
docker run --rm -p 8080:80 --network <сеть-с-backend> wps-front
```

## Роли

| Роль | Возможности в UI |
|---|---|
| admin | всё, включая удаление процессов и управление пользователями |
| editor | создание, редактирование, клонирование и ручной запуск процессов |
| viewer | только просмотр процессов и журнала выполнения |
