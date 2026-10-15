# Expression functions

How to add a function to `calculate` expressions, using a verified `coalesce(a, b, …)` as the example.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../../ru/07-developer-guide/extending/expression-functions.md)
<!-- /lang-switch -->

## 1. When you need it

`calculate` expressions (`modify` with `"change_type": "calculate"`) support numbers, quoted strings, paths
(`object.amount`, `v1.price`), `+ - * / %`, parentheses and the functions `concat`, `upper`, `lower`, `trim`, `len`,
`substr`, `round`, `abs`, `min`, `max`. Add a function when users need a small pure computation — a default value,
date formatting, padding. For logic with its own configuration write a [change type](new-change-type.md).

## 2. How it works

- `Wps\Expression\ExpressionEngine` = `ExpressionLexer` (tokens) + `ExpressionParser` (AST) + evaluator. There is no `eval`.
  Grammar of a call: `IDENT '(' [expr (',' expr)*] ')'`; function names are case-insensitive (stored lower-case).
- `registerFunction(string $name, callable $fn)` is the extension point. The callable receives already evaluated
  arguments (a missing path gives `''`) and returns the value.
- **Save time**: `CalculateChangeType::validate()` parses the expression; `parse()` checks that every called function is
  registered (`Unknown function "foo"` → `422`). **Run time**: a wrong argument count becomes
  `NonRecoverableException("Wrong number of arguments for function foo()")`.
- One engine instance per worker is created in `Container::expressions()` and shared by `CalculateChangeType` and
  everything else that evaluates expressions, so a function registered there is available everywhere at once.
- The parsed AST is cached per expression string (up to 1000 entries).

## 3. Step by step

### 3.1 Register the function — `back/src/Kernel/Container.php`

```php
public function expressions(): ExpressionEngine
{
    return $this->share(ExpressionEngine::class, static function (): ExpressionEngine {
        $engine = new ExpressionEngine();
        // coalesce(a, b, …): the first argument that is not null and not an empty string
        $engine->registerFunction('coalesce', static function (mixed ...$args): mixed {
            foreach ($args as $arg) {
                if ($arg !== null && $arg !== '') {
                    return $arg;
                }
            }
            return '';
        });
        return $engine;
    });
}
```

For a larger set create a class (for example `Wps\Expression\DateFunctions` with a static `register(ExpressionEngine $e)`)
and call it here — the built-in functions are registered the same way in `ExpressionEngine::registerDefaultFunctions()`.

### 3.2 Use it

```json
{ "type": "modify", "target": "object.label", "change_type": "calculate", "value": "coalesce(object.nickname, object.name)" }
```

With `nickname = ""` and `name = "Bob"` the result is `"Bob"` (verified).

## 4. Validation and errors

- Required arguments → declare them as normal parameters: PHP's `ArgumentCountError` is turned into a clear
  non-recoverable error. Optional ones get defaults, variadic ones `mixed ...$args`.
- Converting values: use `ExpressionEngine::toString($v)` for strings. For numbers follow the built-ins (they throw
  `NonRecoverableException` for non-numeric input through the engine's private `toNumber()`); in your function check
  `is_numeric()` and throw `NonRecoverableException` yourself.
- Functions must be **pure and fast**: no I/O, no randomness that breaks retries. The result is written into the context and
  may be saved to the CRM.

## 5. UI and translations

The expression help in the modify block (`ModifyPanel.vue`, toggle "“Calculate” expression help") lists the functions as
literal code, not translated text. Add the new one to the list:

```vue
<i18n-t keypath="modify.help.functions" tag="li" scope="global">
  <template #list>
    <code>concat(a, b, …)</code>, <code>upper(s)</code>, <code>lower(s)</code>, <code>trim(s)</code>,
    <code>len(s)</code>, <code>substr(s, start[, length])</code>, <code>round(x[, precision])</code>,
    <code>abs(x)</code>, <code>min(a, b, …)</code>, <code>max(a, b, …)</code>, <code>coalesce(a, b, …)</code>
  </template>
</i18n-t>
```

No locale keys are needed. The variable sidebar extracts paths from expressions with a regular expression
(`extractPaths()` in `front/src/utils/variables.ts`); function names without a dot are not taken for paths.

To show the list dynamically, expose `ExpressionEngine::functionNames()` through an endpoint — this exact endpoint is the
example in [New API endpoint](new-api-endpoint.md).

## 6. Test

From the verified `testExtensions()` group ([full text](new-action-type.md#6-test)):

```php
// action: ['type' => 'modify', 'target' => 'object.label', 'change_type' => 'calculate',
//          'value' => 'coalesce(object.nickname, object.name)']
// manual run with 'nickname' => '', 'name' => 'Bob':
$this->assertEquals('Bob', $execution['context']['variables']['object']['label']);
```

And an unknown function is rejected on save (existing check in `testProcessValidation()` uses a syntax error; the same
`definition.actions[0].value` field is reported for `foo(1)`).

## 7. Checklist

- [ ] Function registered in `Container::expressions()` (lower-case name, pure callable)
- [ ] Argument errors produce `NonRecoverableException`
- [ ] Added to the help list in `ModifyPanel.vue`
- [ ] Integration check added, suite green
- [ ] Listed in [Change types and expressions](../../03-user-guide/change-types-and-expressions.md) (en + ru) and `back/API.md`

<!-- footer-nav -->
---

← Previous: [New load strategy](new-load-strategy.md) | [Contents](../../README.md) | [New API endpoint](new-api-endpoint.md) Next →
<!-- /footer-nav -->
