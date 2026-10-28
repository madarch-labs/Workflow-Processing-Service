# Функции выражений

Как добавить функцию в язык выражений WPS — на учебном примере `mask(s[, keep])` («скрыть символы строки, кроме
последних keep»).

<!-- lang-switch -->
[🇬🇧 English](../../../en/07-developer-guide/extending/expression-functions.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## 1. Когда это нужно

Выражения используются в `calculate` (`modify` / `set_variable` с `"change_type": "calculate"`), в шаблонах
(`{{ … }}`, фильтры `|`), в правой части условий (`value_type: "expression"`), в `delay`, `foreach` и других местах.
Язык поддерживает числа, строки в кавычках, пути (`object.amount`, `v1.price`), `true` / `false` / `null`, списки
`[a, b]`, `+ - * / %`, сравнения, `and` / `or` / `not`, скобки и около 120 функций шести категорий (полный синтаксис —
[Типы изменений и выражения](../../03-user-guide/change-types-and-expressions.md), список с сигнатурами —
[`GET /api/dictionaries/functions`](../../06-api-reference/endpoints.md#get-apidictionariesfunctions)).

Функцию стоит добавить, когда пользователям нужно небольшое **чистое** вычисление: маскирование, свой формат номера,
рабочие дни по своему календарю. Для логики с собственной конфигурацией пишите [тип изменения](new-change-type.md),
для обращений к внешним системам — [тип действия](new-action-type.md).

> **Примечание**
> `mask` — учебный пример, в поставке его нет. Перед выбором имени проверьте справочник `GET /api/dictionaries/functions`:
> регистрация под существующим именем (например, `repeat`, `pad_left`) молча **заменяет** встроенную функцию.

## 2. Как это устроено

- `Wps\Expression\ExpressionEngine` = `ExpressionLexer` (токены) + `ExpressionParser` (AST) + вычислитель. `eval` не
  используется. Грамматика вызова: `IDENT '(' [expr (',' expr)*] ')'`; имена функций нечувствительны к регистру
  (хранятся в нижнем регистре).
- **Точки расширения:**
  - `registerFunction(string $name, callable $fn, ?FunctionSpec $spec = null): void` — обычная функция: получает уже
    вычисленные аргументы (отсутствующий путь даёт `''`) и возвращает значение;
  - `registerSpecialForm(string $name, \Closure $fn, FunctionSpec $spec): void` — специальная форма: получает аргументы
    **невычисленными**, как `list<\Closure(): mixed>`, и сама решает, какие вычислять. Так устроены `if`, `coalesce`,
    `ifempty`, `ifs`, `switch` (`Wps\Expression\Functions\LogicFunctions`).
- **Где регистрируются встроенные функции:**

  | Класс (`back/src/Expression/Functions/`) | Категория | Где вызывается `register()` | Зависимости |
  |---|---|---|---|
  | `ExpressionEngine::registerDefaultFunctions()` | `string` | конструктор движка | — |
  | `LogicFunctions::register($engine)` | `logic` | конструктор движка | — |
  | `NumberFunctions::register($engine)` | `number` | конструктор движка | — |
  | `TextFunctions::register($engine)` | `string` | конструктор движка | — |
  | `ListFunctions::register($engine)` | `list` | конструктор движка | — |
  | `DateFunctions::register($engine, DateMath $math, Clock $clock)` | `date` | `Container::expressions()` | часы, `WPS_TIMEZONE` |
  | `TypeFunctions::register($engine, TypeCaster $caster, DateMath $dates)` | `type` | `Container::expressions()` | `TypeCaster`, `DateMath` |

  Функции без зависимостей регистрируются в конструкторе, поэтому есть и в `new ExpressionEngine()` (юнит-тесты). Функции
  с зависимостями от конфигурации — в `Container::expressions()`; `new ExpressionEngine()` без контейнера функций дат и
  типов не содержит.
- **`Wps\Expression\FunctionSpec`** описывает функцию (конструктор
  `new FunctionSpec(string $name, int $minArgs, ?int $maxArgs, string $category, string $signature, string $description, string $example = '', string $returns = 'any', bool $pure = true, ?\Closure $validator = null)`):

  | Параметр | Назначение |
  |---|---|
  | `name` | имя (как при регистрации) |
  | `minArgs`, `maxArgs` | допустимое число аргументов; `maxArgs = null` — без верхней границы |
  | `category` | `logic`, `string`, `number`, `date`, `list`, `type`; `custom` — у функций без спецификации. Можно указать свою категорию: справочник сортирует по ней |
  | `signature` | сигнатура для справки, например `mask(s[, keep])` |
  | `description` | что делает функция (встроенные — по-русски); в справочнике фильтров шаблона это `label` |
  | `example` | пример вызова (необязательно) |
  | `returns` | тип результата для справки: `string`, `number`, `boolean`, `date`, `datetime`, `list`, `any` (по умолчанию `any`) |
  | `pure` | результат зависит только от аргументов (по умолчанию `true`); `false` — у `now()`, `today()`. В справочнике — поле `pure` |
  | `validator` | необязательный `\Closure(list<array> $argNodes): ?string` — проверка **литеральных** аргументов при сохранении; возвращает текст ошибки или `null` ([3.3](#33-проверка-литеральных-аргументов)) |

  Без `FunctionSpec` арность берётся из сигнатуры PHP рефлексией (обязательные параметры — минимум, вариативная
  функция — без максимума), категория — `custom`, сигнатура — `имя(…)`, описание пустое.
- **Проверка при сохранении процесса.** `parse()` (его вызывают `CalculateChangeType::validate()`, `TemplateValidator`,
  проверка выражений в условиях и т. д.) проверяет каждый вызов: неизвестная функция (`Unknown function "foo"`),
  неверное число аргументов (`Function "mask" expects 1..2 arguments, got 3`) и ошибка `validator` дают
  `ConfigurationException` → `422`. Во время выполнения число аргументов уже не может быть неверным.
- **Зарезервированные имена** — `and`, `or`, `not`, `true`, `false`, `null` (`ExpressionLexer::KEYWORDS`): регистрация
  бросает `InvalidArgumentException` (`"and" is a reserved word and cannot be used as a function name`). Имя должно
  соответствовать `^[a-z_][a-z0-9_]*$` (`Invalid function name`). Повторная регистрация того же имени заменяет функцию
  (и специальную форму с тем же именем).
- **Один движок на воркер.** `Container::expressions()` создаёт один экземпляр (с общим `TypeCaster` в `WPS_TIMEZONE`);
  его используют `CalculateChangeType`, `TemplateEngine`, `ConditionEngineService`, `LoopRunner`, `DelayHandler`,
  `ForeachBlock` и др., поэтому зарегистрированная там функция сразу доступна везде — в том числе как
  **фильтр шаблона** и в справочниках `GET /api/dictionaries/functions` и `/template-filters`.
- **Ограничения движка:** выражение до 4000 символов (`MAX_LENGTH`), результат-строка до 1 МБ
  (`Expression result is too long`), дробный результат нормализуется (`90.0` → `90`, `0.30000000000000004` → `0.3`),
  `NAN` / `INF` — ошибка `Expression result is not a finite number`. Разобранное AST кэшируется по строке выражения
  (до 1000 записей).
- **Вспомогательные методы движка:** `call($name, ...$args)` — вызвать функцию с готовыми аргументами, `spec($name)`,
  `hasFunction()`, `functionNames()`, `functionSpecs()`, `paths($expression)` — пути переменных выражения,
  `truthy($v)` — истинность по правилам условий, `comparator()` — правила сравнения (`ValueComparator::compare(string $op, $a, $b): bool`),
  `caster()` — `TypeCaster`, `ExpressionEngine::toString($v)` — значение в строку по правилам языка.

## 3. Пошагово

| Файл | Что сделать |
|---|---|
| `back/src/Expression/Functions/MaskFunctions.php` | **новый** — класс со статическим `register()` |
| `back/src/Expression/ExpressionEngine.php` или `back/src/Kernel/Container.php` | вызов `register()` (см. 3.1) |
| `back/tests/Unit/Expression/MaskFunctionTest.php` | **новый** — юнит-тест |
| `back/tests/IntegrationTest.php` | проверка в группе `[expressions]` или своя группа |
| `front/src/components/ModifyPanel.vue` | (по желанию) функция в справке «Расчёт» |
| `doc/{ru,en}/03-user-guide/change-types-and-expressions.md`, `back/API.md` | описание функции |

### 3.1 Функция и регистрация

Для одной функции достаточно вызова в `Container::expressions()`, но удобнее сразу класс со статическим `register()` —
так устроены все встроенные наборы, и его легко подключить в тесте.

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

Подключение — в одном из двух мест:

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

Специальная форма — когда аргументы нельзя вычислять заранее (ветвление, значение по умолчанию, «ленивые» вычисления):

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

Для специальной формы `FunctionSpec` обязателен (рефлексия по замыканию со списком аргументов бессмысленна).

### 3.2 Использование

```json
{ "type": "modify", "target": "object.card_masked", "change_type": "calculate", "value": "mask(object.card_number, 4)" }
```

При `card_number = "4111111111111111"` результат — `"************1111"`. Процесс с `mask()` не сохранится:
`Function "mask" expects 1..2 arguments, got 0`; с `mask(object.card_number, 'x')` —
`Function "mask": keep must be a non-negative integer, got "x"`.

Любая функция сразу доступна и как **фильтр шаблона**: значение слева от `|` становится первым аргументом, аргументы
фильтра — остальными. `{{ object.card_number | mask:2 }}` — то же, что `mask(object.card_number, 2)`. Арность фильтра
проверяется по той же `FunctionSpec` (на один аргумент меньше), функция попадает в справочник
`GET /api/dictionaries/template-filters` ([Шаблон](../../03-user-guide/change-types-and-expressions.md#шаблон)).

### 3.3 Проверка литеральных аргументов

Если аргумент — фиксированное значение из небольшого набора (единица измерения, часовой пояс, формат, число знаков),
проверьте его при сохранении через `FunctionSpec::$validator`. Валидатор получает узлы AST аргументов;
`FunctionSpec::literal($node)` возвращает строку для литерала строки или числа (`'days'`, `'5'`) и `null` для
вычисляемого аргумента (путь, вызов, выражение) — такой аргумент проверяется уже во время выполнения. Ошибка
валидатора превращается в `ConfigurationException`, т. е. `422` при сохранении. Так проверяются `unit` в `date_diff`,
`tz` во всех функциях дат, точность `round()`, шаблоны `regex_*`, путь `json_get()` (всего около 30 встроенных
функций):

```php
new FunctionSpec('date_diff', 3, 3, 'date', 'date_diff(from, to, unit)', '…', "date_diff(object.date_modified, now(), 'days')", 'number',
    validator: static function (array $args): ?string {
        $unit = FunctionSpec::literal($args[2] ?? null);
        return $unit !== null && !in_array(strtolower($unit), DateMath::DIFF_UNITS, true)
            ? "Function \"date_diff\": unit must be one of …, got \"{$unit}\""
            : null;
    });
```

`date_diff(object.a, now(), 'decades')` не сохранится; `date_diff(object.a, now(), object.unit)` сохранится, а неверное
значение поля даст ошибку выполнения. Поэтому проверку в теле функции не убирайте — валидатор её только дублирует для
литералов. Отрицательное число в выражении (`mask('a', -1)`) — это узел `neg`, а не литерал: валидатор его не видит,
ошибку даст выполнение.

### 3.4 Пример: рабочие дни

Рабочие дни и производственный календарь в WPS не входят. Логика дат собрана в `Wps\Time\DateMath` (разбор
`parse()`, `toCanonical()`, `toDate()`, `addDays()`, `addMonths()`, `diff()`, `part()`, `isWeekend()`, `format()` …) —
функцию рабочих дней можно построить на ней (схема; праздники — из вашего источника). Регистрация — в
`Container::expressions()`, где доступен `$this->dateMath()`:

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

Пустую дату возвращайте как `''`; ошибки разбора и счётчика `DateMath` уже бросает как `NonRecoverableException`.
Календарные операции `DateMath` выполняются в `WPS_TIMEZONE`, часы и минуты — по точному времени (`+1d` ≠ `+24h` при
переходе на летнее время).

## 4. Валидация и ошибки

- Указывайте `FunctionSpec` с точной арностью — тогда ошибка находится при сохранении, а справочник показывает
  сигнатуру и описание. Без спецификации арность выводится из параметров PHP: обязательные — обычными параметрами,
  необязательные — со значениями по умолчанию, переменное число — `mixed ...$args`.
- **Ошибки во время выполнения** — `NonRecoverableException` с понятным текстом (`Function "mask": …`): шаг получает
  `failed`, дальше работает `on_error`. Не бросайте `RecoverableException` — функция не обращается к внешним системам,
  повтор ничего не исправит. Другие исключения (`TypeError`, `ValueError`) тоже обрываются шагом, но с непонятным
  сообщением — проверяйте аргументы сами.
- **Преобразование значений.** Для строк — `ExpressionEngine::toString($v)` (`null` → `''`, bool → `true`/`false`,
  массив → JSON). Числа обрабатывайте через `Wps\Expression\Decimal`, а не через `is_numeric()` / `float` — тогда ваша
  функция считает так же точно, как операторы и встроенные функции (`NumberFunctions`):

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

  `Decimal::of()` превращает int / float / числовую строку в каноническую строку, операции (`add`, `sub`, `mul`, `div`,
  `mod`, `round`, `floor`, `ceil`, `trunc`, `pow`, `sqrt`, `cmp`) работают над строками bcmath, `toNative()` возвращает
  результат по правилам типа языка. Текст в «человеческом» формате разбирайте через
  `$engine->caster()->cast($v, new CastOptions(type: CastType::Decimal))`, как `to_number()`. Для сравнений используйте
  `$engine->comparator()`, для условий — `$engine->truthy()`, чтобы правила совпадали с операторами языка.
- **Чистота и скорость.** Без ввода-вывода (CRM, HTTP, БД, файлы) и без случайности: выражение вычисляется при каждом
  событии, в каждой итерации цикла и при каждом повторе шага. Текущее время берите только из `Wps\Time\Clock`
  (`Container::clock()`), а не из `time()` / `new DateTime()`, и помечайте такую функцию `pure: false`.
- **Без состояния.** Движок один на воркер и общий для корутин: функция не должна хранить данные между вызовами в
  `static`-переменных или захваченных по ссылке переменных.
- **Регулярные выражения** — только через `Wps\Expression\SafeRegex` (лимиты PCRE, проверка шаблона при сохранении —
  `SafeRegex::validate()` в `validator`), как `regex_match()`.
- Результат пишется в контекст и может быть сохранён в CRM; строка больше 1 МБ — ошибка `Expression result is too long`.

## 5. UI и переводы

Справочник `GET /api/dictionaries/functions` (данные — `functionSpecs()`, сортировка по категории и имени, в `meta` —
операторы языка) и `GET /api/dictionaries/template-filters` показывают новую функцию без доработок; описание берётся
из `FunctionSpec::$description` и через `locales/*.json` не переводится.

Справка по выражениям в блоке изменения (`ModifyPanel.vue`, переключатель «Справка по выражениям «Расчёт»») — статичный
список базовых функций в коде, а не справочник. Если функция важна для редакторов, добавьте её туда:

```vue
<i18n-t keypath="modify.help.functions" tag="li" scope="global">
  <template #list>
    <code>concat(a, b, …)</code>, <code>upper(s)</code>, <code>lower(s)</code>, <code>trim(s)</code>,
    <code>len(s)</code>, <code>substr(s, start[, length])</code>, <code>round(x[, precision])</code>,
    <code>abs(x)</code>, <code>min(a, b, …)</code>, <code>max(a, b, …)</code>, <code>mask(s[, keep])</code>
  </template>
</i18n-t>
```

Ключи локализации не нужны. Боковая панель переменных извлекает пути из выражений регулярным выражением
(`extractPaths()` в `front/src/utils/variables.ts`); имена функций без точки за пути не принимаются. Как добавить
собственный эндпоинт — [Новый эндпоинт API](new-api-endpoint.md).

## 6. Тест

### 6.1 Юнит-тест — `back/tests/Unit/Expression/MaskFunctionTest.php`

По образцу `TextFunctionsTest` / `ConditionalExpressionsTest`: значения, проверка при сохранении (`parse()`), ошибка
выполнения, фильтр шаблона и спецификация.

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

Класс и тест прогнаны на текущем коде (`OK`). Резолвер выражения возвращает значение пути (`null`, если пути нет), а
резолвер шаблона — пару `[found, value]`, как `ExecutionContext::resolve()`.

Функции, зависящие от времени, тестируйте с `Wps\Time\FrozenClock` — момент задаётся явно и меняется через `set()`
(образец — `DateFunctionsTest`, `DateMathTest`):

```php
$clock = new FrozenClock('2026-10-16 07:30:00');
$engine = new ExpressionEngine();
DateFunctions::register($engine, new DateMath(new \DateTimeZone('Europe/Moscow')), $clock);
self::assertSame('2026-10-16', $engine->evaluate('today()', static fn () => null));
$clock->set('2026-10-16 21:30:00');
self::assertSame('2026-10-17', $engine->evaluate('today()', static fn () => null)); // already the 17th in Moscow
```

Запуск — [Тестирование](../testing.md#юнит-тесты-бэкенда).

### 6.2 Интеграционная проверка — `back/tests/IntegrationTest.php`

По образцу группы `[expressions]` (`testExpressions()`): справочник содержит функцию, процесс с неверными аргументами
отклоняется с `422`, процесс с функцией выполняется.

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

Функция используется и в общем шаблоне `testExtensions()` ([Новый тип действия → Тест](new-action-type.md#6-тест)).

## 7. Типичные ошибки

| Ошибка | Последствие | Как правильно |
|---|---|---|
| Имя совпадает со встроенной функцией | встроенная функция молча заменена во всех процессах и шаблонах | проверить `GET /api/dictionaries/functions` |
| Нет `FunctionSpec` | категория `custom`, пустое описание; для `mixed $x = 4` арность выводится, но справка бедная | всегда передавать спецификацию |
| Проверка аргумента только в `validator` | вычисляемый аргумент (путь) не проверяется при сохранении | проверять и в теле функции |
| `time()` / `new DateTime()` | результат не воспроизводится в тестах, повтор шага даёт другое значение | `Clock` + `pure: false` |
| Ввод-вывод в функции | выражение выполняется в каждой итерации и при каждом повторе | вынести в [действие](new-action-type.md) |
| `float`-арифметика | `0.1 + 0.2` ≠ `0.3`, расхождение со встроенными функциями | `Decimal` |
| `preg_*` с пользовательским шаблоном | ReDoS блокирует воркер | `SafeRegex` |
| Регистрация функции с зависимостями в конструкторе движка | конструктор не знает часов и `WPS_TIMEZONE` | `Container::expressions()` |

## 8. Чек-лист готовности

- [ ] Функция зарегистрирована (конструктор `ExpressionEngine` или `Container::expressions()`), имя в нижнем регистре, не зарезервированное и не занятое
- [ ] Передан `FunctionSpec` с арностью, категорией, сигнатурой, описанием, примером, `returns`; `pure: false` для функций времени
- [ ] Литеральные аргументы проверяются `validator`, вычисляемые — в теле; ошибки — `NonRecoverableException`
- [ ] Функция чистая, без состояния и ввода-вывода; числа — через `Decimal`
- [ ] Юнит-тест и интеграционная проверка добавлены, тесты зелёные
- [ ] (по желанию) добавлена в справку `ModifyPanel.vue`
- [ ] Указана в [Типах изменений и выражениях](../../03-user-guide/change-types-and-expressions.md) (en + ru) и в `back/API.md`

<!-- footer-nav -->
---

← Назад: [Новая стратегия загрузки](new-load-strategy.md) | [Оглавление](../../README.md) | [Новый эндпоинт API](new-api-endpoint.md) Далее →
<!-- /footer-nav -->
