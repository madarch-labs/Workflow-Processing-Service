# New action type

How to add a new kind of step to process definitions, using a verified `log_message` action ("Write to log") as the example.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../../ru/07-developer-guide/extending/new-action-type.md)
<!-- /lang-switch -->

## 1. When you need it

Actions are the steps of a process: `preload`, `modify`, `save_object`, `start_process`. Add a new type when a process
must do something none of them can: call a custom API, send an e-mail or a webhook, write to a queue, log a business
message. If you only need a new way to *compute a value*, write a [change type](new-change-type.md) instead; for a new
way to *load an object*, a [load strategy](new-load-strategy.md).

## 2. How it works

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

- A step in `definition.actions[]` is a JSON object with `"type"`; all other keys are the handler's config.
- **Save time** — `ProcessValidatorService` walks the definition in execution order and calls `validate($config, $scope, $path)`.
  Report problems with `$scope->error("{$path}.field", 'message')`; they become `422 VALIDATION_ERROR` with
  `details[].field = "definition.actions[N].field"`. Check paths you read with `$scope->checkReadPath()` (the variable
  must already exist at this point), declare variables you create with `$scope->declare()` / `checkWritePath()`.
- **Run time** — `ExecutionEngineService::executeStep()` calls `execute($context, $config)`:
  - read and write process data through `ExecutionContext` (`get('object.phone')`, `set('v1.rating', 'VIP')`,
    `getVariable()`, `setVariable()`, `executionId`, `processId`, `stepIndex`);
  - return `ActionResult::success($request, $result)` or `ActionResult::skipped($reason)`. `$request` and `$result` are
    written to the audit (`execution_actions.request_data` / `result_data`) and shown in the UI;
  - throw `RecoverableException` for temporary failures — the engine restores the context snapshot and retries
    `ACTION_RETRY_MAX` times with exponential backoff; throw `NonRecoverableException`/`ConfigurationException` for
    permanent failures — the step and the execution become `failed`, the remaining steps `skipped`.
- `GET /api/dictionaries/action-types` returns `type`, `label`, `description` of every registered handler.
- Handlers are created in `Wps\Kernel\Container::actions()`, so they can receive any service (CRM adapter, logger,
  expression engine, repositories) through the constructor.

## 3. Step by step

### 3.1 The handler — `back/src/Actions/LogMessageHandler.php`

The action evaluates a `calculate`-style expression and writes the result to the service log and to the step audit:
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

### 3.2 Registration — `back/src/Kernel/Container.php`

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

> **Warning**
> Do not call `$this->engine()` directly in a handler constructor: the engine itself depends on the action registry.
> Pass a closure, as `StartProcessHandler` does.

### 3.3 Rebuild and use

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

Each executed step writes one log line. A line from the verification run (message `concat('ext ', object.id, ' ', acc.name)`):

```json
{"timestamp":"2026-10-15T08:06:27.619Z","level":"info","message":"ext e1 INN Company","context":{"execution_id":"38839d14-…","process_id":13,"step":3},"pid":14}
```

## 4. Validation and errors

| Situation | What to do | Result |
|---|---|---|
| Missing/invalid config | `$scope->error()` in `validate()` | `422`, `details[].field = definition.actions[N].<key>` |
| Reads a variable that does not exist yet | `$scope->checkReadPath($path, $field)` | `Variable "v9" is not defined at this point` |
| Creates a variable | `$scope->declare('name', 'created')` or `checkWritePath()` | later steps may use it |
| External system temporarily down | `throw new RecoverableException('…')` | retried, status `retrying`, then `failed` |
| Permanent failure (4xx, bad data) | `throw new NonRecoverableException('…', ['response' => …])` | step `failed`, details in `result_data.details` |
| Nothing to do | `return ActionResult::skipped('reason')` | step `skipped`, execution continues |

The engine restores the context snapshot before a retry, so `execute()` may change the context before it fails.
External side effects are not rolled back — make them idempotent if they can be retried.

In child processes the scope is created with `inheritsVariables: true`, so `checkReadPath()` accepts unknown variables there.

## 5. UI and translations

The process editor has panels only for the built-in types. Any other action type is handled as **passthrough**
(`front/src/stores/processes.ts → formFromProcess()`): it is shown in the "Modify object / variable" block as a read-only
row with its JSON (`ModifyPanel.vue`, tooltip `modify.passthroughHint`) and saved unchanged. So a new action can be
created through the API (`POST`/`PUT /api/processes`) or by importing JSON, and editors can still edit the rest of the
process in the UI.

For a readable caption in the process card add the type to `actionTypeLabel()` in
`front/src/views/ProcessDetailView.vue` and translate it in both locales:

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

To make the action editable, add a row type to `ModifyRow` (`front/src/types/index.ts`), a branch in
`formFromProcess()`/`definitionFromForm()` and an editor branch in `ModifyPanel.vue` (or a new panel in
`ProcessEditView.vue`), with all texts in `locales/*.json`.

## 6. Test

This is the verified `testExtensions()` group used for all extension examples in this section (comparator
`starts_with`, change type `append`, load strategy `by_id_or_field`, function `coalesce`, action `log_message`,
endpoint `/api/dictionaries/expression-functions`). Call it from `run()` after `testRunOnce()`.

```php
private function testExtensions(): void
{
    echo "[extensions]\n";
    $this->test('Expression functions dictionary lists coalesce', function () {
        $res = $this->request('GET', '/api/dictionaries/expression-functions', token: $this->viewerToken, expectedStatus: 200);
        $this->assertTrue(in_array('coalesce', array_column($res['data'], 'value'), true));
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

Verified result (separate stack `wpsext`, mock CRM):

```
[extensions]
  Expression functions dictionary lists coalesce ... OK
  Change types and action types contain the new entries ... OK
  log_message without a message is rejected ... OK
  Seed an account with an INN in the mock CRM ... OK
  Create a process that uses all extensions ... OK
  starts_with: a non-matching phone does not start the process ... OK
  All extensions work in one execution ... OK

Passed: 74, Failed: 0, Skipped: 0
```

## 7. Checklist

- [ ] `final` handler in `back/src/Actions/`, unique `snake_case` `type()`, `label()` and `description()`
- [ ] `validate()` reports every static problem with a field path; read paths checked, created variables declared
- [ ] `execute()` returns `ActionResult` with useful `request`/`result` for the audit; correct exception class for failures
- [ ] Registered in `Container::actions()`
- [ ] Caption in `actionTypeLabel()` + `processDetail.actionType.*` in both locales (or a full editor)
- [ ] Integration check added, suite green, `npm run build` passes
- [ ] Action described in [Actions](../../03-user-guide/actions.md) (en + ru) and `back/API.md`

<!-- footer-nav -->
---

← Previous: [Testing](../testing.md) | [Contents](../../README.md) | [New comparison operator](new-comparator.md) Next →
<!-- /footer-nav -->
