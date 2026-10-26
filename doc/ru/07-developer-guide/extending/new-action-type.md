# Новый тип действия

Как добавить в определение процесса новый вид шага — на проверенном примере действия `log_message` («Записать в журнал»).

<!-- lang-switch -->
[🇬🇧 English](../../../en/07-developer-guide/extending/new-action-type.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## 1. Когда это нужно

Действия — это шаги процесса: `preload`, `modify`, `save_object`, `start_process`. Новый тип нужен, когда процесс должен
сделать то, что ни одно из них не умеет: вызвать собственный API, отправить письмо или webhook, записать в очередь,
залогировать бизнес-сообщение. Если нужен только новый способ *вычислить значение*, напишите [тип изменения](new-change-type.md);
если новый способ *загрузить объект* — [стратегию загрузки](new-load-strategy.md).

## 2. Как это устроено

```mermaid
classDiagram
    class ActionHandlerInterface {
        <<interface>>
        +type() string
        +label() string
        +description() string
        +execute(ExecutionContext, config) ActionResult
        +validate(config, DefinitionScope, path) void
    }
    class ActionHandlerRegistry {
        +register(ActionHandlerInterface)
        +has(type) bool
        +get(type) ActionHandlerInterface
        +dictionary() list
    }
    class ActionResult {
        +status
        +request
        +result
        +success(request, result)$
        +skipped(reason, request)$
    }
    ActionHandlerInterface <|.. PreloadHandler
    ActionHandlerInterface <|.. ModifyHandler
    ActionHandlerInterface <|.. SaveHandler
    ActionHandlerInterface <|.. StartProcessHandler
    ActionHandlerInterface <|.. LogMessageHandler
    ActionHandlerRegistry o-- ActionHandlerInterface
    ExecutionEngineService --> ActionHandlerRegistry : get(type)->execute()
    ProcessValidatorService --> ActionHandlerRegistry : get(type)->validate()
    ActionHandlerInterface ..> ActionResult
```

- Шаг в `definition.actions[]` — это JSON-объект с ключом `"type"`; все остальные ключи — конфигурация обработчика.
- **При сохранении** `ProcessValidatorService` обходит определение в порядке выполнения и вызывает `validate($config, $scope, $path)`.
  Проблемы сообщаются через `$scope->error("{$path}.field", 'message')` и превращаются в `422 VALIDATION_ERROR` с
  `details[].field = "definition.actions[N].field"`. Пути, которые вы читаете, проверяйте через `$scope->checkReadPath()`
  (переменная к этому моменту уже должна существовать); переменные, которые вы создаёте, объявляйте через
  `$scope->declare()` / `checkWritePath()`.
- **При выполнении** `ExecutionEngineService::executeStep()` вызывает `execute($context, $config)`:
  - данные процесса читаются и пишутся через `ExecutionContext` (`get('object.phone')`, `set('v1.rating', 'VIP')`,
    `getVariable()`, `setVariable()`, `executionId`, `processId`, `stepIndex`);
  - возвращается `ActionResult::success($request, $result)` или `ActionResult::skipped($reason)`. `$request` и `$result`
    записываются в аудит (`execution_actions.request_data` / `result_data`) и показываются в UI;
  - при временном сбое бросайте `RecoverableException` — движок восстановит снимок контекста и повторит шаг
    `ACTION_RETRY_MAX` раз с экспоненциальной задержкой; при постоянном сбое — `NonRecoverableException`/`ConfigurationException`:
    шаг и выполнение получат статус `failed`, оставшиеся шаги — `skipped`.
- `GET /api/dictionaries/action-types` возвращает `type`, `label`, `description` каждого зарегистрированного обработчика.
- Обработчики создаются в `Wps\Kernel\Container::actions()`, поэтому через конструктор им можно передать любой сервис
  (адаптер CRM, логгер, движок выражений, репозитории).

## 3. Пошагово

### 3.1 Обработчик — `back/src/Actions/LogMessageHandler.php`

Действие вычисляет выражение в стиле `calculate` и пишет результат в лог сервиса и в аудит шага:
`{ "type": "log_message", "message": "concat('Deal ', object.id, ' processed')", "level": "info" }`.

```php
<?php

declare(strict_types=1);

namespace Wps\Actions;

use Psr\Log\LoggerInterface;
use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Expression\ExpressionEngine;
use Wps\Services\Validation\DefinitionScope;

/**
 * Writes a message to the service log and to the step audit.
 * "message" is a calculate-style expression: "concat('Deal ', object.id, ' processed')".
 */
final class LogMessageHandler implements ActionHandlerInterface
{
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error'];

    public function __construct(
        private readonly ExpressionEngine $expressions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function type(): string
    {
        return 'log_message';
    }

    public function label(): string
    {
        return 'Записать в журнал';
    }

    public function description(): string
    {
        return 'Вычисляет выражение и пишет результат в лог сервиса и в аудит шага';
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $expression = (string)($actionConfig['message'] ?? '');
        $level = (string)($actionConfig['level'] ?? 'info');
        if ($expression === '') {
            throw new ConfigurationException('log_message: message is required');
        }
        $message = ExpressionEngine::toString(
            $this->expressions->evaluate($expression, static fn (string $path) => $context->get($path)),
        );
        $this->logger->log($level, $message, [
            'execution_id' => $context->executionId,
            'process_id' => $context->processId,
            'step' => $context->stepIndex,
        ]);
        return ActionResult::success(['message' => $expression, 'level' => $level], ['logged' => $message]);
    }

    public function validate(array $actionConfig, DefinitionScope $scope, string $path): void
    {
        $message = $actionConfig['message'] ?? null;
        if (!is_string($message) || trim($message) === '') {
            $scope->error("{$path}.message", 'Message expression is required');
        } else {
            try {
                foreach ($this->expressions->paths($message) as $ref) {
                    $scope->checkReadPath($ref, "{$path}.message");
                }
            } catch (ConfigurationException $e) {
                $scope->error("{$path}.message", $e->getMessage());
            }
        }
        if (isset($actionConfig['level']) && !in_array($actionConfig['level'], self::LEVELS, true)) {
            $scope->error("{$path}.level", 'Level must be one of: ' . implode(', ', self::LEVELS));
        }
    }
}
```

### 3.2 Регистрация — `back/src/Kernel/Container.php`

```php
use Wps\Actions\LogMessageHandler;

public function actions(): ActionHandlerRegistry
{
    return $this->share(ActionHandlerRegistry::class, function () {
        $saver = new CrmObjectSaver($this->crm());
        $registry = new ActionHandlerRegistry();
        $registry->register(new PreloadHandler($this->crm(), $this->loadStrategies()));
        $registry->register(new ModifyHandler($this->changeTypes(), $saver));
        $registry->register(new SaveHandler($saver));
        $registry->register(new StartProcessHandler(
            $this->processes(),
            fn (): ExecutionEngineService => $this->engine(),
            $this->config()->maxChildDepth,
        ));
        $registry->register(new LogMessageHandler($this->expressions(), $this->logger()));
        return $registry;
    });
}
```

> **Внимание**
> Не вызывайте `$this->engine()` прямо в конструкторе обработчика: сам движок зависит от реестра действий.
> Передайте замыкание, как это сделано для `StartProcessHandler`.

### 3.3 Пересборка и использование

```bash
docker compose up -d --build backend
```

```json
{
  "name": "Deal log", "object_type": "Deals", "is_child": false,
  "definition": {
    "conditions": [], "preload": [],
    "actions": [ { "type": "log_message", "message": "concat('Deal ', object.id, ' ', object.status)" } ]
  }
}
```

Каждый выполненный шаг пишет одну строку в лог. Строка из проверочного прогона (сообщение `concat('ext ', object.id, ' ', acc.name)`):

```json
{"timestamp":"2026-10-15T08:06:27.619Z","level":"info","message":"ext e1 INN Company","context":{"execution_id":"38839d14-…","process_id":13,"step":3},"pid":14}
```

## 4. Валидация и ошибки

| Ситуация | Что делать | Результат |
|---|---|---|
| Конфигурация отсутствует или неверна | `$scope->error()` в `validate()` | `422`, `details[].field = definition.actions[N].<key>` |
| Чтение переменной, которой ещё нет | `$scope->checkReadPath($path, $field)` | `Variable "v9" is not defined at this point` |
| Действие создаёт переменную | `$scope->declare('name', 'created')` или `checkWritePath()` | следующие шаги могут её использовать |
| Действие кладёт список записей (только чтение) | `$scope->declare('name', ExecutionContext::SOURCE_LIST)`; при выполнении `setVariable($name, $value, $module, ExecutionContext::SOURCE_LIST)` | `modify` / `save_object` по ней отклоняются, `set()` бросает `ConfigurationException` (так делает `FindRecordsHandler`) |
| Действию нужна переменная только на время вычисления (текущая запись) | при валидации — `$scope->declare($var, ExecutionContext::SOURCE_LOOP)` перед проверкой параметров и `$scope->undeclare($var)` после; при выполнении — `$context->withTemporaryVariable($var, $record, $module, fn () => …)` | переменная удаляется и при исключении; так делает `UpdateRecordsHandler` для `item` |
| Внешняя система временно недоступна | `throw new RecoverableException('…')` | повторы, статус `retrying`, затем `failed` |
| Постоянный сбой (4xx, плохие данные) | `throw new NonRecoverableException('…', ['response' => …])` | шаг `failed`, подробности в `result_data.details` |
| Делать нечего | `return ActionResult::skipped('reason')` | шаг `skipped`, выполнение продолжается |

Перед повтором движок восстанавливает снимок контекста, поэтому `execute()` может менять контекст до того, как упадёт.
Внешние побочные эффекты не откатываются — проводите их через журнал побочных эффектов (см.
[ниже](#внешние-вызовы-и-журнал-побочных-эффектов)) или делайте идемпотентными.

В ведомых процессах область видимости создаётся с `inheritsVariables: true`, поэтому там `checkReadPath()` принимает
неизвестные переменные.

### Поля, принимающие секрет

Если действию нужны учётные данные (токен, пароль, DSN), объявите поле, в котором автор процесса указывает ссылку
`secret://name` или `secret://name.field`, и разрешайте её в `execute()` через `SecretResolver`
(`Container::secretResolver()`, передайте его в конструктор обработчика):

```php
$token = $this->secrets->resolve($actionConfig['auth']['token'], $context->secretMasker); // string|array
$hosts = $this->secrets->allowedHosts($actionConfig['auth']['token']);                      // [] = любые хосты
$kind = $this->secrets->kind($actionConfig['auth']['token']);                               // 'bearer', 'basic_auth', …
```

- `resolve()` расшифровывает значение (кэш в памяти воркера, `SECRETS_CACHE_TTL`) и регистрирует его и производные формы
  в маскере шага `$context->secretMasker`. Движок прогоняет `request`, `result`, текст и `details` ошибки шага через этот
  маскер перед записью в аудит, поэтому значение заменяется на `***` автоматически. Не кладите значение секрета в
  переменные процесса и в логи без маскера.
- Нет секрета или поля — `NonRecoverableException` (`Secret "x" not found`, `Secret "x" has no field "y"`).
- При сохранении процесса валидатор сам проверяет, что секрет существует, и отклоняет ссылки в полях данных
  (`value`, `expression`, `field`, `target`, `template`, `subject`, `body`, `text`, `html`) и в условиях. Поэтому поле
  учётных данных не называйте так, как поля данных. Если действие ожидает определённые виды секретов, проверьте
  `kind()` в `execute()` и завершите шаг понятной ошибкой.
- Если секрет уходит во внешнюю систему, проверьте хост запроса по `allowedHosts()`.

### Текстовые поля и шаблоны

Если поле действия — текст с подстановками (тема и тело письма, URL, заголовки, тело запроса), не собирайте его сами:
используйте `TemplateEngine` (`Container::templates()`) и проверяйте его при сохранении через `TemplateValidator`
(`Container::templateValidator()`). Режим экранирования `EscapeMode` выбирает действие по назначению поля:

```php
use Wps\Template\EscapeMode;
use Wps\Template\MissingPolicy;

// validate()
$this->templateValidator->validate($config['subject'] ?? null, $scope, "{$path}.subject", EscapeMode::Header);
$this->templateValidator->validate($config['html'] ?? null, $scope, "{$path}.html", EscapeMode::Html, allowRaw: false);
$this->templateValidator->validate($config['url'] ?? null, $scope, "{$path}.url", EscapeMode::Url);   // хост фиксирован
$this->templateValidator->validate($config['body'] ?? null, $scope, "{$path}.body", EscapeMode::Json); // массив-документ

// execute()
$resolve = static fn (string $p) => $context->resolve($p);
$subject = $this->templates->render($config['subject'], $resolve, EscapeMode::Header)->value;
$to = $this->templates->render($config['to'], $resolve, EscapeMode::Text, MissingPolicy::Error)->value;
$body = $this->templates->renderStructure($config['body'], $resolve)->value; // числа остаются числами
```

- `text` — как есть; `header` — CR/LF удаляются (+ предупреждение в `RenderResult::$warnings`); `html` —
  `htmlspecialchars`, фильтр `raw` допустим, только если вы передали `allowRaw: true` для доверенного поля; `url` —
  `rawurlencode` и запрет подстановок в схеме, хосте и порту; `json` — `renderStructure()`.
- Для получателей и URL используйте `MissingPolicy::Error`: пустой адрес не должен уходить молча.
- `RenderResult::$missing` и `$warnings` положите в `result` шага, чтобы они были видны в аудите.

### Внешние вызовы и журнал побочных эффектов

Шаг выполняется «как минимум один раз»: после падения воркера он повторяется целиком. Если действие отправляет
что-то во внешнюю систему (письмо, вебхук, уведомление), проведите вызов через `SideEffectLedger`
(`Container::sideEffects()`), иначе повтор отправит его второй раз. Подробно о модели —
[Надёжность](../../04-operations/reliability.md#побочные-эффекты-и-повторы).

```php
use Wps\SideEffects\DeliveryPolicy;
use Wps\SideEffects\HasDeliveryPolicy;
use Wps\SideEffects\SideEffectLedger;
use Wps\SideEffects\UnknownOutcomeException;

final class PostToErpHandler implements ActionHandlerInterface, HasDeliveryPolicy
{
    public function __construct(private readonly SideEffectLedger $ledger /* , … */) {}

    public function defaultDelivery(array $config): string
    {
        // повтор POST может создать дубль → по умолчанию «не более одного раза»
        return DeliveryPolicy::AT_MOST_ONCE;
    }

    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $delivery = DeliveryPolicy::resolve($actionConfig['delivery'] ?? null, $this->defaultDelivery($actionConfig));
        $request = ['url' => $url, 'body' => $body];                       // что уходит наружу (без секретов)
        $out = $this->ledger->run($context, $this->type(), '', $delivery, $request,
            function (string $idempotencyKey) use ($url, $body): array {
                // передайте ключ наружу: заголовок Idempotency-Key, Message-ID письма
                // ошибка ДО отправки (нет соединения, DNS)    → throw new RecoverableException(…)   — повторится
                // таймаут/обрыв ПОСЛЕ отправки (at_most_once) → throw UnknownOutcomeException::forEffect(…)
                // окончательная ошибка (4xx, отказ SMTP)      → throw new NonRecoverableException(…)
                return ['status' => 201, 'body' => $responseBody];          // сериализуемый результат
            });
        return ActionResult::success($request, $out['response'] + [
            'idempotency_key' => $out['idempotency_key'],
            'replayed' => $out['replayed'],
        ]);
    }
}
```

- `run()` пишет в журнал `started` до вызова, `done` с ответом после успеха (через маскер секретов шага), `failed` после
  невосстановимой ошибки; восстановимая ошибка и `UnknownOutcomeException` оставляют `started`.
- Повтор после `done` не вызывает замыкание и возвращает сохранённый ответ (`replayed: true`); чужой `started` при
  `at_most_once` — `UnknownOutcomeException` (`error_code: UNKNOWN_OUTCOME` в `result_data` шага).
- Несколько внешних вызовов в одном шаге (письмо каждому получателю, два канала уведомления) различайте параметром
  `$sub` — стабильным идентификатором вызова (`'0'`, `'1'`, `'slack'`): ключ эффекта станет `{pc}@{iteration}#{sub}`.
- Внешний ключ `$idempotencyKey` = `{execution_id}:{effect_key}`; для `Message-ID` писем используйте домен
  `AppConfig::$mailMessageIdDomain` (`WPS_MAIL_MESSAGE_ID_DOMAIN`).
- В тестовом прогоне (`$context->dryRun`) замыкание не вызывается, журнал не трогается, `response = ['dry_run' => true]`.
- Поле `delivery` разрешено только у действий, реализующих `HasDeliveryPolicy`: валидатор сам проверяет значение
  (`Delivery must be "at_most_once" or "at_least_once"`) и отклоняет его у остальных действий
  (`Action "modify" does not support "delivery"`).

### Ждущее действие (парковка)

Действие, которому нужно ждать долго (ответ внешней системы, срок), не должно спать в корутине: верните
`ActionResult::wait()`, и движок припаркует выполнение — снимок состояния уйдёт в `execution_waits`, воркер освободится,
а `WaitResumeJob` продолжит выполнение после срока, в том числе после перезапуска
([Движок выполнения → Ожидание](../../05-architecture/execution-engine.md#ожидание-и-пробуждение-waitresumejob)).
Шаг на время ожидания получает статус `waiting`.

```php
use Wps\Actions\ResumableActionInterface;

final class WaitForApprovalHandler implements ActionHandlerInterface, ResumableActionInterface
{
    public function execute(ExecutionContext $context, array $actionConfig): ActionResult
    {
        $dueAt = $this->clock->now()->modify('+1 day');
        // ActionResult::wait($dueAt, $reason, $request, $result, $info)
        return ActionResult::wait($dueAt, 'approval', ['ticket' => $id], [], ['ticket' => $id]);
    }

    public function resume(ExecutionContext $context, array $actionConfig, array $waitInfo): ActionResult
    {
        // $waitInfo — $info из wait() + due_at, parked_at
        return ActionResult::success(['ticket' => $waitInfo['ticket']], ['approved' => $this->check($waitInfo['ticket'])]);
    }
}
```

- `$reason` попадает в `executions.wait_reason` и `wait.reason` API; `$info` — в `execution_waits.info` и в `resume()`.
  Держите его маленьким и сериализуемым.
- Без `ResumableActionInterface` шаг после пробуждения просто становится `success` с `request`/`result` из `wait()`.
- `resume()` может вернуть `success`, `skipped` или бросить исключение — дальше работают обычные `on_error` и повторы.
- Короткие паузы (до `WAIT_INLINE_MAX_SECONDS`) дешевле выполнить в корутине (`Coroutine::sleep`), как это делает
  `DelayHandler`.
- `WAITING` допустим только там, где есть выполнение: в `prepare()`, при вычислении условий и в тестовом прогоне без
  `handle` шаг падает с `This step cannot wait here (condition evaluation or a test run)`.
- Снимок больше `WAIT_STATE_MAX_BYTES` не паркуется: `Execution state is too large to wait (N bytes, limit M)`.

## 5. UI и переводы

В редакторе процесса есть панели только для встроенных типов. Любой другой тип действия обрабатывается как **passthrough**
(`front/src/stores/processes.ts → formFromProcess()`): он показывается в блоке «Изменить объект / переменную» строкой
только для чтения со своим JSON (`ModifyPanel.vue`, подсказка `modify.passthroughHint`: «Это действие не редактируется
в табличном редакторе и будет сохранено без изменений») и сохраняется без изменений. Значит, новое действие можно создать
через API (`POST`/`PUT /api/processes`) или импортом JSON, а редакторы по-прежнему могут править остальной процесс в UI.

Чтобы в карточке процесса была понятная подпись, добавьте тип в `actionTypeLabel()` в
`front/src/views/ProcessDetailView.vue` и переведите его в обеих локалях:

```ts
// front/src/views/ProcessDetailView.vue
function actionTypeLabel(type: string): string {
  const keys: Record<string, string> = {
    modify: 'processDetail.actionType.modify',
    save_object: 'processDetail.actionType.saveObject',
    start_process: 'processDetail.actionType.startProcess',
    preload: 'processDetail.actionType.preload',
    log_message: 'processDetail.actionType.logMessage',
  }
  return keys[type] ? t(keys[type]) : type
}
```

```json
// front/src/locales/en.json → processDetail.actionType
"logMessage": "Write to log"
// front/src/locales/ru.json → processDetail.actionType
"logMessage": "Запись в журнал"
```

Чтобы действие можно было редактировать, добавьте тип строки в `ModifyRow` (`front/src/types/index.ts`), ветку в
`formFromProcess()`/`definitionFromForm()` и ветку редактора в `ModifyPanel.vue` (или новую панель в
`ProcessEditView.vue`); все тексты — в `locales/*.json`.

## 6. Тест

Это проверенная группа `testExtensions()`, общая для всех примеров расширений в этом разделе (компаратор
`starts_with`, тип изменения `append`, стратегия загрузки `by_id_or_field`, функция `repeat`, действие `log_message`,
эндпоинт `/api/dictionaries/function-categories`). Вызовите её из `run()` после `testRunOnce()`.

```php
private function testExtensions(): void
{
    echo "[extensions]\n";
    $this->test('Function categories dictionary lists date', function () {
        $res = $this->request('GET', '/api/dictionaries/function-categories', token: $this->viewerToken, expectedStatus: 200);
        $this->assertTrue(in_array('date', array_column($res['data'], 'value'), true));
    });
    $this->test('Change types and action types contain the new entries', function () {
        $changeTypes = $this->request('GET', '/api/dictionaries/change-types', token: $this->adminToken)['data'];
        $this->assertTrue(in_array('append', array_column($changeTypes, 'value'), true));
        $actionTypes = $this->request('GET', '/api/dictionaries/action-types', token: $this->adminToken)['data'];
        $this->assertTrue(in_array('log_message', array_column($actionTypes, 'value'), true));
    });
    $this->test('log_message without a message is rejected', function () {
        $res = $this->request('POST', '/api/processes', [
            'name' => 'TEST ext invalid', 'object_type' => 'TestExt', 'is_child' => false,
            'definition' => ['actions' => [['type' => 'log_message', 'level' => 'loud']]],
        ], $this->adminToken, 422);
        $fields = array_column($res['error']['details'], 'field');
        $this->assertTrue(in_array('definition.actions[0].message', $fields, true));
        $this->assertTrue(in_array('definition.actions[0].level', $fields, true));
    });

    $inn = (string)random_int(1000000000, 9999999999);
    $this->test('Seed an account with an INN in the mock CRM', function () use ($inn) {
        $raw = $this->raw('PUT', "/mock/objects/Accounts/{$this->accountId}-inn", ['name' => 'INN Company', 'inn' => $inn], base: $this->crmUrl);
        $this->assertEquals(200, $raw['status']);
    });
    $this->test('Create a process that uses all extensions', function () use ($inn) {
        $res = $this->request('POST', '/api/processes', [
            'name' => 'TEST ext all', 'object_type' => 'TestExt', 'is_child' => false,
            'definition' => [
                'conditions' => [['field' => 'object.phone', 'operator' => 'starts_with', 'value_type' => 'static', 'value' => '+7']],
                'preload' => [['var' => 'acc', 'source' => 'manual', 'module' => 'Accounts', 'strategy' => 'by_id_or_field', 'by_field' => 'inn', 'value' => $inn]],
                'actions' => [
                    ['type' => 'modify', 'target' => 'object.note', 'change_type' => 'append', 'value' => 'checked', 'separator' => ' | '],
                    ['type' => 'modify', 'target' => 'object.label', 'change_type' => 'calculate', 'value' => 'coalesce(object.nickname, object.name)'],
                    ['type' => 'log_message', 'message' => "concat('ext ', object.id, ' ', acc.name)"],
                ],
            ],
        ], $this->adminToken, 201);
        $this->createdProcesses['ext'] = $res['data']['id'];
    });
    $this->test('starts_with: a non-matching phone does not start the process', function () {
        $id = $this->createdProcesses['ext'] ?? $this->skip('ext process not created');
        $res = $this->request('POST', "/api/processes/{$id}/run", ['object' => ['id' => 'e0', 'phone' => '+1555']], $this->adminToken, 200);
        $this->assertFalse($res['data']['conditions_met']);
    });
    $this->test('All extensions work in one execution', function () {
        $id = $this->createdProcesses['ext'] ?? $this->skip('ext process not created');
        $res = $this->request('POST', "/api/processes/{$id}/run", ['object' => [
            'id' => 'e1', 'phone' => '+79990000000', 'note' => 'start', 'nickname' => '', 'name' => 'Bob',
        ]], $this->adminToken, 201);
        $execution = $this->waitForExecution($res['data']['execution_id']);
        $this->assertEquals('completed', $execution['status'], (string)($execution['error_message'] ?? ''));
        $object = $execution['context']['variables']['object'];
        $this->assertEquals('start | checked', $object['note']);
        $this->assertEquals('Bob', $object['label']);
        $this->assertEquals('INN Company', $execution['context']['variables']['acc']['name']);
        $last = $execution['actions'][count($execution['actions']) - 1];
        $this->assertEquals('log_message', $last['action_type']);
        $this->assertEquals('ext e1 INN Company', $last['result_data']['logged']);
    });
}
```

Проверенный результат (отдельный стенд `wpsext`, мок-CRM):

```
[extensions]
  Function categories dictionary lists date ... OK
  Change types and action types contain the new entries ... OK
  log_message without a message is rejected ... OK
  Seed an account with an INN in the mock CRM ... OK
  Create a process that uses all extensions ... OK
  starts_with: a non-matching phone does not start the process ... OK
  All extensions work in one execution ... OK

Passed: 74, Failed: 0, Skipped: 0
```

## 7. Чек-лист готовности

- [ ] `final`-обработчик в `back/src/Actions/`, уникальный `type()` в `snake_case`, `label()` и `description()`
- [ ] `validate()` сообщает о каждой статической проблеме с путём поля; читаемые пути проверены, создаваемые переменные объявлены
- [ ] `execute()` возвращает `ActionResult` с полезными `request`/`result` для аудита; при сбоях — правильный класс исключения
- [ ] Зарегистрирован в `Container::actions()`
- [ ] Подпись в `actionTypeLabel()` + `processDetail.actionType.*` в обеих локалях (или полноценный редактор)
- [ ] Добавлена интеграционная проверка, набор тестов зелёный, `npm run build` проходит
- [ ] Действие описано в разделе [Действия](../../03-user-guide/actions.md) (en + ru) и в `back/API.md`

<!-- footer-nav -->
---

← Назад: [Тестирование](../testing.md) | [Оглавление](../../README.md) | [Новый тип блока](new-block-type.md) Далее →
<!-- /footer-nav -->
