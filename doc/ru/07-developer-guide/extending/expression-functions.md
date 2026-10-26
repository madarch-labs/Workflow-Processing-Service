# Функции выражений

Как добавить функцию в выражения `calculate` — на проверенном примере `repeat(s, n)`.

<!-- lang-switch -->
[🇬🇧 English](../../../en/07-developer-guide/extending/expression-functions.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## 1. Когда это нужно

Выражения `calculate` (`modify` с `"change_type": "calculate"`) поддерживают числа, строки в кавычках, пути
(`object.amount`, `v1.price`), `true` / `false` / `null`, списки `[a, b]`, `+ - * / %`, сравнения, `and` / `or` / `not`,
скобки, условные функции `if`, `coalesce`, `ifempty`, `ifs`, `switch` и функции `concat`, `upper`, `lower`, `trim`,
`len`, `substr`, `round`, `abs`, `min`, `max`, функции дат `now`, `today`, `add_days`, `date_diff`, `format_date` и другие (полный синтаксис — [Типы изменений и выражения](../../03-user-guide/change-types-and-expressions.md)).
Функцию стоит добавить, когда пользователям нужно небольшое чистое вычисление: дополнение строки, повтор символа,
рабочие дни по своему календарю. Для логики с собственной конфигурацией пишите [тип изменения](new-change-type.md).

## 2. Как это устроено

- `Wps\Expression\ExpressionEngine` = `ExpressionLexer` (токены) + `ExpressionParser` (AST) + вычислитель. `eval` не используется.
  Грамматика вызова: `IDENT '(' [expr (',' expr)*] ')'`; имена функций нечувствительны к регистру (хранятся в нижнем регистре).
- Точки расширения:
  - `registerFunction(string $name, callable $fn, ?FunctionSpec $spec = null)` — обычная функция: получает уже
    вычисленные аргументы (отсутствующий путь даёт `''`) и возвращает значение;
  - `registerSpecialForm(string $name, \Closure $fn, FunctionSpec $spec)` — специальная форма: получает аргументы
    **невычисленными**, как `list<\Closure(): mixed>`, и сама решает, какие вычислять. Так устроены `if`, `coalesce`,
    `ifempty`, `ifs`, `switch` (`Wps\Expression\Functions\LogicFunctions`).
- `Wps\Expression\FunctionSpec` описывает функцию:

  | Параметр | Назначение |
  |---|---|
  | `name` | имя (как при регистрации) |
  | `minArgs`, `maxArgs` | допустимое число аргументов; `maxArgs = null` — без верхней границы |
  | `category` | `logic`, `string`, `number`, `date`, `list`, `type`, `system` или `custom` |
  | `signature` | сигнатура для справки, например `repeat(s, n)` |
  | `description` | что делает функция |
  | `example` | пример вызова (необязательно) |
  | `returns` | тип результата: `string`, `number`, `bool`, `any` (по умолчанию `any`) |
  | `pure` | результат зависит только от аргументов (по умолчанию `true`); `false` — у `now()`, `today()`: при повторе шага значение пересчитывается, кэшируется только разбор, не результат. В справочнике — поле `pure` |
  | `validator` | необязательный `\Closure(list<array> $argNodes): ?string` — проверка **литеральных** аргументов при сохранении; возвращает текст ошибки или `null` ([3.3](#33-проверка-литеральных-аргументов)) |

  Без `FunctionSpec` арность берётся из сигнатуры PHP рефлексией (обязательные параметры — минимум, вариативная функция —
  без максимума), категория — `custom`, сигнатура — `имя(…)`.
- **Арность проверяется при сохранении процесса**: `CalculateChangeType::validate()` разбирает выражение, и `parse()`
  проверяет каждый вызов — неизвестная функция (`Unknown function "foo"`) и неверное число аргументов
  (`Function "repeat" expects 2 arguments, got 1`) дают `422`. Во время выполнения число аргументов уже не может быть
  неверным.
- **Зарезервированные имена** — `and`, `or`, `not`, `true`, `false`, `null` (`ExpressionLexer::KEYWORDS`): регистрация с таким
  именем бросает `InvalidArgumentException` (`"and" is a reserved word and cannot be used as a function name`). Имя должно
  соответствовать `^[a-z_][a-z0-9_]*$`. Повторная регистрация того же имени заменяет функцию.
- На каждый воркер в `Container::expressions()` создаётся один экземпляр движка (с общим `TypeCaster`); его используют
  `CalculateChangeType` и всё остальное, что вычисляет выражения, поэтому зарегистрированная там функция сразу доступна
  везде, в том числе в справочнике [`GET /api/dictionaries/functions`](../../06-api-reference/endpoints.md#get-apidictionariesfunctions).
- Разобранное AST кэшируется по строке выражения (до 1000 записей).
- Вспомогательные методы движка: `call($name, ...$args)` — вызвать функцию с готовыми аргументами, `hasFunction()`,
  `functionNames()`, `functionSpecs()`, `truthy($v)` — истинность по правилам условий, `comparator()` — правила сравнения
  (`ValueComparator`), `caster()` — `TypeCaster`.

## 3. Пошагово

### 3.1 Регистрация функции — `back/src/Kernel/Container.php`

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
            new FunctionSpec('repeat', 2, 2, 'string', 'repeat(s, n)', 'Строка s, повторённая n раз', "repeat('*', 5)", 'string'),
        );
        return $engine;
    });
}
```

Для большого набора функций создайте класс со статическим `register()` и вызовите его здесь. Так устроены
`LogicFunctions` (регистрируется в конструкторе движка) и `DateFunctions` —
`register(ExpressionEngine $e, DateMath $math, Clock $clock)`: зависимости от часов и `WPS_TIMEZONE` передаёт контейнер
(`Container::clock()` — `SystemClock`, `Container::dateMath()` — `DateMath` с поясом `WPS_TIMEZONE`), поэтому
`new ExpressionEngine()` без контейнера функций дат не содержит. Строковые и числовые функции регистрируются в
`ExpressionEngine::registerDefaultFunctions()`.

Специальная форма — когда аргументы нельзя вычислять заранее (ветвление, значение по умолчанию):

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

### 3.2 Использование

```json
{ "type": "modify", "target": "object.stars", "change_type": "calculate", "value": "repeat('*', object.rating)" }
```

При `rating = 3` результат — `"***"`. Процесс с `repeat('*')` не сохранится: `Function "repeat" expects 2 arguments, got 1`.

Любая функция сразу доступна и как **фильтр шаблона**: значение слева от `|` становится первым аргументом, аргументы
фильтра — остальными. `{{ '*' | repeat:object.rating }}` — то же, что `repeat('*', object.rating)`. Арность фильтра
проверяется по той же `FunctionSpec` (на один аргумент меньше), функция попадает в справочник
`GET /api/dictionaries/template-filters` ([Шаблон](../../03-user-guide/change-types-and-expressions.md#шаблон)).

### 3.3 Проверка литеральных аргументов

Если аргумент — фиксированное значение из небольшого набора (единица измерения, часовой пояс, формат), проверьте его
при сохранении через `FunctionSpec::$validator`. Валидатор получает узлы AST аргументов; `FunctionSpec::literal($node)`
возвращает строку для литерала (`'days'`, `5`) и `null` для вычисляемого аргумента (путь, вызов) — такой аргумент
проверяется уже во время выполнения. Ошибка валидатора превращается в `ConfigurationException`, т. е. `422` при
сохранении. Так проверяются `unit` в `date_diff` и `tz` во всех функциях дат:

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
значение поля даст ошибку выполнения.

### 3.4 Пример: рабочие дни

Рабочие дни и производственный календарь в WPS не входят. Логика дат собрана в `Wps\Time\DateMath` (разбор
`parse()`, `toCanonical()`, `addDays()`, `part()`, `isWeekend()` …) — функцию рабочих дней можно построить на ней
(схема, праздники — из вашего источника):

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

Пустую дату возвращайте как `''`, ошибки разбора и счётчика `DateMath` уже бросает как `NonRecoverableException`.

## 4. Валидация и ошибки

- Указывайте `FunctionSpec` с точной арностью — тогда ошибка находится при сохранении, а справочник показывает сигнатуру
  и описание. Без спецификации арность выводится из параметров PHP: обязательные — обычными параметрами, необязательные —
  со значениями по умолчанию, переменное число — `mixed ...$args`.
- Преобразование значений: для строк используйте `ExpressionEngine::toString($v)`. Числа обрабатывайте через
  `Wps\Expression\Decimal`, а не через `is_numeric()` / `float` — тогда ваша функция считает так же точно, как операторы
  и встроенные функции (`NumberFunctions`):

  ```php
  use Wps\Expression\Decimal;

  $engine->registerFunction('net', static function (mixed $gross, mixed $rate) {
      foreach ([$gross, $rate] as $v) {
          if (!Decimal::isNumeric($v)) {   // строгий формат: '1 234,56' и '1e3' — не числа
              throw new NonRecoverableException(sprintf('Function "net" requires a number, got "%s"', ExpressionEngine::toString($v)));
          }
      }
      $net = Decimal::div(Decimal::of($gross), Decimal::add('1', Decimal::div(Decimal::of($rate), '100')));
      return Decimal::toNative(Decimal::round($net, 2));   // int / float, если без потерь, иначе строка
  }, new FunctionSpec('net', 2, 2, 'number', 'net(gross, rate)', 'Сумма без НДС', 'net(object.amount, 20)', 'number'));
  ```

  `Decimal::of()` превращает int / float / числовую строку в каноническую строку, операции (`add`, `sub`, `mul`, `div`,
  `mod`, `round`, `floor`, `ceil`, `trunc`, `pow`, `sqrt`, `cmp`) работают над строками bcmath, `toNative()` возвращает
  результат по правилам типа языка. Текст в «человеческом» формате разбирайте через
  `$engine->caster()->cast($v, new CastOptions(type: CastType::Decimal))`, как `to_number()`. Для сравнений используйте `$engine->comparator()`,
  для условий — `$engine->truthy()`, чтобы правила совпадали с операторами языка.
- Функции должны быть **чистыми и быстрыми**: без ввода-вывода и без случайности, ломающей повторы. Текущее время
  берите только из `Wps\Time\Clock` (`Container::clock()`), а не из `time()` / `new DateTime()`, и помечайте такую
  функцию `pure: false`. Результат пишется
  в контекст и может быть сохранён в CRM; результат выражения больше 1 МБ — ошибка `Expression result is too long`.

## 5. UI и переводы

Справка по выражениям в блоке изменения (`ModifyPanel.vue`, переключатель «Справка по выражениям «Расчёт»») перечисляет
функции как код, а не как переводимый текст. Добавьте новую в список:

```vue
<i18n-t keypath="modify.help.functions" tag="li" scope="global">
  <template #list>
    <code>concat(a, b, …)</code>, <code>upper(s)</code>, <code>lower(s)</code>, <code>trim(s)</code>,
    <code>len(s)</code>, <code>substr(s, start[, length])</code>, <code>round(x[, precision])</code>,
    <code>abs(x)</code>, <code>min(a, b, …)</code>, <code>max(a, b, …)</code>, <code>repeat(s, n)</code>
  </template>
