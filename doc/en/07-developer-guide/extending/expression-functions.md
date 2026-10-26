# Expression functions

How to add a function to `calculate` expressions, using a verified `repeat(s, n)` as the example.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../../ru/07-developer-guide/extending/expression-functions.md)
<!-- /lang-switch -->

## 1. When you need it

`calculate` expressions (`modify` with `"change_type": "calculate"`) support numbers, quoted strings, paths
(`object.amount`, `v1.price`), `true` / `false` / `null`, lists `[a, b]`, `+ - * / %`, comparisons, `and` / `or` / `not`,
parentheses, the conditional functions `if`, `coalesce`, `ifempty`, `ifs`, `switch` and the functions `concat`, `upper`,
`lower`, `trim`, `len`, `substr`, `round`, `abs`, `min`, `max`, the date functions `now`, `today`, `add_days`, `date_diff`, `format_date` and others (full syntax — [Change types and expressions](../../03-user-guide/change-types-and-expressions.md)).
Add a function when users need a small pure computation — padding, repeating a character, working days by your own calendar. For logic with
its own configuration write a [change type](new-change-type.md).

## 2. How it works

- `Wps\Expression\ExpressionEngine` = `ExpressionLexer` (tokens) + `ExpressionParser` (AST) + evaluator. There is no `eval`.
  Grammar of a call: `IDENT '(' [expr (',' expr)*] ')'`; function names are case-insensitive (stored lower-case).
- Extension points:
  - `registerFunction(string $name, callable $fn, ?FunctionSpec $spec = null)` — a regular function: receives already
    evaluated arguments (a missing path gives `''`) and returns the value;
  - `registerSpecialForm(string $name, \Closure $fn, FunctionSpec $spec)` — a special form: receives its arguments
    **unevaluated**, as `list<\Closure(): mixed>`, and decides which ones to evaluate. `if`, `coalesce`, `ifempty`, `ifs`,
    `switch` work this way (`Wps\Expression\Functions\LogicFunctions`).
