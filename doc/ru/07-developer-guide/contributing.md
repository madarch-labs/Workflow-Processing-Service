# Участие в разработке

Как предложить изменение: ветки, коммиты, проверки перед pull request и чек-лист ревью.

<!-- lang-switch -->
[🇬🇧 English](../../en/07-developer-guide/contributing.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Что лежит в git

| Отслеживается | Не отслеживается (`.gitignore`) |
|---|---|
| `back/`, `front/`, `mock/`, `docker-compose.yml`, `.env`, `README.md`, `doc/` | `md/` (ТЗ и заметки по реализации), `.tehnical/` (стенды SuiteCRM 7/8 и плагин), `front/node_modules`, `front/dist` |

> **Внимание**
> `./.env` отслеживается git. В нём порты хоста и подключение к CRM (`CRM_API_BASE_URL`, `CRM_API_STATIC_KEY`).
> Не коммитьте туда настоящий ключ CRM или локальный адрес CRM — держите такие изменения локально
> (`git update-index --skip-worktree .env` или передавайте переменные в командной строке).

## Ветки

- Ветка по умолчанию — интеграционная; она всегда должна собираться и проходить тесты.
- Одна тема — одна ветка от ветки по умолчанию: `feature/<short-name>`, `fix/<short-name>`, `docs/<short-name>`.
- Перед открытием pull request сделайте rebase на ветку по умолчанию; конфликты в `front/src/locales/*.json` решайте,
  сохраняя ключи **обеих** сторон (наборы ключей должны оставаться одинаковыми).

## Коммиты

- Короткая тема в повелительном наклонении на английском, строчными буквами, без точки в конце — как в существующей
  истории (`add multi-language`). До ~72 символов; подробности — в теле коммита.
- Один коммит — одно логическое изменение. Не смешивайте переформатирование нетронутого кода с изменением поведения.
- Никогда не коммитьте сгенерированные и локальные файлы: `front/dist`, `node_modules`, `vendor`, `*.tsbuildinfo`,
  скриншоты, снятые для себя, локальные изменения `.env`.

## Перед открытием pull request

Запустите те же проверки, что и ревьюер.

**Бэкенд** (нужна мок-CRM — см. [Тестирование](testing.md)):

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

**Фронтенд:**

```bash
cd front
npm ci
npm run build        # i18n:check + vue-tsc --noEmit + vite build
```

**Весь стенд:** `docker compose up -d --build`, затем пройдите изменённые экраны на обоих языках
(`?lang=en`, `?lang=ru`) и опубликуйте тестовое событие:

```bash
curl -g 'http://localhost:9502/test-event?module=Deals&set[status]=Active'
```

## Что обновить вместе с кодом

| Что вы изменили | Что обновить ещё |
|---|---|
| Эндпоинт API, поле запроса/ответа, код ошибки | `back/API.md`, [справочник API](../06-api-reference/endpoints.md) (en + ru), проверку в `IntegrationTest.php` |
| Схему БД | **Новый** нумерованный файл `back/migrations/00N_<name>.sql`. Применённые файлы записываются в `schema_migrations` и больше не выполняются — не редактируйте их |
| Переменную окружения | `back/.env.example`, `docker-compose.yml`, если нужно значение по умолчанию, [Конфигурацию](../04-operations/configuration.md) |
| Действие, оператор, тип изменения, стратегию загрузки, функцию выражений | Реестр (`Container`), перевод `dict.*`, [руководство пользователя](../03-user-guide/actions.md), проверку расширения в `IntegrationTest.php` |
| Бизнес-поведение задачи из `md/tasks_list/` | Юнит-тесты, блок `[имя]` в `IntegrationTest.php`, кейс `.tehnical/suiteCRM8/processes/cases/<id>-*.json` до `PASSED`, описание `md/tasks_list/cases/<id>-suitecrm8.md`, закрытие `md/tasks_list/tools/complete_task.py` (см. [Тестирование](testing.md#кейсы-suitecrm-8)) |
| Справочник (`/api/dictionaries/*`) | `back/API.md`, [справочник API](../06-api-reference/endpoints.md), перевод `dict.<вид>.<value>` во `front/src/locales/*.json`, если справочник показывается в UI |
| Текст UI | Оба файла: `front/src/locales/ru.json` и `en.json` |
| Экран | Страницы [руководства пользователя](../03-user-guide/login-and-ui.md) и скриншоты из `doc/_meta/screenshots_manifest.md` |
| Поведение, описанное в документации | Страницу в `doc/en/` **и** тот же файл в `doc/ru/` |

Правила документации: деревья `en/` и `ru/` содержат одинаковые файлы и заголовки; термины соответствуют
[глоссарию](../01-overview/glossary.md); подписи UI цитируются дословно из `locales/*.json`. Строка переключения языка
и навигация внизу страницы генерируются — после добавления или переименования страницы обновите список страниц в
`doc/_meta/tools/nav.py` и запустите из корня репозитория:

```bash
python3 doc/_meta/tools/nav.py          # language switch + footer navigation
python3 doc/_meta/tools/check_links.py  # en/ru trees identical, no broken links or anchors
```

## Pull request

- Заголовок в стиле коммитов; в описании — **что** изменено, **зачем** и **как проверено**
  (вывод тестов, скриншоты изменённых экранов на обоих языках).
- Дайте ссылку на требование или задачу, которую реализует изменение.
- Явно отмечайте несовместимые изменения: переименованные коды в определениях процессов (`operator`, `change_type`,
  `type` действия, `strategy`), изменённые поля API, новые обязательные переменные окружения.

## Чек-лист ревью

- [ ] `IntegrationTest.php` проходит (`Failed: 0`); для нового поведения есть проверка
- [ ] Юнит-тесты проходят (`OK`); кейс SuiteCRM 8 задачи — `PASSED`
- [ ] `npm run build` проходит (переводы, типы, сборка)
- [ ] Нет захардкоженных текстов UI и кириллицы в `.vue`/`.ts` вне комментариев; новые ключи есть в обеих локалях
- [ ] PHP: `declare(strict_types=1)`, `final`-классы, зависимости через конструктор, связывание в `Wps\Kernel\Container`
- [ ] Контроллеры тонкие, по одному на эндпоинт; ответы в конверте; ошибки через `ApiException` с кодом в `UPPER_SNAKE_CASE`
- [ ] Доступ к новым эндпоинтам задан в `Wps\Auth\AccessPolicy::RULES`
- [ ] Ошибки действий используют нужный класс: `RecoverableException` (повтор) или `NonRecoverableException` / `ConfigurationException`
- [ ] Статические ошибки в определении процесса ловит `validate()` при сохранении, с путём поля `definition.…`
- [ ] Коды, хранящиеся в определениях процессов, не изменены, или описан путь миграции
- [ ] Изменения схемы — только в новом файле миграции
- [ ] Нет секретов, токенов и персональных данных в коде, логах, фикстурах и скриншотах
- [ ] Обновлены `back/API.md`, `doc/en` и `doc/ru`; `check_links.py` сообщает OK

См. также [Соглашения о коде](coding-conventions.md) и [Тестирование](testing.md).

<!-- footer-nav -->
---

← Назад: [Новый язык интерфейса](extending/new-language.md) | [Оглавление](../README.md) | [Частые вопросы](../08-faq.md) Далее →
<!-- /footer-nav -->
