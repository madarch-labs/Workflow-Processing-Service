# Готовые сценарии

Готовые сквозные сценарии: что делает каждый процесс, как его настроить, его JSON и как проверить его на моке CRM.

<!-- lang-switch -->
[🇬🇧 English](../../en/03-user-guide/recipes.md) | 🇷🇺 Русский
<!-- /lang-switch -->

Все сценарии проверены на встроенном моке CRM (см. [Быстрый старт](../02-getting-started/quick-start.md)): мок публикует
тестовые события в стрим и эмулирует API CRM, поэтому всё можно попробовать без настоящей CRM.

## Как пользоваться сценарием

- **Настройте процесс в интерфейсе**: **Создать процесс**, заполните поля, как описано, затем **Сохранить и активировать**.
- **Или создайте его через API** по JSON из сценария:

  ```bash
  TOKEN=$(curl -s -X POST http://localhost:9501/api/auth/login -H 'Content-Type: application/json' \
    -d '{"email":"admin@wps.local","password":"admin"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["access_token"])')

  curl -s -X POST http://localhost:9501/api/processes -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' -d @recipe.json
  ```

  JSON-файлы сценариев 1–6 лежат в репозитории: `doc/_meta/demo_processes/`.
- **Отправьте тестовое событие** в мок. Используйте `curl -g`, иначе curl воспримет квадратные скобки в `set[status]`
  как шаблон:

  ```bash
  curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=Active"
  ```

  Мок генерирует объект модуля, сохраняет его в моке CRM, подставляет значения из `set[...]` и публикует событие. В
  ответе есть сгенерированный `event` (запомните его `id` и `account_id`).
- **Проверьте результат** в карточке процесса → **Журнал выполнения** и откройте выполнение
  (см. [Выполнения и аудит](executions-and-audit.md)). Записи мока CRM можно прочитать запросом
  `curl -s http://localhost:9502/mock/objects/<Модуль>/<id>`.

> **Примечание**
> Процесс реагирует на событие в течение секунды, но список процессов для типа объекта кешируется на срок до
> `PROCESS_CACHE_TTL` (60 с). При сохранении процесса кеш сбрасывается, поэтому новый процесс подхватывается сразу.