- `Wps\Expression\FunctionSpec` describes a function:

  | Parameter | Purpose |
  |---|---|
  | `name` | the name (as registered) |
  | `minArgs`, `maxArgs` | the allowed number of arguments; `maxArgs = null` — no upper bound |
  | `category` | `logic`, `string`, `number`, `date`, `list`, `type`, `system` or `custom` |
  | `signature` | the signature for the help, e.g. `repeat(s, n)` |
  | `description` | what the function does |
  | `example` | a sample call (optional) |
  | `returns` | the result type: `string`, `number`, `bool`, `any` (default `any`) |
  | `pure` | the result depends on the arguments only (default `true`); `false` for `now()`, `today()`: on a step retry the value is recomputed, only the parse is cached, not the result. Shown as `pure` in the dictionary |
  | `validator` | optional `\Closure(list<array> $argNodes): ?string` — checks **literal** arguments on save; returns an error text or `null` ([3.3](#33-check-literal-arguments)) |

  Without a `FunctionSpec` the arity is taken from the PHP signature by reflection (required parameters are the minimum,
  a variadic function has no maximum), the category is `custom`, the signature is `name(…)`.
- **The arity is checked when the process is saved**: `CalculateChangeType::validate()` parses the expression and
  `parse()` checks every call — an unknown function (`Unknown function "foo"`) and a wrong number of arguments
  (`Function "repeat" expects 2 arguments, got 1`) give `422`. At run time the argument count can no longer be wrong.
- **Reserved names** are `and`, `or`, `not`, `true`, `false`, `null` (`ExpressionLexer::KEYWORDS`): registering such a name
  throws `InvalidArgumentException` (`"and" is a reserved word and cannot be used as a function name`). The name must match
  `^[a-z_][a-z0-9_]*$`. Registering the same name again replaces the function.
- One engine instance per worker is created in `Container::expressions()` (with the shared `TypeCaster`) and used by
  `CalculateChangeType` and everything else that evaluates expressions, so a function registered there is available
  everywhere at once, including the [`GET /api/dictionaries/functions`](../../06-api-reference/endpoints.md#get-apidictionariesfunctions) dictionary.
- The parsed AST is cached per expression string (up to 1000 entries).
- Engine helpers: `call($name, ...$args)` — call a function with ready arguments, `hasFunction()`, `functionNames()`,
  `functionSpecs()`, `truthy($v)` — truthiness by the condition rules, `comparator()` — the comparison rules
  (`ValueComparator`), `caster()` — the `TypeCaster`.

## 3. Step by step

### 3.1 Register the function — `back/src/Kernel/Container.php`

```php
public function expressions(): ExpressionEngine
{
    return $this->share(ExpressionEngine::class, function (): ExpressionEngine {
        $engine = new ExpressionEngine($this->typeCaster());
        DateFunctions::register($engine, $this->dateMath(), $this->clock());
        // repeat(s, n): the string s repeated n times
        $engine->registerFunction(
            'repeat',
            static fn (mixed $s, mixed $n): string => str_repeat(ExpressionEngine::toString($s), max(0, (int)$n)),
            new FunctionSpec('repeat', 2, 2, 'string', 'repeat(s, n)', 'String s repeated n times', "repeat('*', 5)", 'string'),
        );
        return $engine;
    });
}
```

For a larger set create a class with a static `register()` and call it here. `LogicFunctions` (registered in the engine
constructor) and `DateFunctions` work this way — `register(ExpressionEngine $e, DateMath $math, Clock $clock)`: the
container passes the clock and `WPS_TIMEZONE` (`Container::clock()` is a `SystemClock`, `Container::dateMath()` a
`DateMath` with the `WPS_TIMEZONE` zone), so `new ExpressionEngine()` without the container has no date functions. String
and number functions are registered in `ExpressionEngine::registerDefaultFunctions()`.

A special form — when the arguments must not be evaluated up front (branching, a default value):

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
}, new FunctionSpec('firstpositive', 1, null, 'number', 'firstpositive(a, b, …)', 'The first value greater than zero'));
```

### 3.2 Use it

```json
{ "type": "modify", "target": "object.stars", "change_type": "calculate", "value": "repeat('*', object.rating)" }
```

With `rating = 3` the result is `"***"`. A process with `repeat('*')` is not saved: `Function "repeat" expects 2 arguments, got 1`.

Every function is also available as a **template filter** right away: the value left of `|` becomes the first
argument, the filter arguments the rest. `{{ '*' | repeat:object.rating }}` is the same as `repeat('*', object.rating)`.
The filter arity is checked with the same `FunctionSpec` (one argument fewer), and the function appears in
`GET /api/dictionaries/template-filters` ([Template](../../03-user-guide/change-types-and-expressions.md#template)).

### 3.3 Check literal arguments

If an argument is a fixed value from a small set (a unit, a time zone, a format), check it on save with
`FunctionSpec::$validator`. The validator gets the AST nodes of the arguments; `FunctionSpec::literal($node)` returns a
string for a literal (`'days'`, `5`) and `null` for a computed argument (a path, a call) — such an argument is checked at
run time. A validator error becomes a `ConfigurationException`, i.e. `422` on save. This is how `unit` in `date_diff` and
`tz` in all date functions are checked:

```php
new FunctionSpec('date_diff', 3, 3, 'date', 'date_diff(from, to, unit)', '…', "date_diff(object.date_modified, now(), 'days')", 'number',
    validator: static function (array $args): ?string {
        $unit = FunctionSpec::literal($args[2] ?? null);
        return $unit !== null && !in_array(strtolower($unit), DateMath::DIFF_UNITS, true)
            ? "Function \"date_diff\": unit must be one of …, got \"{$unit}\""
            : null;
    });
```

`date_diff(object.a, now(), 'decades')` is not saved; `date_diff(object.a, now(), object.unit)` is saved, and a wrong field
value fails at run time.

### 3.4 Example: working days

Working days and a holiday calendar are not part of WPS. The date logic lives in `Wps\Time\DateMath` (`parse()`,
`toCanonical()`, `addDays()`, `part()`, `isWeekend()` …) — a working-day function can be built on it (a sketch; holidays
come from your own source):

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
}, new FunctionSpec('add_workdays', 2, 2, 'date', 'add_workdays(d, n)', 'Adds working days', 'add_workdays(today(), 3)', 'date'));
```

Return an empty date as `''`; `DateMath` already throws parse and count errors as `NonRecoverableException`.

## 4. Validation and errors

- Pass a `FunctionSpec` with the exact arity — then the error is found on save and the dictionary shows the signature and
  description. Without a spec the arity is derived from the PHP parameters: required ones are normal parameters, optional
  ones get defaults, variadic ones `mixed ...$args`.
- Converting values: use `ExpressionEngine::toString($v)` for strings. Handle numbers with `Wps\Expression\Decimal`
  rather than `is_numeric()` / `float` — then your function computes as exactly as the operators and the built-in
  functions (`NumberFunctions`):

  ```php
  use Wps\Expression\Decimal;

  $engine->registerFunction('net', static function (mixed $gross, mixed $rate) {
      foreach ([$gross, $rate] as $v) {
          if (!Decimal::isNumeric($v)) {   // strict format: '1 234,56' and '1e3' are not numbers
              throw new NonRecoverableException(sprintf('Function "net" requires a number, got "%s"', ExpressionEngine::toString($v)));
          }
      }
      $net = Decimal::div(Decimal::of($gross), Decimal::add('1', Decimal::div(Decimal::of($rate), '100')));
      return Decimal::toNative(Decimal::round($net, 2));   // int / float when lossless, a string otherwise
  }, new FunctionSpec('net', 2, 2, 'number', 'net(gross, rate)', 'Amount without VAT', 'net(object.amount, 20)', 'number'));
  ```

  `Decimal::of()` turns an int / float / numeric string into a canonical string, the operations (`add`, `sub`, `mul`,
  `div`, `mod`, `round`, `floor`, `ceil`, `trunc`, `pow`, `sqrt`, `cmp`) work on bcmath strings, and `toNative()` returns
  the result by the language's type rules. Parse human-formatted text with
  `$engine->caster()->cast($v, new CastOptions(type: CastType::Decimal))`, like `to_number()` does. Use `$engine->comparator()` for comparisons and
  `$engine->truthy()` for conditions so the rules match the language operators.
- Functions must be **pure and fast**: no I/O, no randomness that breaks retries. Take the current time only from
  `Wps\Time\Clock` (`Container::clock()`), not from `time()` / `new DateTime()`, and mark such a function `pure: false`. The result is written into the context and
  may be saved to the CRM; an expression result over 1 MB fails with `Expression result is too long`.

## 5. UI and translations

The expression help in the modify block (`ModifyPanel.vue`, toggle "“Calculate” expression help") lists the functions as
literal code, not translated text. Add the new one to the list:

```vue
<i18n-t keypath="modify.help.functions" tag="li" scope="global">
  <template #list>
    <code>concat(a, b, …)</code>, <code>upper(s)</code>, <code>lower(s)</code>, <code>trim(s)</code>,
    <code>len(s)</code>, <code>substr(s, start[, length])</code>, <code>round(x[, precision])</code>,
    <code>abs(x)</code>, <code>min(a, b, …)</code>, <code>max(a, b, …)</code>, <code>repeat(s, n)</code>
  </template>
</i18n-t>
```

No locale keys are needed. The variable sidebar extracts paths from expressions with a regular expression
(`extractPaths()` in `front/src/utils/variables.ts`); function names without a dot are not taken for paths.

`GET /api/dictionaries/functions` already returns the functions with signatures and descriptions (data from
`functionSpecs()`), so a new function shows up there without extra work. To add an endpoint of your own see
[New API endpoint](new-api-endpoint.md).

## 6. Test

A unit test (`back/tests/Unit/Expression/`, run with `composer test:unit`), following `ConditionalExpressionsTest`:

```php
public function testRepeat(): void
{
    $engine = new ExpressionEngine();
    $engine->registerFunction('repeat', static fn (mixed $s, mixed $n) => str_repeat((string)$s, (int)$n));
    self::assertSame('abab', $engine->evaluate("repeat('ab', 2)", static fn () => null));
    $this->expectException(ConfigurationException::class); // arity is checked on parse, i.e. on save
    $engine->parse("repeat('ab')");
}
```

Test time-dependent functions with `Wps\Time\FrozenClock` — the moment is set explicitly and changed with `set()`
(see `DateFunctionsTest`, `DateMathTest`):

```php
$clock = new FrozenClock('2026-10-16 07:30:00');
$engine = new ExpressionEngine();
DateFunctions::register($engine, new DateMath(new \DateTimeZone('Europe/Moscow')), $clock);
self::assertSame('2026-10-16', $engine->evaluate('today()', static fn () => null));
$clock->set('2026-10-16 21:30:00');
self::assertSame('2026-10-17', $engine->evaluate('today()', static fn () => null)); // already the 17th in Moscow
```

Integration check — following the `[expressions]` group in `back/tests/IntegrationTest.php` (`testExpressions()`): a
process with a wrong arity is rejected with `422`, the `GET /api/dictionaries/functions` dictionary lists the function.

## 7. Checklist

- [ ] Function registered in `Container::expressions()` (lower-case, non-reserved name, pure callable)
- [ ] A `FunctionSpec` with arity, category, signature, description and example is passed
- [ ] Argument errors produce `NonRecoverableException`
- [ ] Added to the help list in `ModifyPanel.vue`
- [ ] Unit test and integration check added, suites green
- [ ] Listed in [Change types and expressions](../../03-user-guide/change-types-and-expressions.md) (en + ru) and `back/API.md`

<!-- footer-nav -->
---

← Previous: [New load strategy](new-load-strategy.md) | [Contents](../../README.md) | [New API endpoint](new-api-endpoint.md) Next →
<!-- /footer-nav -->
