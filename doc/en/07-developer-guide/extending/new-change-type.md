# New change type

How to add a way of computing the new value in a `modify` action, using a verified `append` change type ("Append to value").

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../../ru/07-developer-guide/extending/new-change-type.md)
<!-- /lang-switch -->

## 1. When you need it

A `modify` action writes a value into `object.<field>` or `<variable>.<field>`. The built-in change types are
`set_value` (a typed value), `set_from_variable` (copy of another field) and `calculate` (an expression). Add a change
type when the value is produced by logic that is awkward as an expression — appending to a list, date arithmetic,
a lookup table. If only a function is missing in expressions, add an [expression function](expression-functions.md) instead.

## 2. How it works

```mermaid
classDiagram
    class ChangeTypeInterface {
        <<interface>>
        +name() string
        +label() string
        +compute(ExecutionContext, config) mixed
        +validate(config, DefinitionScope, path) void
    }
    ChangeTypeInterface <|.. SetValueChangeType
    ChangeTypeInterface <|.. SetFromVariableChangeType
    ChangeTypeInterface <|.. CalculateChangeType
    ChangeTypeInterface <|.. AppendChangeType
    ChangeTypeRegistry o-- ChangeTypeInterface
    ModifyHandler --> ChangeTypeRegistry
```

- `ModifyHandler::execute()` takes `change_type` from the action, calls `compute($context, $actionConfig)` with the **whole
  modify action** as config (`target`, `value`, `save_to_crm` and any extra keys you define), reads the old value, writes the
  new one with `$context->set($target, $new)` and puts `old_value`/`new_value` into the audit. With `save_to_crm: true` the
  variable is saved right after.
- `ModifyHandler::validate()` checks that `change_type` is registered, calls your `validate()` **before** the target is
  declared (the value is computed before it is written), then checks `target`.
- The registry is filled in `Container::changeTypes()`; `GET /api/dictionaries/change-types` lists it.

## 3. Step by step

### 3.1 The class — `back/src/Actions/ChangeTypes/AppendChangeType.php`

```php
<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Comparators\ComparatorRegistry;
use Wps\Context\ExecutionContext;
use Wps\Services\Validation\DefinitionScope;

/**
 * "Дописать": appends the value to the current value of the target field,
 * separated by "separator" (default: a space). An empty field simply gets the value.
 */
final class AppendChangeType implements ChangeTypeInterface
{
    public function name(): string
    {
        return 'append';
    }

    public function label(): string
    {
        return 'Дописать к значению';
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        $current = ComparatorRegistry::stringify($context->get((string)($config['target'] ?? '')));
        $value = ComparatorRegistry::stringify($config['value'] ?? '');
        if ($current === '') {
            return $value;
        }
        $separator = isset($config['separator']) && is_string($config['separator']) ? $config['separator'] : ' ';
        return $current . $separator . $value;
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $value = $config['value'] ?? null;
        if (!is_scalar($value) || (string)$value === '') {
            $scope->error("{$path}.value", 'Value to append is required');
        }
        if (isset($config['separator']) && !is_string($config['separator'])) {
            $scope->error("{$path}.separator", 'Separator must be a string');
        }
    }
}
```

### 3.2 Registration — `back/src/Kernel/Container.php`

```php
use Wps\Actions\ChangeTypes\AppendChangeType;

public function changeTypes(): ChangeTypeRegistry
{
    return $this->share(ChangeTypeRegistry::class, function () {
        $registry = new ChangeTypeRegistry();
        $registry->register(new SetValueChangeType());
        $registry->register(new SetFromVariableChangeType());
        $registry->register(new CalculateChangeType($this->expressions()));
        $registry->register(new AppendChangeType());
        return $registry;
    });
}
```

### 3.3 Use it

```json
{ "type": "modify", "target": "object.note", "change_type": "append", "value": "checked", "separator": " | " }
```

With `object.note = "start"` the result is `"start | checked"` (verified, see the test below).

## 4. Validation and errors

- Report config problems with `$scope->error("{$path}.<key>", …)`; `$path` is `definition.actions[N]`.
- If your config contains paths that are **read** (like `set_from_variable` does with `value`), check them with
  `$scope->checkReadPath($value, "{$path}.value")`, otherwise a typo is only found at run time.
- `compute()` runs inside the engine's retry loop: throw `NonRecoverableException` for bad data,
  `RecoverableException` only if you call something that can be temporarily unavailable.

## 5. UI and translations

The modify row in `ModifyPanel.vue` offers the change types from the dictionary (translated with
`dict.changeType.<name>`). For a new type:

1. Translations in both locales:

```json
// en.json → dict.changeType
"append": "Append to value"
// ru.json → dict.changeType
"append": "Дописать к значению"
```

2. Extend the TypeScript union in `front/src/types/index.ts`:

```ts
export type ChangeType = 'set_value' | 'set_from_variable' | 'calculate' | 'append'
```

A type that is not in the union still works at run time, but the editor code compares `change_type` with literal values
and `vue-tsc` keeps the union exhaustive for new branches.

3. The value field: `ModifyPanel.vue` shows a variable-path input for `set_from_variable`, an expression input with help for
`calculate` and a plain text input for anything else — `append` needs no change.

> **Warning**
> The editor keeps only `target`, `change_type`, `value` and `save_to_crm` of a modify row
> (`definitionFromForm()` in `front/src/stores/processes.ts`). Extra keys such as `separator` are **lost when the process is
> saved from the UI**. Either design the change type without extra keys, or add the key to `ModifyRow`/`ModifyAction`,
> `formFromProcess()`, `definitionFromForm()` and an input in `ModifyPanel.vue`.

## 6. Test

From the verified `testExtensions()` group (full text in [New action type → Test](new-action-type.md#6-test)):

```php
$this->test('Change types contain append', function () {
    $changeTypes = $this->request('GET', '/api/dictionaries/change-types', token: $this->adminToken)['data'];
    $this->assertTrue(in_array('append', array_column($changeTypes, 'value'), true));
});
// in a process: ['type' => 'modify', 'target' => 'object.note', 'change_type' => 'append', 'value' => 'checked', 'separator' => ' | ']
// manual run with 'note' => 'start', then:
$this->assertEquals('start | checked', $execution['context']['variables']['object']['note']);
```

## 7. Checklist

- [ ] `final` class in `back/src/Actions/ChangeTypes/`, unique `name()`
- [ ] Registered in `Container::changeTypes()`
- [ ] `dict.changeType.<name>` in both locales, `ChangeType` union extended, extra keys handled in the editor (or none)
- [ ] Integration check added, suite green, `npm run build` passes
- [ ] Described in [Change types and expressions](../../03-user-guide/change-types-and-expressions.md) (en + ru) and `back/API.md`

<!-- footer-nav -->
---

← Previous: [New comparison operator](new-comparator.md) | [Contents](../../README.md) | [New load strategy](new-load-strategy.md) Next →
<!-- /footer-nav -->
