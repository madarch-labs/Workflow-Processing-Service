# Expression functions

How to add a function to the WPS expression language, using the tutorial function `mask(s[, keep])` ("hide every
character of a string except the last keep") as the example.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../../ru/07-developer-guide/extending/expression-functions.md)
<!-- /lang-switch -->

## 1. When you need it

Expressions are used in `calculate` (`modify` / `set_variable` with `"change_type": "calculate"`), in templates
(`{{ … }}`, `|` filters), in the right-hand side of conditions (`value_type: "expression"`), in `delay`, `foreach` and
elsewhere. The language supports numbers, quoted strings, paths (`object.amount`, `v1.price`), `true` / `false` /
`null`, lists `[a, b]`, `+ - * / %`, comparisons, `and` / `or` / `not`, parentheses and about 120 functions in six
categories (full syntax — [Change types and expressions](../../03-user-guide/change-types-and-expressions.md), the list
with signatures — [`GET /api/dictionaries/functions`](../../06-api-reference/endpoints.md#get-apidictionariesfunctions)).

Add a function when users need a small **pure** computation: masking, a custom number format, working days by your own
calendar. For logic with its own configuration write a [change type](new-change-type.md); for calls to external
systems — an [action type](new-action-type.md).

> **Note**
> `mask` is a tutorial example; WPS does not ship it. Check `GET /api/dictionaries/functions` before choosing a name:
> registering an existing name (e.g. `repeat`, `pad_left`) silently **replaces** the built-in function.

## 2. How it works

- `Wps\Expression\ExpressionEngine` = `ExpressionLexer` (tokens) + `ExpressionParser` (AST) + an evaluator. No `eval`.
  Call grammar: `IDENT '(' [expr (',' expr)*] ')'`; function names are case-insensitive (stored in lower case).
- **Extension points:**
  - `registerFunction(string $name, callable $fn, ?FunctionSpec $spec = null): void` — a regular function: receives
    already evaluated arguments (a missing path gives `''`) and returns a value;
  - `registerSpecialForm(string $name, \Closure $fn, FunctionSpec $spec): void` — a special form: receives its
    arguments **unevaluated**, as `list<\Closure(): mixed>`, and decides which to evaluate. This is how `if`,
    `coalesce`, `ifempty`, `ifs`, `switch` work (`Wps\Expression\Functions\LogicFunctions`).
- **Where built-in functions are registered:**

  | Class (`back/src/Expression/Functions/`) | Category | Where `register()` is called | Dependencies |
  |---|---|---|---|
  | `ExpressionEngine::registerDefaultFunctions()` | `string` | engine constructor | — |
  | `LogicFunctions::register($engine)` | `logic` | engine constructor | — |
  | `NumberFunctions::register($engine)` | `number` | engine constructor | — |
  | `TextFunctions::register($engine)` | `string` | engine constructor | — |
  | `ListFunctions::register($engine)` | `list` | engine constructor | — |
  | `DateFunctions::register($engine, DateMath $math, Clock $clock)` | `date` | `Container::expressions()` | clock, `WPS_TIMEZONE` |
  | `TypeFunctions::register($engine, TypeCaster $caster, DateMath $dates)` | `type` | `Container::expressions()` | `TypeCaster`, `DateMath` |

  Functions without dependencies are registered in the constructor, so `new ExpressionEngine()` (unit tests) has them
  too. Functions that depend on configuration are registered in `Container::expressions()`; a
  `new ExpressionEngine()` outside the container has no date and type functions.
- **`Wps\Expression\FunctionSpec`** describes a function (constructor
  `new FunctionSpec(string $name, int $minArgs, ?int $maxArgs, string $category, string $signature, string $description, string $example = '', string $returns = 'any', bool $pure = true, ?\Closure $validator = null)`):

  | Parameter | Purpose |
  |---|---|
  | `name` | the name (as registered) |
  | `minArgs`, `maxArgs` | allowed number of arguments; `maxArgs = null` means no upper bound |
  | `category` | `logic`, `string`, `number`, `date`, `list`, `type`; `custom` for functions without a spec. You may use your own category: the dictionary sorts by it |
  | `signature` | signature for the help, e.g. `mask(s[, keep])` |
  | `description` | what the function does (built-ins use Russian); in the template-filters dictionary this is `label` |
  | `example` | example call (optional) |
  | `returns` | result type for the help: `string`, `number`, `boolean`, `date`, `datetime`, `list`, `any` (default `any`) |
  | `pure` | the result depends only on the arguments (default `true`); `false` for `now()`, `today()`. Exposed as `pure` in the dictionary |
  | `validator` | optional `\Closure(list<array> $argNodes): ?string` — checks **literal** arguments on save; returns an error text or `null` ([3.3](#33-check-literal-arguments)) |

  Without a `FunctionSpec` the arity is taken from the PHP signature by reflection (required parameters are the
  minimum, a variadic function has no maximum), the category is `custom`, the signature `name(…)`, the description is
  empty.
- **Checks on process save.** `parse()` (called by `CalculateChangeType::validate()`, `TemplateValidator`, the check of
  expressions in conditions, etc.) checks every call: an unknown function (`Unknown function "foo"`), a wrong number of
  arguments (`Function "mask" expects 1..2 arguments, got 3`) and a `validator` error give a `ConfigurationException`
  → `422`. At run time the argument count can no longer be wrong.
- **Reserved names** are `and`, `or`, `not`, `true`, `false`, `null` (`ExpressionLexer::KEYWORDS`): registering one
  throws `InvalidArgumentException` (`"and" is a reserved word and cannot be used as a function name`). A name must
  match `^[a-z_][a-z0-9_]*$` (`Invalid function name`). Registering the same name again replaces the function (and a
  special form with that name).
- **One engine per worker.** `Container::expressions()` creates one instance (with the shared `TypeCaster` in
  `WPS_TIMEZONE`); it is used by `CalculateChangeType`, `TemplateEngine`, `ConditionEngineService`, `LoopRunner`,
  `DelayHandler`, `ForeachBlock` and others, so a function registered there is available everywhere at once —
  including as a **template filter** and in the `GET /api/dictionaries/functions` and `/template-filters`
  dictionaries.
- **Engine limits:** an expression is at most 4000 characters (`MAX_LENGTH`), a string result at most 1 MB
  (`Expression result is too long`), a float result is normalized (`90.0` → `90`, `0.30000000000000004` → `0.3`),
  `NAN` / `INF` is an error (`Expression result is not a finite number`). The parsed AST is cached by the expression
  string (up to 1000 entries).
- **Engine helpers:** `call($name, ...$args)` — call a function with ready arguments, `spec($name)`, `hasFunction()`,
  `functionNames()`, `functionSpecs()`, `paths($expression)` — variable paths of an expression, `truthy($v)` —
  truthiness by the condition rules, `comparator()` — comparison rules
  (`ValueComparator::compare(string $op, $a, $b): bool`), `caster()` — the `TypeCaster`,
  `ExpressionEngine::toString($v)` — a value as a string by the language rules.

## 3. Step by step

| File | What to do |
|---|---|
| `back/src/Expression/Functions/MaskFunctions.php` | **new** — a class with a static `register()` |
| `back/src/Expression/ExpressionEngine.php` or `back/src/Kernel/Container.php` | the `register()` call (see 3.1) |
| `back/tests/Unit/Expression/MaskFunctionTest.php` | **new** — unit test |
| `back/tests/IntegrationTest.php` | a check in the `[expressions]` group or a group of its own |
| `front/src/components/ModifyPanel.vue` | (optional) the function in the "Calculate" help |
| `doc/{ru,en}/03-user-guide/change-types-and-expressions.md`, `back/API.md` | function description |

### 3.1 Register the function — `back/src/Kernel/Container.php`

A call in `Container::expressions()` is enough for one function, but a class with a static `register()` is handier —
every built-in set is built that way, and it is easy to plug into a test.

`back/src/Expression/Functions/MaskFunctions.php`:

```php
<?php

declare(strict_types=1);

namespace Wps\Expression\Functions;

use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\FunctionSpec;

/**
 * mask(s[, keep]): every character of s but the last keep (default 4) is replaced with "*".
 */
final class MaskFunctions
{
    public static function register(ExpressionEngine $engine): void
    {
        $engine->registerFunction('mask', static function (mixed $value, mixed $keep = 4): string {
            if (!is_int($keep) && !(is_string($keep) && ctype_digit($keep))) {
                throw new NonRecoverableException(sprintf('Function "mask": keep must be a non-negative integer, got "%s"', ExpressionEngine::toString($keep)));
            }
            $text = ExpressionEngine::toString($value);
            $hidden = max(0, mb_strlen($text) - (int)$keep);
            return str_repeat('*', $hidden) . mb_substr($text, $hidden);
        }, new FunctionSpec(
            'mask', 1, 2, 'string', 'mask(s[, keep])',
            'Скрывает символы строки звёздочками, кроме последних keep (по умолчанию 4)',
            'mask(object.card_number, 4)', 'string',
            validator: static function (array $args): ?string {
                $keep = FunctionSpec::literal($args[1] ?? null);
                return $keep !== null && !ctype_digit($keep)
                    ? "Function \"mask\": keep must be a non-negative integer, got \"{$keep}\""
                    : null;
            },
        ));
    }
}
```

Hook it up in one of two places:

```php
// back/src/Expression/ExpressionEngine.php — the function has no dependencies: available in new ExpressionEngine() too
public function __construct(?TypeCaster $caster = null)
{
    // …
    ListFunctions::register($this);
    MaskFunctions::register($this);
}
```

```php
// back/src/Kernel/Container.php — the function needs services of the container (clock, time zone, config …)
public function expressions(): ExpressionEngine
{
    return $this->share(ExpressionEngine::class, function (): ExpressionEngine {
        $engine = new ExpressionEngine($this->typeCaster());
        DateFunctions::register($engine, $this->dateMath(), $this->clock());
        TypeFunctions::register($engine, $this->typeCaster(), $this->dateMath());
        MaskFunctions::register($engine);
        return $engine;
    });
}
```

A special form — when arguments must not be evaluated up front (branching, defaults, lazy evaluation):

```php
// firstpositive(a, b, …): the first argument greater than zero; the rest are not evaluated
$engine->registerSpecialForm('firstpositive', static function (array $args) use ($engine): mixed {
    foreach ($args as $arg) {
        $value = $arg();
        if ($engine->comparator()->compare('>', $value, 0)) {
            return $value;
        }
    }
    return '';
}, new FunctionSpec('firstpositive', 1, null, 'number', 'firstpositive(a, b, …)', 'Первое значение больше нуля'));
```

A `FunctionSpec` is mandatory for a special form (reflection over a closure taking an argument list is meaningless).

### 3.2 Use it

```json
{ "type": "modify", "target": "object.card_masked", "change_type": "calculate", "value": "mask(object.card_number, 4)" }
```

With `card_number = "4111111111111111"` the result is `"************1111"`. A process with `mask()` is not saved:
`Function "mask" expects 1..2 arguments, got 0`; with `mask(object.card_number, 'x')` —
`Function "mask": keep must be a non-negative integer, got "x"`.

Every function is also immediately available as a **template filter**: the value left of `|` becomes the first
argument, the filter arguments the rest. `{{ object.card_number | mask:2 }}` is the same as `mask(object.card_number, 2)`.
The filter arity is checked against the same `FunctionSpec` (one argument fewer), and the function appears in the
`GET /api/dictionaries/template-filters` dictionary ([Template](../../03-user-guide/change-types-and-expressions.md#template)).

### 3.3 Check literal arguments

When an argument is a fixed value from a small set (a unit, a time zone, a format, a number of digits), check it on
save with `FunctionSpec::$validator`. The validator receives the AST nodes of the arguments;
`FunctionSpec::literal($node)` returns a string for a string or number literal (`'days'`, `'5'`) and `null` for a
computed argument (a path, a call, an expression) — such an argument is checked at run time. A validator error becomes
a `ConfigurationException`, i.e. `422` on save. This is how `unit` of `date_diff`, `tz` of every date function, the
precision of `round()`, the patterns of `regex_*`, the path of `json_get()` are checked (about 30 built-in functions in
total):

```php
new FunctionSpec('date_diff', 3, 3, 'date', 'date_diff(from, to, unit)', '…', "date_diff(object.date_modified, now(), 'days')", 'number',
    validator: static function (array $args): ?string {
        $unit = FunctionSpec::literal($args[2] ?? null);
        return $unit !== null && !in_array(strtolower($unit), DateMath::DIFF_UNITS, true)
            ? "Function \"date_diff\": unit must be one of …, got \"{$unit}\""
            : null;
    });
```

`date_diff(object.a, now(), 'decades')` is not saved; `date_diff(object.a, now(), object.unit)` is saved, and a wrong
field value gives a run-time error. So do not drop the check from the function body — the validator only duplicates it
for literals. A negative number in an expression (`mask('a', -1)`) is a `neg` node, not a literal: the validator does
not see it, and the error comes at run time.

### 3.4 Example: working days

Working days and a production calendar are not part of WPS. Date logic lives in `Wps\Time\DateMath` (`parse()`,
`toCanonical()`, `toDate()`, `addDays()`, `addMonths()`, `diff()`, `part()`, `isWeekend()`, `format()` …) — a
working-days function can be built on it (a sketch; holidays come from your own source). Register it in
`Container::expressions()`, where `$this->dateMath()` is available:

```php
// add_workdays(d, n): n working days forward (n >= 0), weekends and $holidays are skipped
$math = $this->dateMath();
$holidays = ['2026-11-04', '2027-01-01'];               // e.g. from configuration
$engine->registerFunction('add_workdays', static function (mixed $value, mixed $count) use ($math, $holidays): string {
    $n = DateMath::count($count, 'add_workdays');
    $date = $math->parse($value, 'add_workdays');
    if ($date === null) {
        return '';
    }
    $date = $math->toDate($date);
    while ($n > 0) {
        $date = $math->addDays($date, 1);
        if (!$math->isWeekend($date) && !in_array($math->toCanonical($date), $holidays, true)) {
            $n--;
        }
    }
    return $math->toCanonical($date);
}, new FunctionSpec('add_workdays', 2, 2, 'date', 'add_workdays(d, n)', 'Прибавляет рабочие дни', 'add_workdays(today(), 3)', 'date'));
```

Return an empty date as `''`; `DateMath` already throws parse and counter errors as `NonRecoverableException`.
Calendar operations of `DateMath` run in `WPS_TIMEZONE`, hours and minutes use exact time (`+1d` ≠ `+24h` across a DST
change).

## 4. Validation and errors

- Provide a `FunctionSpec` with the exact arity — then errors are found on save and the dictionary shows the signature
  and description. Without a spec the arity is derived from the PHP parameters: required ones as plain parameters,
  optional ones with default values, a variable count as `mixed ...$args`.
- **Run-time errors** — a `NonRecoverableException` with a clear text (`Function "mask": …`): the step fails, then
  `on_error` applies. Do not throw `RecoverableException` — a function does not call external systems, a retry fixes
  nothing. Other exceptions (`TypeError`, `ValueError`) also end the step, but with an obscure message — check the
  arguments yourself.
- **Value conversion.** For strings use `ExpressionEngine::toString($v)` (`null` → `''`, bool → `true`/`false`, array →
  JSON). Handle numbers with `Wps\Expression\Decimal`, not with `is_numeric()` / `float` — then your function computes
  as precisely as the operators and built-in functions (`NumberFunctions`):

  ```php
  use Wps\Expression\Decimal;

  $engine->registerFunction('net', static function (mixed $gross, mixed $rate) {
      foreach ([$gross, $rate] as $v) {
          if (!Decimal::isNumeric($v)) {   // strict format: '1 234,56' and '1e3' are not numbers
              throw new NonRecoverableException(sprintf('Function "net" requires a number, got "%s"', ExpressionEngine::toString($v)));
          }
      }
      $net = Decimal::div(Decimal::of($gross), Decimal::add('1', Decimal::div(Decimal::of($rate), '100')));
      return Decimal::toNative(Decimal::round($net, 2));   // int / float when lossless, otherwise a string
  }, new FunctionSpec('net', 2, 2, 'number', 'net(gross, rate)', 'Сумма без НДС', 'net(object.amount, 20)', 'number'));
  ```

  `Decimal::of()` turns an int / float / numeric string into a canonical string, the operations (`add`, `sub`, `mul`,
  `div`, `mod`, `round`, `floor`, `ceil`, `trunc`, `pow`, `sqrt`, `cmp`) work on bcmath strings, and `toNative()`
  returns the result by the language type rules. Parse "human" formatted text with
  `$engine->caster()->cast($v, new CastOptions(type: CastType::Decimal))`, like `to_number()`. Use
  `$engine->comparator()` for comparisons and `$engine->truthy()` for conditions, so the rules match the language
  operators.
- **Purity and speed.** No I/O (CRM, HTTP, database, files) and no randomness: an expression is evaluated for every
  event, in every loop iteration and on every step retry. Take the current time only from `Wps\Time\Clock`
  (`Container::clock()`), never from `time()` / `new DateTime()`, and mark such a function `pure: false`.
- **No state.** The engine is one per worker and shared by coroutines: a function must not keep data between calls in
  `static` variables or variables captured by reference.
- **Regular expressions** — only through `Wps\Expression\SafeRegex` (PCRE limits; pattern check on save —
  `SafeRegex::validate()` in the `validator`), like `regex_match()`.
- The result is written to the context and may be saved to the CRM; a string over 1 MB is an error
  (`Expression result is too long`).

## 5. UI and translations

The `GET /api/dictionaries/functions` dictionary (data from `functionSpecs()`, sorted by category and name, language
operators in `meta`) and `GET /api/dictionaries/template-filters` show the new function without further work; the
description comes from `FunctionSpec::$description` and is not translated through `locales/*.json`.

The expression help of the change block (`ModifyPanel.vue`, the "Calculate expression help" toggle) is a static list of
basic functions in code, not the dictionary. If the function matters to editors, add it there:

```vue
<i18n-t keypath="modify.help.functions" tag="li" scope="global">
  <template #list>
    <code>concat(a, b, …)</code>, <code>upper(s)</code>, <code>lower(s)</code>, <code>trim(s)</code>,
    <code>len(s)</code>, <code>substr(s, start[, length])</code>, <code>round(x[, precision])</code>,
    <code>abs(x)</code>, <code>min(a, b, …)</code>, <code>max(a, b, …)</code>, <code>mask(s[, keep])</code>
  </template>
</i18n-t>
```

No locale keys are needed. The variables side panel extracts paths from expressions with a regular expression
(`extractPaths()` in `front/src/utils/variables.ts`); function names without a dot are not taken for paths. How to add
an endpoint of your own — [New API endpoint](new-api-endpoint.md).

## 6. Test

### 6.1 Unit test — `back/tests/Unit/Expression/MaskFunctionTest.php`

Modelled on `TextFunctionsTest` / `ConditionalExpressionsTest`: values, save-time checks (`parse()`), a run-time
error, the template filter and the spec.

```php
<?php

declare(strict_types=1);

namespace Wps\Tests\Unit\Expression;

use PHPUnit\Framework\TestCase;
use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\Functions\MaskFunctions;
use Wps\Template\TemplateEngine;

final class MaskFunctionTest extends TestCase
{
    private static function engine(): ExpressionEngine
    {
        $engine = new ExpressionEngine();
        MaskFunctions::register($engine); // not needed once it is called from the ExpressionEngine constructor
        return $engine;
    }

    private static function eval(string $expression, array $vars = []): mixed
    {
        return self::engine()->evaluate($expression, static fn (string $path) => $vars[$path] ?? null);
    }

    public function testValues(): void
    {
        self::assertSame('************1111', self::eval("mask('4111111111111111')"));
        self::assertSame('*****67', self::eval("mask(object.phone, 2)", ['object.phone' => '1234567']));
        self::assertSame('abc', self::eval("mask('abc', 10)"), 'nothing to hide');
        self::assertSame('***', self::eval("mask('abc', 0)"));
        self::assertSame('', self::eval('mask(object.none)'), 'a missing path is an empty string');
        self::assertSame('**ёж', self::eval("mask('ёжёж', 2)"), 'characters, not bytes');
    }

    public function testArityAndLiteralsAreCheckedOnSave(): void
    {
        $engine = self::engine();
        foreach (["mask()" => 'Function "mask" expects 1..2 arguments, got 0', "mask('a', 'x')" => 'Function "mask": keep must be a non-negative integer, got "x"', "mask('a', -1)" => null] as $expression => $message) {
            try {
                $engine->parse($expression);
                self::assertNull($message, $expression);
            } catch (ConfigurationException $e) {
                self::assertSame($message, $e->getMessage(), $expression);
            }
        }
    }

    public function testComputedArgumentIsCheckedAtRunTime(): void
    {
        $this->expectException(NonRecoverableException::class);
        $this->expectExceptionMessage('Function "mask": keep must be a non-negative integer, got "x"');
        self::eval('mask(object.card, object.keep)', ['object.card' => '1234', 'object.keep' => 'x']);
    }

    public function testFilterAndSpec(): void
    {
        $engine = self::engine();
        $templates = new TemplateEngine($engine);
        $resolve = static fn (string $path) => $path === 'object.card' ? [true, '4111111111115678'] : [false, null];
        self::assertSame('Card ************5678', $templates->render('Card {{ object.card | mask }}', $resolve)->value);
        self::assertSame('Card **************78', $templates->render('Card {{ object.card | mask:2 }}', $resolve)->value);
        self::assertSame(['name' => 'mask', 'category' => 'string', 'signature' => 'mask(s[, keep])', 'min_args' => 1, 'max_args' => 2, 'returns' => 'string',
            'description' => 'Скрывает символы строки звёздочками, кроме последних keep (по умолчанию 4)', 'example' => 'mask(object.card_number, 4)', 'pure' => true],
            $engine->spec('mask')?->toArray());
    }
}
```

The class and the test were run against the current code (`OK`). The expression resolver returns the value of a path
(`null` when missing), while the template resolver returns a `[found, value]` pair, like `ExecutionContext::resolve()`.

Test time-dependent functions with `Wps\Time\FrozenClock` — the instant is set explicitly and changed with `set()`
(models: `DateFunctionsTest`, `DateMathTest`):

```php
$clock = new FrozenClock('2026-10-16 07:30:00');
$engine = new ExpressionEngine();
DateFunctions::register($engine, new DateMath(new \DateTimeZone('Europe/Moscow')), $clock);
self::assertSame('2026-10-16', $engine->evaluate('today()', static fn () => null));
$clock->set('2026-10-16 21:30:00');
self::assertSame('2026-10-17', $engine->evaluate('today()', static fn () => null)); // already the 17th in Moscow
```

Running — [Testing](../testing.md#backend-unit-tests).

### 6.2 Integration check — `back/tests/IntegrationTest.php`

Modelled on the `[expressions]` group (`testExpressions()`): the dictionary contains the function, a process with
wrong arguments is rejected with `422`, a process with the function runs.

```php
$this->test('mask(): dictionary, save-time checks, a manual run', function () {
    $functions = array_column($this->request('GET', '/api/dictionaries/functions', token: $this->viewerToken)['data'], null, 'name');
    $this->assertEquals('mask(s[, keep])', $functions['mask']['signature'] ?? null);
    $bad = $this->request('POST', '/api/processes', ['name' => 'TEST mask bad', 'object_type' => 'TestMask', 'is_child' => false, 'definition' => ['actions' => [
        ['type' => 'modify', 'target' => 'object.x', 'change_type' => 'calculate', 'value' => "mask(object.card, 'x')"],
    ]]], $this->adminToken, 422);
    $this->assertEquals('Function "mask": keep must be a non-negative integer, got "x"', array_column($bad['error']['details'], 'message', 'field')['definition.actions[0].value'] ?? null);
    $process = $this->request('POST', '/api/processes', ['name' => 'TEST mask', 'object_type' => 'TestMask', 'is_child' => false, 'definition' => ['actions' => [
        ['type' => 'modify', 'target' => 'object.card_masked', 'change_type' => 'calculate', 'value' => 'mask(object.card, 4)'],
    ]]], $this->adminToken, 201)['data'];
    $run = $this->request('POST', "/api/processes/{$process['id']}/run", ['object' => ['id' => 'm' . random_int(1000, 9999), 'module' => 'TestMask', 'card' => '4111111111111111']], $this->editorToken, 201)['data'];
    $e = $this->waitForExecution($run['execution_id']);
    $this->assertEquals('************1111', $e['context']['variables']['object']['card_masked'] ?? null);
    $this->request('DELETE', "/api/processes/{$process['id']}", token: $this->adminToken);
});
```

The function is also used in the shared `testExtensions()` template ([New action type → Test](new-action-type.md#6-test)).

## 7. Common pitfalls

| Mistake | Consequence | Do instead |
|---|---|---|
| The name matches a built-in function | the built-in is silently replaced in every process and template | check `GET /api/dictionaries/functions` |
| No `FunctionSpec` | category `custom`, empty description; the arity of `mixed $x = 4` is derived, but the help is poor | always pass a spec |
| An argument checked only in `validator` | a computed argument (a path) is not checked on save | check in the function body too |
| `time()` / `new DateTime()` | results not reproducible in tests, a step retry gives another value | `Clock` + `pure: false` |
| I/O inside a function | the expression runs in every iteration and on every retry | move it into an [action](new-action-type.md) |
| `float` arithmetic | `0.1 + 0.2` ≠ `0.3`, differs from the built-ins | `Decimal` |
| `preg_*` with a user pattern | ReDoS blocks the worker | `SafeRegex` |
| Registering a function with dependencies in the engine constructor | the constructor knows neither the clock nor `WPS_TIMEZONE` | `Container::expressions()` |

## 8. Readiness checklist

- [ ] The function is registered (`ExpressionEngine` constructor or `Container::expressions()`), lower-case name, not reserved and not taken
- [ ] A `FunctionSpec` with arity, category, signature, description, example, `returns`; `pure: false` for time functions
- [ ] Literal arguments checked by `validator`, computed ones in the body; errors are `NonRecoverableException`
- [ ] The function is pure, stateless and does no I/O; numbers via `Decimal`
- [ ] Unit test and integration check added, tests green
- [ ] (optional) added to the help in `ModifyPanel.vue`
- [ ] Listed in [Change types and expressions](../../03-user-guide/change-types-and-expressions.md) (en + ru) and in `back/API.md`

<!-- footer-nav -->
---

← Previous: [New load strategy](new-load-strategy.md) | [Contents](../../README.md) | [New API endpoint](new-api-endpoint.md) Next →
<!-- /footer-nav -->