</i18n-t>
```

Ключи локализации не нужны. Боковая панель переменных извлекает пути из выражений регулярным выражением
(`extractPaths()` в `front/src/utils/variables.ts`); имена функций без точки за пути не принимаются.

Список функций с сигнатурами и описаниями уже отдаёт `GET /api/dictionaries/functions` (данные — `functionSpecs()`),
так что новая функция появляется в нём без доработок. Как добавить собственный эндпоинт — [Новый эндпоинт API](new-api-endpoint.md).

## 6. Тест

Юнит-тест (`back/tests/Unit/Expression/`, запуск `composer test:unit`), по образцу `ConditionalExpressionsTest`:

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

Интеграционная проверка — по образцу группы `[expressions]` в `back/tests/IntegrationTest.php` (`testExpressions()`):
процесс с неверной арностью отклоняется с `422`, справочник `GET /api/dictionaries/functions` содержит функцию.

## 7. Чек-лист готовности

- [ ] Функция зарегистрирована в `Container::expressions()` (имя в нижнем регистре, не зарезервированное, чистая функция)
- [ ] Передан `FunctionSpec` с арностью, категорией, сигнатурой, описанием и примером
- [ ] Ошибки аргументов дают `NonRecoverableException`
- [ ] Добавлена в список справки в `ModifyPanel.vue`
- [ ] Юнит-тест и интеграционная проверка добавлены, тесты зелёные
- [ ] Указана в [Типах изменений и выражениях](../../03-user-guide/change-types-and-expressions.md) (en + ru) и в `back/API.md`

<!-- footer-nav -->
---

← Назад: [Новая стратегия загрузки](new-load-strategy.md) | [Оглавление](../../README.md) | [Новый эндпоинт API](new-api-endpoint.md) Далее →
<!-- /footer-nav -->
