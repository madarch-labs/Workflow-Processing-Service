# Действия

Что делает процесс, когда условия выполнены: загружает связанные объекты, меняет поля, сохраняет данные в CRM, ищет и массово меняет записи, обходит их в цикле, отправляет письма и уведомления, вызывает внешние API, связывает записи, назначает ответственных и запускает ведомые процессы.

<!-- lang-switch -->
[🇬🇧 English](../../en/03-user-guide/actions.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## Порядок выполнения

У блока **Блок 2. Действия** (выполняются последовательно) в редакторе три раздела:

| Раздел | Тип действия (`type`) | Назначение |
|---|---|---|
| **Предзагрузка** | `preload` (блок `definition.preload`) | Загрузить связанные объекты CRM в переменные |
| **Изменить объект / переменную** | `modify`, `save_object` | Изменить поля; сохранить переменную в CRM |
| **Запустить следующий процесс** | `start_process` | Асинхронно запустить ведомые процессы |

При выполнении каждая строка становится **шагом**, и шаги идут строго друг за другом:

1. все строки **Предзагрузки** сверху вниз;
2. строки **Изменить объект / переменную** в их порядке (порядок меняется кнопками со стрелками);
3. строки **Запустить следующий процесс** — редактор всегда ставит их в конец.

Если шаг завершился ошибкой, остальные шаги не выполняются (в аудите они отмечены как **Пропущен**), а выполнение заканчивается со статусом **Ошибка**. Временные ошибки (таймаут CRM, ответ CRM 5xx, сбой сети) сначала повторяются — см. [Выполнения и аудит](executions-and-audit.md).

Данные, с которыми работает процесс, хранятся в **переменных**:

- `object` — объект из события (запись, которую сохранили в CRM);
- переменные, загруженные **Предзагрузкой**, например `v1`;
- переменные, созданные **Изменением**, когда оно пишет в новое имя, например `task.name`.

К полям обращаются через точку: `object.phone`, `v1.contact_id`. Панель **Переменные** справа в редакторе показывает, что доступно в текущем месте.

## Предзагрузка

Загружает один объект из CRM в переменную. Типичный случай: событие пришло по сделке, а нужен её контрагент.

![Строка предзагрузки](../../images/screenshots/ru/06-process-edit-preload.png)

*Предзагрузка `v1`: объект `Accounts`, `id` которого берётся из `object.account_id`.*

| Колонка | Назначение | Значения | Пример |
|---|---|---|---|
| **Переменная** | Имя заполняемой переменной | латиница, цифры, `_`, не с цифры; не `object`; уникальное в процессе | `v1`, `account` |
| **Тип инициации** | Откуда берётся значение для поиска | **Из поля объекта** (`object_field`), **Из поля переменной** (`variable_field`), **Ручной ввод** (`manual`) | **Из поля объекта** |
| **Поле объекта / переменной / значение** | Значение для поиска: путь для первых двух источников, само значение для **Ручного ввода** | `object.account_id`; `v1.parent_id`; `123` | `object.account_id` |
| **Модуль (тип объекта)** | Модуль CRM, из которого загружать | латиница, цифры, `_`; выбрать из списка или ввести | `Accounts` |
| **Поле поиска** | По какому полю искать | `id` (по умолчанию) или любое имя поля | `id`, `inn` |

Как выполняется поиск:

- **Поле поиска** `id` → объект читается по идентификатору (`GET /{module}/{id}` в API CRM).
- Любое другое поле → поиск по этому полю (`GET /{module}?filter[<поле>]=<значение>`); если подходят несколько записей, **берётся первая** (**Поле поиска «id» — загрузка по идентификатору, иное — поиск по полю (берётся первая запись).**).
- **Из поля переменной** может ссылаться только на переменные из строк выше.
- **Из поля объекта** понимает сокращение `account_id` как `object.account_id`.

**Если ничего не найдено.** Если значение для поиска пустое или в CRM нет такого объекта, переменная становится пустой (`null`), а шаг всё равно успешен (в аудите: `"found": false`). Дальше:

- условия по этой переменной ложны;
- **Сохранить в CRM** для этой переменной будет **Пропущен** (`Variable "v1" is empty (object was not loaded) — nothing to save`);
- чтение её полей даёт пустое значение.

> **Внимание**
> **Изменение**, которое пишет в поле пустой переменной, превращает её в новый объект с одним этим полем и **без** `id`. Если затем сохранить такую переменную в CRM, в модуле предзагрузки будет создана **новая** запись (POST). Защищайте такие процессы условием по переменной, например `v1.id` **Не равен** *(пусто)*: оно ложно, если `v1` не найдена.

Ошибки при сохранении процесса:

| Сообщение | Причина |
|---|---|
| `Предзагрузка 1 (v1): некорректное имя переменной` / `Variable name is required (letters, digits, "_"; e.g. v1)` | Неверное имя |
| `имя «object» зарезервировано` / `Name "object" is reserved for the event object` | Имя `object` |
| `имя переменной уже используется` / `Variable "v1" is already defined` | Повтор имени |
| `Name "object_before" is reserved` (сервер) | Имя `object_before` — старые значения события, только для чтения |
| `Name "sys" is reserved` (сервер) | Имя `sys` — системная переменная (последняя обработанная ошибка), только для чтения |
| `укажите модуль` / `CRM module is required` | Модуль пустой или некорректный |
| `укажите значение` / `Value is required` / `Field path is required` | Нет значения для поиска |
| `путь должен начинаться с object.` / `For "from object field" the path must start with "object."` | Неверный путь для **Из поля объекта** |
| `переменная «v2» должна быть объявлена выше` / `Variable "v2" is not defined at this point` | **Из поля переменной** ссылается на более позднюю или неизвестную переменную |
| `For "from variable field" the path must reference a variable (e.g. v1.contact_id)` | **Из поля переменной** с путём `object.` |

### Предзагрузка внутри действий (только через API)

Шаг `preload` можно поставить и в `definition.actions` с теми же полями — например, чтобы загрузить объект уже после изменения. Редактор показывает такие строки без возможности правки (**Это действие не редактируется в табличном редакторе и будет сохранено без изменений**). Через API в строке предзагрузки можно явно задать `"strategy": "by_id"` или `"by_field"`; по умолчанию стратегия выбирается по **Полю поиска**.

```json
{ "type": "preload", "var": "v2", "source": "variable_field", "module": "Contacts", "by_field": "id", "value": "v1.contact_id" }
```

## Изменить объект / переменную

Меняет одно поле объекта события или переменной **в памяти процесса**. CRM не затрагивается, если в этой строке не отмечено **Сохранить в CRM** и после неё нет отдельной строки **Сохранить в CRM**.

![Строки изменения](../../images/screenshots/ru/07-process-edit-modify.png)

*Две строки с типом **Расчёт** и строка **Сохранить в CRM** для `object`.*

| Колонка | Назначение | Значения | Пример |
|---|---|---|---|
| **Объект / переменная (target)** | Поле, в которое пишем | `object.<поле>` или `<переменная>.<поле>`; новое имя переменной создаёт новый объект | `object.phone`, `v1.rating`, `task.name` |
| **Тип изменения** | Как вычисляется новое значение | **Равен значению**, **Равен переменной**, **Расчёт** | **Равен значению** |
| **Новое значение** | Значение, путь к источнику или выражение — зависит от типа изменения | см. [Типы изменений и выражения](change-types-and-expressions.md) | `VIP` |
| **Сохранить в CRM** | Сохранить переменную в CRM сразу после этого изменения (то же, что строка **Сохранить в CRM**) | **Да** / не отмечено | отмечено |

Строка добавляется кнопкой **Изменение**. Аудит хранит `old_value` и `new_value` каждого изменения.

Запись в переменную, которой ещё нет (например, `task.name`), создаёт её; панель **Переменные** помечает её как **создана в процессе**. У такой переменной нет модуля — укажите его в строке **Сохранить в CRM**.

Ошибки: `Действие 1: укажите поле назначения (object.поле или v1.поле)`, `Действие 1: укажите значение` (для **Равен переменной** и **Расчёт**); сервер: `"object" must point to a field, e.g. object.name`, `Change type must be one of: set_value, set_from_variable, calculate`, `Must be true or false` (`save_to_crm`), `Variable "object_before" is read-only` (цель `object_before.*`; то же для `save_object`).

## Сохранить в CRM

Кнопка **Сохранить в CRM** в разделе **Изменить объект / переменную** добавляет строку типа `save_object`. Она отправляет изменённые поля переменной в CRM.

| Колонка | Назначение | Значения | Пример |
|---|---|---|---|
| **Объект / переменная (target)** (подсказка **Переменная**) | Что сохранять: `object` или любую переменную | имя переменной | `object`, `v1`, `task` |
| **Модуль (для новых переменных, необязательно)** | Модуль CRM; нужен только для переменных, созданных в процессе | имя модуля | `Tasks` |

Что происходит:

| Ситуация | Результат |
|---|---|
| У переменной есть `id` и изменённые поля | `PUT /{module}/{id}` **только с изменёнными полями**; шаг **Успех** |
| У переменной есть `id`, но ничего не менялось | Шаг **Пропущен**: `No changed fields to save` |
| У переменной нет `id` (создана в процессе или не найдена предзагрузкой и потом изменена) | `POST /{module}` со всеми полями — новая запись; новый `id` записывается обратно в переменную |
| Переменная пустая (предзагрузка ничего не нашла) | Шаг **Пропущен**: `Variable "v1" is empty (object was not loaded) — nothing to save` |
| Модуль неизвестен | Шаг **Ошибка**: `Cannot save "task": CRM module is unknown (set "module" in the save action)` |

Модуль берётся из строки, а если там пусто — из переменной: для `object` это модуль события, для переменной предзагрузки — её модуль.

После успешного сохранения у переменной нет несохранённых изменений, поэтому повторное сохранение без новых изменений будет пропущено.

Через API можно отправлять все поля, а не только изменённые: `"mode": "full"` (по умолчанию `"changed"`).

```json
{ "type": "save_object", "target": "task", "module": "Tasks" }
```

Ответы CRM: 5xx, таймауты и сетевые ошибки повторяются; 4xx (ошибка валидации в CRM, нет записи при PUT) сразу завершают шаг ошибкой.

Ошибки: `Действие 2: укажите сохраняемую переменную`; сервер: `Invalid CRM module`, `Mode must be "changed" or "full"`, `Variable "x" is not defined at this point`.

## Запустить следующий процесс

Асинхронно запускает один или несколько **ведомых** процессов; текущий процесс их не ждёт. Ведомый процесс получает то же событие и все переменные. Подробности, ограничения и примеры — в разделе [Ведомые процессы](subprocesses.md).

```json
{ "type": "start_process", "process_id": 4 }
```

## Отправить письмо

`send_email` отправляет **одно письмо** всем получателям (`to`, `cc`, `bcc`) через SMTP самого WPS (библиотека
`symfony/mailer`). Тема, тела, заголовки и имена получателей — [шаблоны](change-types-and-expressions.md#шаблон)
с подстановками. Отправка проходит через журнал побочных эффектов, поэтому шаг, повторённый после падения воркера, письмо
второй раз не отправляет.

```json
{
  "id": "welcome", "type": "send_email",
  "to": ["{{ object.email1 }}"],
  "cc": [{ "path": "owner.email1", "name": "{{ owner.first_name }} {{ owner.last_name }}" }],
  "bcc": ["audit@company.ru"],
  "from": { "email": "crm@company.ru", "name": "CRM" },
  "subject": "Сделка {{ object.name }}: {{ object.sales_stage }}",
  "html": "<p>Сумма: <b>{{ object.amount | number:2:',':' ' }}</b></p>",
  "headers": { "X-Deal": "{{ object.id }}" },
  "save_to_crm": { "module": "Emails", "parent": "object" }
}
```

### Поля

| Поле | Обязательно | Описание |
|---|---|---|
| `transport` | нет | Ссылка на секрет `secret://name` вида `smtp_dsn` со строкой подключения (`smtp://user:pass@host:587`, `smtps://…`). Не задано — транспорт по умолчанию `MAILER_DSN`. Нет ни того, ни другого — ошибка сохранения процесса |
| `from` | нет | `"crm@company.ru"` или `{ "email": …, "name": "<шаблон>" }`. По умолчанию — `MAILER_FROM` / `MAILER_FROM_NAME`. Если задан `MAILER_ALLOWED_FROM` (адреса или домены `@company.ru`), другой отправитель не сохраняется — защита от подделки |
| `to` | да | Список получателей, хотя бы один (формы — ниже) |
| `cc`, `bcc`, `reply_to` | нет | Списки получателей |
| `subject` | да | Шаблон; переводы строк заменяются пробелом, длина — до 998 символов. Пустая тема после подстановки → `(no subject)` и предупреждение |
| `text` | `text` или `html` | Шаблон текстовой части; значения подставляются как есть |
| `html` | | Шаблон HTML-части: подставленные значения **экранируются** (`<script>` из поля CRM приходит текстом). Есть только `html` — текстовая часть строится из него автоматически |
| `headers` | нет | Дополнительные заголовки `{ "X-Имя": "<шаблон>" }`. Нельзя задавать `From`, `To`, `Cc`, `Bcc`, `Subject`, `Reply-To`, `Sender`, `Return-Path`, `Message-ID`, `MIME-Version`, `Content-*`; переводы строк в значениях удаляются |
| `on_empty_recipients` | нет | Что делать, если после проверки адресов не осталось ни одного получателя: `skip` (по умолчанию — шаг `skipped` с причиной `No valid recipients`) или `fail` (шаг завершается ошибкой) |
| `save_to_crm` | нет | После отправки создать запись истории письма в CRM (ниже) |
| `delivery` | нет | `at_most_once` (по умолчанию) или `at_least_once`, см. [Надёжность → Побочные эффекты и повторы](../04-operations/reliability.md#побочные-эффекты-и-повторы) |

Пока не поддерживаются: `attachments` (вложения) и `template` (шаблоны писем CRM `EmailTemplates`) — такие поля
отклоняются при сохранении.

### Получатели

| Форма | Пример | Как разрешается |
|---|---|---|
| Адрес | `"sales@company.ru"` | Как есть; неверный статический адрес — ошибка сохранения |
| Шаблон | `"{{ object.email1 }}"` | Результат подстановки: один адрес или несколько через `,` / `;` |
| Путь | `{ "path": "owner.email1", "name": "{{ owner.last_name }}" }` | Значение поля; `name` — шаблон имени получателя |
| Путь на список | `{ "path": "team.emails" }` | Массив адресов или строка с `,` / `;` |

Правила:

- адрес обрезается, домен приводится к нижнему регистру (кириллические домены поддерживаются);
- **неверный или пустой адрес из данных не делает шаг ошибочным**: адрес пропускается, в результате шага появляется
  предупреждение `invalid_recipients` с причиной `invalid` или `missing`;
- повторы убираются: адрес, уже стоящий в `to`, не добавляется в `cc` и `bcc`;
- получателей в одном письме — не больше `MAILER_MAX_RECIPIENTS` (50), иначе шаг завершается ошибкой
  `Too many recipients (N > 50)` — защита от рассылки «всем» по ошибке.

**Письмо ответственному.** Отдельного «пользовательского» типа получателя нет: загрузите пользователя предзагрузкой и
укажите путь на его адрес.

```json
{
  "preload": [{ "var": "owner", "source": "object_field", "module": "Users", "by_field": "id", "value": "object.assigned_user_id" }],
  "actions": [{
    "type": "send_email",
    "to": [{ "path": "owner.email1", "name": "{{ owner.first_name }} {{ owner.last_name }}" }],
    "subject": "Вам назначен лид {{ object.last_name }}",
    "text": "Лид: {{ object.first_name }} {{ object.last_name }}\nТелефон: {{ object.phone_work | default:'—' }}"
  }]
}
```

Основной адрес людей и компаний SuiteCRM (`email1`) плагин 1.2.0 читает и отдаёт как обычное поле записи, см.
[Интеграция с CRM](../04-operations/crm-integration.md).

### Отправка и повторы

- **Message-ID** письма постоянен для шага: `<{execution_id}.{позиция шага}@WPS_MAIL_MESSAGE_ID_DOMAIN>`, например
  `<6c1e….0@wps.local>`. Почтовые системы по нему отсекают дубликаты. Дополнительно ставится `X-WPS-Execution-Id`.
- Шаг, повторённый после падения воркера, находит в журнале отметку «отправлено» и письмо не отправляет
  (`result.replayed: true`).
- Ошибки транспорта:

| Ошибка | Что происходит |
|---|---|
| Не удалось подключиться, таймаут до передачи письма | Повтор по [профилю `retry`](#профиль-retry) |
| Временный отказ SMTP `4xx` (421, 450, 451, 452) | Повтор (`SMTP_4xx`) |
| Постоянный отказ SMTP `5xx` (550 нет ящика, 553, 554) | Шаг завершается ошибкой без повторов (`SMTP_5xx`) |
| Ошибка аутентификации (530, 534, 535) | Ошибка конфигурации `Mail transport authentication failed` (`SMTP_AUTH`), без повторов |
| Обрыв соединения **после** передачи письма (команда `DATA` принята) | `at_most_once` — шаг завершается ошибкой `UNKNOWN_OUTCOME` и не повторяется (письмо могло уйти); `at_least_once` — повтор |

- **Лимиты частоты:** не больше `MAILER_RATE_PER_MINUTE` (60) писем в минуту на транспорт — сверх лимита шаг
  повторяется (`MAIL_RATE_LIMIT`); не больше `MAILER_RATE_PER_RECIPIENT_HOUR` (10) писем в час одному получателю —
  сверх лимита получатель пропускается (`result.rate_limited`). Лимит на получателя защищает от процессов, которые по
  ошибке шлют письмо при каждом сохранении записи; надёжнее — запуск по [изменению поля](triggers-and-conditions.md).

### История письма в CRM

`"save_to_crm": { "module": "Emails", "parent": "object" }` после отправки создаёт запись в CRM. По умолчанию
передаются поля, подходящие для SuiteCRM `Emails`: `name` (тема), `description`, `description_html`, `from_addr`,
`to_addrs`, `cc_addrs`, `bcc_addrs`, `status: sent`, `type: out`, `date_sent_received`, `message_id`, а для `parent` —
`parent_type` (модуль переменной) и `parent_id`. Поля, которых нет в таблице модуля (в SuiteCRM тело и адреса лежат в
отдельной таблице), CRM пропускает.

Для другой CRM задайте карту полей — значения являются шаблонами, `mail.*` — данные отправленного письма (`subject`,
`text`, `html`, `from`, `to`, `cc`, `bcc`, `message_id`):

```json
"save_to_crm": { "module": "Emails", "fields": { "name": "{{ mail.subject }}", "parent_id": "{{ object.id }}" } }
```

Ошибка создания истории шаг **не** валит (письмо уже ушло): шаг `success`, в результате — `crm_history_error`.

### Аудит

Запрос шага: `transport`, `from`, `to`, `cc`, `bcc` — **только количество** (`["***2 hidden***"]`), `subject`,
`text_preview` (первые 500 символов), `html_size`, `message_id`. Пароль из строки подключения маскируется.
Результат: `sent`, `message_id`, `recipients`, `replayed`, при необходимости `invalid_recipients`, `rate_limited`,
`crm_history_id` / `crm_history_error`.

### Пример: приветственное письмо новому лиду

```json
{
  "trigger_on": ["create"],
  "definition": {
    "conditions": [{ "field": "object.email1", "operator": "is_not_empty" }],
    "actions": [{
      "id": "welcome", "type": "send_email",
      "to": ["{{ object.email1 }}"],
      "cc": ["sales-lead@company.ru"],
      "from": { "email": "crm@company.ru", "name": "CRM WPS" },
      "subject": "Добро пожаловать, {{ object.first_name }}",
      "html": "<p>Здравствуйте, <b>{{ object.first_name | default:'клиент' }}</b>!</p><p>Компания: {{ object.account_name | default:'—' }}</p>",
      "save_to_crm": { "module": "Emails", "parent": "object" }
    }]
  }
}
```

Результат на SuiteCRM 8 (кейс `.tehnical/suiteCRM8/processes/cases/4.1-send-email.json`): письмо на адрес лида и в
копию, текстовая часть построена из HTML, в CRM — запись `Emails` со статусом `sent`, связанная с лидом. Если у лида нет
адреса, он пропускается с предупреждением `missing`, а письмо уходит только в копию.

### Проверка настроек почты

`POST /api/mail/test` (администратор) с телом `{ "to": "you@company.ru", "transport": "secret://smtp_main" }` отправляет
тестовое письмо и возвращает `sent: true` или текст ошибки SMTP. На стенде все письма перехватывает
[Mailpit](../04-operations/deployment.md#сервисы): `http://localhost:8025`.

### Ошибки сохранения

| Ошибка | Поле |
|---|---|
| `At least one recipient is required` | `…to` |
| `Recipient must be an email, a template or {"path": ...}`, `Invalid email address "abc"` | `…to[i]`, `…cc[i]`, … |
| `Variable "owner" is not defined at this point` | `…to[i].path` |
| `Subject is required` | `…subject` |
| `Email body is required (text or html)` | шаг |
| `Unknown template filter "foo"` и другие ошибки шаблона | `…subject`, `…text`, `…html`, `…headers.*` |
| `Sender must be an email or {"email": ..., "name": ...}`, `Sender address is not allowed`, `Sender is required (set "from" or MAILER_FROM)` | `…from` |
| `Transport must be a secret reference (secret://name)`, `No mail transport configured (set MAILER_DSN or "transport")`, `Secret must be of kind smtp_dsn, got …`, `Secret "x" does not exist` | `…transport` |
| `Header "From" cannot be set here`, `Invalid header name` | `…headers.<имя>` |
| `Must be "skip" or "fail"` | `…on_empty_recipients` |
| `Attachments are not supported yet`, `CRM e-mail templates are not supported yet` | `…attachments`, `…template` |
| `Invalid CRM module`, `Variable "x" is not defined at this point` | `…save_to_crm.module`, `…save_to_crm.parent` |

Ошибки выполнения: `Email body is empty`, `No valid recipients` (при `on_empty_recipients: fail`),
`Too many recipients (N > 50)`, ошибки транспорта из таблицы выше.

## HTTP-запрос

`http_request` вызывает внешний API или вебхук: URL, параметры, заголовки и тело — [шаблоны](change-types-and-expressions.md#шаблон)
с подстановками, учётные данные — только из [секретов](secrets.md), ответ можно сохранить в переменную и разобрать
JSONPath. Запрос проходит через журнал побочных эффектов: шаг, повторённый после падения воркера, не отправляет запрос
второй раз (см. [Повторы и неизвестный исход](#повторы-и-неизвестный-исход)). Адрес проверяется SSRF-защитой —
см. [Безопасность → Исходящие HTTP-запросы](../04-operations/security.md#исходящие-http-запросы-ssrf-защита).

```json
{
  "id": "to_erp", "type": "http_request", "var": "erp", "method": "POST",
  "url": "https://erp.company.ru/api/orders/{{ object.id }}",
  "query": { "source": "crm" },
  "headers": { "X-Source": "WPS" },
  "auth": { "type": "bearer", "token": "secret://erp_token" },
  "body": { "type": "json", "value": {
    "number": "{{ object.name }}", "amount": "{{= to_number(object.amount) }}",
    "customer": { "inn": "{{ account.inn_c }}" } } },
  "response": { "extract": { "order_id": "$.data.order_id", "status_text": "$.data.status" } },
  "timeout": 20
}
```

### Поля

| Поле | Обязательно | Описание |
|---|---|---|
| `var` | нет | Переменная, в которую кладётся ответ (ниже). Не задана — ответ виден только в аудите. Нельзя `object`, `object_before`, `sys` |
| `method` | да | `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE` |
| `url` | да | Шаблон. **Схема и хост — только литералом** до первой подстановки: `https://erp.company.ru/api/orders/{{ object.erp_id_c }}` — можно, `{{ object.webhook_url }}` и `https://{{ v1.host }}/…` — нет. Подстановки в путь и query кодируются (`a/b` → `a%2Fb`, новый сегмент пути не появится). Отсутствующее значение в URL — ошибка шага. Исключение — URL целиком из секрета вида `webhook_url`: `"url": "secret://slack_hook"` |
| `query` | нет | `{ "имя": "<шаблон>" }`, добавляется к query из `url`. Пустое значение передаётся пустым (`?a=`), отсутствующий путь — параметр пропускается |
| `headers` | нет | `{ "Имя": "<шаблон>" }`; переводы строк удаляются. Нельзя `Host`, `Content-Length`, `Transfer-Encoding`, `Connection` |
| `auth` | нет | Авторизация (ниже) |
| `body` | нет | Тело (ниже); для `GET`, `HEAD`, `DELETE` запрещено |
| `response.type` | нет | `auto` (по умолчанию: JSON, если `Content-Type` JSON, иначе текст), `json`, `text`, `none` (тело не сохраняется) |
| `response.extract` | нет | `{ "поле": "<JSONPath>" }` — поля переменной из тела ответа |
| `success_codes` | нет | `"2xx"` (по умолчанию) или список кодов и диапазонов: `["2xx", 404]`. Код вне списка — ошибка шага |
| `timeout` | нет | Общий таймаут, с: 1…`HTTP_MAX_TIMEOUT` (30). По умолчанию 30 |
| `connect_timeout` | нет | Таймаут подключения, с: 1…30. По умолчанию 5 |
| `follow_redirects` | нет | 0…5 (по умолчанию 0 — ответ 3xx возвращается как есть) |
| `delivery` | нет | `at_most_once` (по умолчанию для `POST` и `PATCH`) или `at_least_once` (по умолчанию для остальных), см. [Надёжность](../04-operations/reliability.md#побочные-эффекты-и-повторы) |
| `idempotency_header` | нет | Заголовок с ключом идемпотентности. По умолчанию `Idempotency-Key` для `POST`/`PATCH`, `null` — не отправлять. Значение — `{execution_id}:{позиция шага}`, постоянное для шага |
| `retry`, `on_error` | нет | [Ошибки шага](#ошибки-шага-on_error-и-повторы) |

### Тело запроса

| `body.type` | `value` | Content-Type |
|---|---|---|
| `json` (по умолчанию) | JSON-структура; строки-листья — шаблоны | `application/json` |
| `form` | `{ "имя": "<шаблон>" }` | `application/x-www-form-urlencoded` |
| `raw` | строка-шаблон | `body.content_type` (обязателен) |

В `json` лист, состоящий **целиком** из одной подстановки (`"{{ object.tags }}"`), получает исходное значение — массив
остаётся массивом, число — числом. Смешанный текст (`"Сделка {{ object.name }}"`) — строка. JSON собирается как
структура, а не склейкой строк, поэтому внедрить в него лишнее поле через значение из CRM нельзя. Поля CRM приходят
строками: `"{{ object.amount }}"` при `"1500.50"` даёт строку; число — явно, выражением
`"{{= to_number(object.amount) }}"` → `1500.5`. Определение процесса хранится в PostgreSQL как `jsonb`, поэтому ключи
объекта в теле могут прийти **в другом порядке** (для JSON это не важно; где порядок важен — см.
[Записать в переменную процесса](#записать-в-переменную-процесса)). Тело больше `HTTP_MAX_REQUEST_BYTES` (1 МБ) —
ошибка шага. `multipart` пока не поддерживается.

### Авторизация

| `auth.type` | Поля | Как применяется |
|---|---|---|
| `none` | — | по умолчанию |
| `bearer` | `token`: секрет (`bearer` / `generic`) | `Authorization: Bearer …` |
| `basic` | `credentials`: секрет `basic_auth` | `Authorization: Basic …` |
| `api_key` | `key`: секрет; `in`: `header` (по умолчанию) или `query`; `name` (по умолчанию `X-Api-Key`) | заголовок или параметр запроса |
| `oauth2_client_credentials` | `client`: секрет `oauth2_client` (`token_url`, `client_id`, `client_secret`, `scope`); `auth_style`: `header` (по умолчанию) или `body` | токен запрашивается по `client_credentials`, кэшируется в Redis до истечения (минус 60 с); одновременно токен запрашивает один воркер. Ответ `401` — токен сбрасывается, запрос повторяется **один раз** с новым |

Значения — **только ссылки `secret://name`**, литерал не сохраняется (`Credentials must reference a secret (secret://name)`).
Если у секрета задан `allowed_hosts`, запрос на другой хост завершается ошибкой
`Secret "x" is not allowed for host "y"`. `token_url` секрета OAuth2 должен быть `https`. Значения секретов (и токен
OAuth2) маскируются в аудите, журнале и **в переменной ответа** — даже если внешний сервис вернул их в теле.

### Переменная ответа

```json
{ "status": 201, "ok": true,
  "headers": { "content-type": "application/json", "location": "…" },
  "body": { "data": { "order_id": "A-1042" } },
  "order_id": "A-1042", "status_text": "new" }
```

- `status`, `ok` (`2xx`), `headers` — имена в нижнем регистре, без `set-cookie` и заголовков авторизации.
- `body` — разобранный JSON; `body_text` — текст (для `response.type: text` или не-JSON ответа, перекодируется в UTF-8 по
  `charset`). Тело больше `HTTP_MAX_STORED_BODY_BYTES` (64 КБ) в переменную не кладётся: `body: null`,
  `body_truncated: true` (поля `extract` при этом считаются по полному телу).
- Поля `extract` — на верхнем уровне: `erp.order_id`. JSONPath в синтаксисе Гёсснера (`$.data.id`, `$.items[0]`,
  `$..id`, фильтры `[?(@.qty > 3)]`): ничего не нашлось → `null`, одно совпадение → значение, несколько → список.
  Нельзя называть поля `status`, `ok`, `headers`, `body`, `body_text`, `body_truncated`.
- Вложенные поля читаются обычным путём: `erp.body.data.items`.
- У переменной нет модуля CRM; чтобы сохранить её в CRM, укажите `module` в `save_object`.

Ответ больше `HTTP_MAX_RESPONSE_BYTES` (5 МБ) обрывается при чтении — ошибка `Response is too large`
(`RESPONSE_TOO_LARGE`). Невалидный JSON при `response.type: json` — ошибка `Response is not valid JSON`.

### Повторы и неизвестный исход

| Ситуация | Что происходит |
|---|---|
| Код из `success_codes` | `success` |
| Не удалось подключиться, DNS | Повтор по [профилю `retry`](#профиль-retry): запрос не уходил |
| `408`, `425`, `429`, `503` | Повтор; задержка учитывает `Retry-After` (секунды или HTTP-дата), но не больше `HTTP_RETRY_AFTER_MAX` (60 с) |
| `500`, `502`, `504` | Повтор, **только если** отправлен ключ идемпотентности или `delivery: at_least_once`; иначе `UNKNOWN_OUTCOME` без повтора — сервер мог обработать запрос |
| Таймаут или обрыв **после** отправки | `at_most_once` без ключа идемпотентности — `UNKNOWN_OUTCOME`, без повтора; иначе повтор |
| Прочие коды (`400`, `401`, `403`, `404`, `409`, `422`, `3xx` без редиректа) | Ошибка шага `HTTP 404 from erp.company.ru` (`HTTP_404`), без повторов; тело ответа (до 2 КБ) — в `details` |

Если воркер упал посреди запроса, после восстановления шаг находит в журнале незавершённую запись (`started`). При
`at_most_once` он не отправляет запрос повторно, а завершается `UNKNOWN_OUTCOME`. Если запись завершена (`done`),
ответ берётся из журнала, переменная восстанавливается, и запрос не повторяется (`result.replayed: true`).

### Аудит

Запрос шага: `method`, `url` (с query; секреты маскируются), список имён заголовков, `body_size`, `body_preview`
(500 символов), `delivery`. Результат: `status`, `duration_ms`, `var`, `replayed`, `response` (переменная; тело длиннее
2000 символов — превью `body_preview`).

### Пример: лид в ERP и номер заказа обратно в CRM

```json
{
  "trigger_on": ["create"],
  "definition": { "actions": [
    { "id": "to_erp", "type": "http_request", "var": "erp", "method": "POST",
      "url": "https://erp.company.ru/api/leads/{{ object.id }}",
      "idempotency_header": null,
      "body": { "type": "json", "value": { "name": "{{ object.first_name }} {{ object.last_name }}", "status": "{{ object.status }}", "crm_id": "{{ object.id }}" } },
      "response": { "extract": { "order_id": "$.data.order_id" } } },
    { "type": "modify", "target": "object.description", "change_type": "template",
      "value": "Заказ в ERP: {{ erp.order_id }} (HTTP {{ erp.status }})", "save_to_crm": true }
  ] }
}
```

Результат на SuiteCRM 8 (кейс `.tehnical/suiteCRM8/processes/cases/4.2-http-request.json`, внешняя система — mock
`/mock/echo`): описание лида «Заказ в ERP: A-1001 (HTTP 200)», система получила запрос один раз. Во втором сценарии
внешняя система отвечает через 8 с, и посреди запроса воркер WPS убивается (`SIGKILL`). После восстановления шаг не
повторяет запрос: выполнение `failed` с `UNKNOWN_OUTCOME`, запись журнала `GET /api/executions/{id}/side-effects` —
`started`, внешняя система получила запрос ровно один раз, описание лида не изменено. Чтобы в таком случае запрос
повторялся, внешняя система должна принимать `Idempotency-Key` (оставьте `idempotency_header` по умолчанию) или шаг
должен быть `delivery: at_least_once`.

### Ошибки сохранения

| Ошибка | Поле |
|---|---|
| `Method must be one of: GET, HEAD, POST, PUT, PATCH, DELETE` | `…method` |
| `URL is required`, `Scheme and host of the URL must be literal (https://host/...)`, `Plain http is not allowed (HTTP_ALLOW_PLAIN_HTTP=false)`, `Secret must be of kind webhook_url, got …`, ошибки шаблона, `Variable "v9" is not defined at this point` | `…url` |
| `Must be an object of name => template`, `Header "Host" cannot be set here` | `…query`, `…headers.<имя>` |
| `Must be one of: none, bearer, basic, api_key, oauth2_client_credentials`, `Credentials must reference a secret (secret://name)`, `Must be "header" or "query"` | `…auth.*` |
| `A body is not allowed for GET`, `Must be one of: json, form, raw`, `Content type is required for a raw body`, `Multipart body is not supported yet` | `…body.*` |
| `Must be one of: auto, json, text, none`, `Invalid field name (reserved: …)`, `JSONPath must start with "$"`, `Invalid JSONPath: …` | `…response.*` |
| `Must be "2xx" or a list of codes and ranges (200, "2xx")` | `…success_codes` |
| `Must be an integer between 1 and 30` / `… 0 and 5` | `…timeout`, `…connect_timeout`, `…follow_redirects` |
| `Must be a header name or null` | `…idempotency_header` |
| `Variable name is required (letters, digits, "_"; not object, object_before, sys)` | `…var` |

Ошибки выполнения: `Destination address is not allowed (private network)`, `Host "x" is not allowed`,
`Port N is not allowed`, `DNS lookup failed for "x"`, `Secret "x" is not allowed for host "y"`, `Response is too large`,
`Response is not valid JSON`, `HTTP 4xx from host`, `UNKNOWN_OUTCOME` — см. [Решение проблем](../04-operations/troubleshooting.md).

## Уведомление

Действие `notify` отправляет **короткое уведомление** ответственному или команде: в колокольчик CRM, в Telegram, Slack
или Microsoft Teams — одним шагом, в несколько каналов сразу. Для писем клиентам используйте
[Отправить письмо](#отправить-письмо): канала email в `notify` нет.

### Поля

```json
{ "type": "notify", "id": "notify_lead", "title": "Новый лид",
  "message": "Вам назначен лид {{ object.first_name }} {{ object.last_name }} ({{ object.lead_source | default:'источник не указан' }})",
  "link": { "record": "object" },
  "channels": [
    { "channel": "crm_alert", "users": ["{{ object.assigned_user_id }}"] },
    { "channel": "telegram", "connection": "secret://sales_bot", "chat_id": "-1001234567890" },
    { "channel": "slack", "connection": "secret://slack_sales" }
  ],
  "fail_on": "any" }
```

| Поле | Обязательно | Значение |
|---|---|---|
| `message` | да | [Шаблон](change-types-and-expressions.md#шаблон) текста. Длиннее 4000 символов — обрезается, в аудите предупреждение `Message was cut to 4000 characters`. Пустой после подстановок — ошибка шага `Notification message is empty` |
| `title` | нет | Шаблон заголовка: жирная первая строка в мессенджерах, `name` записи в колокольчике CRM (без заголовка — первые 80 символов текста) |
| `link` | нет | Ссылка «Открыть»: `{"record": "object"}` — на запись переменной (адрес строится по `CRM_RECORD_URL_TEMPLATE`), или `{"url": "https://…/{{ object.id }}"}` — шаблон адреса |
| `channels` | да | 1..10 каналов (ниже) |
| `fail_on` | нет | `any` (по умолчанию) — шаг `failed`, если упал хотя бы один канал; `all` — только если не доставлено никуда |
| `delivery` | нет | `at_most_once` (по умолчанию) или `at_least_once` — что делать с неизвестным исходом, см. [ниже](#повтор-без-дублей) |

Общие поля шага (`id`, `name`, `on_error`, `retry`) тоже действуют. Каналы отправляются по очереди; ошибка одного
канала не мешает остальным.

### Каналы

| Канал | Поля | Что отправляется |
|---|---|---|
| `crm_alert` — колокольчик CRM | `users` (обязательно, до 50): id пользователя, шаблон (`"{{ object.assigned_user_id }}"`) или `{"path": "team.ids"}` — путь к id или к списку id; пустые и повторы убираются. Необязательно: `module` (по умолчанию `Alerts`), `profile` (`suitecrm8` / `suitecrm7` / `custom`, по умолчанию `CRM_ALERT_PROFILE`), `fields` — словарь «поле CRM → шаблон», переопределяет поля | Запись `Alerts` на каждого получателя: `name` (заголовок), `description` (текст), `assigned_user_id`, `is_read = 0`, `type = info`, `target_module`, `url_redirect` (ссылка); для SuiteCRM 8 ещё `date_start`, `snooze`, `status`. Получатель увидит уведомление в колокольчике интерфейса CRM |
| `telegram` — бот | `connection` — секрет вида `telegram_bot` (или `generic`) с токеном; `chat_id` — id чата, группы или канала (`-100…`), можно шаблоном | `sendMessage` с `parse_mode: HTML`: `<b>заголовок</b>`, текст (подстановки экранируются как HTML), ссылка «Открыть». Токен — в пути URL, поэтому URL целиком маскируется в аудите и логах |
| `slack` — входящий вебхук | `connection` — секрет вида `webhook_url` (или `generic`) с URL вебхука | `text` (простой текст для уведомлений) и блок `mrkdwn` с `*заголовком*`, текстом (`&`, `<`, `>` экранируются) и ссылкой `<url\|Открыть>` |
| `teams` — вебхук Microsoft Teams | `connection` — секрет вида `webhook_url`; `format` — `adaptive_card` (по умолчанию, вебхуки Workflows) или `message_card` (устаревающие коннекторы Office 365) | Карточка с заголовком, текстом и кнопкой «Открыть» |

**Ссылки.** `{"record": "object"}` даёт ссылку на запись по шаблону `CRM_RECORD_URL_TEMPLATE` (по умолчанию по профилю:
SuiteCRM 8 — `#/leads/record/{id}`, SuiteCRM 7 — `index.php?module=Leads&action=DetailView&record={id}`). Колокольчик
CRM получает относительную ссылку, мессенджеры — абсолютную: к ней добавляется `CRM_UI_BASE_URL`. Если абсолютную
ссылку построить нельзя (не задан адрес интерфейса CRM), мессенджеры отправляются без кнопки «Открыть».

### Повтор без дублей

Каждая доставка — отдельная запись [журнала побочных эффектов](../04-operations/reliability.md#побочные-эффекты-и-повторы)
(`crm_alert` — по одной на получателя, мессенджер — одна на чат или вебхук). Если шаг выполняется повторно (повтор
после ошибки, восстановление после падения воркера), уже доставленное **не отправляется снова**: ответ берётся из
журнала (`replayed: true`).

| Ответ канала | Что происходит |
|---|---|
| `2xx` (Telegram — ещё `ok: true`) | доставлено |
| `408`, `425`, `429`, `503`, сеть до отправки | восстановимая ошибка: после обхода всех каналов шаг повторяется по политике повторов, `Retry-After` (у Telegram — `retry_after`) учитывается; доставленные каналы при повторе берутся из журнала |
| `500`, `502`, `504`, таймаут или обрыв после отправки | исход неизвестен: при `at_most_once` (по умолчанию) — `UNKNOWN_OUTCOME` без повтора (сообщение могло дойти); при `at_least_once` — повтор (возможен дубль) |
| прочие (`400`, `403`, `404`) | ошибка канала: `telegram: HTTP 403 Forbidden: bot was kicked`, без повтора |

У вебхуков мессенджеров нет ключей идемпотентности, поэтому по умолчанию WPS предпочитает не отправить, чем отправить
дважды.

**Результат шага.** `request_data`: `title`, `message_preview` (первые 500 символов), `link`, `channels` (`channel`,
`connection`, `chat_id`, `users`, `format` — без секретов), `delivery`. `result_data.channels` — по каналу:
`status` (`success` / `failed` / `skipped` — нет получателей), `deliveries` (`user` и `record` для CRM, `chat_id` и
`message_id` для Telegram, `status` для вебхуков, `replayed`), `error`, `error_code`; `warnings`. Если шаг упал,
текст ошибки — `notify: 1 of 3 channels failed: telegram: HTTP 403 …`, сводка — в `details`.

### Как подключить мессенджеры

1. **Telegram.** Создайте бота у [@BotFather](https://core.telegram.org/bots/features#botfather) и получите токен.
   Добавьте бота в группу или канал (в канал — администратором). `chat_id` группы можно узнать из ответа
   [`getUpdates`](https://core.telegram.org/bots/api#getupdates) после сообщения в группе. Сохраните токен секретом:
   `{"name": "sales_bot", "kind": "telegram_bot", "value": "123456:ABC…"}`.
2. **Slack.** Создайте [входящий вебхук](https://api.slack.com/messaging/webhooks) (Slack app → Incoming Webhooks →
   канал) и сохраните URL секретом вида `webhook_url`. Кто знает URL, может писать в канал, — поэтому только секрет.
3. **Microsoft Teams.** Создайте вебхук через Workflows («Post to a channel when a webhook request is received») —
   инструкция Microsoft: [Create incoming webhooks](https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook);
   URL — секрет вида `webhook_url`, `format: adaptive_card`. Старые коннекторы Office 365 — `format: message_card`.
4. **Сеть.** Запросы к мессенджерам проходят ту же защиту, что и [HTTP-запрос](#http-запрос): если задан белый список
   `HTTP_ALLOW_HOSTS`, добавьте в него `api.telegram.org`, `hooks.slack.com` и хост вебхука Teams
   (например, `*.logic.azure.com`) — см. [Конфигурация](../04-operations/configuration.md#исходящие-http-запросы-http_request).
5. **Проверка без процесса** — `POST /api/notify/test` (редакторы) с одним каналом:
   `{"channel": {"channel": "telegram", "connection": "secret://sales_bot", "chat_id": "-100…"}}` → `{"sent": true, …}`
   или `{"sent": false, "error": "…"}` ([API](../06-api-reference/endpoints.md#post-apinotifytest)).

### Пример: новый лид → колокольчик, Telegram и Slack

Определение — в [начале раздела](#уведомление). Результат на SuiteCRM 8 (кейс
`.tehnical/suiteCRM8/processes/cases/4.8-notify.json`, Telegram и Slack — на mock): в SuiteCRM 8 создана запись `Alerts`
у пользователя admin — «Новый лид», «Вам назначен лид Анна … (Web Site)», `target_module = Leads`,
`url_redirect = #/leads/record/{id}`, уведомление видно в колокольчике; в Telegram ушло
`<b>Новый лид</b>\nВам назначен лид …\n<a href="http://localhost:8801/#/leads/record/{id}">Открыть</a>`; в аудите виден
`chat_id` и `connection: secret://…`, токена бота нет.

### Ошибки сохранения

| Ошибка | Поле |
|---|---|
| `Message is required`, ошибки шаблона | `…message` |
| `Must be a template string`, ошибки шаблона | `…title` |
| `Use {"record": "<variable>"} or {"url": "<template>"}`, `Variable name is required (e.g. object)`, `Variable "x" is not defined at this point` | `…link`, `…link.record` |
| `From 1 to 10 channels are required` | `…channels` |
| `Unknown channel "sms"`, `Channel is required` | `…channels[i].channel` |
| `At least one user is required`, `User id, template or {"path": ...} is required` | `…channels[i].users` |
| `Invalid CRM module`, `Must be one of: suitecrm8, suitecrm7, custom`, `Must be a map "CRM field" → template`, `Profile "custom" needs "fields"` | `…module`, `…profile`, `…fields` |
| `Connection secret is required (secret://name)`, `Secret "x" has kind "bearer", expected telegram_bot or generic` | `…channels[i].connection` |
| `Chat id is required` | `…channels[i].chat_id` |
| `Must be adaptive_card or message_card` | `…channels[i].format` |
| `Must be "any" or "all"` | `…fail_on` |

## Записать в переменную процесса

`set_variable` (**Записать в переменную процесса**) сохраняет промежуточное значение в **локальную переменную** — она
не является записью CRM. В отличие от [Изменить объект / переменную](#изменить-объект--переменную), такая переменная
никогда не помечается изменённой и не может быть сохранена в CRM: подходит для подписей, сумм, счётчиков и других
расчётов, которые потом переносятся в запись одним `modify`.

### Формы

Одно поле — как `modify`:

```json
{ "type": "set_variable", "target": "calc.subject", "change_type": "template", "value": "Сделка {{ object.name }} на {{ object.amount }}" }
```

Несколько полей за один шаг — `var` + `fields`. **Список** вычисляется строго по порядку, следующее поле видит
предыдущее:

```json
{ "type": "set_variable", "var": "calc", "fields": [
  { "name": "rate", "change_type": "calculate", "value": "ifs(object.sales_stage == 'Negotiation/Review', 10, 0)" },
  { "name": "discount", "change_type": "calculate", "value": "round(percent(object.amount, calc.rate), 2)" },
  { "name": "net", "change_type": "calculate", "value": "object.amount - calc.discount" }
] }
```

`fields` можно задать и объектом `{"имя": {"change_type": …, "value": …}}`, но **порядок ключей объекта не
сохраняется**: определение хранится в PostgreSQL как `jsonb`, и PostgreSQL переставляет ключи (сначала короткие).
Объект подходит только для независимых полей; если поле ссылается на другое поле той же переменной, сохранение даёт
предупреждение `The order of object keys is not kept: use a list of fields when a field uses another field of "calc"`.

Работают все [типы изменения](change-types-and-expressions.md): `set_value`, `set_from_variable`, `calculate`,
`template`, `date_shift`.

### Как работает

- Переменной ещё нет — создаётся с источником `local` (`context.meta.<имя>.source = "local"`); есть и она `local` —
  поля перезаписываются и добавляются.
- Писать в переменную-запись CRM (`object`, предзагрузка, созданная `modify`, ответ `http_request`) нельзя:
  `Variable "acc" is a CRM record variable; use modify` при сохранении, `set_variable: "acc" is a CRM record variable;
  use modify` при выполнении.
- Поля не помечаются изменёнными. `save_object` локальной переменной и `modify … save_to_crm: true` с её полем
  отклоняются: `Variable "calc" is local (set_variable) and cannot be saved to the CRM` (при сохранении процесса и, если
  переменная пришла от родителя, при выполнении).
- `modify` поля локальной переменной разрешён и оставляет её локальной.
- Дочерний процесс получает локальные переменные вместе с остальными; в аудите шага — `request_data = {var, fields}`,
  `result_data.fields.<имя> = {old_value, new_value}`.

**Накопление.** Переменная сохраняется между шагами (и итерациями [цикла `foreach`](#цикл-для-каждого-элемента)), поэтому так считают суммы.
Инициализируйте её заранее: без начального значения первое `'' + 500` по правилу `+` склеивает строки и даёт `'500'`
(см. [Значения и типы](change-types-and-expressions.md#значения-и-типы)):

```json
{ "type": "set_variable", "target": "totals.sum", "change_type": "set_value", "value": "0" }
{ "type": "set_variable", "target": "totals.sum", "change_type": "calculate", "value": "totals.sum + item.amount" }
```

### Пример: скидка и итог сделки

Кейс `.tehnical/suiteCRM8/processes/cases/4.13-set-variable.json`: процесс по новым сделкам считает в `calc`
ставку скидки по стадии, сумму скидки, итог и подпись (`fields` списком), затем переносит подпись в сделку:

```json
{ "type": "set_variable", "var": "calc", "fields": [
  { "name": "rate", "change_type": "calculate", "value": "ifs(object.sales_stage == 'Negotiation/Review', 10, object.sales_stage == 'Proposal/Price Quote', 5, 0)" },
  { "name": "discount", "change_type": "calculate", "value": "round(percent(object.amount, calc.rate), 2)" },
  { "name": "net", "change_type": "calculate", "value": "object.amount - calc.discount" },
  { "name": "label", "change_type": "template", "value": "Скидка {{ calc.rate }}%: {{ calc.discount | number:2:',':' ' }} ₽, к оплате {{ calc.net | number:2:',':' ' }} ₽" }
] }
{ "type": "modify", "target": "object.next_step", "change_type": "set_from_variable", "value": "calc.label", "save_to_crm": true }
```

Сделка 1 234 567,80 в стадии Negotiation/Review получает в `next_step` «Скидка 10%: 123 456,78 ₽, к оплате
1 111 111,02 ₽»; сама `calc` в CRM не попадает.

### Ошибки сохранения

| Правило | Ошибка |
|---|---|
| нет ни `target`, ни `var` + `fields`, или есть оба | `Either "target" or "var" with "fields" is required` |
| `target` без поля | `"calc" must point to a field, e.g. calc.name` |
| неверное имя переменной / зарезервированное (`object`, `object_before`, `sys`, `config`) | `Invalid variable name "…"` / `Name "sys" is reserved` |
| переменная уже объявлена как запись CRM | `Variable "acc" is a CRM record variable; use modify` |
| `fields` пустой или не список / объект | `"fields" must be a non-empty list or object` |
| элемент списка без `name` | `Must be an object {"name": ..., "change_type": ..., "value": ...}` |
| неверное имя поля | `Invalid field name "…"` |
| неизвестный тип изменения | `Change type must be one of: …` (путь `…fields[k].change_type` / `…fields.<имя>.change_type`) |
| ошибки значения | как у типа изменения, с путём поля |
| `save_object` / `save_to_crm` для локальной переменной | `Variable "calc" is local (set_variable) and cannot be saved to the CRM` |

## Связать / отвязать записи

`link_records` («Связать записи») и `unlink_records` («Отвязать записи») создают и удаляют связи записи с другими
записями через link-поле её модуля CRM: участники встречи (`Meetings.users`), контакты контрагента
(`Accounts.contacts`), лиды звонка (`Calls.leads`). Связи не хранятся в переменных процесса — они пишутся сразу в CRM.

```json
{ "type": "link_records", "record": "meeting", "link": "users", "related_module": "Users",
  "related": [ { "path": "object.assigned_user_id" }, { "path": "team.ids" }, { "value": "1" } ],
  "fields": { "accept_status": "accept" }, "on_not_found": "warn" }

{ "type": "unlink_records", "record": "m", "link": "leads", "related": [ { "path": "object.id" } ] }
```

### Поля

| Поле | Обяз. | Описание |
|---|---|---|
| `record` | да | Переменная-запись, к которой привязываем: `object`, предзагрузка, созданная и сохранённая запись (`meeting` после `save_object`). Нужен её `id` |
| `module` | нет | Модуль записи. По умолчанию — из метаданных переменной: `object` — модуль события, предзагрузка — её модуль, созданная запись — модуль из `save_object` |
| `link` | да | Имя link-поля модуля записи (`users`, `contacts`, `leads`, `documents`, …). Задаётся явно — WPS не читает метаданные CRM |
| `related` | да | Список источников id. `{"path": "<переменная>.<поле>"}` — значение поля: строка, **список** (`team.ids`, разворачивается) или список записей (берётся их `id`); `{"value": "<id>"}` — литерал |
| `related_module` | нет | Модуль связанных записей (передаётся плагину в `type`); по умолчанию плагин берёт модуль из определения связи |
| `fields` | нет | Только `link_records`: поля связи, объект `имя → строковое значение` (`accept_status`, `required`, `contact_role`) |
| `on_not_found` | нет | CRM вернула id в `not_found`: `warn` (по умолчанию — шаг успешен, id в результате) или `error` (шаг падает) |
| `on_missing_record` | нет | У переменной `record` нет `id` (запись не сохранена, переменная пуста): `skip` (по умолчанию — шаг `skipped`) или `error` |

### Как работает

1. `id` записи — `record.id`. Нет `id` → по `on_missing_record`: `skipped` с причиной
   `Record "meeting" has no id — save it before linking` или ошибка.
2. Модуль записи неизвестен (не задан `module`, у переменной нет модуля) → ошибка
   `Cannot link "meeting": CRM module is unknown (set "module")`.
3. id собираются из всех источников по порядку: пробелы обрезаются, пустые значения отбрасываются, дубли убираются.
   Отсутствующий путь — не ошибка: он попадает в `missing_paths` результата.
4. Ни одного id → шаг `skipped`: `No related ids to link` / `No related ids to unlink`.
5. Больше `LINK_RECORDS_MAX` (по умолчанию 500) id → ошибка `Too many related ids (612 > 500)`.
6. `link_records` отправляет id пакетами по `max_link_batch` из возможностей CRM (по умолчанию 100) и объединяет
   ответы. Связывание **идемпотентно**: существующая связь не дублируется, её поля обновляются (`updated`), поэтому
   повтор шага безопасен. `unlink_records` удаляет связи по одной: удалённые — `removed`, которых не было — `not_linked`
   (шаг всё равно успешен).
7. Нужен контракт CRM 2.0 с возможностью `relationships`. Со старым плагином шаг падает с `CRM_CAPABILITY_MISSING`.
   Если нет возможности `relationship_fields`, связи создаются без полей, а в результате появляется предупреждение.
8. Ошибки CRM: запись не найдена (404) и неизвестный link / недопустимое поле связи (4xx) — без повтора; 5xx и сеть —
   повтор по обычной политике шага.
9. Связи не порождают событий в поток, поэтому каскадов нет. Контекст выполнения не меняется.

**Результат шага.** `request_data`: `module`, `record_id`, `link`, `related_module`, `related_ids` (первые 100),
`related_count`, для `link_records` — `fields` и `batches`. `result_data`: `linked`, `updated`, `not_found`, `warnings`
(или `removed`, `not_linked` для `unlink_records`), `missing_paths`.

### Частые link-поля SuiteCRM

| Модуль записи | `link` | Связанный модуль | Поля связи |
|---|---|---|---|
| Meetings | `users`, `contacts`, `leads` | Users, Contacts, Leads | `accept_status`: `none`, `accept`, `decline`, `tentative`; `required`: `0` / `1` |
| Calls | `users`, `contacts`, `leads` | Users, Contacts, Leads | `accept_status`, `required` |
| Accounts | `contacts` | Contacts | — |
| Opportunities | `contacts` | Contacts | `contact_role` |
| Accounts | `documents` | Documents | — |

### Пример: встреча с участниками

```json
{
  "trigger_on": ["create"],
  "definition": { "actions": [
    { "type": "modify", "target": "meeting.name", "change_type": "template", "value": "Встреча: {{ object.first_name }} {{ object.last_name }}" },
    { "type": "modify", "target": "meeting.date_start", "change_type": "date_shift", "base": "today", "shift": "+1 day", "set_time": "10:00" },
    { "type": "modify", "target": "meeting.duration_hours", "change_type": "set_value", "value": "1" },
    { "type": "modify", "target": "meeting.parent_type", "change_type": "set_value", "value": "Leads" },
    { "type": "modify", "target": "meeting.parent_id", "change_type": "set_from_variable", "value": "object.id" },
    { "type": "save_object", "target": "meeting", "module": "Meetings" },
    { "id": "invite", "type": "link_records", "record": "meeting", "link": "users",
      "related": [ { "path": "object.assigned_user_id" } ], "fields": { "accept_status": "accept" } },
    { "type": "link_records", "record": "meeting", "link": "leads", "related": [ { "path": "object.id" } ] }
  ] }
}
```

Результат на SuiteCRM 8 (кейс `.tehnical/suiteCRM8/processes/cases/4.12-link-records.json`): создана встреча на
завтра 10:00, участник — ответственный лида с `accept_status = accept`, встреча связана с лидом. Второй процесс кейса
при `object.status changed_to Dead` находит встречу лида предзагрузкой по `parent_id` и отвязывает лида
(`unlink_records`, `removed: [id лида]`); участник-пользователь остаётся.

### Ошибки сохранения

| Ошибка | Поле |
|---|---|
| `Record variable is required (e.g. meeting)`, `Variable "v9" is not defined at this point` | `…record` |
| `Invalid CRM module` | `…module`, `…related_module` |
| `Link name is required (e.g. contacts)` | `…link` |
| `At least one related id source is required` | `…related` |
| `Use {"path": ...} or {"value": ...}` | `…related[k]` |
| `Variable "v9" is not defined at this point` | `…related[k].path` |
| `Related id must be a non-empty string` | `…related[k].value` |
| `Relationship fields must be an object of scalar values`, `Fields are not used when unlinking` | `…fields` |
| `Must be "warn" or "error"`, `Must be "skip" or "error"` | `…on_not_found`, `…on_missing_record` |

## Назначить ответственного

Действие `assign` ставит в поле пользователя записи (по умолчанию `assigned_user_id`) сотрудника, выбранного **по
правилу**: по кругу, наименее загруженного, случайно, руководителя текущего ответственного или конкретного
пользователя. Это распределение лидов и обращений из AOW / Битрикс24 без ручных формул.

### Поля

```json
{ "type": "assign", "id": "rr", "target": "object", "strategy": "round_robin",
  "pool": { "security_group": "wps-case-sales" }, "queue_key": "leads_sales", "save_to_crm": true }
```

| Поле | Обязательно | Значение |
|---|---|---|
| `target` | да | Переменная-запись, которой назначается ответственный (`object`, переменная предзагрузки); не список, не локальная переменная |
| `field` | нет | Поле пользователя, по умолчанию `assigned_user_id` |
| `strategy` | да | `round_robin`, `least_busy`, `random`, `manager`, `user` (справочник [`GET /api/dictionaries/assign-strategies`](../06-api-reference/endpoints.md#get-apidictionariesassign-strategies)) |
| `pool` | для `round_robin`, `least_busy`, `random` | Ровно одно: `{"users": [{"value": "u1"}, {"path": "object.team_ids"}]}` — список источников id (значение или путь к id / списку id), или `{"security_group": "<id>"}` / `{"security_group": {"path": "…"}}` — участники группы безопасности CRM |
| `only_active` | нет | `true` (по умолчанию) — только активные пользователи (ниже) |
| `exclude` | нет | Источники id, которых не назначать: `[{"path": "object.created_by"}]` |
| `queue_key` | нет | Ключ очереди `round_robin` / `least_busy`, `^[A-Za-z0-9_:.-]{1,100}$`. По умолчанию — свой у шага: `p{id процесса}:{id шага или pc}`. Одинаковый ключ у нескольких процессов — **общая** очередь |
| `least_busy` | для `least_busy` | `{"module": "Cases", "user_field": "assigned_user_id", "filter": [...]}` — какие записи считать «открытыми» (фильтр обязателен, формат — как у [Найти записи](#найти-записи)) |
| `manager_of` | нет | Для `manager`: чей руководитель (`{"path": …}` / `{"value": …}`); по умолчанию — текущий ответственный записи |
| `user` | для `user` | `{"path": "…"}` или `{"value": "<id>"}` |
| `fallback` | нет | Если выбрать некого: `{"mode": "keep"}` (по умолчанию) — поле не меняется, шаг `skipped`; `{"mode": "error"}` — шаг падает; `{"mode": "user", "user": {"value": "<id>"}}` — назначить запасного |
| `save_to_crm` | нет | `true` — сразу сохранить запись (как `save_object`); иначе нужен отдельный «Сохранить в CRM» |
| `module` | нет | Модуль для `save_to_crm`, если он неизвестен из переменной |

### Стратегии

| Стратегия | Как выбирает |
|---|---|
| `round_robin` — по кругу | Пул сортируется по id; в Redis хранится **последний назначенный** (`assign:rr:{queue_key}`), следующим берётся первый id после него, после последнего — снова первый. Выбор атомарный (Lua-скрипт), поэтому параллельные выполнения не получают одного и того же. Добавление или удаление сотрудника из пула не даёт никому двух записей подряд |
| `least_busy` — наименее загруженный | Для каждого пользователя пула — запрос `count` открытых записей (`least_busy.filter` + `user_field = пользователь`, до 10 запросов параллельно); выбирается минимум, при равенстве — тот, кто назначался давнее в этой очереди, затем по id. Пул не больше `ASSIGN_LEAST_BUSY_MAX_POOL` (50) |
| `random` — случайно | Случайный пользователь пула |
| `manager` — руководитель | `reports_to_id` пользователя `manager_of` (по умолчанию — текущего ответственного): эскалация. Нет руководителя или он неактивен — `fallback` |
| `user` — конкретный | Пользователь из `user` (значение или путь); неактивный — `fallback` |

**Пул и активность.** `{"users": …}` — один запрос `Users` с `in` по всем id (отсутствующие в CRM отбрасываются, не
больше 200); `{"security_group": …}` — участники `SecurityGroups/{id}/relationships/users`, кэш на
`ASSIGN_POOL_CACHE_TTL` (60 с). При `only_active` пользователь подходит, если `status = Active`, `employee_status`
`Active` или пусто (отпуск — `Leave of Absence` — исключает), это не групповой (`is_group`) и не портальный
(`portal_only`) пользователь. Отброшенные видны в аудите: `inactive`, `excluded`. Пустой пул — `fallback`.

**Повтор шага не сдвигает очередь.** Решение шага запоминается на сутки по выполнению, `pc` и итерации цикла. Если шаг
выполняется снова (повтор после ошибки `save_to_crm`, восстановление после падения воркера), назначается тот же
пользователь, а очередь не сдвигается ещё раз (`reused_decision: true`). В [цикле `foreach`](#цикл-для-каждого-элемента)
у каждой итерации своё решение — записи списка распределяются по кругу.

**Сброс очереди** — удалить ключ Redis: `redis-cli -p 6380 DEL assign:rr:<queue_key>` (см.
[Конфигурация](../04-operations/configuration.md#назначение-ответственного-assign)).

**Результат шага.** `request_data`: `target`, `field`, `strategy`, `only_active`, `queue_key` (для очередей), `save`.
`result_data`: `chosen_user_id`, `previous_user_id`, `unchanged`, `reason` («round_robin: next after u2»,
«least_busy: u3 has 0 open Cases (min)», «manager of u3», «fallback: keep — pool is empty»), `pool_size`, `pool` (до
50), `pool_source`, `inactive`, `excluded`, `counts` (для `least_busy`), `manager_of`, `fallback`, `reused_decision`,
`save`. Выбрать некого при `fallback: keep` — шаг `skipped` с той же сводкой.

### Пример: лиды по кругу, обращение наименее загруженному, эскалация

```json
{ "type": "assign", "target": "object", "strategy": "round_robin",
  "pool": { "security_group": "wps-case-sales" }, "queue_key": "case49", "save_to_crm": true }

{ "type": "assign", "target": "object", "strategy": "least_busy",
  "pool": { "users": [ { "value": "wps-case-u1" }, { "value": "wps-case-u2" }, { "value": "wps-case-u3" } ] },
  "least_busy": { "module": "Cases", "filter": [ { "field": "state", "operator": "not_equals", "value": "Closed" } ] },
  "save_to_crm": true }

{ "type": "assign", "target": "object", "strategy": "manager", "fallback": { "mode": "keep" }, "save_to_crm": true }
```

Результат на SuiteCRM 8 (кейс `.tehnical/suiteCRM8/processes/cases/4.9-assign.json`, пользователи и группа — из
`seed_case_users.sh`): 4 лида подряд получили трёх разных активных менеджеров группы, четвёртый — снова первого;
неактивный `wps-case-u4` пропущен (`inactive`). Новое обращение при двух открытых у u1, одном у u2 и одном закрытом у
u3 назначено u3 (`counts`: u1 = 2, u3 = 0). Обращение u3 переведено в приоритет P1 — ответственным стал его
руководитель `wps-case-u1`.

### Ошибки сохранения

| Ошибка | Поле |
|---|---|
| `Record variable is required (e.g. object)`, `Variable "x" is not a CRM record` | `…target` |
| `Invalid field name` | `…field` |
| `Strategy must be one of: round_robin, least_busy, random, manager, user` | `…strategy` |
| `Pool is required: {"users": [...]} or {"security_group": ...}`, `At least one user source is required`, `Security group id is required` | `…pool…` |
| `Use {"path": ...} or {"value": ...}`, `Id must be a non-empty string` | источники id (`…pool.users[k]`, `…user`, `…manager_of`, `…exclude[k]`, `…fallback.user`) |
| `CRM module of the counted records is required (e.g. Cases)`, `Filter of "open" records is required for least_busy`, ошибки строк фильтра | `…least_busy…` |
| `Invalid queue key` | `…queue_key` |
| `Fallback must be keep, error or user` | `…fallback` |
| `Must be true or false` | `…only_active`, `…save_to_crm` |

**Ошибки выполнения:** `assign: pool is empty` и другие причины при `fallback: error`, `Security group "x" not found in
CRM`, `assign: pool is too large (N > 200)`, `Pool is too large for least_busy (N > 50)`.

## Найти записи

`find_records` («Найти записи») находит записи CRM и кладёт **список** в переменную: открытые задачи лида, сделки
контрагента, контакты компании. Есть два режима:

- **по модулю** (`module`) — поиск в модуле CRM по фильтру; фильтр, сортировка и лимит выполняются в CRM;
- **по связи** (`related_to`) — записи, связанные с записью через link-поле (`Accounts.contacts`, `Leads.calls`);
  фильтр применяется на стороне WPS.

```json
{ "type": "find_records", "var": "deals", "module": "Opportunities",
  "filter": [
    { "field": "account_id", "operator": "equals", "value_type": "from_object_field", "value": "object.id" },
    { "field": "sales_stage", "operator": "not_in", "value": ["Closed Won", "Closed Lost"] },
    { "field": "amount", "operator": "greater_or_equal", "value": "1000", "cast": "decimal" }
  ],
  "sort": [ { "field": "amount", "direction": "desc" } ], "fields": ["name", "amount"], "limit": 20 }

{ "type": "find_records", "var": "contacts", "related_to": { "record": "object", "link": "contacts" },
  "filter": [ { "field": "title", "operator": "equals", "value": "Decision Maker" } ] }
```

### Поля

| Поле | Обяз. | Описание |
|---|---|---|
| `var` | да | Имя переменной-списка (`deals`). Нельзя: `object`, `object_before`, `sys`, `event_info`, `config` и имя другой переменной, не являющейся списком. Повторный `find_records` в то же имя перезаписывает список |
| `module` | одно из двух | Режим модуля: модуль CRM (`Tasks`, `Opportunities`) |
| `related_to` | одно из двух | Режим связи: `{"record": "<переменная-запись>", "link": "<link-поле>", "module": "<модуль записи>"}`; `module` нужен, только если модуль переменной неизвестен |
| `filter` | нет | Список условий в формате условий процесса: `field` (поле **найденных** записей), `operator`, `value`, `value_type` (`static` или `from_object_field`), `cast`. Все условия объединяются **AND**. Пустой фильтр — все записи модуля (или связи) |
| `sort` | нет | Только режим модуля: до 2 полей `[{"field": "date_due", "direction": "asc"}]` (`asc` по умолчанию, `desc`) |
| `fields` | нет | Какие поля загрузить (`id` добавляется всегда). По умолчанию — все поля. Сужайте: список меньше и быстрее |
| `limit` | нет | Сколько записей взять, `1..FIND_RECORDS_MAX` (по умолчанию 100) |
| `on_limit` | нет | Записей больше `limit`: `truncate` (по умолчанию — взять первые, `has_more: true`) или `error` (шаг падает) |
| `on_empty_value` | нет | Значение `from_object_field` пустое: `empty_result` (по умолчанию — пустой список без запроса в CRM, в результате `reason`), `ignore_condition` (условие отбрасывается) или `error` |

**Операторы фильтра в режиме модуля** (условие уходит в CRM):

| Оператор | В CRM | Замечание |
|---|---|---|
| `equals` / `not_equals` | `eq` / `neq` | `not_equals` включает записи с пустым полем |
| `greater_than`, `greater_or_equal`, `less_than`, `less_or_equal` | `gt`, `gte`, `lt`, `lte` | Нужен `cast`: `int`, `decimal`, `date` или `datetime` |
| `between` | `gte` + `lte` | Пара статических границ `[от, до]`, нужен `cast`; `not_between` в режиме модуля недоступен |
| `contains` | `like '%…%'` | `%`, `_` и `\` в значении экранируются — ищется буквальная подстрока |
| `starts_with` / `ends_with` | `like '…%'` / `like '%…'` | Префикс / суффикс, с тем же экранированием; пустое статическое значение не сохраняется |
| `in` / `not_in` | `in` / `nin` | Список 1..100 значений (или строка через запятую из поля). `not_in` включает записи с пустым полем; нужна возможность плагина `filter_nin` |
| `is_empty` / `is_not_empty` | `null` / `notnull` | Пустая строка тоже считается пустым значением. Значение не нужно |

`not_contains`, `not_between`, `is_null`, `is_not_null`, операторы изменений (`changed`, …) в режиме модуля недоступны. Значения
приводятся по `cast` и уходят в формате хранения CRM: дата — `Y-m-d`, дата-время — UTC `Y-m-d H:i:s` (литерал без
пояса читается в `WPS_TIMEZONE`; статическое значение может быть [относительной датой](triggers-and-conditions.md#относительные-даты):
`date_due less_than today` (`cast: datetime`) → `filter[date_due][lt]=<сегодня 00:00 по WPS_TIMEZONE в UTC>`), decimal — с точкой (`1 000,5` → `1000.5`), логическое — `1`/`0`.

В **режиме связи** доступны все операторы условий процесса (включая `not_contains`, `is_null`), фильтр проверяется
движком условий по каждой связанной записи.

### Переменная-список

```json
{ "items": [ { "id": "…", "name": "Отправить КП", "status": "In Progress", "date_due": "2026-11-01 09:00:00" }, … ],
  "count": 2, "has_more": true, "ids": ["…", "…"] }
```

- `items` — найденные записи (поля из `fields`), `count` — их число, `has_more` — в CRM есть ещё подходящие записи,
  `ids` — список id (удобно для `link_records`: `{"path": "deals.ids"}`).
- Читается в выражениях и шаблонах: `{{ deals.count }}`, `{{ deals.items.0.name }}`,
  `{{= if(deals.has_more, '+', '') }}`.
- Переменная **только для чтения**: `modify` и `save_object` по ней отклоняются при сохранении процесса
  (`Variable "deals" is a record list; use foreach to change its records`), а запись в неё при выполнении — ошибка шага.
  Изменить одинаковые поля у всех найденных записей — действием [Изменить записи списка](#изменить-записи-списка),
  по одной — в [цикле `foreach`](#цикл-для-каждого-элемента): `"source": "deals.items"`.

### Как работает

1. **Режим модуля.** Фильтр переводится в запрос контракта 2.0 (`filter[f][op]=v`, `sort`, `fields`), записи читаются
   страницами по курсору, пока не наберётся `limit + 1` (лишняя запись нужна, чтобы узнать `has_more`).
2. **Пустое значение из записи** (`from_object_field` указывает на пустое поле или отсутствующий путь) — по
   `on_empty_value`. По умолчанию запроса в CRM нет: пустой список, `reason: The value of filter[0] (object.account_id) is empty`.
3. **Режим связи.** id записи — `record.id`; нет `id` — пустой список с `reason`. Связь читается страницами целиком (до
   `FIND_RECORDS_SCAN_MAX` записей), к каждой записи применяется фильтр, набирается `limit`. Достигнут предел
   сканирования, а записи ещё есть — `has_more: true` и предупреждение `Scanned 1000 related records (FIND_RECORDS_SCAN_MAX), more were not checked`.
4. `has_more` и `on_limit: error` → ошибка `find_records: more than 20 records match (on_limit=error)`.
5. Список больше `FIND_RECORDS_MAX_BYTES` в JSON → ошибка `find_records: result is too large (1.4 MB); narrow "fields" or "limit"`.
6. Шаг только читает CRM: без внешних эффектов, повторяется по обычной политике при 5xx и сбоях сети.
7. Нужен контракт CRM 2.0: операторы кроме равенства — `filter_operators`, сортировка — `sort`, больше 100 записей —
   `cursor_pagination`, `not_in` — `filter_nin`, режим связи — `relationships`. Без возможности шаг падает с
   `CRM_CAPABILITY_MISSING` ([Интеграция с CRM](../04-operations/crm-integration.md)).

**Результат шага.** `request_data`: `var`, `limit`, `module` / `related_to`, `filter`, `sort`, `fields`, для режима
модуля — `crm_filter` (что ушло в CRM, например `{"filter": {"status": {"nin": ["Completed", "Deferred"]}}}`) и
`crm_requests`. `result_data`: `count`, `has_more`, первые 100 `ids`, `reason` (пустой результат без запроса),
`warnings`.

> **Ссылка `Leads.calls` в SuiteCRM** — это звонки, в которые лид **приглашён** участником (таблица `calls_leads`), а не
> звонки с `parent_id` лида. Звонки «по родителю» ищите режимом модуля: `Calls`, `parent_id equals object.id`. То же для
> `Leads.meetings`.

### Пример: сводка по лиду

При переводе лида в статус `In Process` процесс находит две ближайшие открытые задачи лида и запланированные звонки, в
которых лид — участник, и пишет сводку в описание лида.

```json
{
  "object_type": "Leads",
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.status", "operator": "changed_to", "value_type": "static", "value": "In Process" } ],
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

Результат на SuiteCRM 8 (кейс `.tehnical/suiteCRM8/processes/cases/4.10-find-records.json`): у лида 5 задач (2 открытые
на ноябрь, выполненная, отложенная, открытая на декабрь) и 3 звонка (2 `Planned`, 1 `Held`). В CRM ушло
`filter[status][nin][]=Completed&filter[status][nin][]=Deferred` с сортировкой по `date_due`; описание лида —
«Открытых задач: 2+; ближайшая: Отправить КП; запланировано звонков: 2».

### Ошибки сохранения

| Ошибка | Поле |
|---|---|
| `Variable name is required (letters, digits, "_"; e.g. deals)`, `Name "object" is reserved`, `Variable "x" is already defined` | `…var` |
| `Either "module" or "related_to" is required` | шаг |
| `Invalid CRM module` | `…module` |
| `Record variable is required (e.g. account)`, `Variable "v7" is not defined at this point` | `…related_to.record` |
| `Link name is required (e.g. contacts)` | `…related_to.link` |
| `Must be a list of conditions`, `Condition must be an object` | `…filter`, `…filter[j]` |
| `Field name of the found records is required (e.g. sales_stage)` | `…filter[j].field` |
| `Unknown comparison operator`, `Operator "not_contains" is not supported in find_records filter`, `Operator "is_null" is not supported in find_records filter: use is_empty` | `…filter[j].operator` |
| `Operator "greater_than" requires cast: int, decimal, date or datetime` | `…filter[j].cast` |
| `Value "abc" is not an integer` (литерал не приводится по `cast`), `List of 1..100 values is required`, `Variable "v9" is not defined at this point` | `…filter[j].value` |
| `Must be "static" or "from_object_field"` | `…filter[j].value_type` |
| `At most 2 sort fields: …`, `Use {"field": "<name>", "direction": "asc" \| "desc"}`, `Sorting is not available for related records` | `…sort`, `…sort[k]` |
| `Must be an integer between 1 and 500` | `…limit` |
| `Must be a non-empty list of field names` | `…fields` |
| `Must be one of: truncate, error`, `Must be one of: empty_result, ignore_condition, error` | `…on_limit`, `…on_empty_value` |
| `Variable "deals" is a record list; use foreach to change its records` | `…target` у `modify` / `save_object` |

## Изменить записи списка

Действие `update_records` меняет **одни и те же поля у всех записей** списка, найденного шагом
[Найти записи](#найти-записи), — одним шагом и одной записью аудита. Это аналог «Modify Record» связанных записей в
AOW: «сделка проиграна → отложить все её открытые задачи», «сменился ответственный контрагента → всем его контактам».

### Поля

```json
{ "type": "update_records", "id": "defer", "list": "tasks",
  "set": [
    { "field": "status", "change_type": "set_value", "value": "Deferred" },
    { "field": "description", "change_type": "template", "value": "{{ item.description }} Отложено." }
  ],
  "item_var": "item", "only_changed": true, "max": 200, "on_item_error": "continue" }
```

| Поле | Обязательно | Значение |
|---|---|---|
| `list` | да | Переменная-список шага «Найти записи» (`tasks`); другая переменная — ошибка сохранения |
| `set` | да | 1..20 правил `{field, change_type, …}`: те же типы изменения, что у [`modify`](#изменить-объект--переменную) (`set_value`, `set_from_variable`, `calculate`, `template`, `date_shift`) с их параметрами. Значение вычисляется для каждой записи. `field` — имя поля, не `id`, без повторов |
| `item_var` | нет | Имя переменной текущей записи внутри правил, по умолчанию `item`: `item.status`, `{{ item.name }}`. Имя не должно быть занято другой переменной |
| `only_changed` | нет | `true` (по умолчанию) — отправляются только поля, значение которых отличается от записи; запись без изменений в CRM не отправляется (`unchanged`). `false` — отправляются все поля правил |
| `max` | нет | Наибольшее число записей, `1..UPDATE_RECORDS_MAX` (200). Записей в списке больше — шаг падает **до** изменений: `update_records: list has 250 records, max is 200` |
| `on_item_error` | нет | `continue` (по умолчанию) — ошибка записи не мешает остальным; `stop` — после первой ошибки записи остальные не обрабатываются |
| `module` | нет | Модуль CRM записей, если он неизвестен из списка (обычно не нужен: модуль задаёт «Найти записи») |

Действие принимает и общие поля шага `id`, `name`, `on_error`, `retry`.

### Как работает

1. **Пустой список** — шаг `skipped` («List "tasks" is empty — nothing to update»), запросов нет.
2. Для каждой записи: переменная `item_var` = запись (существует только на время вычисления правил), правила
   вычисляются по порядку, при `only_changed` остаются отличающиеся поля, затем `PUT` записи в CRM. Запись без `id` —
   `skipped_no_id`.
3. **Ошибки записи** (ошибка вычисления правила, ответ 4xx, записи нет — 404) собираются: при `continue` остальные
   записи обрабатываются. В конце шаг **`failed`** с текстом `update_records: 1 of 4 records failed`, а полная сводка
   (`updated`, `unchanged`, `errors`, `items`) — в `result_data.details`. Дальше действует `on_error` шага
   ([Ошибки шага](#ошибки-шага-on_error-и-повторы)): `continue` — выполнение идёт дальше.
4. **Временные ошибки** (5xx CRM, сеть, таймаут) повторяют **весь шаг** по обычной политике повторов. Это безопасно:
   повторный `PUT` тех же значений ничего не меняет, а с `only_changed` уже обновлённые записи становятся `unchanged`.
5. **Список в контексте** получает новые значения обновлённых полей — их видят следующие шаги (`{{ tasks.items.0.status }}`).
   Сам список по-прежнему только для чтения и в CRM не сохраняется.

**Результат шага.** `request_data`: `list`, `module`, `count`, `set`, `only_changed`, `max`. `result_data`: `updated`,
`unchanged`, `skipped_no_id`, `errors` и `items` — по записи на каждую обработанную (до 200): `{id, status, fields,
error}`, где `status` — `updated`, `unchanged`, `skipped_no_id` или `error`.

### Действие или цикл

| Задача | Что выбрать |
|---|---|
| Одинаковые поля у всех записей (статус, ответственный, дата, текст по шаблону от полей записи) | `update_records`: один шаг, одна запись аудита, неизменённые записи не отправляются |
| Отбор записей по условию, которое не выразить фильтром «Найти записи» | [`foreach`](#цикл-для-каждого-элемента) с `where`, или сузьте фильтр поиска |
| Для каждой записи несколько разных действий: письмо, связь, дочерний процесс, пауза | `foreach` |
| Сумма или счётчик по записям | `foreach` с накопителем `set_variable` |
| Видеть шаги по каждой записи в журнале | `foreach` (записи тела с `iteration`) |

### Пример: сделка проиграна → отложить открытые задачи

```json
{
  "object_type": "Opportunities",
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.sales_stage", "operator": "changed_to", "value_type": "static", "value": "Closed Lost" } ],
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

Результат на SuiteCRM 8 (кейс `.tehnical/suiteCRM8/processes/cases/4.6-update-records.json`): у сделки 4 задачи.
«Подготовить КП» (`Not Started`, «Черновик готов.») и «Созвониться» (`In Progress`) стали `Deferred` с припиской
«Отложено: сделка проиграна.»; «Ждать бюджет» уже была `Deferred` — `unchanged`, запрос в CRM не отправлялся;
выполненная «Квалификация» в список не попала. В журнале 2 записи на всё выполнение, `updated = 2`, `unchanged = 1`.

### Ошибки сохранения

| Ошибка | Поле |
|---|---|
| `Record list variable is required (e.g. contacts)`, `Variable "x" is not defined at this point`, `Variable "x" is not a record list (use find_records)` | `…list` |
| `Variable name is required (letters, digits, "_"; e.g. item)`, `Variable "item" already exists; set "item_var"` | `…item_var` |
| `1..20 field rules are required: [{"field": ..., "change_type": ..., "value": ...}]` | `…set` |
| `Rule must be an object` | `…set[k]` |
| `Field name is required`, `Field "id" cannot be changed`, `Field "status" is set twice` | `…set[k].field` |
| `Change type must be one of: …`, ошибки параметров типа изменения (шаблон, выражение, сдвиг даты) | `…set[k]…` |
| `Invalid CRM module` | `…module` |
| `Max must be between 1 and 200` | `…max` |
| `Must be one of: continue, stop` | `…on_item_error` |
| `Must be true or false` | `…only_changed` |

**Ошибки выполнения:** `update_records: variable "x" is not a record list (use find_records)`,
`update_records: list has N records, max is M`, `update_records: CRM module of "x" is unknown (set "module")`,
`update_records: N of M records failed`.

## Цикл (для каждого элемента)

Блок `foreach` выполняет вложенные действия **для каждого элемента списка**: записей, найденных шагом
[Найти записи](#найти-записи), связанных записей или значений, которые вернуло выражение. Это аналог «Итератора»
Битрикс24 и изменения связанных записей в AOW. Без цикла массовые операции невозможны: «сменить ответственного у всех
открытых сделок контрагента», «каждому участнику встречи — письмо», «сумма по строкам, кроме отменённых».

### Формат

```json
{ "type": "foreach", "id": "each_deal", "name": "Каждая открытая сделка",
  "source": "deals.items", "as": "deal",
  "where": [ { "field": "deal.sales_stage", "operator": "not_in", "value_type": "static", "value": ["Closed Won", "Closed Lost"] } ],
  "max_items": 200, "on_overflow": "fail", "on_item_error": "stop",
  "actions": [
    { "type": "modify", "target": "deal.assigned_user_id", "change_type": "set_from_variable", "value": "object.assigned_user_id" },
    { "type": "save_object", "target": "deal" }
  ] }
```

| Поле | Обязательно | Значение |
|---|---|---|
| `source` | да | Путь к списку: `deals.items` (результат «Найти записи») или любое поле-список, **или** `{"expression": "…"}` — выражение, которое возвращает список |
| `as` | да | Имя переменной текущего элемента (правила имени переменной; не `object`, `object_before`, `sys`, `config`; не объявлена ранее) |
| `loop_var` | нет | Имя переменной с данными итерации, по умолчанию `<as>_loop` |
| `module` | нет | Модуль CRM элементов — для `save_object` элемента. По умолчанию — модуль переменной-источника (для списка «Найти записи» он известен) |
| `where` | нет | Условия в формате [условий запуска](triggers-and-conditions.md), проверяются для каждого элемента; ложь — элемент пропускается |
| `max_items` | нет | Максимум элементов, целое `1..LOOP_MAX_ITEMS` (по умолчанию `LOOP_MAX_ITEMS`, 500) |
| `on_overflow` | нет | `fail` (по умолчанию) — элементов больше `max_items` → шаг `foreach` падает; `truncate` — обработать первые `max_items` |
| `on_item_error` | нет | `stop` (по умолчанию) — ошибка в теле завершает выполнение; `continue` — итерация прерывается, цикл идёт к следующему элементу |
| `actions` | да | Тело цикла; может содержать блоки, в том числе другой `foreach` |

Блок также принимает общие поля шага `id`, `name`, `on_error` (см. [Вложенные блоки и поля шага](#вложенные-блоки-и-поля-шага)).

### Переменные в теле

- `<as>` — **копия** текущего элемента. Запись CRM (объект) копируется как есть; скаляр (строка, число) становится
  объектом `{"value": …}`: `{{ tag.value }}`. Модуль переменной — `module` или модуль источника.
- `<loop_var>` — `{"index": 0, "number": 1, "count": 12, "first": true, "last": false}`, **только для чтения**:
  `modify` / `set_variable` / `save_object` по ней отклоняются (`Variable "deal_loop" is read-only`).
- Переменные, созданные в теле (`modify` новой переменной, `set_variable`, «Найти записи»), **сохраняются** между
  итерациями и после цикла — так считают суммы. После цикла они могут быть не определены (цикл мог не выполниться ни
  разу): чтение даёт предупреждение «may be undefined here».
- После цикла `<as>` и `<loop_var>` удаляются: обращение к ним после блока — ошибка сохранения
  `Variable "deal" is not defined at this point`.

> **Изменения элемента нужно сохранить.** `modify deal.…` меняет копию, а не запись в CRM и не элемент списка-источника.
> Чтобы изменение попало в CRM, в теле нужен `save_object` с `target: "deal"`. Итерации с несохранёнными изменениями
> считаются в `unsaved_items` итоговой записи цикла — частая ошибка автора.

### Как работает

1. **Источник.** Путь не найден или пуст (`null`) — пустой список (`source_missing: true`), цикл пропускается. Значение
   не список (объект, строка) — шаг падает: `foreach: source "object" is not a list`.
2. **Лимит.** Элементов больше `max_items`: `fail` — ошибка `foreach: 750 items exceed max_items (500)`; `truncate` —
   берутся первые `max_items`, в итоге `truncated: true`.
3. **Снимок.** Элементы сохраняются один раз в начале цикла в отдельный ключ Redis; дальше цикл идёт по снимку.
   Изменения переменной-источника в теле на перебор не влияют; после сбоя воркера цикл продолжается с того же элемента
   по тому же набору.
4. **Каждый элемент:** выставляются `<as>` и `<loop_var>`, проверяется `where` (ложь — элемент только считается в
   `skipped_by_where`, записей в аудите нет), выполняется тело.
5. **Ошибка шага в теле.** Сначала действует `on_error` самого шага ([Ошибки шага](#ошибки-шага-on_error-и-повторы)).
   Не обработана:
   - `on_item_error: stop` — выполнение `failed`, оставшиеся шаги итерации и шаги после цикла — `skipped`, итоговая
     запись `foreach` — `failed` с `failed_at_index`;
   - `on_item_error: continue` — остаток итерации не выполняется и не пишется, цикл идёт к следующему элементу.
     Запись упавшего шага получает `handled = item_continue`, ошибка попадает в `sys.last_error` (с полем
     `iteration`), выполнение завершается `completed` (с `handled_errors`), а итог цикла — `failed_items` и до 50
     `failed_indexes`.

   Во вложенных циклах ошибку подхватывает ближайший внешний цикл с `continue`; внутренние циклы при этом закрываются.
6. **Досрочный выход.** `stop_process` в теле завершает **всё** выполнение (итог цикла с `stopped: true`);
   `on_error: {"goto": …}` уводит из цикла на шаг верхнего уровня (итог `failed`, `left_by: "goto"`). Операторов
   `break` / `continue` нет: пропуск элементов — `where` или `condition` в теле.
7. **Пауза в теле.** [Пауза](#пауза-delay) до `WAIT_INLINE_MAX_SECONDS` выполняется на месте; более долгая переводит
   выполнение в ожидание: снимок списка переносится вместе с состоянием в PostgreSQL и восстанавливается после срока,
   цикл продолжается с того же элемента.
8. **`start_process` в теле** запускает по дочернему процессу на итерацию.
9. Общий лимит инструкций выполнения (`EXECUTION_MAX_INSTRUCTIONS`, 5000) действует и на циклы.

**Аудит.** У шага `foreach` **одна** запись: в начале — `request_data = {source, as, max_items, on_overflow,
on_item_error, has_where}`, `result_data = {count, truncated, source_missing}`; по окончании цикла та же запись
перезаписывается итогами: `count`, `processed`, `skipped_by_where`, `failed_items`, `failed_indexes`, `unsaved_items`,
`truncated`, `source_missing`, `where_traces` (трасса `where` первых 20 пропусков), при ошибке — `failed_at_index`;
`duration_ms` — длительность всего цикла. Шаги тела — обычные записи с номером итерации (`iteration`: `0`, `1`, … ;
во вложенных циклах `2/0`). См. [Журнал выполнений](executions-and-audit.md).

### Пример: сделки нового клиента

Контрагент стал клиентом (`account_type` → `Customer`): каждой его открытой сделке ставится следующий шаг, сумма
открытых сделок копится в переменной процесса и пишется в контрагента.

```json
{
  "object_type": "Accounts",
  "trigger_on": ["update"],
  "definition": {
    "conditions": [ { "field": "object.account_type", "operator": "changed_to", "value_type": "static", "value": "Customer" } ],
    "actions": [
      { "type": "find_records", "var": "deals", "related_to": { "record": "object", "link": "opportunities" } },
      { "type": "set_variable", "var": "sum", "fields": [
        { "name": "total", "change_type": "set_value", "value": "0" },
        { "name": "n", "change_type": "set_value", "value": "0" } ] },
      { "id": "each_deal", "type": "foreach", "source": "deals.items", "as": "deal",
        "where": [ { "field": "deal.sales_stage", "operator": "not_in", "value_type": "static", "value": ["Closed Won", "Closed Lost"] } ],
        "actions": [
          { "type": "modify", "target": "deal.next_step", "change_type": "template", "value": "Клиент подтверждён: согласовать КП ({{ object.name | truncate:20 }})" },
          { "type": "save_object", "target": "deal" },
          { "type": "set_variable", "var": "sum", "fields": [
            { "name": "total", "change_type": "calculate", "value": "sum.total + deal.amount" },
            { "name": "n", "change_type": "calculate", "value": "sum.n + 1" } ] }
        ] },
      { "type": "modify", "target": "object.description", "change_type": "template", "value": "Открытых сделок: {{ sum.n }} на сумму {{ sum.total }}" },
      { "type": "save_object", "target": "object", "module": "Accounts" }
    ]
  }
}
```

Результат на SuiteCRM 8 (кейс `.tehnical/suiteCRM8/processes/cases/4.11-foreach.json`): у контрагента 3 сделки —
`Prospecting` на 1000, `Negotiation/Review` на 2500.50 и выигранная на 4000. Итог цикла — `processed: 2`,
`skipped_by_where: 1`, `unsaved_items: 0`; у двух открытых сделок в CRM следующий шаг «Клиент подтверждён: согласовать
КП (…)», выигранная не изменена; описание контрагента — «Открытых сделок: 2 на сумму 3500.5». `save_object deal` без
`module`: модуль `Opportunities` известен из списка «Найти записи».

### Ошибки сохранения

| Ошибка | Поле |
|---|---|
| `List source is required (e.g. deals.items)`, `Variable "v1" is not defined at this point` | `…source` |
| ошибка разбора выражения, `Expression is required` | `…source.expression` |
| `Variable name is required (letters, digits, "_"; e.g. item)`, `Name "sys" is reserved`, `Variable "deal" is already defined` | `…as`, `…loop_var` |
| `"loop_var" must differ from "as"` | `…loop_var` |
| `max_items must be an integer between 1 and 500` | `…max_items` |
| `Must be one of: fail, truncate`, `Must be one of: stop, continue` | `…on_overflow`, `…on_item_error` |
| `Invalid CRM module` | `…module` |
| `Must be an array or a condition group`, ошибки условий | `…where`, `…where[n]…` |
| `Must be an array` | `…actions` |
| `Variable "deal_loop" is read-only` | `…target` шага в теле |

**Ошибки выполнения:** `foreach: source "…" is not a list`, `foreach: N items exceed max_items (M)`,
`Loop items snapshot is lost; the execution cannot be resumed` (снимок в Redis потерян — например, Redis очищен во
время выполнения).

## Условие (если / иначе)

Блок `condition` выбирает, какие действия выполнить, по тем же условиям, что и [условия запуска](triggers-and-conditions.md):
один процесс с ветками вместо нескольких процессов с разными условиями запуска и одинаковой предзагрузкой.

### Формат

```json
{ "type": "condition", "id": "deal_size", "name": "Размер сделки",
  "branches": [
    { "name": "Больше 1 млн",
      "conditions": [ { "field": "object.amount", "operator": "greater_than", "cast": "decimal", "value": "1000000" } ],
      "actions":    [ { "type": "modify", "target": "object.next_step", "change_type": "set_value", "value": "A" } ] },
    { "name": "Больше 100 тыс. или VIP",
      "conditions": { "logical_operator": "OR", "conditions": [
        { "field": "object.amount", "operator": "greater_than", "cast": "decimal", "value": "100000" },
        { "field": "object.description", "operator": "contains", "value": "VIP" } ] },
      "actions":    [ { "type": "modify", "target": "object.next_step", "change_type": "set_value", "value": "B" } ] }
  ],
  "else": [ { "type": "modify", "target": "object.next_step", "change_type": "set_value", "value": "C" } ] }
```

| Поле | Обязательно | Описание |
|---|---|---|
| `branches` | да | 1–20 веток; несколько веток — это «иначе если» |
| `branches[k].conditions` | нет | Формат условий запуска: плоский список (AND) или дерево групп `{logical_operator, conditions, groups}`; все операторы и `cast` |
| `branches[k].actions` | нет | Действия ветки (может быть пустым, может содержать вложенные блоки) |
| `branches[k].name` | нет | Подпись ветки для аудита, до 255 символов |
| `else` | нет | Действия, если ни одна ветка не подошла |

### Как работает

1. Ветки проверяются строго по порядку; выполняется **первая** истинная, остальные не проверяются.
2. Ни одна не подошла → `else`; `else` нет → выполнение идёт к шагу после блока.
3. Ветка **без условий** (`"conditions": []` или поле не задано) истинна всегда — так задаётся ветка по умолчанию.
   Если после неё есть другие ветки или `else`, сохранение вернёт предупреждение: они недостижимы.
4. Отсутствующее поле в условии ветки — «ложь», как и в условиях запуска. Переменная должна быть объявлена до блока:
   условия веток видят переменные, известные **перед** блоком (переменные из ветки выше в условиях следующих веток не видны).
5. Ошибка приведения типа с `"on_cast_error": "error"` — шаг `condition` падает; дальше работает его `on_error`
   (`continue` переходит к шагу после всего блока, ни одна ветка не выполняется).
6. После ветки управление переходит на шаг за блоком — «проваливания» в следующую ветку нет.
7. Блоки вкладываются друг в друга и в ветки до `PROGRAM_MAX_DEPTH` уровней (по умолчанию 5); работают и в дочерних процессах.

В аудите блок даёт одну запись `condition`: выбранная ветка и трасса проверенных веток
(см. [Выполнения и аудит](executions-and-audit.md#запись-блока-condition)). Шаги невыбранных веток в аудит не пишутся.

### Пример: разные действия для крупных и мелких сделок

Процесс по новым сделкам (`trigger_on: ["create"]`) — одна сделка проходит через три ветки:

| Сделка | Ветка | Результат |
|---|---|---|
| 2 500 000 | 0 «Больше 1 млн» | `next_step = «A: согласовать с руководителем»`, задача «Крупная сделка на 2 500 000» |
| 5 000, в описании «VIP» | 1 «Больше 100 тыс. или VIP» (группа OR) | `next_step = «B: обычный процесс»` |
| 5 000 | 2 «Остальные» (без условий) | `next_step = «C: автоматическая обработка»`, задачи нет |

Полное определение — в [рецептах](recipes.md#26-разные-действия-для-крупных-и-мелких-сделок).

### Ошибки и предупреждения при сохранении

| Ситуация | Сообщение (поле) |
|---|---|
| Нет веток | `At least one branch is required` (`…branches`) |
| Больше 20 веток | `Too many branches (max 20)` (`…branches`) |
| Ветка не объект | `Branch must be an object` (`…branches[k]`) |
| `conditions` не список и не группа | `Must be an array or a condition group` (`…branches[k].conditions`) |
| Ошибка в строке условия | как у условий запуска, с путём `…branches[k].conditions[n].field` и т. п. |
| `actions` ветки или `else` не список | `Must be an array` |
| `name` ветки не строка или длиннее 255 | `Branch name must be a string up to 255 characters` |
| Ветка без условий не последняя | **предупреждение** `Branch k has no conditions: the following branches are never reached` |
| Ветка без условий и есть `else` | **предупреждение** `Branch k has no conditions: "else" is never reached` |

Предупреждения возвращаются в поле `warnings` ответа и не мешают сохранению.

## Завершить процесс

Блок `stop_process` досрочно завершает **всё** выполнение — успешно или с ошибкой и сообщением — из любого места:
верхнего уровня, ветки [условия](#условие-если--иначе), вложенного блока. Удобен для проверок «если данных не хватает —
остановиться с понятной причиной» и «если запись уже обработана — ничего не делать».

```json
{ "type": "stop_process", "status": "failed", "code": "NO_CONTACTS",
  "message": "У лида {{ object.first_name }} {{ object.last_name }} нет email и телефона" }
```

| Поле | Обязательно | Описание |
|---|---|---|
| `status` | нет | `completed` (по умолчанию) или `failed` |
| `message` | для `failed` — да | Текст до 1000 символов; [шаблон](change-types-and-expressions.md#шаблон) (`{{ … }}`, отсутствующий путь — пусто) |
| `code` | нет | Машинный код `^[A-Z][A-Z0-9_]{0,63}$` — для фильтров, разбора и метрик |

### Как работает

1. Шаг пишет запись аудита `stop_process` со статусом `success` (шаг сам выполнен): `result_data` —
   `stopped: true`, `status`, `code`, `message` (сообщение после подстановок).
2. `completed` — выполнение завершается `completed`, `error_message` пуст; `failed` — выполнение `failed`,
   `error_message = "[CODE] сообщение"` (без кода — просто сообщение).
3. Оставшиеся шаги текущего списка и всех объемлющих списков до верхнего уровня получают `skipped` с причиной
   `Not executed: the process was stopped by stop_process` — одинаково для обоих статусов.
4. В снимке выполнения появляется `context.stop = { status, code, message }`.
5. Останавливается всё выполнение, в том числе изнутри ветки; фреймы циклов снимаются.
6. [`on_error`](#ошибки-шага-on_error-и-повторы) **не** перехватывает `stop_process` — это намеренное завершение, а не
   ошибка шага; поле `on_error` на самом шаге запрещено.
7. Уже запущенные дочерние процессы (`start_process` выше) продолжают работать — они независимы.
8. Ошибка подстановки в шаблоне сообщения не мешает остановке: используется исходный текст `message`, а в
   `result_data` пишется `message_error`.
9. В лог пишется `info` `Process stopped` (`execution_id`, `status`, `code`), метрика —
   `wps_process_stopped_total` (см. [Мониторинг](../04-operations/monitoring.md)).

### Пример: не обрабатывать лид без контактов

Процесс по новым лидам проверяет контакты блоком `condition`; если контактов нет — выполнение `failed`
с кодом `NO_CONTACTS`, если лид уже в статусе Dead — успешное завершение без действий:

```json
{ "id": "check", "type": "condition", "branches": [
    { "name": "нет контактов",
      "conditions": [ { "field": "object.email1", "operator": "is_empty" }, { "field": "object.phone_work", "operator": "is_empty" } ],
      "actions": [ { "type": "stop_process", "status": "failed", "code": "NO_CONTACTS",
                     "message": "У лида {{ object.first_name }} {{ object.last_name }} нет email и телефона" } ] },
    { "name": "отказ",
      "conditions": [ { "field": "object.status", "operator": "equals", "value": "Dead" } ],
      "actions": [ { "type": "stop_process", "status": "completed", "code": "LEAD_DEAD", "message": "Лид в статусе Dead — обработка не нужна" } ] }
] }
```

| Лид | Итог выполнения |
|---|---|
| без email и телефона | `failed`, `error_message = "[NO_CONTACTS] У лида Олег … нет email и телефона"`, следующий шаг `skipped` |
| в статусе Dead, с телефоном | `completed`, `context.stop.code = LEAD_DEAD`, в CRM ничего не записано |
| с email | `completed`, все шаги выполнены |

Полное определение — в [рецептах](recipes.md#27-не-обрабатывать-лид-без-контактов-stop_process).

### Ошибки и предупреждения при сохранении

| Ситуация | Сообщение (поле) |
|---|---|
| `status` не `completed` / `failed` | `Status must be "completed" or "failed"` (`…status`) |
| `status: failed` без `message` | `Message is required for status "failed"` (`…message`) |
| `message` не строка или длиннее 1000 | `Message must be a string up to 1000 characters` (`…message`) |
| Ошибка шаблона в `message` | текст ошибки шаблона (`Unknown template filter "foo"`, `Variable "v9" is not defined at this point` …) |
| `code` не по шаблону | `Code must match ^[A-Z][A-Z0-9_]{0,63}$` (`…code`) |
| На шаге задан `on_error` | `on_error is not allowed for stop_process` (`…on_error`) |
| Шаги после `stop_process` в том же списке | **предупреждение** `Steps after stop_process in the same list are never executed` |

## Пауза (delay)

Шаг `delay` приостанавливает выполнение на срок («через 2 дня») или до момента («за 30 дней до окончания договора»),
после чего выполняются следующие шаги. Короткая пауза ждёт в корутине, длинная **паркует** выполнение: воркер
освобождается, состояние хранится в PostgreSQL, и ожидание переживает перезапуск backend и потерю Redis
([Надёжность → Ожидающие выполнения](../04-operations/reliability.md#ожидающие-выполнения)).

```json
{ "id": "wait", "type": "delay", "mode": "duration", "duration": "P2D" }
{ "id": "remind", "type": "delay", "mode": "until", "until": "object.end_date", "cast": "date", "time": "09:00", "offset": "-P30D" }
```

### Поля

| Поле | Обязательно | Описание |
|---|---|---|
| `mode` | да | `duration` (на срок) или `until` (до момента) |
| `duration` | для `duration` — да (или `duration_expression`) | ISO 8601 (`PT30M`, `P3D`, `P1M`) или короткая форма `30s`, `15m`, `2h`, `3d`, `1w`. `P1M`/`P1Y` — календарные месяцы и годы с обрезкой конца месяца (31 января + `P1M` = 28/29 февраля). Число без единицы не принимается |
| `duration_expression` | нет | [Выражение](change-types-and-expressions.md), которое возвращает длительность в том же формате, например `concat(object.days, 'd')` |
| `until` | для `until` — да (или `until_expression`) | Путь к полю (`object.date_end`, `v1.end_date`) — значение CRM считается **UTC**; или литерал даты `2026-12-31 09:00` — в часовом поясе `WPS_TIMEZONE` либо поля `timezone` |
| `until_expression` | нет | Выражение, которое возвращает дату |
| `cast` | нет | `date` — взять только дату (полночь), `datetime` — как есть |
| `time` | нет | `HH:MM` — время суток в `WPS_TIMEZONE`, ставится после `cast` |
| `offset` | нет | Сдвиг со знаком в тех же форматах: `-P30D`, `-3d`, `PT10S` |
| `timezone` | нет | Часовой пояс литерала `until` (`Europe/Warsaw`) |
| `if_past` | нет | Момент уже прошёл: `continue` (по умолчанию) — идти дальше без ожидания, `fail` — шаг падает |
| `on_invalid` | нет | Дату не удалось вычислить: `fail` (по умолчанию) или `continue` — шаг `skipped` с причиной `Delay skipped: …` |
| `max_wait` | нет | Предел длительности паузы для этого шага, не больше `DELAY_MAX` (`P400D`) |

### Как работает

1. Срок вычисляется при выполнении шага; `request_data` содержит настройку, `due_at` (UTC) и `due_at_local`
   (в `WPS_TIMEZONE`).
2. Момент уже прошёл: при `if_past: continue` шаг `success` с `result_data = { waited: false, past_due: true }`; при
   `fail` — ошибка `delay: the moment … has already passed`.
3. Пауза не длиннее `WAIT_INLINE_MAX_SECONDS` (5 с) ждёт в корутине: `result_data = { waited: true, waited_seconds, inline: true }`.
4. Более длинная пауза паркует выполнение: шаг получает статус `waiting`, выполнение — `waiting` с
   `waiting_until = due_at` и `wait_reason: delay`. Список ожидающих — `GET /api/executions?status=waiting`
   ([Выполнения и аудит](executions-and-audit.md#ожидающие-выполнения)).
5. После срока выполнение просыпается (точность — `WAIT_POLL_INTERVAL_MS`, 1 с), шаг становится `success` с
   `result_data = { waited: true, lateness_ms, waited_seconds }`, и выполняются следующие шаги.
6. `object` после паузы — тот же снимок из события, он мог устареть. Если важны свежие данные, перечитайте запись
   [предзагрузкой](#предзагрузка-внутри-действий-только-через-api) после паузы.
7. Деактивация процесса ожидающие выполнения не останавливает. Удаление процесса с ожидающими выполнениями отклоняется
   (`409 PROCESS_HAS_WAITING_EXECUTIONS`); `DELETE /api/processes/{id}?force=true` отменяет их (статус `cancelled`).

### Пример: звонок через паузу

Кейс `.tehnical/suiteCRM8/processes/cases/4.7-delay.json` (проверено на SuiteCRM 8): короткая пауза `2s` проходит в
корутине, пауза до «дата создания −1 день» уже прошла (`past_due: true`), пауза до «дата создания + 10 секунд»
паркует выполнение, после пробуждения создаётся звонок «Перезвонить {{ object.first_name }}».

```json
"actions": [
  { "id": "short", "type": "delay", "mode": "duration", "duration": "2s" },
  { "id": "past", "type": "delay", "mode": "until", "until": "object.date_entered", "offset": "-P1D" },
  { "id": "until_created", "type": "delay", "mode": "until", "until": "object.date_entered", "offset": "PT10S" },
  { "type": "modify", "target": "call.name", "change_type": "template", "value": "Перезвонить {{ object.first_name }}" },
  { "type": "save_object", "target": "call", "module": "Calls" }
]
```

Готовые процессы — в рецептах [28](recipes.md#28-напомнить-за-30-дней-до-окончания-договора-delay) и
[29](recipes.md#29-перезвонить-через-2-дня-delay).

### Ошибки сохранения

| Ситуация | Сообщение (поле) |
|---|---|
| `mode` не задан или неверен | `Delay mode must be one of: duration, until` (`…mode`) |
| Нет ни `duration`, ни `duration_expression` | `Duration is required` |
| Заданы оба | `Use either duration or duration_expression` |
| Неверная длительность | `Invalid duration "3x" (use ISO 8601 like P3D or 3d, 2h, 30m)` (`…duration`) |
| Длительность больше предела | `Delay exceeds the limit P400D` (`…duration`) |
| Нет `until` / `until_expression` | `Date is required` (`…until`) |
| Неверный литерал даты | `Invalid date "31.02.2026"` (`…until`) |
| Неверный сдвиг | `Invalid offset "-3x"` (`…offset`) |
| Неверное время | `Time must be HH:MM` (`…time`) |
| Неверный `cast` | `Cast must be "date" or "datetime"` (`…cast`) |
| Неизвестный часовой пояс | `Unknown timezone "…"` (`…timezone`) |
| `if_past` / `on_invalid` не из списка | `Must be "continue" or "fail"` |
| `max_wait` больше `DELAY_MAX` | `Must not exceed P400D` (`…max_wait`) |

### Ошибки выполнения

| Ситуация | `error_message` |
|---|---|
| Поле даты пустое или не дата | `delay: cannot resolve a date from object.date_end (value "")` |
| Срок дальше предела | `delay: wait of 500 days exceeds the limit P400D` |
| Момент прошёл при `if_past: fail` | `delay: the moment 2026-01-01T00:00:00+00:00 has already passed` |
| Выражение вернуло неверную длительность | `delay: invalid duration "soon"` |
| Снимок выполнения слишком большой для парковки | `Execution state is too large to wait (N bytes, limit M)` |

Ошибки даты при `on_invalid: continue` не останавливают выполнение: шаг `skipped` с `Delay skipped: …`.

## Вложенные блоки и поля шага

Помимо действий список `actions` может содержать **блоки** — управляющие конструкции с вложенными списками действий.
Сейчас доступны блоки [`condition`](#условие-если--иначе), [`foreach`](#цикл-для-каждого-элемента) и [`stop_process`](#завершить-процесс); блоки задаются через API, редактор сохраняет их без изменений.
Блоки можно вкладывать друг в друга до `PROGRAM_MAX_DEPTH` уровней (по умолчанию 5).

У любого шага (действия или блока) на любом уровне есть необязательные поля:

| Поле | Формат | Назначение |
|---|---|---|
| `id` | `^[a-z][a-z0-9_]{0,63}$`, уникален в процессе (вместе с вложенными блоками) | Ссылка на шаг (переход при ошибке) и читаемый аудит (`step_id`) |
| `name` | строка до 255 символов | Подпись шага для аудита и интерфейса |
| `on_error` | `"stop"`, `"continue"`, `{ "goto": "<id>" }` | Что делать, если шаг упал (см. [Ошибки шага](#ошибки-шага-on_error-и-повторы)) |
| `retry` | объект | Профиль повторов шага (там же) |

**Видимость переменных.** Переменная, созданная во **всех** ветках блока с `else`, после блока считается объявленной.
Переменная, созданная только в части веток (или в блоке без `else`), после блока читать можно, но сохранение процесса
вернёт предупреждение в поле `warnings` ответа:

```json
"warnings": [ { "field": "definition.actions[1].value", "message": "Variable \"task\" may be undefined here: it is set only in some branches" } ]
```

Предупреждение не мешает сохранению: в выполнении такая переменная ведёт себя как ненайденная (условие по ней ложно,
выражение получает пустое значение, `save_object` даёт `skipped`).

Ошибки сохранения: `Step id must match ^[a-z][a-z0-9_]{0,63}$`, `Step id "x" is already used at …`,
`Step name must be a string up to 255 characters`, `Nesting is too deep (max 5)`, `Unknown action type` (для
неизвестного типа во вложенном списке); ошибки самого блока — в разделе [Условие](#ошибки-и-предупреждения-при-сохранении).

## Ошибки шага: on_error и повторы

Когда шаг завершается ошибкой, движок сначала **повторяет** его по профилю `retry`, а если повторы не помогли —
поступает по **политике** `on_error`. Оба поля задаются у любого шага в `definition.actions` (действия и блока
`condition`, на любом уровне вложенности). В блоке `preload` поле `on_error` не поддерживается: ошибки ленивой
предзагрузки обрабатываются до старта выполнения.

```json
{ "id": "sync_user", "type": "save_object", "target": "usr", "module": "Users",
  "on_error": { "goto": "manual_check" },
  "retry": { "max_attempts": 3, "base_delay": "1s", "max_delay": "10m", "backoff": 2,
             "jitter": "full", "retry_on": ["5xx"], "respect_retry_after": true } }
```

### Политика `on_error`

| Значение | Поведение |
|---|---|
| `"stop"` (по умолчанию) | Как раньше: шаг `failed`, оставшиеся шаги `skipped`, выполнение `failed` |
| `"continue"` | Шаг `failed` с пометкой `handled: "continue"`, выполнение идёт к следующему шагу. Для упавшего блока `condition` (ошибка в условиях ветки) — к шагу **после всего блока**: ни одна ветка не выполняется |
| `{ "goto": "<id>" }` | Шаг `failed` с `handled: "goto"`, переход к шагу с этим `id` |

Ограничения `goto` (проверяются при сохранении):

- цель — шаг с `id` **на верхнем уровне** `definition.actions`;
- цель стоит **после** шага верхнего уровня, внутри которого произошла ошибка (только вперёд, поэтому `goto` не
  может зациклить процесс);
- переход из ветки `condition` выходит из ветки (и из всех вложенных блоков); шаги между ошибкой и целью не
  выполняются и **в аудит не пишутся** — как невыбранные ветки.

Если все ошибки обработаны (`continue` / `goto`) и дальше всё прошло успешно, выполнение получает статус
`completed`, а в поле `handled_errors` — число обработанных ошибок. Отдельного статуса «завершено с ошибками» нет.
Обработчика ошибок уровня процесса тоже нет: его роль играет `goto` на шаг-обработчик.

### Профиль `retry`

Без `retry` действуют глобальные `ACTION_RETRY_*` (см. [Конфигурация](../04-operations/configuration.md)).

| Поле | Значения | По умолчанию | Смысл |
|---|---|---|---|
| `max_attempts` | целое 1–20 | `ACTION_RETRY_MAX + 1` (4) | Всего попыток, включая первую; `1` — без повторов |
| `base_delay` | длительность | `ACTION_RETRY_BASE_DELAY_MS` (1 с) | Задержка перед 2-й попыткой |
| `backoff` | число 1–10 | `ACTION_RETRY_BACKOFF` (2) | Множитель задержки |
| `max_delay` | длительность, не больше `P7D`, не меньше `base_delay` | `ACTION_RETRY_MAX_DELAY` (`1h`) | Предел одной задержки. Задержка длиннее `WAIT_INLINE_MAX_SECONDS` (5 с) паркует выполнение (`waiting`, `wait_reason: retry`) |
| `jitter` | `none`, `full`, `equal` | `ACTION_RETRY_JITTER` (`full`) | Случайный разброс задержки (см. [Надёжность](../04-operations/reliability.md#повторы-шага)) |
| `retry_on` | непустой список классов | `["transient"]` | Какие ошибки повторять |
| `respect_retry_after` | `true` / `false` | `true` | Учитывать заголовок `Retry-After` ответа (не больше `max_delay`) |

**Длительности**: `500ms`, `2s`, `10m`, `1h`, `1d`, `1w`, сочетания (`1h30m`), ISO 8601 (`PT5M`, `P7D`; месяцы и
годы не допускаются) или целое число миллисекунд.

**Классы `retry_on`** (справочник `GET /api/dictionaries/retry-classes`):

| Класс | Что повторяется |
|---|---|
| `transient` | Временные ошибки: сеть, таймаут, HTTP `5xx`, `408`, `425`, `429`, Redis, потеря соединения с БД |
| `5xx` / `4xx` | Любой ответ HTTP из этого диапазона |
| `"409"` и другие трёхзначные коды | Ответ с этим кодом |
| `any` | Любая ошибка |

**Никогда не повторяются**, что бы ни было в `retry_on`: ошибки конфигурации (`CONFIG`: неверный модуль, неизвестная
функция …), `CRM_CAPABILITY_MISSING` (плагин CRM не поддерживает возможность) и `UNKNOWN_OUTCOME` (неизвестный
исход побочного эффекта с политикой `at_most_once`, см. [Надёжность](../04-operations/reliability.md)). К ним
по-прежнему применяется `on_error`.

### Переменная `sys`: последняя ошибка

После обработанной ошибки (`continue` / `goto`) в выражениях и шаблонах доступна системная переменная `sys`
(только чтение, имя зарезервировано):

| Путь | Значение |
|---|---|
| `sys.last_error.step_id` | `id` упавшего шага (или пусто) |
| `sys.last_error.step_path` | путь шага в определении (`actions[1]`) |
| `sys.last_error.action_type` | тип шага |
| `sys.last_error.code` | код ошибки: `HTTP_405`, `HTTP_503`, `TIMEOUT`, `TRANSPORT`, `CONFIG`, `UNKNOWN_OUTCOME` … |
| `sys.last_error.message` | текст ошибки (секреты замаскированы) |
| `sys.last_error.http_status` | HTTP-статус, если был |
| `sys.last_error.attempts` | сколько попыток было сделано |
| `sys.last_error.at` | время (ISO 8601, UTC) |
| `sys.errors_count` | число обработанных ошибок в выполнении |

`sys.last_error` перезаписывается следующей обработанной ошибкой и не сбрасывается успешными шагами. До первой
обработанной ошибки пути не найдены (`''` в выражении, пусто в шаблоне).

### Пример: запись не удалась → задача «проверить вручную»

Процесс по лидам обновляет пользователя CRM (в API плагина модуль `Users` только для чтения — ответ `405`). Ошибка
`4xx` не входит в `retry_on: ["5xx"]` и не повторяется; `goto` ведёт на создание задачи с кодом и текстом ошибки, а
вторая такая же ошибка обрабатывается `continue`:

```json
{ "trigger_on": ["create"],
  "definition": { "actions": [
    { "type": "modify", "target": "usr.description", "change_type": "set_value", "value": "лид" },
    { "id": "sync_user", "type": "save_object", "target": "usr", "module": "Users",
      "on_error": { "goto": "manual_check" }, "retry": { "max_attempts": 3, "base_delay": "1s", "retry_on": ["5xx"] } },
    { "type": "modify", "target": "object.description", "change_type": "set_value", "value": "не выполнится", "save_to_crm": true },
    { "id": "manual_check", "type": "modify", "target": "task.name", "change_type": "template",
      "value": "Проверить вручную: {{ sys.last_error.code }} на шаге {{ sys.last_error.step_id }}" },
    { "type": "modify", "target": "task.description", "change_type": "template",
      "value": "{{ sys.last_error.message | truncate:200 }} (попыток: {{ sys.last_error.attempts }})" },
    { "type": "modify", "target": "task.parent_type", "change_type": "set_value", "value": "Leads" },
    { "type": "modify", "target": "task.parent_id", "change_type": "set_from_variable", "value": "object.id" },
    { "type": "save_object", "target": "task", "module": "Tasks" },
    { "type": "save_object", "target": "usr", "module": "Users", "on_error": "continue", "retry": { "max_attempts": 1 } },
    { "type": "modify", "target": "object.description", "change_type": "template",
      "value": "Ошибок обработано: {{ sys.errors_count }}", "save_to_crm": true } ] } }
```

Результат: выполнение `completed`, `handled_errors = 2`; шаг `sync_user` — `failed`, `error_code: HTTP_405`,
`attempts: 1`, `handled: goto`; третий шаг не выполнялся; в CRM задача «Проверить вручную: HTTP_405 на шаге
sync_user», у лида описание «Ошибок обработано: 2».

### Ошибки сохранения

| Ошибка | Поле |
|---|---|
| `Must be "stop", "continue" or {"goto": "<step id>"}` | `…on_error` |
| `Step "x" not found` | `…on_error.goto` |
| `Step "x" must be a top-level step after the failing step` | `…on_error.goto` (цель вложенная или раньше) |
| `Not supported in the preload block` | `definition.preload[i].on_error` |
| `Must be an object`, `Unknown retry option` | `…retry`, `…retry.<поле>` |
| `Must be an integer between 1 and 20` | `…retry.max_attempts` |
| `Invalid duration (e.g. 2s, 10m, 1h; at most 7 days)`, `Must be greater than or equal to base_delay` | `…retry.base_delay`, `…retry.max_delay` |
| `Must be a number between 1 and 10` | `…retry.backoff` |
| `Must be one of: none, full, equal` | `…retry.jitter` |
| `Must be a non-empty list`, `Unknown retry class "6xx"` | `…retry.retry_on`, `…retry.retry_on[i]` |
| `Must be true or false` | `…retry.respect_retry_after` |

## Учётные данные внешних систем

Действия, которые обращаются к внешним системам, получают пароли и токены ссылкой `secret://name` на секрет, который
завёл администратор; значение не попадает ни в определение, ни в аудит. Подробно — [Секреты](secrets.md).

## Пример: вся цепочка

Процесс **Active deal → mark account as VIP** (`Deals`):

```json
{
  "conditions": [{ "field": "object.status", "operator": "equals", "value_type": "static", "value": "Active" }],
  "preload": [{ "var": "v1", "source": "object_field", "module": "Accounts", "by_field": "id", "value": "object.account_id" }],
  "actions": [{ "type": "modify", "target": "v1.rating", "change_type": "set_value", "value": "VIP", "save_to_crm": true }]
}
```

Шаги выполнения: `0 preload` (загружает контрагента) → `1 modify` (ставит `v1.rating = "VIP"` и сохраняет контрагента запросом `PUT /Accounts/{id}`, только поле `rating`). Другие сценарии — в разделе [Готовые сценарии](recipes.md).

<!-- footer-nav -->
---

← Назад: [Условия запуска](triggers-and-conditions.md) | [Оглавление](../README.md) | [Типы изменений и выражения](change-types-and-expressions.md) Далее →
<!-- /footer-nav -->
