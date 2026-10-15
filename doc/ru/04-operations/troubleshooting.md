# Решение проблем

Типичные проблемы: как их распознать и как исправить.

<!-- lang-switch -->
[🇬🇧 English](../../en/04-operations/troubleshooting.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## С чего начать

1. **Страница выполнения** в UI: баннер с ошибкой, шаги с данными `request`/`result` и контекст (событие, переменные,
   трассировка условий). См. [Выполнения и аудит](../03-user-guide/executions-and-audit.md).
2. **`GET /api/health`** — доступны ли PostgreSQL и оба Redis, есть ли у стрима lag или pending-сообщения.
3. **Логи backend** — `docker compose logs -f backend` (строки JSON; с `LOG_LEVEL=debug` виден каждый запрос к CRM).
4. **DLQ** — `docker compose exec -T redis-stream redis-cli XRANGE crm_events:dlq - + COUNT 10`.

![Выполнение с ошибкой: баннер, четыре попытки шага предзагрузки, следующий шаг пропущен](../../images/screenshots/ru/12-execution-detail-failed.png)
*Ошибка вызова CRM: CRM ответила HTTP 500, шаг повторён 3 раза (4 попытки), следующий шаг пропущен.*

## Симптом → причина → решение

| # | Симптом | Вероятная причина | Решение |
|---|---|---|---|
| 1 | Событие отправлено, а выполнения нет | Для этого `module` нет **активного процесса верхнего уровня**; процесс ведомый; тип объекта отличается регистром (`deals` ≠ `Deals`) | Проверьте фильтры списка процессов «Тип объекта», «Тип процесса», «Активность». Ведомые процессы от событий не запускаются |
| 2 | Выполнения нет, процесс настроен верно | Условия не выполнены. Неудачная проверка выполнения не создаёт (так задумано) | Сделайте [ручной запуск](../03-user-guide/manual-run-and-reset.md) с тем же объектом: в диалоге видна трассировка условий с `actual` / `expected` |
| 3 | Выполнения нет, условия выполнены | Однократный запуск: у записи уже есть отметка. В логе — `Process skipped: run_once` | Карточка процесса → «Однократные запуски» → снимите отметку или отключите «Однократный запуск для записи» |
| 4 | Ничего не обрабатывается, `stream.lag` растёт | Backend не читает стрим: он остановлен или подключён к другому Redis / стриму | `docker compose ps`, `docker compose logs backend`; проверьте `REDIS_STREAM_HOST`, `REDIS_STREAM_NAME`, группу в `XINFO GROUPS crm_events` |
| 5 | События пропадают, растёт `wps_events_invalid_total` | Некорректное событие → DLQ: `Event has no "module"/"object_type"`, `Event object has no "id"`, `Field "data" is not a JSON object` | Прочитайте `reason` в `crm_events:dlq` и исправьте издателя (см. [формат события](crm-integration.md#формат-события-crm--wps)) |
| 6 | Шаг падает с `CRM GET … returned HTTP 500` примерно через 7 с, `attempts: 4` | CRM отвечает ошибкой; WPS повторил запрос 3 раза | Исправьте CRM, затем запустите процесс вручную. Если CRM нужно больше времени, настройте `ACTION_RETRY_*` |
| 7 | Шаг падает с `CRM … : connection failed` / `request timeout` | Неверный `CRM_API_BASE_URL`, контейнер CRM не в сети WPS или отвечает дольше `CRM_API_TIMEOUT` | Проверьте доступность из контейнера backend: `docker compose exec backend curl -s -o /dev/null -w '%{http_code}' <базовый адрес CRM>/modules`; подключите CRM к сети WPS; увеличьте `CRM_API_TIMEOUT` |
| 8 | Шаг падает с `CRM … returned HTTP 401` | Неверный `CRM_API_STATIC_KEY` или часы серверов расходятся больше чем на час | Используйте один ключ в WPS и в CRM (`MOCK_CRM_STATIC_KEY` / `WPS_API_STATIC_KEY`); синхронизируйте часы (NTP, UTC) |
| 9 | Шаг падает с `CRM PUT …/{id} returned HTTP 404` | `save_object`/`save_to_crm` для записи, которой нет в CRM (событие составлено вручную или мок потерял данные) | Сначала загрузите запись предзагрузкой или убедитесь, что `id` события — настоящий id CRM |
| 10 | Запросы уходят на `/api/v8/...`, а CRM обслуживает `/api/v9` | `CRM_API_BASE_URL` не заканчивается на `/api/vN`, и WPS дописывает `/api/v8` | Задайте `CRM_API_BASE_URL=http://<crm>/api/v9` |
| 11 | После переключения на SuiteCRM в списке «Тип объекта» модули мока | `MOCK_SERVICE_URL` всё ещё задан или справочник закэширован (300 с) | Задайте `MOCK_SERVICE_URL=` (пусто), перезапустите backend, нажмите обновление рядом с «Тип объекта» или выполните `POST /api/dictionaries/modules/refresh` |
| 12 | Переменная предзагрузки равна `null`, следующие шаги ничего не пишут или `save_object` пропущен (`skipped`) | Запись не найдена (`404` или пустой поиск) или значение для поиска пустое — это не ошибка | Посмотрите результат шага предзагрузки (`found: false`, `lookup_value`). Исправьте путь (`object.account_id`) или `by_field` |
| 13 | Сохранение пропущено с `No changed fields to save` | `save_object` отправляет только поля, изменённые в этом выполнении | Измените поле перед сохранением или укажите `"mode": "full"` в JSON действия |
| 14 | Сохранение падает: `Cannot save "x": CRM module is unknown` | У новой переменной (созданной через `modify`) нет модуля | Укажите «Модуль» в строке сохранения (`module` в действии) |
| 15 | Сохранение процесса: `422 VALIDATION_ERROR`, `Variable "v1" is not defined at this point` | Переменная используется раньше своей строки предзагрузки, или такой строки нет | Поменяйте строки местами; в ведомых процессах переменные родителя разрешены |
| 16 | Сохранение процесса: `CYCLE_DETECTED` | `start_process` образует цикл А → Б → А | Уберите одну из ссылок; цепочка указана в `details.path` ошибки |
| 17 | Сохранение: `Process N "…" is not a child process` | `start_process` запускает только ведомые процессы | Отметьте целевой процесс как «Ведомый процесс» |
| 18 | Удаление процесса: `409 PROCESS_IN_USE` | Его запускает другой процесс | Уберите ссылку `start_process` в процессах, перечисленных в `details` |
| 19 | Процесс запускается снова и снова | Он сохраняет запись, которая его запустила → новый `after_save` → новое событие | Включите «Однократный запуск для записи» или добавьте условие на поле-маркер, которое процесс выставляет |
| 20 | Выполнение зависло в `running` | Упал воркер. `RecoveryJob` продолжит выполнение через `RECOVERY_STALE_SECONDS` (5 мин) или пометит его ошибочным («Execution context expired or lost») | Дождитесь восстановления; ищите в логе `Resuming interrupted execution` |
| 21 | Скрипты получают `401 TOKEN_EXPIRED` | Access-токен живёт 15 минут | Вызовите `POST /api/auth/refresh` или войдите заново |
| 22 | `403 FORBIDDEN` | Роль не разрешает операцию (например, viewer запускает процесс, editor удаляет) | См. [таблицу ролей](security.md#роли) |
| 23 | `/api/health` отвечает `503` | Недоступен PostgreSQL, `redis-data` или `redis-stream`; причина — в `checks.<имя>.error` | Запустите упавший сервис; проверьте переменные хоста, порта и пароля |
| 24 | Backend при старте завершается с `[migrate] database is not reachable` | PostgreSQL не готов за `DB_WAIT_SECONDS` (60) или неверные `DB_POSTGRES_*` | Проверьте контейнер postgres и учётные данные; увеличьте `DB_WAIT_SECONDS` |
| 25 | Изменения в `back/` не применяются | Код вшит в образ | `docker compose up -d --build backend` |
| 26 | `+` в `calculate` склеивает текст (`100 USD5`) или возвращает один операнд (`1000`, если второе поле пустое) | `+` выполняет конкатенацию, если хотя бы один операнд не число (пустое или отсутствующее поле — пустая строка) | Убедитесь, что оба поля — числа; `- * / %` считают пустое значение равным `0`, а на тексте падают с `Operator "*" requires numbers, got "…"` |
| 27 | Шаг падает с `Division by zero in expression` | Поле-делитель равно `0` или пустое | Защитите делитель: `max(object.qty, 1)` или условие |
| 28 | Интерфейс не на том языке | Язык выбирается по `?lang=`, затем по сохранённому выбору, затем по языку браузера | Переключите язык в шапке или добавьте `?lang=ru` |

## Полезные команды

```bash
# отправить тестовое событие через мок
curl -sg 'http://localhost:9502/test-event?module=Deals&set[status]=Active'

# посмотреть объект в хранилище мок-CRM
curl -s http://localhost:9502/mock/objects/Accounts/<account_id>

# повторить запуск с тестовым объектом (токен editor или admin)
curl -s -X POST http://localhost:9501/api/processes/1/run \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"object":{"id":"<deal id>","module":"Deals","status":"Active","account_id":"<account_id>"}}'

# pending-сообщения и потребители
docker compose exec -T redis-stream redis-cli XPENDING crm_events wps_workers
docker compose exec -T redis-stream redis-cli XINFO CONSUMERS crm_events wps_workers
```

Идентификаторы в примерах — заглушки: возьмите `id` и `account_id` из `event` в ответе `test-event`.

> **Внимание**
> Если аккаунта нет в CRM, процесс 1 не падает: предзагрузка возвращает `null`, а шаг `modify` с
> **Сохранить в CRM** создаёт новый аккаунт (`POST`). См. [Действия](../03-user-guide/actions.md).

Если проблемы нет в списке, посмотрите точные правила повторов и восстановления в разделе [Надёжность](reliability.md) и
[FAQ](../08-faq.md).

<!-- footer-nav -->
---

← Назад: [Безопасность](security.md) | [Оглавление](../README.md) | [Обзор архитектуры](../05-architecture/overview.md) Далее →
<!-- /footer-nav -->