| # | Сценарий | Тип объекта | Что показывает |
|---|---|---|---|
| 1 | [Активная сделка → контрагент VIP](#1-активная-сделка--контрагент-vip) | Deals | условие, предзагрузка по id, **Равен значению** + **Сохранить в CRM** |
| 2 | [Скидка 10 % на счёт](#2-скидка-10--на-счёт) | Invoices | **Расчёт**, функции `round`, `concat`, сохранение объекта события |
| 3 | [Контакт: название контрагента](#3-контакт-название-контрагента) | Contacts | условие на предзагруженную переменную, **Равен переменной** |
| 4 | [Ведомый процесс: задача на перезвон](#4-ведомый-процесс-задача-на-перезвон) | Leads (ведомый) | новая переменная сохраняется как новая запись CRM |
| 5 | [Синхронизация платежа: сбой CRM](#5-синхронизация-платежа-сбой-crm) | Payments | повторы и выполнение с ошибкой |
| 6 | [Новый лид → звонок, один раз на лид](#6-новый-лид--звонок-один-раз-на-лид) | Leads | **Включает**, **Запустить следующий процесс**, **Однократный запуск для записи** |
| 7 | [Заказ: контрагент по ИНН](#7-заказ-контрагент-по-инн) | Orders | предзагрузка по полю, отличному от id |
| 8 | [Обращение: заметка у контакта контрагента](#8-обращение-заметка-у-контакта-контрагента) | Tickets | цепочка предзагрузки **Из поля переменной**, защитное условие |
| 10 | [Категория сделки](#10-категория-сделки-ifs-if) | Deals | **Расчёт**, условные функции `ifs`, `if`, сравнения и `and` |
| 11 | [Лучший телефон для звонка](#11-лучший-телефон-для-звонка-coalesce) | Leads | **Расчёт**, `coalesce` — первое непустое значение |
| 12 | [Звонок за 3 дня до закрытия сделки в 10:00 по местному времени](#12-звонок-за-3-дня-до-закрытия-сделки-в-1000-по-местному-времени) | Deals | функции дат `add_days`, `datetime` с поясом, `format_date`, создание звонка |
| 13 | [Продление сделки на месяц](#13-продление-сделки-на-месяц-add_months-end_of_quarter) | Deals | `add_months` с обрезкой конца месяца, `end_of_quarter`, `is_weekend` |
| 14 | [Скидка из текста менеджера и НДС](#14-скидка-из-текста-менеджера-и-ндс-to_number-percent-number_format) | Deals | точная десятичная арифметика, `to_number`, `percent`, `round`, `floor`, `number_format` |
| 15 | [Звонок по лиду: текст шаблоном](#15-звонок-по-лиду-текст-шаблоном-template) | Leads | **Шаблон**: подстановки, фильтры `default`, `upper`, `truncate`, `format_date`, `{{= }}` |
| 16 | [Задача на завтра в 18:00](#16-задача-на-завтра-в-1800-date_shift) | Leads | **Сдвиг даты**: база `today`, `+1 day`, `set_time` |
| 17 | [Задача за 3 дня до закрытия сделки, продление на месяц](#17-задача-за-3-дня-до-закрытия-сделки-продление-на-месяц-date_shift) | Deals | **Сдвиг даты** от поля, сдвиг в часах от `now`, обрезка конца месяца |
| 18 | [Сделка выиграна → задача](#18-сделка-выиграна--задача-changed_to) | Deals | **Изменилось на**, `object_before` в шаблоне |
| 19 | [Сумма изменилась: было → стало](#19-сумма-изменилась-было--стало-changed-с-cast-decimal) | Deals | **Изменилось** с `cast: decimal` (без фантомных изменений) |
| 20 | [Приветственное письмо новому лиду](#20-приветственное-письмо-новому-лиду-send_email) | Leads | **Отправить письмо**: адрес из поля, копия, HTML-шаблон, история в CRM |
| 21 | [Эскалация обращения ответственному и руководителю](#21-эскалация-обращения-ответственному-и-руководителю-send_email) | Cases | **Отправить письмо** через предзагрузку `Users`, `changed_to`, свой транспорт-секрет |
| 22 | [Лид в ERP и номер заказа обратно в CRM](#22-лид-в-erp-и-номер-заказа-обратно-в-crm-http_request) | Leads | **HTTP-запрос**: JSON-тело, JSONPath, секрет, журнал и `Idempotency-Key` |
| 23 | [Уведомление в Slack / мессенджер по вебхуку](#23-уведомление-в-slack--мессенджер-по-вебхуку-http_request) | Deals | **HTTP-запрос** на URL из секрета `webhook_url`, `on_error: continue` |
| 24 | [Встреча с участниками по новому лиду](#24-встреча-с-участниками-по-новому-лиду-link_records) | Leads | **Связать записи**: участник встречи с `accept_status`, связь встречи с лидом |
| 25 | [Лид отказался → отвязать от встречи](#25-лид-отказался--отвязать-от-встречи-unlink_records) | Leads | **Отвязать записи**, предзагрузка по `parent_id`, `changed_to` |
| 26 | [Разные действия для крупных и мелких сделок](#26-разные-действия-для-крупных-и-мелких-сделок) | Opportunities | **Условие (если / иначе)**: ветки с `cast`, группа OR, ветка по умолчанию |
| 27 | [Не обрабатывать лид без контактов](#27-не-обрабатывать-лид-без-контактов-stop_process) | Leads | **Завершить процесс** внутри ветки условия, код и сообщение |
| 28 | [Напомнить за 30 дней до окончания договора](#28-напомнить-за-30-дней-до-окончания-договора-delay) | AOS_Contracts | **Пауза** до даты поля со сдвигом и временем суток |
| 29 | [Перезвонить через 2 дня](#29-перезвонить-через-2-дня-delay) | Leads | **Пауза** на срок, звонок после ожидания |
| 30 | [Сводка по лиду: открытые задачи и звонки](#30-сводка-по-лиду-открытые-задачи-и-звонки-find_records) | Leads | **Найти записи**: фильтр с `not_in`, сортировка, лимит; записи по связи с локальным фильтром |
| 31 | [Сменился ответственный контрагента → передать его открытые сделки](#31-сменился-ответственный-контрагента--передать-его-открытые-сделки-foreach) | Accounts | **Цикл**: каждая открытая сделка, `save_object` элемента, `on_item_error: continue` |
| 32 | [Сделки нового клиента: следующий шаг и сумма](#32-сделки-нового-клиента-следующий-шаг-и-сумма-foreach--накопитель) | Accounts | **Цикл** с `where` и накопителем `set_variable` |
| 33 | [Сделка проиграна → отложить все открытые задачи](#33-сделка-проиграна--отложить-все-открытые-задачи-update_records) | Opportunities | **Изменить записи списка**: правила `set_value` и `calculate`, `only_changed` |
| 34 | [Сменился ответственный контрагента → всем его контактам](#34-сменился-ответственный-контрагента--всем-его-контактам-update_records) | Accounts | **Изменить записи списка** по связи; действие или цикл |
| 35 | [Сводка по контрагенту: сделки, задачи, последний звонок](#35-сводка-по-контрагенту-сделки-задачи-последний-звонок-aggregate) | Accounts | **Агрегат по записям**: `count`, `sum`, `avg` по связи, `count` задач через `meta.total`, `max` даты |
| 36 | [Сумма выигранных сделок контрагента](#36-сумма-выигранных-сделок-контрагента) | Accounts | Отбор по связи + накопитель в цикле; агрегат по модулю, если есть колонка контрагента |
| 37 | [Новый лид → колокольчик и Telegram](#37-новый-лид--колокольчик-и-telegram-notify) | Leads | **Уведомление**: `crm_alert` ответственному, Telegram, ссылка на запись |
| 38 | [Крупная сделка выиграна → Slack](#38-крупная-сделка-выиграна--slack-notify) | Opportunities | **Уведомление** в Slack и Teams, `fail_on: all` |
| 39 | [Распределение лидов по кругу](#39-распределение-лидов-по-кругу-assign) | Leads | **Назначить ответственного**: `round_robin` по группе безопасности, `exclude`, `fallback` |
| 40 | [Обращение — наименее загруженному](#40-обращение--наименее-загруженному-assign) | Cases | **Назначить ответственного**: `least_busy` по открытым обращениям |
| 41 | [Эскалация руководителю](#41-эскалация-руководителю-assign) | Cases | **Назначить ответственного**: `manager`, затем уведомление |
| 42 | [Лид с бесплатной почтой](#42-лид-с-бесплатной-почтой-ends_with) | Leads | **Начинается с**, **Заканчивается на** без учёта регистра, группа OR |
| 43 | [Сделка среднего сегмента](#43-сделка-среднего-сегмента-between) | Opportunities | **В диапазоне** в условии и в фильтре «Найти записи» (`gte` + `lte`) |
| 44 | [Сумма вне коридора лимитов контрагента](#44-сумма-вне-коридора-лимитов-контрагента-not_between) | Opportunities | **Вне диапазона** с границами из полей контрагента, ленивая предзагрузка |
| 45 | [Продление договора в ближайшие 30 дней](#45-продление-договора-в-ближайшие-30-дней-относительные-даты) | AOS_Contracts | Относительные даты `today`, `today+30d` в условии и фильтре |
| 46 | [Обращение, созданное за последние сутки, стало срочным](#46-обращение-созданное-за-последние-сутки-стало-срочным-now-24h) | Cases | `now-24h`, уведомление в колокольчик |
| 47 | [Выигранные сделки менеджера в этом квартале](#47-выигранные-сделки-менеджера-в-этом-квартале-between-с-относительными-датами) | Opportunities | `between` `[start_of_quarter, end_of_quarter]` в фильтре агрегата |
| 48 | [Московский лид](#48-московский-лид-без-учёта-регистра-и-пробелов) | Leads | `cast_options`: `case_sensitive: false`, `trim: true` для `equals` и `in` |
| 49 | [КП с метками vip или partner, но без spam](#49-кп-с-метками-vip-или-partner-но-без-spam-мультисписок) | AOS_Quotes | **Содержит любое из**, **Не содержит ни одного из** для мультисписка `^a^,^b^` |
| 50 | [Сделка сверх 80 % кредитного лимита контрагента](#50-сделка-сверх-80--кредитного-лимита-контрагента-value_type-expression) | Opportunities | Правая часть условия — **выражение** `v1.credit_limit_c * 0.8`, ленивая предзагрузка |
| 51 | [Сделка в коридоре ±10 % от плана](#51-сделка-в-коридоре-10--от-плана-between-с-выражениями) | Opportunities | **В диапазоне** с парой выражений |
| 52 | [Карточка контрагента: счётчик, история, рейтинг по отрасли](#52-карточка-контрагента-счётчик-история-рейтинг-по-отрасли-increment-append-clear-map) | Accounts | **Увеличить на**, **Дописать в конец**, **Очистить**, **По таблице соответствия** |
| 53 | [Приоритет обращения по рейтингу контрагента](#53-приоритет-обращения-по-рейтингу-контрагента-map) | Cases | **По таблице соответствия** с ключом `""` и `default` |

---

## 1. Активная сделка → контрагент VIP

**Цель.** Когда сделка сохраняется со статусом `Active`, выставить её контрагенту в CRM поле `rating` = `VIP`.

**Настройка в интерфейсе**

1. **Наименование процесса**: `Active deal → mark account as VIP`; **Тип процесса**: **Процесс верхнего уровня**;
   **Тип объекта (object.type)**: `Deals`.
2. **Блок 1. Условия запуска** → **Добавить строку**: `object.status` · **Равен** · **Значение** · `Active`.
3. **Предзагрузка** → **Добавить строку**: переменная `v1`, тип инициации **Из поля объекта**, `object.account_id`,
   модуль `Accounts`, поле поиска `id`.
4. **Изменить объект / переменную** → **Изменение**: `v1.rating`, **Равен значению**, `VIP`, отметьте **Сохранить в CRM**.

![Форма редактирования сценария 1](../../images/screenshots/ru/04-process-edit-header.png)

*Форма редактирования: конфигурация, одно условие, предзагрузка `v1` и панель переменных.*

**JSON**

```json
{
  "name": "Active deal → mark account as VIP",
  "object_type": "Deals", "is_child": false, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }
    ],
    "preload": [
      { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" }
    ],
    "actions": [
      { "type": "modify", "target": "v1.rating", "change_type": "set_value", "value": "VIP", "save_to_crm": true }
    ]
  }
}
```

**Проверка**

```bash
curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=Active"   # процесс запустится
curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=Closed"   # процесс не запустится
curl -s  "http://localhost:9502/mock/objects/Accounts/<account_id из события>"   # "rating": "VIP"
```

**Что вы увидите.** По выполнению на каждую сделку `Active` со статусом **Завершено** и двумя шагами: `preload`
(`found: true`, контрагент в `result_data.object`) и `modify` (`old_value: ""`, `new_value: "VIP"` и блок `save` с
`"method": "PUT"`, `"attributes": {"rating": "VIP"}`). Для сделки `Closed` выполнения нет: условия не выполнены.

![Завершённое выполнение сценария 1](../../images/screenshots/ru/11-execution-detail-success.png)

*Выполнение сценария 1: предзагрузка контрагента, затем изменение с сохранением в CRM.*

---

## 2. Скидка 10 % на счёт

**Цель.** Для счёта со статусом `Active` уменьшить `amount` на 10 %, записать пояснение в `description` и сохранить оба поля.

**Настройка в интерфейсе**

1. **Тип объекта**: `Invoices`; **Приоритет**: `10` (если на один тип объекта реагируют несколько процессов, процесс с
   меньшим значением выполняется раньше).
2. Условие: `object.status` · **Равен** · **Значение** · `Active`.
3. **Изменить объект / переменную**:
   - **Изменение**: `object.amount`, **Расчёт**, `round(object.amount * 0.9, 2)`;
   - **Изменение**: `object.description`, **Расчёт**, `concat('Discount applied, new amount: ', object.amount)`;
   - **Сохранить в CRM** (кнопка под таблицей): переменная `object`.

![Две строки «Расчёт» и строка «Сохранить в CRM»](../../images/screenshots/ru/07-process-edit-modify.png)

*Сценарий 2: второе выражение уже использует новое значение `object.amount`, вычисленное первым.*

**JSON**

```json
{
  "name": "Invoice discount 10%",
  "object_type": "Invoices", "is_child": false, "is_active": true, "priority": 10, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.amount", "change_type": "calculate", "value": "round(object.amount * 0.9, 2)", "save_to_crm": false },
      { "type": "modify", "target": "object.description", "change_type": "calculate", "value": "concat('Discount applied, new amount: ', object.amount)", "save_to_crm": false },
      { "type": "save_object", "target": "object" }
    ]
  }
}
```

**Проверка**

```bash
curl -sg "http://localhost:9502/test-event?module=Invoices&set[status]=Active&set[amount]=19500"
```

**Что вы увидите.** Три успешных шага. `modify` #0: `old_value "19500"` → `new_value 17550`; `modify` #1:
`"Discount applied, new amount: 17550"`; `save_object`: `"method": "PUT"`, в `attributes` только два изменённых поля.
Действия выполняются строго по порядку, а несохранённые изменения копятся и уходят в CRM одним запросом.

---

## 3. Контакт: название контрагента

**Цель.** Когда сохраняется контакт не в статусе `Closed`, а его контрагент активен, скопировать название контрагента в
поле `description` контакта.

**Настройка в интерфейсе**

1. **Тип объекта**: `Contacts`.
2. Условия:
   - `object.status` · **Не равен** · **Значение** · `Closed`;
   - `v1.status` · **Равен** · **Значение** · `Active`.
3. **Предзагрузка**: `v1`, **Из поля объекта**, `object.account_id`, `Accounts`, `id`.
4. **Изменение**: `object.description`, **Равен переменной**, `v1.name`, **Сохранить в CRM**.

![Условия на объект и на переменную v1](../../images/screenshots/ru/05-process-edit-conditions.png)

*Второе условие использует `v1`: панель переменных показывает, что `v1` загружается из CRM.*

**Как это работает.** Условие на переменную включает **ленивую предзагрузку**: WPS сначала проверяет строки по
`object.*` и только если они выполнены, запускает предзагрузку и проверяет остальные строки. Поэтому контакт со
статусом `Closed` не стоит ни одного запроса к CRM.

**JSON**

```json
{
  "name": "Contact: copy account name",
  "object_type": "Contacts", "is_child": false, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "not_equals", "value_type": "static", "value": "Closed" },
      { "field": "v1.status", "operator": "equals", "value_type": "static", "value": "Active" }
    ],
    "preload": [
      { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" }
    ],
    "actions": [
      { "type": "modify", "target": "object.description", "change_type": "set_from_variable", "value": "v1.name", "save_to_crm": true }
    ]
  }
}
```

**Проверка**

```bash
curl -sg "http://localhost:9502/test-event?module=Contacts&set[status]=New"
```

**Что вы увидите.** Шаги `preload` (контрагент) и `modify`: `new_value` — название контрагента,
`save.attributes = {"description": "<название контрагента>"}`. Во вкладке **Условия (conditions)** контекста обе строки
с `result: true`.

---

## 4. Ведомый процесс: задача на перезвон

**Цель.** Переиспользуемый ведомый процесс, который создаёт запись `Tasks` «Call back: <имя лида>», связанную с лидом.
Его запускает сценарий 6.

**Настройка в интерфейсе**

1. **Тип процесса**: **Ведомый процесс**; **Тип объекта**: `Leads`; условий нет.
2. **Изменить объект / переменную**:
   - `task.name`, **Расчёт**, `concat('Call back: ', object.name)`;
   - `task.status`, **Равен значению**, `Not Started`;
   - `task.parent_id`, **Расчёт**, `object.id`;
   - **Сохранить в CRM**: переменная `task`, модуль `Tasks`.

Переменная `task` не загружается предзагрузкой: первое изменение создаёт её как новый объект. Переменная без `id`
сохраняется запросом `POST`, то есть как новая запись CRM, поэтому в строке сохранения нужно указать модуль.

Название удобнее собрать [шаблоном](change-types-and-expressions.md#шаблон): `task.name`, **Шаблон**,
`Call back: {{ object.name }}` — см. [сценарий 15](#15-звонок-по-лиду-текст-шаблоном-template).

**JSON**

```json
{
  "name": "Create follow-up task",
  "object_type": "Leads", "is_child": true, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "task.name", "change_type": "calculate", "value": "concat('Call back: ', object.name)", "save_to_crm": false },
      { "type": "modify", "target": "task.status", "change_type": "set_value", "value": "Not Started", "save_to_crm": false },
      { "type": "modify", "target": "task.parent_id", "change_type": "calculate", "value": "object.id", "save_to_crm": false },
      { "type": "save_object", "target": "task", "module": "Tasks" }
    ]
  }
}
```

**Проверка.** Ведомый процесс не реагирует на события: проверьте его через сценарий 6 или запустите вручную из
карточки с объектом `{"id": "1", "module": "Leads", "name": "Test lead"}`.

**Что вы увидите.** Четыре шага; у `save_object` — `"method": "POST"`, `"module": "Tasks"` и `created_id` — id новой
задачи. Источник запуска — **Дочерний**, **Родительское выполнение** ведёт на выполнение сценария 6.

![Карточка ведомого процесса](../../images/screenshots/ru/18-process-detail-child.png)

*Сценарий 4: в поле **Запускается из** — родительский процесс.*

---

## 5. Синхронизация платежа: сбой CRM

**Цель.** Показать, что происходит при сбое CRM. Предзагрузка запрашивает контрагента `fail500-demo`, а мок отвечает
HTTP 500 на любой id, начинающийся с `fail500`.

**JSON**

```json
{
  "name": "Payment sync (CRM failure demo)",
  "object_type": "Payments", "is_child": false, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "type": "preload", "var": "v1", "source": "manual", "module": "Accounts", "by_field": "id", "value": "fail500-demo" },
      { "type": "modify", "target": "object.status", "change_type": "set_value", "value": "Synced", "save_to_crm": true }
    ]
  }
}
```

Строка предзагрузки может быть и действием (`"type": "preload"` в `actions`); тип инициации **Ручной ввод** берёт
введённое значение вместо поля.

**Проверка**

```bash
curl -sg "http://localhost:9502/test-event?module=Payments"
```

**Что вы увидите** (примерно через 7 секунд: задержки повторов 1, 2 и 4 с). Статус **Ошибка**,
**Кол-во повторов (retry)** 3, баннер `CRM GET http://mock:9502/api/v8/Accounts/fail500-demo returned HTTP 500`; у шага
`preload` **Попыток** 4 и `recoverable: true`; шаг `modify` **Пропущен**. Во время повторов выполнение находится в
статусе **Повтор**.

![Выполнение сценария 5 с ошибкой](../../images/screenshots/ru/12-execution-detail-failed.png)

*Сценарий 5: три повтора временной ошибки, затем выполнение завершается ошибкой и останавливается.*

---

## 6. Новый лид → звонок, один раз на лид

**Цель.** Для лида со статусом `New` и с e-mail запустить ведомый процесс из сценария 4 — но только один раз на лид,
сколько бы раз лид ни сохраняли.

**Настройка в интерфейсе**

1. **Тип объекта**: `Leads`; отметьте **Однократный запуск для записи**.
2. Условия: `object.status` · **Равен** · `New`; `object.email` · **Включает** · `@`.
3. **Запустить следующий процесс** → **Добавить процесс** → ведомый процесс из сценария 4.

![Сценарий 6: условия и строка «Запустить следующий процесс»](../../images/screenshots/ru/08-process-edit-start-process.png)

*Сценарий 6: два условия по «И» и ведомый процесс #4.*

**JSON** — `4` здесь id **вашего** ведомого процесса из сценария 4:

```json
{
  "name": "New lead → schedule a call (once per lead)",
  "object_type": "Leads", "is_child": false, "is_active": true, "priority": 0, "run_once": true,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "New" },
      { "field": "object.email", "operator": "contains", "value_type": "static", "value": "@" }
    ],
    "preload": [],
    "actions": [
      { "type": "start_process", "process_id": 4 }
    ]
  }
}
```

**Проверка**

```bash
curl -sg "http://localhost:9502/test-event?module=Leads&set[status]=New"        # запустятся родитель и ведомый
curl -sg "http://localhost:9502/test-event?module=Leads&set[status]=Inactive"   # условия не выполнены
```

Затем повторите запуск для того же лида вручную (**Выполнить (Run)**) с `id` лида из первого события:
`{"id": "<id лида>", "module": "Leads", "status": "New", "email": "lead@example.com"}`.

**Что вы увидите.** У выполнения родителя один шаг `start_process` с
`children: [{"process_id": 4, "status": "started"}]` и таблица **Дочерние выполнения**. Ручной повтор отвечает
**Процесс уже выполнялся для этой записи — новый запуск не создан**, а во вкладке **Однократные запуски** у лида
**Заблокировано** = 1. Чтобы разрешить новый запуск, нажмите **Сбросить** (см.
[Ручной запуск и сброс](manual-run-and-reset.md)).

![Выполнение родителя с ведомым процессом](../../images/screenshots/ru/13-execution-detail-parent-child.png)

*Сценарий 6: родитель завершился сразу, ведомый процесс выполнился параллельно и создал задачу.*

---

## 7. Заказ: контрагент по ИНН

**Цель.** В заказе есть ИНН покупателя `customer_inn`, а не id контрагента. Найти контрагента по полю `inn`, привязать к
нему заказ и записать в заказ название и телефон покупателя.

**Настройка в интерфейсе**

1. **Тип объекта**: `Orders`.
2. Условие: `object.customer_inn` · **Не равен** · **Значение** · пусто (оставьте значение пустым).
3. **Предзагрузка**: переменная `acc`, **Из поля объекта**, `object.customer_inn`, модуль `Accounts`, поле поиска `inn`.
   Любое поле поиска, кроме `id`, означает поиск по этому полю; если подходит несколько записей, берётся первая.
4. Изменения:
   - `object.account_id`, **Равен переменной**, `acc.id`;
   - `object.description`, **Расчёт**, `concat('Customer: ', acc.name, ', phone ', acc.phone)`;
   - **Сохранить в CRM**: `object`.

**JSON**

```json
{
  "name": "Order: find customer account by INN",
  "object_type": "Orders", "is_child": false, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.customer_inn", "operator": "not_equals", "value_type": "static", "value": "" }
    ],
    "preload": [
      { "var": "acc", "source": "object_field", "module": "Accounts", "by_field": "inn", "value": "object.customer_inn" }
    ],
    "actions": [
      { "type": "modify", "target": "object.account_id", "change_type": "set_from_variable", "value": "acc.id", "save_to_crm": false },
      { "type": "modify", "target": "object.description", "change_type": "calculate", "value": "concat('Customer: ', acc.name, ', phone ', acc.phone)", "save_to_crm": false },
      { "type": "save_object", "target": "object" }
    ]
  }
}
```

**Проверка.** У контрагентов мока есть поле `inn`. Возьмите id контрагента из любого сгенерированного события,
прочитайте его `inn` и отправьте заказ с этим ИНН:

```bash
curl -s  "http://localhost:9502/mock/objects/Accounts/<account_id>"            # → "inn": "7726436001"
curl -sg "http://localhost:9502/test-event?module=Orders&set[customer_inn]=7726436001"
```

**Что вы увидите.** У шага `preload` — `"strategy": "by_field"`, `"by_field": "inn"`, `lookup_value` равен ИНН, а в
`result_data.object` — найденный контрагент. После `save_object` у заказа в моке CRM `account_id` этого контрагента и
`description` вида `Customer: ПАО "Восток", phone +74954813492`. Заказ без `customer_inn` процесс не запускает:
условие на отсутствующее поле ложно.

---

## 8. Обращение: заметка у контакта контрагента

**Цель.** Когда сохраняется обращение со статусом `New`, найти контакт контрагента из обращения и записать название
обращения в поле `description` контакта — только если такой контакт есть.

**Настройка в интерфейсе**

1. **Тип объекта**: `Tickets`.
2. Условия:
   - `object.status` · **Равен** · `New`;
   - `v2.id` · **Не равен** · **Значение** · пусто — защитное условие, см. ниже.
3. **Предзагрузка**, две строки в таком порядке:
   - `v1`, **Из поля объекта**, `object.account_id`, `Accounts`, `id`;
   - `v2`, **Из поля переменной**, `v1.id`, `Contacts`, поле поиска `account_id`.
4. **Изменение**: `v2.description`, **Расчёт**, `concat('Open ticket: ', object.name, ' (', v1.name, ')')`,
   **Сохранить в CRM**.

Строка **Из поля переменной** может использовать только переменные, объявленные выше неё
(**Переменная «…» должна быть объявлена выше**).

> **Внимание**
> Без защитного условия `v2.id` процесс выполняется и тогда, когда у контрагента нет контакта. Тогда `v2` равна `null`,
> изменение создаёт новый объект `v2`, а **Сохранить в CRM** отправляет его запросом `POST` — в CRM появляется новая,
> почти пустая запись `Contacts`. Всегда защищайте изменения предзагруженной переменной, которая может оказаться пустой,
> условием на неё — или используйте отдельный шаг `save_object` (для пустой переменной он **Пропущен**).

**JSON**

```json
{
  "name": "Ticket: note on the account's contact",
  "object_type": "Tickets", "is_child": false, "is_active": true, "priority": 0, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "New" },
      { "field": "v2.id", "operator": "not_equals", "value_type": "static", "value": "" }
    ],
    "preload": [
      { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" },
      { "var": "v2", "source": "variable_field", "module": "Contacts", "by_field": "account_id", "value": "v1.id" }
    ],
    "actions": [
      { "type": "modify", "target": "v2.description", "change_type": "calculate", "value": "concat('Open ticket: ', object.name, ' (', v1.name, ')')", "save_to_crm": true }
    ]
  }
}
```

**Проверка.** Контакты мока не привязаны к контрагентам, поэтому создайте контакт через тестовый помощник и отправьте
обращение для этого контрагента:

```bash
curl -s -X PUT http://localhost:9502/mock/objects/Contacts/c-706 -H 'Content-Type: application/json' \
  -d '{"name":"Anna Smirnova","account_id":"706","email":"anna@example.com"}'
curl -sg "http://localhost:9502/test-event?module=Tickets&set[status]=New&set[account_id]=706"   # выполнится
curl -sg "http://localhost:9502/test-event?module=Tickets&set[status]=New"                      # контакта нет → не выполнится
```

(`706` должен быть существующим контрагентом мока: возьмите `account_id` из любого сгенерированного события.)

**Что вы увидите.** Для первого обращения — два шага `preload` (`v2` найден по `account_id`) и шаг `modify` с
`save.method: "PUT"` для `c-706`. Для второго обращения выполнение не создаётся; ручной запуск с тем же объектом
покажет строку `v2.id` с пустым значением в **Фактически** и **Результат** `false`.

## 9. Условия по типу, пустоте и спискам (через API)

Четыре коротких условия из задач 3.1, 3.2, 3.4. Редактор пока строит только операторы **Равен** / **Не равен** /
**Включает** / **Не включает**, поэтому такие строки задаются через API (`POST` / `PUT /api/processes`); редактор
сохраняет их без изменений. Подробности — [Условия запуска](triggers-and-conditions.md#операторы).

**Крупная сделка.** Сумма больше миллиона — сравнение как десятичных чисел (`"1500000"`, `"1 500 000,00"` и
`1500000` одинаковы):

```json
{ "field": "object.amount", "operator": "greater_than", "cast": "decimal", "value_type": "static", "value": "1 000 000" }
```

**Нет мобильного телефона.** CRM присылает незаполненное поле пустой строкой — это «пусто»:

```json
{ "field": "object.phone_mobile", "operator": "is_empty" }
```

**Компания не найдена в CRM.** Предзагрузка `company` (модуль `Accounts`, поле поиска `name`, значение
`object.account_name`); если компании нет, переменная пустая и данных у `company.id` нет:

```json
{ "field": "company.id", "operator": "is_null" }
```

**Статус из списка.** Вместо трёх групп OR — один оператор:

```json
{ "field": "object.status", "operator": "in", "value_type": "static", "value": ["New", "Assigned", "In Process"] }
```

**Как проверить.** Ручной запуск (`POST /api/processes/{id}/run`) показывает в трассе условий `actual_cast` /
`expected_cast`, для `is_null` — `found: false`, для `in` — список элементов `expected_cast`.

---

## 10. Категория сделки (`ifs`, `if`)

**Цель.** Для активной сделки записать категорию по сумме (A — больше миллиона, B — больше 100 000, иначе C) и пометку
о приоритете: «В работе», если вероятность не меньше 50 % и сделка не проиграна, иначе «Низкий приоритет».

**Настройка в интерфейсе**

1. **Тип объекта**: `Deals`.
2. Условие: `object.status` · **Равен** · **Значение** · `Active`.
3. **Изменить объект / переменную**:
   - **Изменение**: `object.next_step`, **Расчёт**,
     `concat('Категория ', ifs(object.amount > 1000000, 'A', object.amount > 100000, 'B', 'C'))`;
   - **Изменение**: `object.description`, **Расчёт**,
     `if(object.probability >= 50 and object.sales_stage != 'Closed Lost', 'В работе', 'Низкий приоритет')`;
   - **Сохранить в CRM**: переменная `object`.

`ifs` проверяет пары «условие, значение» по порядку и берёт первую истинную; последний нечётный аргумент `'C'` —
значение по умолчанию. Суммы сравниваются как числа, если CRM присылает их в строгом числовом формате (`"2000000"`,
`"250000.00"`); сумма вида `"1 000 000"` сравнивалась бы как строка — см.
[Правила сравнения](change-types-and-expressions.md#правила-сравнения).

**JSON**

```json
{
  "name": "Deal category",
  "object_type": "Deals", "is_child": false, "is_active": true, "priority": 20, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.next_step", "change_type": "calculate", "value": "concat('Категория ', ifs(object.amount > 1000000, 'A', object.amount > 100000, 'B', 'C'))", "save_to_crm": false },
      { "type": "modify", "target": "object.description", "change_type": "calculate", "value": "if(object.probability >= 50 and object.sales_stage != 'Closed Lost', 'В работе', 'Низкий приоритет')", "save_to_crm": false },
      { "type": "save_object", "target": "object" }
    ]
  }
}
```

**Проверка**

```bash
curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=Active&set[amount]=2000000&set[probability]=60&set[sales_stage]=Negotiation"
curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=Active&set[amount]=250000&set[probability]=10&set[sales_stage]=Prospecting"
```

**Что вы увидите.** Первая сделка: `next_step` — «Категория A», `description` — «В работе». Вторая: «Категория B» и
«Низкий приоритет». Процесс с `ifs(object.amount > 1)` или `if(1)` не сохранится: `Function "ifs" expects at least 2
arguments, got 1`. Тот же сценарий проверен на SuiteCRM 8 (модуль `Opportunities`).

---

## 11. Лучший телефон для звонка (`coalesce`)

**Цель.** У нового лида записать в `description` телефон, по которому звонить: мобильный, если он заполнен, иначе
рабочий, иначе другой; если телефонов нет — «нет телефона».

**Настройка в интерфейсе**

1. **Тип объекта**: `Leads`.
2. Условие: `object.status` · **Равен** · **Значение** · `New`.
3. **Изменить объект / переменную**:
   - **Изменение**: `object.description`, **Расчёт**,
     `concat('Звонить: ', coalesce(object.phone_mobile, object.phone_work, object.phone_other, 'нет телефона'))`;
   - **Сохранить в CRM**: переменная `object`.

`coalesce` возвращает первый аргумент, который не `null` и не пустая строка, — незаполненное поле CRM (пустая строка) и
отсутствующее поле пропускаются. Для одного запасного значения короче `ifempty(object.phone_mobile, object.phone_work)`.

**JSON**

```json
{
  "name": "Best phone to call",
  "object_type": "Leads", "is_child": false, "is_active": true, "priority": 20, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "New" }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.description", "change_type": "calculate", "value": "concat('Звонить: ', coalesce(object.phone_mobile, object.phone_work, object.phone_other, 'нет телефона'))", "save_to_crm": false },
      { "type": "save_object", "target": "object" }
    ]
  }
}
```

**Проверка**

```bash
curl -sg "http://localhost:9502/test-event?module=Leads&set[status]=New&set[phone_mobile]=&set[phone_work]=%2B7%20495%20000-00-21"
curl -sg "http://localhost:9502/test-event?module=Leads&set[status]=New&set[phone_mobile]=&set[phone_work]=&set[phone_other]="
```

**Что вы увидите.** Первый лид: «Звонить: +7 495 000-00-21» (мобильный пуст — взят рабочий). Второй: «Звонить: нет
телефона».

---

## 12. Звонок за 3 дня до закрытия сделки в 10:00 по местному времени

**Цель.** Для новой сделки создать звонок «Перезвонить до …» на 10:00 по местному времени (`WPS_TIMEZONE`) за три дня
до даты закрытия.

**Настройка в интерфейсе**

1. **Тип объекта**: `Deals`.
2. Условие: `object.status` · **Равен** · **Значение** · `New`.
3. **Изменить объект / переменную** (новая переменная `call`):
   - `call.name`, **Расчёт**, `concat('Перезвонить до ', format_date(add_days(object.date_closed, -3), 'd.m.Y'))`;
   - `call.date_start`, **Расчёт**, `datetime(concat(add_days(object.date_closed, -3), ' 10:00'), 'Y-m-d H:i', 'local')`;
   - `call.status` — **Равен значению** `Planned`, `call.parent_type` — `Deals`, `call.parent_id` — **Равен переменной** `object.id`;
   - **Сохранить в CRM**: переменная `call`, модуль `Calls`.

`add_days(object.date_closed, -3)` даёт дату `Y-m-d`, `concat` добавляет время, а `datetime(…, 'local')` читает его как
местное и переводит в UTC — в таком виде дату-время ждёт CRM. Вместо `'local'` можно указать пояс явно:
`'Europe/Moscow'`.

**JSON**

```json
{
  "name": "Call 3 days before close",
  "object_type": "Deals", "is_child": false, "is_active": true, "priority": 20, "run_once": true,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "New" }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "call.name", "change_type": "calculate", "value": "concat('Перезвонить до ', format_date(add_days(object.date_closed, -3), 'd.m.Y'))", "save_to_crm": false },
      { "type": "modify", "target": "call.date_start", "change_type": "calculate", "value": "datetime(concat(add_days(object.date_closed, -3), ' 10:00'), 'Y-m-d H:i', 'local')", "save_to_crm": false },
      { "type": "modify", "target": "call.status", "change_type": "set_value", "value": "Planned", "save_to_crm": false },
      { "type": "modify", "target": "call.parent_type", "change_type": "set_value", "value": "Deals", "save_to_crm": false },
      { "type": "modify", "target": "call.parent_id", "change_type": "set_from_variable", "value": "object.id", "save_to_crm": false },
      { "type": "save_object", "target": "call", "module": "Calls" }
    ]
  }
}
```

**Проверка**

```bash
curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=New&set[date_closed]=2026-01-31"
```

**Что вы увидите.** Звонок «Перезвонить до 28.01.2026» с `date_start` = `2026-01-28 10:00:00` при `WPS_TIMEZONE=UTC` или
`2026-01-28 07:00:00` (10:00 по Москве) при `WPS_TIMEZONE=Europe/Moscow`. На SuiteCRM 8 (модуль `Opportunities`, пояс
`'Europe/Moscow'`) сценарий проверен кейсом 5.2.2.

---

## 13. Продление сделки на месяц (`add_months`, `end_of_quarter`)

**Цель.** Перенести дату закрытия сделки на месяц вперёд и записать в `next_step` конец квартала новой даты и признак
выходного дня.

**Настройка в интерфейсе**

1. **Тип объекта**: `Deals`.
2. Условие: `object.status` · **Равен** · **Значение** · `Prolong`.
3. **Изменить объект / переменную**:
   - `object.date_closed`, **Расчёт**, `add_months(object.date_closed, 1)`;
   - `object.next_step`, **Расчёт**,
     `concat('Квартал до ', format_date(end_of_quarter(object.date_closed), 'd.m.Y'), ', ', if(is_weekend(object.date_closed), 'выходной', 'будний'))`;
   - **Сохранить в CRM**: переменная `object`.

`add_months` обрезает день до конца месяца: 31 января + 1 месяц = 28 февраля (а не 3 марта). Вторая строка уже видит
новое значение `object.date_closed`. Производственный календарь не учитывается: `is_weekend` проверяет только субботу
и воскресенье.

**JSON**

```json
{
  "name": "Prolong deal by a month",
  "object_type": "Deals", "is_child": false, "is_active": true, "priority": 20, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Prolong" }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.date_closed", "change_type": "calculate", "value": "add_months(object.date_closed, 1)", "save_to_crm": false },
      { "type": "modify", "target": "object.next_step", "change_type": "calculate", "value": "concat('Квартал до ', format_date(end_of_quarter(object.date_closed), 'd.m.Y'), ', ', if(is_weekend(object.date_closed), 'выходной', 'будний'))", "save_to_crm": false },
      { "type": "save_object", "target": "object" }
    ]
  }
}
```

**Проверка**

```bash
curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=Prolong&set[date_closed]=2026-01-31"
```

**Что вы увидите.** `date_closed` = `2026-02-28`, `next_step` — «Квартал до 31.03.2026, выходной» (28.02.2026 —
суббота). Сценарий проверен на SuiteCRM 8 кейсом 5.2.2.

## 14. Скидка из текста менеджера и НДС (`to_number`, `percent`, `number_format`)

**Цель.** Менеджер пишет процент скидки в описании сделки в привычном формате (`7,5`). Процесс точно пересчитывает
сумму, пишет итог и выделенный НДС в русском формате.

**Настройка в интерфейсе**

1. **Тип объекта**: `Deals`.
2. Условие: `object.status` · **Равен** · **Значение** · `Discount`.
3. **Изменить объект / переменную**:
   - `object.amount`, **Расчёт**, `round(object.amount - percent(object.amount, to_number(object.description)), 2)`;
   - `object.next_step`, **Расчёт**, `concat('Итого: ', number_format(object.amount, 2, ',', ' '), ' ₽')`;
   - `object.description`, **Расчёт**, `concat('НДС 20% в сумме: ', number_format(round(object.amount / 6, 2), 2, ',', ' '), '; округление вниз до тысяч: ', floor(object.amount, -3))`;
   - **Сохранить в CRM**: переменная `object`.

`to_number` понимает пробелы и десятичную запятую (`'7,5'` → `7.5`, `'1 234,56'` → `1234.56`); без него текст `'7,5'`
не является числом и `percent` завершил бы шаг ошибкой. Арифметика точная: `1234567.89 × 0.925 = 1141975.29825` →
`round(…, 2)` = `1141975.3` (половина — от нуля), без погрешностей float. `floor(x, -3)` округляет вниз до тысяч.

**JSON**

```json
{
  "name": "Discount from manager text and VAT",
  "object_type": "Deals", "is_child": false, "is_active": true, "priority": 20, "run_once": false,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Discount" }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.amount", "change_type": "calculate", "value": "round(object.amount - percent(object.amount, to_number(object.description)), 2)", "save_to_crm": false },
      { "type": "modify", "target": "object.next_step", "change_type": "calculate", "value": "concat('Итого: ', number_format(object.amount, 2, ',', ' '), ' ₽')", "save_to_crm": false },
      { "type": "modify", "target": "object.description", "change_type": "calculate", "value": "concat('НДС 20% в сумме: ', number_format(round(object.amount / 6, 2), 2, ',', ' '), '; округление вниз до тысяч: ', floor(object.amount, -3))", "save_to_crm": false },
      { "type": "save_object", "target": "object" }
    ]
  }
}
```

**Проверка**

```bash
curl -sg "http://localhost:9502/test-event?module=Deals&set[status]=Discount&set[amount]=1234567.89&set[description]=7,5"
```

**Что вы увидите.** `amount` = `1141975.3`, `next_step` — «Итого: 1 141 975,30 ₽», `description` — «НДС 20% в сумме:
190 329,22; округление вниз до тысяч: 1141000». Если в описании не число (`много`), выполнение завершится ошибкой
`Cannot convert "много" to a number`, а сделка останется без изменений. Сценарий проверен на SuiteCRM 8 кейсом 5.2.3.

---

## 15. Звонок по лиду: текст шаблоном (`template`)

**Цель.** Для нового лида создать звонок, название и описание которого собраны шаблонами, — без цепочек
`substr(concat(…))`, и пометить лид. Если у лида нет мобильного, подставляется рабочий телефон.

**Настройка в интерфейсе**

1. **Тип объекта**: `Leads`; отметьте **Однократный запуск для записи**; условие: `object.status` · **Равен** · `New`.
2. **Изменить объект / переменную**:
   - `object.description`, **Шаблон**, `Обработан WPS: {{ object.salutation | default:'без обращения' }}, источник — {{ object.lead_source | default:'неизвестен' }}`, **Сохранить в CRM**;
   - `call.name`, **Шаблон**, `Позвонить: {{ object.first_name }} {{ object.last_name | upper | truncate:25 }}`;
   - `call.description`, **Шаблон**, `Компания: {{ object.account_name | default:'—' }}. Телефон: {{= coalesce(object.phone_mobile, object.phone_work, 'нет') }}. Лид создан {{ object.date_entered | format_date:'d.m.Y H:i':'Europe/Moscow' }} МСК`;
   - `call.direction` = `Outbound`, `call.status` = `Planned`, `call.date_start`, **Расчёт**, `add_days(now(), 1)`,
     `call.duration_hours` = `0`, `call.duration_minutes` = `15`, `call.parent_type` = `Leads`,
     `call.parent_id`, **Равен переменной**, `object.id`;
   - **Сохранить в CRM**: переменная `call`, модуль `Calls`.

`truncate:25` ограничивает длину вместе с `…`; `default` подставляет текст, если поле пустое или его нет;
`{{= … }}` вычисляет выражение прямо в тексте; `format_date` с поясом показывает дату создания (в CRM она в UTC) по
московскому времени.

**JSON**

```json
{
  "name": "New lead → call (template)",
  "object_type": "Leads", "is_child": false, "is_active": true, "priority": 0, "run_once": true,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "New" }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.description", "change_type": "template", "value": "Обработан WPS: {{ object.salutation | default:'без обращения' }}, источник — {{ object.lead_source | default:'неизвестен' }}", "save_to_crm": true },
      { "type": "modify", "target": "call.name", "change_type": "template", "value": "Позвонить: {{ object.first_name }} {{ object.last_name | upper | truncate:25 }}" },
      { "type": "modify", "target": "call.description", "change_type": "template", "value": "Компания: {{ object.account_name | default:'—' }}. Телефон: {{= coalesce(object.phone_mobile, object.phone_work, 'нет') }}. Лид создан {{ object.date_entered | format_date:'d.m.Y H:i':'Europe/Moscow' }} МСК" },
      { "type": "modify", "target": "call.direction", "change_type": "set_value", "value": "Outbound" },
      { "type": "modify", "target": "call.status", "change_type": "set_value", "value": "Planned" },
      { "type": "modify", "target": "call.date_start", "change_type": "calculate", "value": "add_days(now(), 1)" },
      { "type": "modify", "target": "call.duration_hours", "change_type": "set_value", "value": "0" },
      { "type": "modify", "target": "call.duration_minutes", "change_type": "set_value", "value": "15" },
      { "type": "modify", "target": "call.parent_type", "change_type": "set_value", "value": "Leads" },
      { "type": "modify", "target": "call.parent_id", "change_type": "set_from_variable", "value": "object.id" },
      { "type": "save_object", "target": "call", "module": "Calls" }
    ]
  }
}
```

**Что вы увидите.** Для лида «Анна Смирнова-Голенищева», компания «ООО «Ромашка»», только рабочий телефон: звонок
«Позвонить: Анна СМИРНОВА-ГОЛЕНИЩЕВА…» (длина 25 символов после имени вместе с `…`), описание «Компания: ООО «Ромашка».
Телефон: +7 495 000-51-11. Лид создан 20.10.2026 10:21 МСК», у лида — «Обработан WPS: без обращения, источник —
неизвестен». Если в шаблоне есть поле, которого у лида нет (например, `{{ object.no_such_field }}`), подставится пустая
строка, а путь появится в `result_data.missing` шага; с `"missing": "error"` шаг завершится ошибкой. Сценарий проверен на
SuiteCRM 8 кейсом 5.1.1.

---

## 16. Задача на завтра в 18:00 (`date_shift`)

**Цель.** Для нового лида создать задачу «Квалифицировать лид» со сроком «завтра в 18:00» по местному времени — без формул.

**Настройка в интерфейсе**

1. **Тип объекта**: `Leads`; отметьте **Однократный запуск для записи**; условие: `object.status` · **Равен** · `New`.
2. **Изменить объект / переменную**:
   - `task.name`, **Шаблон**, `Квалифицировать: {{ object.last_name | truncate:30 }}`;
   - `task.date_due`, **Сдвиг даты**: база `today`, сдвиг `+1 day`, время `18:00`;
   - `task.status` = `Not Started`, `task.parent_type` = `Leads`, `task.parent_id`, **Равен переменной**, `object.id`;
   - **Сохранить в CRM**: переменная `task`, модуль `Tasks`.

**JSON**

```json
{
  "name": "New lead → task tomorrow 18:00",
  "object_type": "Leads", "is_child": false, "is_active": true, "priority": 0, "run_once": true,
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "equals", "value_type": "static", "value": "New" }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "task.name", "change_type": "template", "value": "Квалифицировать: {{ object.last_name | truncate:30 }}" },
      { "type": "modify", "target": "task.date_due", "change_type": "date_shift", "base": "today", "shift": "+1 day", "set_time": "18:00" },
      { "type": "modify", "target": "task.status", "change_type": "set_value", "value": "Not Started" },
      { "type": "modify", "target": "task.parent_type", "change_type": "set_value", "value": "Leads" },
      { "type": "modify", "target": "task.parent_id", "change_type": "set_from_variable", "value": "object.id" },
      { "type": "save_object", "target": "task", "module": "Tasks" }
    ]
  }
}
```

**Что вы увидите.** При `WPS_TIMEZONE=Europe/Moscow` лид, созданный 16.10.2026, получит задачу со сроком
`2026-10-17 15:00:00` (UTC) — в CRM это 17.10 18:00 по Москве. При `WPS_TIMEZONE=UTC` — `2026-10-17 18:00:00`.

---

## 17. Задача за 3 дня до закрытия сделки, продление на месяц (`date_shift`)

**Цель.** Для сделки создать задачу «Подготовить закрытие» со сроком «дата закрытия − 3 дня, 18:00» и началом
«через 2 часа», затем перенести дату закрытия на месяц вперёд.

**Настройка в интерфейсе**

1. **Тип объекта**: `Opportunities`; **Однократный запуск для записи**.
2. **Изменить объект / переменную**:
   - `task.name`, **Шаблон**, `Подготовить закрытие: {{ object.name | truncate:25 }}`;
   - `task.date_due`, **Сдвиг даты**: база `object.date_closed`, сдвиг `-3 day`, время `18:00`;
   - `task.date_start`, **Сдвиг даты**: база `now`, сдвиг 2 часа;
   - `task.status` = `Not Started`, `task.priority` = `High`, `task.parent_type` = `Opportunities`,
     `task.parent_id`, **Равен переменной**, `object.id`; **Сохранить в CRM**: `task`, модуль `Tasks`;
   - `object.date_closed`, **Сдвиг даты**: база `object.date_closed`, сдвиг `+1 month`, **Сохранить в CRM**.

**JSON**

```json
{
  "name": "Deal → prepare closing task",
  "object_type": "Opportunities", "is_child": false, "is_active": true, "priority": 0, "run_once": true,
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "task.name", "change_type": "template", "value": "Подготовить закрытие: {{ object.name | truncate:25 }}" },
      { "type": "modify", "target": "task.date_due", "change_type": "date_shift", "base": "object.date_closed", "shift": "-3 day", "set_time": "18:00" },
      { "type": "modify", "target": "task.date_start", "change_type": "date_shift", "base": "now", "shift": [ { "amount": 2, "unit": "hour" } ] },
      { "type": "modify", "target": "task.status", "change_type": "set_value", "value": "Not Started" },
      { "type": "modify", "target": "task.priority", "change_type": "set_value", "value": "High" },
      { "type": "modify", "target": "task.parent_type", "change_type": "set_value", "value": "Opportunities" },
      { "type": "modify", "target": "task.parent_id", "change_type": "set_from_variable", "value": "object.id" },
      { "type": "save_object", "target": "task", "module": "Tasks" },
      { "type": "modify", "target": "object.date_closed", "change_type": "date_shift", "base": "object.date_closed", "shift": "+1 month", "save_to_crm": true }
    ]
  }
}
```

**Что вы увидите.** Для сделки с датой закрытия 31.01.2026 (при `WPS_TIMEZONE=UTC`): задача со сроком
`2026-01-28 18:00:00`, началом через 2 часа от запуска; дата закрытия сделки — `2026-02-28` (конец месяца обрезан).
В аудите шагов — `result_data.base` и `result_data.shifts`. Если база пустая, по умолчанию (`on_missing: null`) поле
очищается, а в `result_data.missing_base` — `true`. Поле `name` задачи в SuiteCRM — 50 символов, поэтому `truncate`.
Сценарий проверен на SuiteCRM 8 кейсом 5.1.2.

## 18. Сделка выиграна → задача (`changed_to`)

**Что делает.** Когда стадия сделки **становится** `Closed Won`, создаёт задачу «выставить счёт» со старой и новой стадией.
Повторные сохранения выигранной сделки (комментарий, сумма) задачу не дублируют — в отличие от условия
`sales_stage equals Closed Won`.

**Как настроить.** Объект **Deals**, условие `object.sales_stage` **Изменилось на** `Closed Won`; действия — шаблоны и
**Сохранить объект** в модуль Tasks.

```json
{
  "name": "Сделка выиграна → задача", "object_type": "Deals", "is_child": false, "is_active": true,
  "definition": {
    "conditions": [{ "field": "object.sales_stage", "operator": "changed_to", "value_type": "static", "value": "Closed Won" }],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "task.name", "change_type": "template", "value": "Сделка выиграна: счёт на {{ object.amount | number:0:'':' ' }}" },
      { "type": "modify", "target": "task.description", "change_type": "template", "value": "Стадия: {{ object_before.sales_stage }} → {{ object.sales_stage }}" },
      { "type": "modify", "target": "task.parent_type", "change_type": "set_value", "value": "Opportunities" },
      { "type": "modify", "target": "task.parent_id", "change_type": "set_from_variable", "value": "object.id" },
      { "type": "save_object", "target": "task", "module": "Tasks" }
    ]
  }
}
```

**Проверка на моке.** Событие формата 2.0 со сменой стадии:

```bash
curl -sg "http://localhost:9502/test-event?module=Deals&event_type=update&before[sales_stage]=Prospecting&set[sales_stage]=Closed%20Won&set[amount]=1500"
```

Без `before[sales_stage]` (или с `event_type`, но без смены стадии) процесс не запускается. Проверено на SuiteCRM 8 кейсом 3.3:
задача «Сделка выиграна: счёт на 1 500», описание «Стадия: Prospecting → Closed Won», повторное сохранение — без второй задачи.

## 19. Сумма изменилась: было → стало (`changed` с `cast: decimal`)

**Что делает.** Пишет в `next_step` сделки «сумма 1 000 → 1 500», когда сумма действительно изменилась. Нормализация
`1000.00 → 1000` изменением не считается благодаря `cast: decimal`.

```json
{
  "name": "Сумма изменилась", "object_type": "Deals", "is_child": false, "is_active": true,
  "definition": {
    "conditions": [{ "field": "object.amount", "operator": "changed", "cast": "decimal" }],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.next_step", "change_type": "template",
        "value": "сумма {{ object_before.amount | number:0:'':' ' }} → {{ object.amount | number:0:'':' ' }}", "save_to_crm": true }
    ]
  }
}
```

**Проверка на моке.**

```bash
curl -sg "http://localhost:9502/test-event?module=Deals&event_type=update&before[amount]=1000&set[amount]=1500"     # запускается
curl -sg "http://localhost:9502/test-event?module=Deals&event_type=update&before[amount]=1000.00&set[amount]=1000"  # нет
```

Сохранение `next_step` само порождает событие, но сумма в нём не меняется — процесс не зацикливается. Чтобы реагировать
только на изменение с определённого значения, добавьте `changed_from` (`"value": "1000"`, `cast: decimal`).

## 20. Приветственное письмо новому лиду (`send_email`)

**Что делает.** Когда в CRM создаётся лид с адресом почты, отправляет ему приветственное письмо с копией отделу продаж и
сохраняет историю письма в CRM (`Emails`, связь с лидом).

```json
{
  "name": "Приветственное письмо", "object_type": "Leads", "is_child": false, "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [{ "field": "object.email1", "operator": "is_not_empty" }],
    "preload": [],
    "actions": [
      { "id": "welcome", "type": "send_email",
        "to": ["{{ object.email1 }}"],
        "cc": ["sales-lead@company.ru"],
        "from": { "email": "crm@company.ru", "name": "CRM" },
        "subject": "Добро пожаловать, {{ object.first_name }}",
        "html": "<p>Здравствуйте, <b>{{ object.first_name | default:'клиент' }}</b>!</p><p>Ваш менеджер свяжется с вами {{ object.date_entered | add_days:1 | format_date:'d.m.Y' }}.</p>",
        "save_to_crm": { "module": "Emails", "parent": "object" } }
    ]
  }
}
```

**Проверка на моке.** Письма на стенде перехватывает Mailpit — откройте `http://localhost:8025`.

```bash
curl -sg "http://localhost:9502/test-event?module=Leads&event_type=create&set[first_name]=Anna&set[email1]=anna@example.com"
```

`trigger_on: ["create"]` гарантирует одно письмо на лид: изменения лида процесс не запускают. Повторная доставка того же
события и повтор шага после падения воркера письмо не дублируют (журнал побочных эффектов, постоянный `Message-ID`).

## 21. Эскалация обращения ответственному и руководителю (`send_email`)

**Что делает.** Когда приоритет обращения **становится** `P1`, отправляет письмо ответственному (адрес — из предзагрузки
пользователя) с копией руководителю группы через отдельный SMTP-сервер службы поддержки.

```json
{
  "name": "Эскалация P1", "object_type": "Cases", "is_child": false, "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [{ "field": "object.priority", "operator": "changed_to", "value_type": "static", "value": "P1" }],
    "preload": [
      { "var": "owner", "source": "object_field", "module": "Users", "by_field": "id", "value": "object.assigned_user_id" }
    ],
    "actions": [
      { "type": "send_email",
        "transport": "secret://smtp_support",
        "to": [{ "path": "owner.email1", "name": "{{ owner.first_name }} {{ owner.last_name }}" }],
        "cc": ["support-lead@company.ru"],
        "subject": "[P1] Обращение {{ object.case_number }}: {{ object.name | truncate:80 }}",
        "text": "Приоритет поднят до P1 (был {{ object_before.priority | default:'—' }}).\nКлиент: {{ object.account_name | default:'—' }}\n\n{{ object.description | truncate:1000 }}",
        "on_empty_recipients": "fail",
        "retry": { "max_attempts": 5, "base_delay": "30s", "max_delay": "10m" } }
    ]
  }
}
```

Секрет `smtp_support` вида `smtp_dsn` заводит администратор (см. [Секреты](secrets.md)). Если у ответственного нет адреса,
письмо всё равно уйдёт руководителю; если не осталось ни одного получателя, шаг завершается ошибкой (`on_empty_recipients:
fail`). Временные отказы SMTP повторяются по профилю `retry`.

## 22. Лид в ERP и номер заказа обратно в CRM (`http_request`)

**Что делает.** Для каждого нового лида отправляет его во внешнюю систему (ERP) запросом `POST` с JSON-телом, достаёт
номер заказа из ответа JSONPath и записывает его в описание лида.

```json
{
  "name": "Лид в ERP", "object_type": "Leads", "is_child": false, "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "actions": [
      { "id": "to_erp", "type": "http_request", "var": "erp", "method": "POST",
        "url": "https://erp.company.ru/api/leads/{{ object.id }}",
        "auth": { "type": "bearer", "token": "secret://erp_token" },
        "body": { "type": "json", "value": {
          "name": "{{ object.first_name }} {{ object.last_name }}",
          "status": "{{ object.status }}", "crm_id": "{{ object.id }}" } },
        "response": { "extract": { "order_id": "$.data.order_id" } },
        "retry": { "max_attempts": 3, "base_delay": "2s" } },
      { "type": "modify", "target": "object.description", "change_type": "template",
        "value": "Заказ в ERP: {{ erp.order_id }} (HTTP {{ erp.status }})", "save_to_crm": true }
    ]
  }
}
```

Секрет `erp_token` вида `bearer` (лучше с `allowed_hosts: ["erp.company.ru"]`) заводит администратор. Для `POST`
по умолчанию отправляется `Idempotency-Key`: если ERP его учитывает, ответы `5xx` и обрывы безопасно повторяются.
Если ERP ключ не понимает, задайте `"idempotency_header": null` — тогда при неизвестном исходе (обрыв после отправки,
`500`) шаг завершится `UNKNOWN_OUTCOME` и **не** создаст второй заказ.

**Как проверить на моке.** Мок отвечает на любой `/mock/echo/...` эхом запроса и номером заказа `A-1001`, `A-1002`…:
замените URL на `http://mock:9502/mock/echo/erp/leads/{{ object.id }}` (на стенде mock разрешён SSRF-настройками
compose) и уберите `auth`. Что пришло в «ERP»: `curl -s "http://localhost:9502/mock/echo-last?key=default"`.

## 23. Уведомление в Slack / мессенджер по вебхуку (`http_request`)

**Что делает.** Когда сделка выиграна, отправляет сообщение во входящий вебхук Slack. Адрес вебхука — секрет вида
`webhook_url`, он не появляется ни в определении, ни в аудите.

```json
{
  "name": "Выигранная сделка в Slack", "object_type": "Opportunities", "is_child": false, "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [{ "field": "object.sales_stage", "operator": "changed_to", "value_type": "static", "value": "Closed Won" }],
    "actions": [
      { "type": "http_request", "method": "POST", "url": "secret://slack_sales",
        "idempotency_header": null,
        "body": { "type": "json", "value": {
          "text": "Сделка «{{ object.name }}» выиграна: {{ object.amount | number:0:'':' ' }} ₽" } },
        "response": { "type": "text" },
        "on_error": "continue" }
    ]
  }
}
```

Хост из секрета проходит ту же SSRF-проверку при каждом запросе. `on_error: continue` — сбой мессенджера не останавливает
процесс, ошибка видна в `sys.last_error` и аудите шага.

## 24. Встреча с участниками по новому лиду (`link_records`)

**Что делает.** Для каждого нового лида создаёт встречу на завтра в 10:00, приглашает ответственного лида (участник
встречи с `accept_status = accept`) и связывает встречу с лидом.

```json
{
  "name": "Встреча с участниками", "object_type": "Leads", "is_child": false, "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "actions": [
      { "type": "modify", "target": "meeting.name", "change_type": "template",
        "value": "Встреча: {{ object.first_name }} {{ object.last_name | truncate:30 }}" },
      { "type": "modify", "target": "meeting.date_start", "change_type": "date_shift", "base": "today", "shift": "+1 day", "set_time": "10:00" },
      { "type": "modify", "target": "meeting.duration_hours", "change_type": "set_value", "value": "1" },
      { "type": "modify", "target": "meeting.duration_minutes", "change_type": "set_value", "value": "0" },
      { "type": "modify", "target": "meeting.status", "change_type": "set_value", "value": "Planned" },
      { "type": "modify", "target": "meeting.parent_type", "change_type": "set_value", "value": "Leads" },
      { "type": "modify", "target": "meeting.parent_id", "change_type": "set_from_variable", "value": "object.id" },
      { "type": "modify", "target": "meeting.assigned_user_id", "change_type": "set_from_variable", "value": "object.assigned_user_id" },
      { "type": "save_object", "target": "meeting", "module": "Meetings" },
      { "id": "invite", "type": "link_records", "record": "meeting", "link": "users",
        "related": [ { "path": "object.assigned_user_id" } ], "fields": { "accept_status": "accept" } },
      { "type": "link_records", "record": "meeting", "link": "leads", "related": [ { "path": "object.id" } ] }
    ]
  }
}
```

`link_records` после `save_object`: у переменной `meeting` уже есть `id` и модуль `Meetings`. Название встречи в
SuiteCRM — до 50 символов, лишнее CRM обрезает, поэтому фамилия ограничена `truncate`.

**Как проверить на моке.** Отправьте событие лида
(`curl -sg "http://localhost:9502/test-event?module=Leads&event_type=create&set[assigned_user_id]=u7"`), затем
посмотрите связи встречи: `curl -s http://localhost:9502/mock/relationships/Meetings/<id встречи>/users` — участник
`u7` с `accept_status: accept`. Id встречи — в переменной `meeting` выполнения.

## 25. Лид отказался → отвязать от встречи (`unlink_records`)

**Что делает.** Когда статус лида становится `Dead`, находит его встречу (предзагрузка по `parent_id`) и отвязывает
лида от встречи. Участники-пользователи остаются.

```json
{
  "name": "Лид отказался — убрать из встречи", "object_type": "Leads", "is_child": false, "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [{ "field": "object.status", "operator": "changed_to", "value_type": "static", "value": "Dead" }],
    "preload": [{ "var": "m", "source": "object_field", "module": "Meetings", "by_field": "parent_id", "value": "object.id" }],
    "actions": [
      { "type": "unlink_records", "record": "m", "link": "leads", "related": [ { "path": "object.id" } ] }
    ]
  }
}
```

Если встречи нет, переменная `m` пуста и шаг завершается `skipped` (`Record "m" has no id …`). Повтор безопасен:
уже отвязанный лид попадает в `not_linked`, шаг успешен.

Оба сценария проверены на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/4.12-link-records.json`.

## 26. Разные действия для крупных и мелких сделок

**Что делает.** Один процесс по новым сделкам вместо трёх: блок `condition` проверяет ветки по порядку и выполняет
первую подходящую. Крупная сделка получает стадию «A» и задачу руководителю, средняя (или с пометкой VIP в
описании — группа OR) — «B», остальные — ветка по умолчанию без условий.

```json
{
  "name": "Размер сделки",
  "object_type": "Opportunities",
  "is_child": false,
  "is_active": true,
  "trigger_on": [
    "create"
  ],
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      {
        "id": "deal_size",
        "type": "condition",
        "name": "Размер сделки",
        "branches": [
          {
            "name": "Больше 1 млн",
            "conditions": [
              {
                "field": "object.amount",
                "operator": "greater_than",
                "cast": "decimal",
                "value_type": "static",
                "value": "1000000"
              }
            ],
            "actions": [
              {
                "type": "modify",
                "target": "object.next_step",
                "change_type": "set_value",
                "value": "A: согласовать с руководителем",
                "save_to_crm": true
              },
              {
                "type": "modify",
                "target": "task.name",
                "change_type": "template",
                "value": "Крупная сделка на {{ object.amount | number:0:'':' ' }}"
              },
              {
                "type": "modify",
                "target": "task.priority",
                "change_type": "set_value",
                "value": "High"
              },
              {
                "type": "modify",
                "target": "task.parent_type",
                "change_type": "set_value",
                "value": "Opportunities"
              },
              {
                "type": "modify",
                "target": "task.parent_id",
                "change_type": "set_from_variable",
                "value": "object.id"
              },
              {
                "type": "save_object",
                "target": "task",
                "module": "Tasks"
              }
            ]
          },
          {
            "name": "Больше 100 тыс. или VIP",
            "conditions": {
              "logical_operator": "OR",
              "conditions": [
                {
                  "field": "object.amount",
                  "operator": "greater_than",
                  "cast": "decimal",
                  "value_type": "static",
                  "value": "100000"
                },
                {
                  "field": "object.description",
                  "operator": "contains",
                  "value_type": "static",
                  "value": "VIP"
                }
              ]
            },
            "actions": [
              {
                "type": "modify",
                "target": "object.next_step",
                "change_type": "set_value",
                "value": "B: обычный процесс",
                "save_to_crm": true
              }
            ]
          },
          {
            "name": "Остальные",
            "conditions": [],
            "actions": [
              {
                "type": "modify",
                "target": "object.next_step",
                "change_type": "set_value",
                "value": "C: автоматическая обработка",
                "save_to_crm": true
              }
            ]
          }
        ]
      }
    ]
  }
}
```

| Сделка | Выбранная ветка | `next_step` |
|---|---|---|
| 2 500 000 | 0 «Больше 1 млн» | «A: согласовать с руководителем» + задача «Крупная сделка на 2 500 000» |
| 5 000, в описании «VIP» | 1 «Больше 100 тыс. или VIP» | «B: обычный процесс» |
| 5 000 | 2 «Остальные» | «C: автоматическая обработка», задачи нет |

**Как проверить.** В журнале выполнения запись `condition` показывает `selected` и трассу проверенных веток с их
именами ([Выполнения и аудит](executions-and-audit.md#запись-блока-condition)); шаги невыбранных веток не пишутся.
Если поставить ветку без условий не последней, сохранение вернёт предупреждение
`Branch 0 has no conditions: the following branches are never reached`.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/4.3-condition-branch.json`.

## 27. Не обрабатывать лид без контактов (`stop_process`)

**Что делает.** Процесс по новым лидам проверяет, есть ли у лида email или телефон. Нет ни того, ни другого —
выполнение завершается `failed` с кодом `NO_CONTACTS` и понятным сообщением; лид уже в статусе Dead — успешное
завершение без действий; иначе в описание лида пишется пометка.

```json
{
  "name": "Проверка лида",
  "object_type": "Leads",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "id": "check", "type": "condition", "branches": [
        { "name": "нет контактов",
          "conditions": [ { "field": "object.email1", "operator": "is_empty" }, { "field": "object.phone_work", "operator": "is_empty" } ],
          "actions": [ { "type": "stop_process", "status": "failed", "code": "NO_CONTACTS", "message": "У лида {{ object.first_name }} {{ object.last_name }} нет email и телефона" } ] },
        { "name": "отказ",
          "conditions": [ { "field": "object.status", "operator": "equals", "value_type": "static", "value": "Dead" } ],
          "actions": [ { "type": "stop_process", "status": "completed", "code": "LEAD_DEAD", "message": "Лид в статусе Dead — обработка не нужна" } ] }
      ] },
      { "type": "modify", "target": "object.description", "change_type": "template", "value": "Проверен: {{ object.email1 | default:object.phone_work }}", "save_to_crm": true }
    ]
  }
}
```

| Лид | Итог |
|---|---|
| без email и телефона | `failed`, `error_message = "[NO_CONTACTS] У лида … нет email и телефона"`, шаг `modify` — `skipped` |
| Dead, с телефоном | `completed`, `context.stop.code = LEAD_DEAD`, описание не изменено |
| с email | `completed`, описание «Проверен: …» |

**Как проверить.** В журнале выполнения — запись `stop_process` и `skipped` у последующих шагов
([Выполнения и аудит](executions-and-audit.md#остановленное-выполнение)); у остановленных выполнений фильтр по статусу
`failed` показывает код в `error_message`.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/4.14-stop-process.json`.

## 28. Напомнить за 30 дней до окончания договора (`delay`)

**Что делает.** При создании договора (`AOS_Contracts`) процесс ждёт до 09:00 (по `WPS_TIMEZONE`) за 30 дней до даты
окончания и создаёт ответственному задачу «Продлить договор». Ожидание длится месяцами, но не занимает воркер и
переживает перезапуски. Если до окончания меньше 30 дней, задача создаётся сразу (`if_past: continue`); договор без даты
окончания пропускает паузу (`on_invalid: continue`).

```json
{
  "name": "Напоминание о продлении договора",
  "object_type": "AOS_Contracts",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "id": "wait_end", "type": "delay", "mode": "until", "until": "object.end_date", "cast": "date", "time": "09:00",
        "offset": "-P30D", "if_past": "continue", "on_invalid": "continue" },
      { "type": "modify", "target": "task.name", "change_type": "template", "value": "Продлить договор {{ object.name }}" },
      { "type": "modify", "target": "task.date_due", "change_type": "set_from_variable", "value": "object.end_date" },
      { "type": "modify", "target": "task.assigned_user_id", "change_type": "set_from_variable", "value": "object.assigned_user_id" },
      { "type": "modify", "target": "task.parent_type", "change_type": "set_value", "value": "AOS_Contracts" },
      { "type": "modify", "target": "task.parent_id", "change_type": "set_from_variable", "value": "object.id" },
      { "type": "save_object", "target": "task", "module": "Tasks" }
    ]
  }
}
```

**Как проверить.** Сразу после создания договора выполнение в статусе **Ожидает**: `GET /api/executions?status=waiting`
показывает `waiting_until` — 09:00 за 30 дней до окончания. Для быстрой проверки поставьте дату окончания на сегодня+30
дней и `time` на ближайшую минуту. Данные договора после паузы берутся из события; если за месяцы их могли изменить,
перечитайте договор предзагрузкой после паузы ([Пауза](actions.md#пауза-delay)).

- Модуль `AOS_Contracts` должен быть в `WPS_EVENT_MODULES` плагина CRM — иначе события по договорам не публикуются и
  процесс не запускается ([Интеграция с CRM](../04-operations/crm-integration.md)).
- SuiteCRM при создании договора через API сам ставит `end_date` = дата начала + 1 год. Договор без даты окончания
  (`on_invalid: continue` — задача сразу) бывает, только если поле очищено явно.

Проверено на SuiteCRM 8 (2026-10-20): окончание через 30 дней — срок уже прошёл, задача создаётся сразу
(`past_due: true`); окончание через 60 дней — выполнение ждёт до 09:00 UTC за 30 дней до окончания.

## 29. Перезвонить через 2 дня (`delay`)

**Что делает.** Через 2 дня после создания лида создаёт звонок «Перезвонить …» на ближайший час.

```json
{
  "name": "Перезвонить через 2 дня",
  "object_type": "Leads",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "id": "wait", "type": "delay", "mode": "duration", "duration": "2d" },
      { "type": "modify", "target": "call.name", "change_type": "template", "value": "Перезвонить {{ object.first_name }}" },
      { "type": "modify", "target": "call.date_start", "change_type": "date_shift", "base": "now", "shift": "+1 hour" },
      { "type": "modify", "target": "call.direction", "change_type": "set_value", "value": "Outbound" },
      { "type": "modify", "target": "call.status", "change_type": "set_value", "value": "Planned" },
      { "type": "modify", "target": "call.parent_type", "change_type": "set_value", "value": "Leads" },
      { "type": "modify", "target": "call.parent_id", "change_type": "set_from_variable", "value": "object.id" },
      { "type": "save_object", "target": "call", "module": "Calls" }
    ]
  }
}
```

**Как проверить.** Временно замените `2d` на `30s`: выполнение уходит в **Ожидает**, через 30 секунд шаг `delay`
становится `success` с `waited: true`, и в CRM появляется звонок.

Проверено на SuiteCRM 8 (тот же сценарий с паузой до «дата создания + 10 секунд»): кейс
`.tehnical/suiteCRM8/processes/cases/4.7-delay.json`.

## 30. Сводка по лиду: открытые задачи и звонки (`find_records`)

**Что делает.** Когда лид переходит в статус `In Process`, находит две ближайшие по сроку открытые задачи лида и
запланированные звонки, в которых лид — участник, и пишет сводку в описание лида.

```json
{
  "name": "Сводка по лиду",
  "object_type": "Leads",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [
      { "field": "object.status", "operator": "changed_to", "value_type": "static", "value": "In Process" }
    ],
    "preload": [],
    "actions": [
      { "type": "find_records", "var": "tasks", "module": "Tasks",
        "filter": [
          { "field": "parent_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" },
          { "field": "status", "operator": "not_in", "value": ["Completed", "Deferred"] }
        ],
        "sort": [ { "field": "date_due", "direction": "asc" } ], "fields": ["name", "status", "date_due"], "limit": 2 },
      { "type": "find_records", "var": "calls", "related_to": { "record": "object", "link": "calls" },
        "filter": [ { "field": "status", "operator": "equals", "value": "Planned" } ] },
      { "type": "modify", "target": "object.description", "change_type": "template",
        "value": "Открытых задач: {{ tasks.count }}{{= if(tasks.has_more, '+', '') }}; ближайшая: {{ tasks.items.0.name }}; запланировано звонков: {{ calls.count }}" },
      { "type": "save_object", "target": "object", "module": "Leads" }
    ]
  }
}
```

Задачи ищутся в модуле `Tasks` по `parent_id` — фильтр, сортировка и лимит выполняются в CRM; `has_more: true`
означает, что открытых задач больше двух (в сводке «2+»). Звонки берутся по ссылке `Leads.calls` — это звонки, куда
лид **приглашён** участником; отбор по статусу делает WPS. Звонки, у которых лид указан родителем, ищите так же, как
задачи: `Calls`, `parent_id equals object.id`.

**Как проверить.** Создайте лиду несколько задач в разных статусах и звонок, пригласите лида в звонок, переведите лид в
`In Process`. В аудите шага `find_records` видно `request_data.crm_filter` — что ушло в CRM — и `result_data.ids`.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/4.10-find-records.json` — «Открытых задач: 2+;
ближайшая: Отправить КП; запланировано звонков: 2».


## 31. Сменился ответственный контрагента → передать его открытые сделки (`foreach`)

**Что делает.** Когда у контрагента меняется ответственный, всем его открытым сделкам (кроме `Closed Won` и
`Closed Lost`) ставится тот же ответственный.

```json
{
  "name": "Передать открытые сделки новому ответственному",
  "object_type": "Accounts",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [
      { "field": "object.assigned_user_id", "operator": "changed" }
    ],
    "preload": [],
    "actions": [
      { "type": "find_records", "var": "deals", "related_to": { "record": "object", "link": "opportunities" },
        "filter": [ { "field": "sales_stage", "operator": "not_in", "value_type": "static", "value": ["Closed Won", "Closed Lost"] } ] },
      { "id": "each_deal", "type": "foreach", "source": "deals.items", "as": "deal", "on_item_error": "continue",
        "actions": [
          { "type": "modify", "target": "deal.assigned_user_id", "change_type": "set_from_variable", "value": "object.assigned_user_id" },
          { "type": "save_object", "target": "deal" }
        ] }
    ]
  }
}
```

Сделки контрагента берутся по ссылке `Accounts.opportunities`, открытые отбирает фильтр «Найти записи»; можно и
наоборот — взять все сделки и отсеять закрытые `where` цикла (так в аудите видно `skipped_by_where`). `save_object deal`
обязателен: `modify` меняет только копию элемента. `on_item_error: continue` — сделка, которую не удалось сохранить, не
останавливает передачу остальных; в итоговой записи `foreach` будут `failed_items` и `failed_indexes`.

**Как проверить.** Смените ответственного у контрагента с несколькими сделками. В журнале — одна запись `foreach` с
`processed` и записи `modify` / `save_object` с номерами итераций `0`, `1`, …

## 32. Сделки нового клиента: следующий шаг и сумма (`foreach` + накопитель)

**Что делает.** Контрагент стал клиентом (`account_type` → `Customer`): каждой его открытой сделке ставится следующий
шаг «Клиент подтверждён: согласовать КП», сумма открытых сделок копится в переменной процесса и пишется в описание
контрагента.

Определение — в разделе [Цикл (для каждого элемента)](actions.md#пример-сделки-нового-клиента). Главное:

- накопитель `sum` инициализируется **до** цикла (`set_variable` со значением `0`) и увеличивается в теле
  (`calculate`: `sum.total + deal.amount`) — переменные тела сохраняются между итерациями и после цикла;
- закрытые сделки отсеивает `where` цикла, поэтому итог показывает `skipped_by_where`;
- после цикла `deal` и `deal_loop` недоступны, `sum` — доступна.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/4.11-foreach.json` — у двух открытых сделок
следующий шаг заполнен, выигранная не изменена, описание контрагента «Открытых сделок: 2 на сумму 3500.5».


## 33. Сделка проиграна → отложить все открытые задачи (`update_records`)

**Что делает.** Когда сделка переходит в `Closed Lost`, все её невыполненные задачи получают статус `Deferred`, а к
описанию добавляется «Отложено: сделка проиграна.». Уже отложенные задачи не трогаются.

```json
{
  "name": "Отложить задачи проигранной сделки",
  "object_type": "Opportunities",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [
      { "field": "object.sales_stage", "operator": "changed_to", "value_type": "static", "value": "Closed Lost" }
    ],
    "preload": [],
    "actions": [
      { "type": "find_records", "var": "tasks", "module": "Tasks",
        "filter": [
          { "field": "parent_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" },
          { "field": "status", "operator": "not_in", "value": ["Completed"] }
        ],
        "fields": ["name", "status", "description"] },
      { "id": "defer", "type": "update_records", "list": "tasks", "set": [
        { "field": "status", "change_type": "set_value", "value": "Deferred" },
        { "field": "description", "change_type": "calculate",
          "value": "if(item.status == 'Deferred', item.description, trim(concat(item.description, ' Отложено: сделка проиграна.')))" }
      ] }
    ]
  }
}
```

`item` — текущая задача внутри правил. Для уже отложенной задачи оба правила дают прежние значения, и с
`only_changed: true` (по умолчанию) она в CRM не отправляется — в аудите `unchanged`. Если какую-то задачу сохранить не
удалось (например, её удалили), остальные всё равно обновятся, а шаг станет `failed` со сводкой; добавьте
`"on_error": "continue"`, если процесс должен идти дальше.

**Как проверить.** Создайте сделке задачи в разных статусах и переведите её в `Closed Lost`. В журнале — две записи:
`find_records` и `update_records` с `updated`, `unchanged` и списком `items`.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/4.6-update-records.json` — `updated = 2`,
`unchanged = 1`, выполненная задача не тронута.

## 34. Сменился ответственный контрагента → всем его контактам (`update_records`)

**Что делает.** Когда у контрагента меняется ответственный, тот же ответственный ставится всем контактам контрагента.

```json
{
  "name": "Ответственный контрагента → контактам",
  "object_type": "Accounts",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.assigned_user_id", "operator": "changed" } ],
    "preload": [],
    "actions": [
      { "type": "find_records", "var": "contacts", "related_to": { "record": "object", "link": "contacts" },
        "fields": ["assigned_user_id"] },
      { "type": "update_records", "list": "contacts", "on_error": "continue", "set": [
        { "field": "assigned_user_id", "change_type": "set_from_variable", "value": "object.assigned_user_id" }
      ] }
    ]
  }
}
```

Контакты, у которых ответственный уже нужный, не отправляются (`unchanged`). Тот же сценарий для сделок с отбором
открытых — рецепт [31](#31-сменился-ответственный-контрагента--передать-его-открытые-сделки-foreach) на цикле.

**Действие или цикл.**

| Задача | Что выбрать |
|---|---|
| Одинаковые поля у всех найденных записей | `update_records` (рецепты 33, 34): один шаг, одна запись аудита |
| Для каждой записи несколько действий (письмо, связь, дочерний процесс, пауза), сумма по записям, шаги по каждой записи в журнале | `foreach` (рецепты 31, 32) |

Подробнее — [Действия → Действие или цикл](actions.md#действие-или-цикл).


## 35. Сводка по контрагенту: сделки, задачи, последний звонок (`aggregate`)

**Что делает.** При смене отрасли контрагента (или любом другом событии — замените условие) считает число и сумму
связанных сделок, среднюю сумму, число открытых задач и дату последнего звонка, пишет сумму в `annual_revenue`, а
сводку — в описание.

```json
{
  "name": "Сводка по контрагенту",
  "object_type": "Accounts",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.industry", "operator": "changed" } ],
    "preload": [],
    "actions": [
      { "type": "set_variable", "var": "stats", "fields": [
        { "name": "deals", "change_type": "aggregate", "function": "count",
          "source": { "relationship": { "module": "Accounts", "id": "object.id", "link": "opportunities" } } },
        { "name": "total", "change_type": "aggregate", "function": "sum", "field": "amount",
          "source": { "relationship": { "module": "Accounts", "id": "object.id", "link": "opportunities" } } },
        { "name": "avg", "change_type": "aggregate", "function": "avg", "field": "amount",
          "source": { "relationship": { "module": "Accounts", "id": "object.id", "link": "opportunities" } } },
        { "name": "open_tasks", "change_type": "aggregate", "function": "count",
          "source": { "module": "Tasks", "filter": [
            { "field": "parent_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" },
            { "field": "status", "operator": "not_in", "value": ["Completed", "Deferred"] } ] } },
        { "name": "last_call", "change_type": "aggregate", "function": "max", "field": "date_start", "cast": "datetime",
          "source": { "module": "Calls", "filter": [
            { "field": "parent_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" } ] },
          "empty_result": "нет" }
      ] },
      { "type": "modify", "target": "object.annual_revenue", "change_type": "set_from_variable", "value": "stats.total" },
      { "type": "modify", "target": "object.description", "change_type": "template",
        "value": "Сделок: {{ stats.deals }} на {{ stats.total }} (средняя {{ stats.avg }}); открытых задач: {{ stats.open_tasks }}; последний звонок: {{ stats.last_call }}" },
      { "type": "save_object", "target": "object", "module": "Accounts" }
    ]
  }
}
```

Сделки берутся по связи `Accounts.opportunities` (в SuiteCRM сделка связана с контрагентом через таблицу связи, у
`Opportunities` нет колонки `account_id` для фильтра). Задачи и звонки — по колонке `parent_id`; `count` задач
делается одним запросом (`meta.total`). Сохранение контрагента вызовет новое событие `update`, но условие
`industry changed` на нём ложно — зацикливания нет.

**Как проверить.** В журнале шаг `set_variable` показывает по каждому полю `records_scanned`, `pages`, `total`.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/5.1.7-aggregate.json` (там же `annual_revenue`
считается агрегатом прямо в `modify`) — «Сделок: 3 на 4000.5 (средняя 1333.5); открытых задач: 2; последний звонок:
2026-11-05 14:30:00».

## 36. Сумма выигранных сделок контрагента

**Что делает.** Пишет в `annual_revenue` контрагента сумму его сделок в стадии `Closed Won`.

Агрегат по связи (`source.relationship`) фильтра не имеет — он сложит **все** сделки. А фильтр по модулю
`Opportunities` с `account_id` в SuiteCRM не работает: это relate-поле без колонки (`422 Unknown filter field
Opportunities.account_id`). Поэтому в SuiteCRM выигранные сделки отбираются шагом «Найти записи» по связи с локальным
фильтром, а сумма копится в цикле:

```json
{
  "name": "Сумма выигранных сделок",
  "object_type": "Accounts",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "type": "find_records", "var": "won", "related_to": { "record": "object", "link": "opportunities" },
        "filter": [ { "field": "sales_stage", "operator": "equals", "value": "Closed Won" } ], "fields": ["amount"] },
      { "type": "set_variable", "target": "sum.total", "change_type": "set_value", "value": "0" },
      { "type": "foreach", "source": "won.items", "as": "deal", "actions": [
        { "type": "set_variable", "target": "sum.total", "change_type": "calculate", "value": "sum.total + to_number(deal.amount)" }
      ] },
      { "type": "modify", "target": "object.annual_revenue", "change_type": "set_from_variable", "value": "sum.total",
        "save_to_crm": true }
    ]
  }
}
```

Если у сделки есть **колонка** контрагента (своё поле или другая CRM), хватит одного агрегата по модулю:

```json
{ "type": "modify", "target": "object.annual_revenue", "change_type": "aggregate", "function": "sum", "field": "amount",
  "source": { "module": "Opportunities", "filter": [
    { "field": "account_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" },
    { "field": "sales_stage", "operator": "equals", "value": "Closed Won" } ] } }
```

Здесь пустой `object.id` не даст запроса (результат `0`), а сумма считается десятичной арифметикой. Для модулей
SuiteCRM с настоящей колонкой контрагента (`Cases.account_id`) этот вариант работает как есть.

**Как проверить.** Запустите процесс вручную на контрагенте с выигранными и открытыми сделками: в `annual_revenue` —
сумма только выигранных. Рецепт на стенде не запускался; агрегат и цикл по отдельности проверены кейсами 5.1.7 и 4.11.


## 37. Новый лид → колокольчик и Telegram (`notify`)

**Что делает.** При создании лида отправляет ответственному уведомление в колокольчик SuiteCRM и сообщение в групповой
чат отдела продаж в Telegram — со ссылкой на лид.

```json
{
  "name": "Уведомить о новом лиде",
  "object_type": "Leads",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "type": "notify", "title": "Новый лид",
        "message": "Вам назначен лид {{ object.first_name }} {{ object.last_name }} ({{ object.lead_source | default:'источник не указан' }})",
        "link": { "record": "object" },
        "channels": [
          { "channel": "crm_alert", "users": ["{{ object.assigned_user_id }}"] },
          { "channel": "telegram", "connection": "secret://sales_bot", "chat_id": "-1001234567890" }
        ] }
    ]
  }
}
```

Перед запуском: секрет `sales_bot` вида `telegram_bot` с токеном бота, бот добавлен в группу, `chat_id` группы известен
(см. [Как подключить мессенджеры](actions.md#как-подключить-мессенджеры)); `CRM_UI_BASE_URL` — адрес интерфейса CRM для
кнопки «Открыть». Лид без ответственного даёт канал `crm_alert` со статусом `skipped` («No recipients»), Telegram всё
равно отправляется. Проверить канал без процесса — `POST /api/notify/test`.

**Как проверить.** Создайте лид с ответственным: у пользователя появится уведомление в колокольчике (ссылка ведёт на
лид), в группе — сообщение с жирным заголовком и ссылкой «Открыть».

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/4.8-notify.json` (Telegram и Slack — на mock) —
запись `Alerts` у admin с `url_redirect = #/leads/record/{id}`, в Telegram — HTML-сообщение со ссылкой на
`http://localhost:8801/#/leads/record/{id}`.

## 38. Крупная сделка выиграна → Slack (`notify`)

**Что делает.** Когда сделка от 1 000 000 переходит в `Closed Won`, пишет в канал Slack руководству и в Teams отдела.

```json
{
  "name": "Крупная сделка выиграна",
  "object_type": "Opportunities",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [
      { "field": "object.sales_stage", "operator": "changed_to", "value_type": "static", "value": "Closed Won" },
      { "field": "object.amount", "operator": "greater_or_equal", "value_type": "static", "value": "1000000", "cast": "decimal" }
    ],
    "preload": [],
    "actions": [
      { "type": "notify", "title": "Выиграна сделка {{ object.name }}",
        "message": "Сумма: {{ object.amount | number_format:2 }}. Ответственный: {{ object.assigned_user_name | default:'—' }}",
        "link": { "record": "object" },
        "channels": [
          { "channel": "slack", "connection": "secret://slack_management" },
          { "channel": "teams", "connection": "secret://teams_sales" }
        ],
        "fail_on": "all", "on_error": "continue" }
    ]
  }
}
```

`fail_on: all` — шаг падает, только если сообщение не ушло никуда; `on_error: continue` — даже тогда процесс идёт
дальше (ошибка видна в журнале). Секреты `slack_management` и `teams_sales` — вида `webhook_url`. Если включён
`HTTP_ALLOW_HOSTS`, добавьте `hooks.slack.com` и хост вебхука Teams. Подставляемые из CRM `&`, `<`, `>` в Slack
экранируются, поэтому название сделки не ломает разметку.

**Как проверить.** Переведите сделку на 1 200 000 в `Closed Won`: в Slack — сообщение «*Выиграна сделка …*» со ссылкой
«Открыть». В журнале у шага `notify` — `deliveries` с `status: 200` по каждому вебхуку; при повторе шага (например, после
429 от Teams) Slack не дублируется (`replayed: true`). Рецепт на стенде не запускался; каналы Slack и Teams проверены
интеграционными тестами на mock.


## 39. Распределение лидов по кругу (`assign`)

**Что делает.** Каждый новый лид получает следующего по очереди активного менеджера из группы безопасности «Продажи»;
сотрудники в отпуске (`Leave of Absence`) и неактивные пропускаются.

```json
{
  "name": "Лиды по кругу",
  "object_type": "Leads",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "id": "rr", "type": "assign", "target": "object", "strategy": "round_robin",
        "pool": { "security_group": "<id группы «Продажи»>" }, "queue_key": "leads_sales",
        "exclude": [ { "path": "object.created_by" } ],
        "fallback": { "mode": "user", "user": { "value": "1" } },
        "save_to_crm": true }
    ]
  }
}
```

`queue_key` задан явно, чтобы очередь не начиналась заново после правки процесса и чтобы другой процесс (например,
лиды из веб-формы) мог стоять в той же очереди. `exclude` не даёт назначить лид его автору. Если в группе нет активных
сотрудников, лид получает запасного ответственного (`fallback: user`). Новый сотрудник группы попадает в очередь в
пределах `ASSIGN_POOL_CACHE_TTL`.

**Как проверить.** Создайте подряд столько лидов, сколько сотрудников в группе, и ещё один: ответственные идут по кругу.
В журнале у шага `assign` — `reason` («round_robin: next after …»), `pool`, `inactive`. Сброс очереди —
`DEL assign:rr:leads_sales` в redis-data.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/4.9-assign.json` (группа `wps-case-sales`) —
4 лида получили трёх разных активных менеджеров, четвёртый — снова первого, неактивный пропущен.

## 40. Обращение — наименее загруженному (`assign`)

**Что делает.** Новое обращение назначается сотруднику поддержки, у которого меньше всего открытых обращений.

```json
{
  "name": "Обращение наименее загруженному",
  "object_type": "Cases",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [],
    "preload": [],
    "actions": [
      { "type": "assign", "target": "object", "strategy": "least_busy",
        "pool": { "security_group": "<id группы «Поддержка»>" },
        "least_busy": { "module": "Cases", "user_field": "assigned_user_id",
          "filter": [ { "field": "state", "operator": "not_equals", "value": "Closed" } ] },
        "save_to_crm": true }
    ]
  }
}
```

На каждого сотрудника — один запрос подсчёта в CRM (до 10 параллельно), поэтому пул ограничен
`ASSIGN_LEAST_BUSY_MAX_POOL` (50). При равной загрузке выбирается тот, кому дольше всего ничего не назначали.

**Как проверить.** В журнале у шага — `counts` (число открытых обращений у каждого) и `reason`
(«least_busy: … has 0 open Cases (min)»).

Проверено на SuiteCRM 8: кейс 4.9 — при двух открытых у u1, одном у u2 и одном закрытом у u3 обращение получил u3.

## 41. Эскалация руководителю (`assign`)

**Что делает.** Когда обращению ставят приоритет P1, ответственным становится руководитель текущего ответственного
(поле «Руководитель» — `reports_to_id` — в карточке пользователя).

```json
{
  "name": "Эскалация руководителю",
  "object_type": "Cases",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.priority", "operator": "changed_to", "value_type": "static", "value": "P1" } ],
    "preload": [],
    "actions": [
      { "type": "assign", "target": "object", "strategy": "manager", "fallback": { "mode": "keep" }, "save_to_crm": true },
      { "type": "notify", "title": "Эскалация обращения",
        "message": "Обращение {{ object.name }} передано вам: приоритет P1",
        "link": { "record": "object" },
        "channels": [ { "channel": "crm_alert", "users": ["{{ object.assigned_user_id }}"] } ] }
    ]
  }
}
```

Нет руководителя или он неактивен — `fallback: keep`: ответственный не меняется, шаг `skipped` с причиной («user … has
no manager»). Уведомление идёт новому ответственному: `object.assigned_user_id` уже изменён шагом `assign`.

**Как проверить.** Поставьте обращению приоритет P1: ответственный — руководитель прежнего, в журнале `reason`
«manager of …», `previous_user_id`.

Проверено на SuiteCRM 8: кейс 4.9 — обращение `wps-case-u3` при P1 перешло к `wps-case-u1` (шаг уведомления в кейсе не
проверялся).


## 42. Лид с бесплатной почтой (`ends_with`)

**Что делает.** Лид с адресом на gmail.com или mail.ru и российским мобильным получает пометку в описании и статус
`Assigned` — его стоит квалифицировать отдельно от корпоративных.

```json
{
  "name": "Лид с бесплатной почтой",
  "object_type": "Leads",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": {
      "logical_operator": "AND",
      "conditions": [
        { "field": "object.phone_mobile", "operator": "starts_with", "value": "+7" }
      ],
      "groups": [
        { "logical_operator": "OR", "conditions": [
          { "field": "object.email1", "operator": "ends_with", "value": "@gmail.com", "cast": "string", "cast_options": { "case_sensitive": false } },
          { "field": "object.email1", "operator": "ends_with", "value": "@mail.ru", "cast": "string", "cast_options": { "case_sensitive": false } }
        ] }
      ]
    },
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.description", "change_type": "template", "value": "Бесплатная почта, РФ. {{ object.description }}" },
      { "type": "modify", "target": "object.status", "change_type": "set_value", "value": "Assigned", "save_to_crm": true }
    ]
  }
}
```

`ends_with` с `case_sensitive: false` поймает и `Ivan@Gmail.com`, но не `x@gmail.com.evil.ru` — а **Включает**
`gmail.com` сработал бы на нём.

**Как проверить.** Создайте лид с `+7 900 …` и адресом `…@Gmail.com` — условия выполнены; лид с
`…@gmail.com.evil.ru` процесс не запускает (это видно ручным запуском с трассой условий).

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/3.5-starts-ends-with.json` (там же `starts_with` в
фильтре «Найти записи» → `filter[name][like]=…%`).


## 43. Сделка среднего сегмента (`between`)

**Что делает.** Новая сделка на сумму от 10 000 до 50 000 включительно получает следующий шаг «Средний сегмент» и
число похожих сделок того же диапазона.

```json
{
  "name": "Средний сегмент",
  "object_type": "Opportunities",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [
      { "field": "object.amount", "operator": "between", "value_type": "static", "value": ["10000", "50000"], "cast": "decimal" }
    ],
    "preload": [],
    "actions": [
      { "type": "find_records", "var": "similar", "module": "Opportunities",
        "filter": [ { "field": "amount", "operator": "between", "value": ["10000", "50000"], "cast": "decimal" },
                    { "field": "sales_stage", "operator": "not_in", "value": ["Closed Won", "Closed Lost"] } ] },
      { "type": "modify", "target": "object.next_step", "change_type": "template",
        "value": "Средний сегмент; открытых сделок в сегменте: {{ similar.count }}{{= if(similar.has_more, '+', '') }}" },
      { "type": "save_object", "target": "object", "module": "Opportunities" }
    ]
  }
}
```

Границы включаются: сделка ровно на 50 000 попадает в сегмент, на 50 000,01 — нет. В фильтре поиска `between` уходит
в CRM как `filter[amount][gte]=10000&filter[amount][lte]=50000`.

**Как проверить.** Создайте сделки на 50 000 и 50 000,01: процесс запускается только для первой; в журнале
`find_records` видно `request_data.crm_filter`.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/3.6-between.json`.

## 44. Сумма вне коридора лимитов контрагента (`not_between`)

**Что делает.** Если сумма новой сделки вне коридора, заданного в карточке контрагента (поля «минимальный» и
«максимальный» лимит), в следующий шаг сделки пишется предупреждение.

```json
{
  "name": "Сделка вне лимитов контрагента",
  "object_type": "Opportunities",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [
      { "field": "object.amount", "operator": "not_between", "value_type": "from_object_field",
        "value": ["v1.limit_min_c", "v1.limit_max_c"], "cast": "decimal" }
    ],
    "preload": [ { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "name", "value": "object.account_name_c" } ],
    "actions": [
      { "type": "modify", "target": "object.next_step", "change_type": "template",
        "value": "Сумма {{ object.amount | number:0 }} вне лимита {{ v1.limit_min_c }}–{{ v1.limit_max_c }}" },
      { "type": "save_object", "target": "object", "module": "Opportunities" }
    ]
  }
}
```

`limit_min_c` и `limit_max_c` — пользовательские поля контрагента (подставьте свои; в проверенном кейсе коридор хранится
в стандартных `employees` и `annual_revenue`). Контрагент ищется предзагрузкой по имени из поля сделки
`account_name_c`: используйте поле сделки, в котором действительно лежит имя или id контрагента (например, своё поле
`account_id_c` с `"by_field": "id"`). Стандартное `Opportunities.account_id` в SuiteCRM — relate-поле без колонки: в
событии сделки его, скорее всего, нет, а фильтр по нему отклоняется (`422`).

Границы — пути, поэтому контрагент загружается ленивой предзагрузкой при проверке условия. Если контрагент не найден
или у него не заполнен лимит, условие **ложно** (не «вне коридора»); если минимальный лимит больше максимального,
условие ложно, а в трассе — `range_inverted`.

**Как проверить.** Задайте контрагенту коридор 100–500 и создайте сделки на 700 и на 300: процесс запускается только
для первой. Ручной запуск показывает трассу условия с границами `expected: ["100", "500"]`.

Проверено на SuiteCRM 8: кейс 3.6 (коридор хранится в полях `employees` и `annual_revenue` тестового контрагента) —
«Сумма 700 вне лимита 100–500», сделка на 300 процесс не запускает.


## 45. Продление договора в ближайшие 30 дней (относительные даты)

**Что делает.** Когда договор подписывают, а до его окончания осталось не больше 30 дней, создаёт ответственному задачу
«Продлить договор» и указывает, сколько задач по договору уже просрочено.

```json
{
  "name": "Договор скоро истекает",
  "object_type": "AOS_Contracts",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [
      { "field": "object.end_date", "operator": "between", "cast": "date", "value_type": "static", "value": ["today", "today+30d"] },
      { "field": "object.status", "operator": "changed_to", "value_type": "static", "value": "Signed" }
    ],
    "preload": [],
    "actions": [
      { "type": "find_records", "var": "overdue", "module": "Tasks", "filter": [
        { "field": "parent_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" },
        { "field": "date_due", "operator": "less_than", "value": "today", "cast": "datetime" } ] },
      { "type": "modify", "target": "task.name", "change_type": "template", "value": "Продлить договор (просрочено задач: {{ overdue.count }})" },
      { "type": "modify", "target": "task.date_due", "change_type": "date_shift", "base": "today", "shift": "+3 day", "set_time": "12:00" },
      { "type": "modify", "target": "task.assigned_user_id", "change_type": "set_from_variable", "value": "object.assigned_user_id" },
      { "type": "modify", "target": "task.parent_type", "change_type": "set_value", "value": "AOS_Contracts" },
      { "type": "modify", "target": "task.parent_id", "change_type": "set_from_variable", "value": "object.id" },
      { "type": "save_object", "target": "task", "module": "Tasks" }
    ]
  }
}
```

`["today", "today+30d"]` — с сегодняшнего дня по день «сегодня + 30» включительно, в `WPS_TIMEZONE`. Фильтр
`date_due less_than today` уходит в CRM как полночь сегодняшнего дня по `WPS_TIMEZONE` в UTC. Модуль `AOS_Contracts`
должен быть в `WPS_EVENT_MODULES` плагина.

**Как проверить.** Подпишите договор с окончанием через 20 дней — задача создана; через 60 дней — процесс не
запускается. В трассе условия — `expected_resolved` (вычисленные границы) и `timezone`.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/3.7-relative-dates.json` (там условие
`less_than today+30d`) — «Продлить договор (просрочено задач: 2)», договор на 60 дней процесс не запускает.

## 46. Обращение, созданное за последние сутки, стало срочным (`now-24h`)

**Что делает.** Если обращению ставят приоритет P1 в первые 24 часа после создания, ответственный получает уведомление в
колокольчике CRM — клиент, вероятно, ждёт ответа прямо сейчас.

```json
{
  "name": "Свежее обращение стало P1",
  "object_type": "Cases",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [
      { "field": "object.priority", "operator": "changed_to", "value_type": "static", "value": "P1" },
      { "field": "object.date_entered", "operator": "greater_or_equal", "cast": "datetime", "value_type": "static", "value": "now-24h" }
    ],
    "preload": [],
    "actions": [
      { "type": "notify", "title": "Срочное обращение", "message": "{{ object.name }}: приоритет P1 в первые сутки",
        "link": { "record": "object" },
        "channels": [ { "channel": "crm_alert", "users": ["{{ object.assigned_user_id }}"] } ] }
    ]
  }
}
```

`now-24h` — ровно 24 часа назад (точное время, переход на летнее время не влияет); `date_entered` из CRM хранится в UTC.
Для «с начала сегодняшнего дня» используйте `today`, для «за последние 7 календарных дней» — `today-7d`.

**Как проверить.** Поставьте P1 только что созданному обращению — уведомление есть; обращению недельной давности — нет
(ручной запуск покажет `expected_resolved`). Рецепт на стенде не запускался; `now-24h` проверен интеграционным тестом
`[relative dates]`.

## 47. Выигранные сделки менеджера в этом квартале (`between` с относительными датами)

**Что делает.** Когда сделку выигрывают, считает выигранные сделки ответственного за текущий квартал и их сумму и пишет
итог в следующий шаг сделки.

```json
{
  "name": "Сделки менеджера за квартал",
  "object_type": "Opportunities",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.sales_stage", "operator": "changed_to", "value_type": "static", "value": "Closed Won" } ],
    "preload": [],
    "actions": [
      { "type": "set_variable", "var": "q", "fields": [
        { "name": "count", "change_type": "aggregate", "function": "count",
          "source": { "module": "Opportunities", "filter": [
            { "field": "assigned_user_id", "operator": "equals", "value_type": "from_object_field", "value": "object.assigned_user_id" },
            { "field": "sales_stage", "operator": "equals", "value": "Closed Won" },
            { "field": "date_closed", "operator": "between", "cast": "date", "value": ["start_of_quarter", "end_of_quarter"] } ] } },
        { "name": "total", "change_type": "aggregate", "function": "sum", "field": "amount",
          "source": { "module": "Opportunities", "filter": [
            { "field": "assigned_user_id", "operator": "equals", "value_type": "from_object_field", "value": "object.assigned_user_id" },
            { "field": "sales_stage", "operator": "equals", "value": "Closed Won" },
            { "field": "date_closed", "operator": "between", "cast": "date", "value": ["start_of_quarter", "end_of_quarter"] } ] } }
      ] },
      { "type": "modify", "target": "object.next_step", "change_type": "template",
        "value": "В этом квартале у менеджера выиграно сделок: {{ q.count }} на {{ q.total }}", "save_to_crm": true }
    ]
  }
}
```

`between` с относительными границами уходит в CRM как `filter[date_closed][gte]=<начало квартала>&filter[date_closed][lte]=<конец
квартала>`; кварталы считаются по `WPS_TIMEZONE`. Пустой `assigned_user_id` у сделки — агрегат без запроса (`0`).

**Как проверить.** Выиграйте сделку менеджера: в следующем шаге — число и сумма его выигранных сделок квартала; в
журнале `set_variable` у полей видно `total` (подсчёт одним запросом) и `records_scanned`. Рецепт на стенде не
запускался; `between` квартала проверен юнит-тестом `RelativeDateTest`, агрегат — кейсом 5.1.7.


## 48. Московский лид (без учёта регистра и пробелов)

**Что делает.** Лид из Москвы с сайта или от партнёра получает пометку в описании — как бы ни был набран город:
«Москва», «МОСКВА», « москва ».

```json
{
  "name": "Московский лид",
  "object_type": "Leads",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [
      { "field": "object.primary_address_city", "operator": "equals", "value": "Москва",
        "cast": "string", "cast_options": { "case_sensitive": false, "trim": true } },
      { "field": "object.lead_source", "operator": "in", "value": ["WEB SITE", "Partner"],
        "cast": "string", "cast_options": { "case_sensitive": false, "trim": true } }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.description", "change_type": "set_value", "value": "Московский лид с сайта или от партнёра", "save_to_crm": true }
    ]
  }
}
```

Параметры задаются в `cast_options`, а не рядом с `cast`. Для процесса «город изменился» (`changed` с теми же
параметрами) правка «МОСКВА» → «москва» изменением не считается.

**Как проверить.** Создайте лид с городом «  МОСКВА » и источником `Web Site`: в трассе условия `actual_cast: "москва"`,
описание заполнено.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/3.8-case-insensitive.json` (там же процесс
«город изменился»).


## 49. КП с метками vip или partner, но без spam (мультисписок)

**Что делает.** Коммерческое предложение (`AOS_Quotes`), у которого в мультисписке есть метка `vip` или `partner` и нет
`spam` / `test`, получает пометку «Важное КП».

```json
{
  "name": "Важное КП",
  "object_type": "AOS_Quotes",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create", "update"],
  "definition": {
    "conditions": [
      { "field": "object.template_ddown_c", "operator": "contains_any", "value": ["vip", "partner"],
        "cast": "list", "cast_options": { "wrapper": "^", "case_sensitive": false } },
      { "field": "object.template_ddown_c", "operator": "contains_none", "value": "^spam^,^test^",
        "cast": "list", "cast_options": { "wrapper": "^" } }
    ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.description", "change_type": "set_value", "value": "Важное КП", "save_to_crm": true }
    ]
  }
}
```

`template_ddown_c` — стандартный мультисписок КП в SuiteCRM; для своего поля подставьте его имя. `wrapper: "^"` разбирает
`^VIP^,^CRM^` в `[VIP, CRM]`; правую часть `contains_none` можно вставить прямо в формате CRM. В отличие от `contains`,
`^vipclient^` метку `vip` не содержит. Модуль `AOS_Quotes` должен быть в `WPS_EVENT_MODULES` плагина. Сохранение
описания вызывает новое событие `update` и повторную проверку: она пройдёт, но описание уже то же — добавьте условие
`object.description not_equals "Важное КП"`, если повторная запись нежелательна.

**Как проверить.** Создайте КП с метками `^VIP^,^CRM^` — описание «Важное КП», в трассе `matched: ["vip"]`; с
`^partner^,^spam^` или `^vipclient^` — процесс не запускается.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/3.9-list-operators.json` (там же `contains_all`).


## 50. Сделка сверх 80 % кредитного лимита контрагента (`value_type: expression`)

**Что делает.** Если сумма новой сделки больше 80 % кредитного лимита контрагента, в следующий шаг пишется «Согласовать».

```json
{
  "name": "Сделка сверх 80 % лимита",
  "object_type": "Opportunities",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [
      { "field": "object.amount", "operator": "greater_than", "cast": "decimal",
        "value_type": "expression", "value": "coalesce(v1.credit_limit_c, 0) * 0.8" }
    ],
    "preload": [ { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "name", "value": "object.account_name_c" } ],
    "actions": [
      { "type": "modify", "target": "object.next_step", "change_type": "template",
        "value": "Согласовать: сумма больше 80 % лимита {{ v1.credit_limit_c }}", "save_to_crm": true }
    ]
  }
}
```

`credit_limit_c` — поле лимита контрагента, `account_name_c` — поле сделки с именем контрагента (подставьте свои; в
проверенном кейсе лимит хранится в `annual_revenue`, а имя — в `description` сделки). Стандартное
`Opportunities.account_id` не подходит: в SuiteCRM это relate-поле без колонки (см. рецепт 44). Контрагент
загружается ленивой предзагрузкой при проверке условия. Без `coalesce` пустой лимит дал бы `null` и ложное условие;
с `coalesce(…, 0)` любая сделка при пустом лимите считается сверх лимита — выберите поведение, нужное бизнесу.

**Как проверить.** Лимит 100 000: сделка на 90 000 — процесс запускается (в трассе `expected = 80000`), на 70 000 — нет.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/3.10-expression-value.json`.

## 51. Сделка в коридоре ±10 % от плана (`between` с выражениями)

**Что делает.** Сделка, сумма которой отличается от плановой суммы контрагента не больше чем на 10 %, помечается
источником «Existing Customer».

```json
{
  "name": "Сделка по плану",
  "object_type": "Opportunities",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [
      { "field": "object.amount", "operator": "between", "cast": "decimal",
        "value_type": "expression", "value": ["v1.plan_amount_c * 0.9", "v1.plan_amount_c * 1.1"] }
    ],
    "preload": [ { "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "name", "value": "object.account_name_c" } ],
    "actions": [
      { "type": "modify", "target": "object.lead_source", "change_type": "set_value", "value": "Existing Customer", "save_to_crm": true }
    ]
  }
}
```

Обе границы — выражения; границы включаются. Пустой план даёт `null` у границ — условие ложно. В кейсе план хранится в
`employees` тестового контрагента.

**Как проверить.** План 50 000: сделка на 52 000 — процесс запускается (`expected = [45000, 55000]`), на 90 000 — нет.

Проверено на SuiteCRM 8: кейс 3.10.


## 52. Карточка контрагента: счётчик, история, рейтинг по отрасли (`increment`, `append`, `clear`, `map`)

**Что делает.** При смене тикера контрагента увеличивает счётчик, дописывает событие в историю в описании, очищает
устаревший код и выставляет рейтинг по отрасли.

```json
{
  "name": "Карточка контрагента",
  "object_type": "Accounts",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.ticker_symbol", "operator": "changed" } ],
    "preload": [],
    "actions": [
      { "type": "modify", "target": "object.employees", "change_type": "increment" },
      { "type": "modify", "target": "object.description", "change_type": "append",
        "value": "тикер {{ object.ticker_symbol }}", "separator": "; ", "max_length": 2000 },
      { "type": "modify", "target": "object.sic_code", "change_type": "clear" },
      { "type": "modify", "target": "object.rating", "change_type": "map", "source": "object.industry",
        "map": { "Banking": "Hot", "Technology": "Warm" }, "default": "Cold",
        "cast": "string", "cast_options": { "case_sensitive": false } },
      { "type": "save_object", "target": "object", "module": "Accounts" }
    ]
  }
}
```

`employees` здесь — просто числовое поле для счётчика; в своей CRM возьмите отдельное поле. Сохранение контрагента
вызывает новое событие `update`, но тикер в нём не меняется — процесс не зацикливается. `max_length` не даёт истории
переполнить поле: при превышении сохраняется конец (новые записи).

**Как проверить.** Смените тикер дважды (второй раз — вместе с отраслью на Other): счётчик +2, в описании две записи,
рейтинг Hot → Cold.

Проверено на SuiteCRM 8: кейс `.tehnical/suiteCRM8/processes/cases/5.1.3-change-types.json` (без `max_length`).

## 53. Приоритет обращения по рейтингу контрагента (`map`)

**Что делает.** Новое обращение получает приоритет по рейтингу его контрагента: Hot — P1, Warm — P2, остальные — P3.

```json
{
  "name": "Приоритет по рейтингу",
  "object_type": "Cases",
  "is_child": false,
  "is_active": true,
  "trigger_on": ["create"],
  "definition": {
    "conditions": [],
    "preload": [ { "var": "acc", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" } ],
    "actions": [
      { "type": "modify", "target": "object.priority", "change_type": "map", "source": "acc.rating",
        "map": { "Hot": "P1", "Warm": "P2", "": "P3" }, "default": "P3",
        "cast": "string", "cast_options": { "case_sensitive": false, "trim": true } },
      { "type": "save_object", "target": "object", "module": "Cases" }
    ]
  }
}
```

Ключ `""` срабатывает, когда рейтинг пуст, а также когда контрагент не найден (значения нет); `default` — для любого
другого рейтинга. У обращений `Cases.account_id` — настоящая колонка, поэтому предзагрузка по id работает. Для суммы или
даты используйте `mode: "ranges"` (например, сумма сделки → категория).

**Как проверить.** Создайте обращение для контрагента с рейтингом Hot — приоритет P1; без рейтинга — P3. В журнале шага
`modify` видны `old_value` и `new_value`. Рецепт на стенде не запускался; `map` проверен кейсом 5.1.3.

---

См. также: [Условия запуска](triggers-and-conditions.md), [Действия](actions.md),
[Типы изменений и выражения](change-types-and-expressions.md), [Ведомые процессы](subprocesses.md).

<!-- footer-nav -->
---

← Назад: [Пользователи и роли](users-and-roles.md) | [Оглавление](../README.md) | [Развёртывание](../04-operations/deployment.md) Далее →
<!-- /footer-nav -->
