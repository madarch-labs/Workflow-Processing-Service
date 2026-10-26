# Change types and expressions

How a **Modify object / variable** row computes the new value: a fixed value, a copy of another field, a calculated expression, text from a template, a shifted date, a counter, a clear, an append, a lookup table or an aggregate over CRM records.

<!-- lang-switch -->
🇬🇧 English | [🇷🇺 Русский](../../ru/03-user-guide/change-types-and-expressions.md)
<!-- /lang-switch -->

![Calculate rows](../../images/screenshots/en/07-process-edit-modify.png)

*Process **Invoice discount 10%**: two rows with the **Calculate** change type.*

Change types (the **Change type** column of a row; `aggregate` and types with extra fields — `separator`, `map`, `list`
and so on — are set through the API: the editor keeps only `target`, `change_type`, `value` and `save_to_crm` of a row):

| UI (EN) | UI (RU) | Code | **New value** contains |
|---|---|---|---|
| **Set to value** | Равен значению | `set_value` | the value itself |
| **Set from variable** | Равен переменной | `set_from_variable` | a path to another field |
| **Calculate** | Расчёт | `calculate` | an expression |
| **Template** | Шаблон | `template` | text with `{{ … }}` substitutions, see [Template](#template) |
| **Date shift** | Сдвиг даты | `date_shift` | base, shift and time in separate fields, see [Date shift](#date-shift) |
| **Increment by** / **Decrement by** | Увеличить на / Уменьшить на | `increment` / `decrement` | the step (1 by default), see [Increment / decrement by](#increment--decrement-by) |
| **Clear** | Очистить | `clear` | not needed, see [Clear](#clear) |
| **Append** / **Prepend** / **Remove from list** | Дописать в конец / в начало / Удалить из списка | `append` / `prepend` / `remove` | a text template or list elements, see [Append](#append-prepend-remove-from-a-list) |
| **Map** | По таблице соответствия | `map` | the source field and the table, see [Map](#map) |
| **Aggregate over records** | Агрегат по записям | `aggregate` | function, field and record source, see [Aggregate over records](#aggregate-over-records) |

The same change types work in the [Set a process variable](actions.md#set-a-process-variable) action (`set_variable`) —
for intermediate values that are not saved to the CRM — and in the rules of
[Update list records](actions.md#update-list-records) (`update_records`).

## Set to value

Writes the text from **New value** into the target field as is.

- Allowed values: any text or number; an empty value writes an empty string.
- Example: target `v1.rating`, value `VIP`.
- Server error: `Value must be a string or a number` (possible only via the API, e.g. an array as value).

## Set from variable

Copies the value of another field — of the event object or of any variable.

- **New value** is a path: `object.phone`, `v1.name`, `v2.sale`.
- If the source field does not exist (or the variable is empty), the target gets an empty value (`null`).
- Example: target `object.description`, value `v1.name` — the contact gets the name of its account (recipe **Contact: copy account name**).
- Errors: `Action 1: specify a value`; server: `Field path is required`, `Variable "v3" is not defined at this point`, `"v1" must point to a field, e.g. v1.name`.

## Calculate

Computes the value from an expression. Click **“Calculate” expression help** (or the `?` icon in the row) for a short reminder in the editor.

The same expression language works in **conditions**: `"value_type": "expression"` computes the right side of a
condition (e.g. `v1.credit_limit * 0.8`), see [Trigger conditions → Comparing with an expression](triggers-and-conditions.md#comparing-with-an-expression).

```text
round(object.amount * 0.9, 2)
concat('Discount applied, new amount: ', object.amount)
upper(trim(object.name))
```

### Syntax

| Element | Syntax | Examples |
|---|---|---|
| Number | digits with an optional decimal point | `10`, `0.9`, `.5` |
| String | in single or double quotes; `\` escapes the next character | `'abc'`, `"Call back: "`, `'it\'s'` |
| Field path | `object.<field>` or `<variable>.<field>`; nested fields with more dots | `object.amount`, `v1.price`, `v1.address.city` |
| Literals | `true`, `false`, `null` | `object.is_active == true` |
| List | `[a, b, …]` | `[object.a, object.b] == [1, 2]` |
| Arithmetic | `+` `-` `*` `/` `%` | `object.amount * 0.9 + 10` |
| Unary minus | `-` before a value | `-object.amount` |
| Comparison | `==` `!=` `<` `<=` `>` `>=` | `object.amount > 100000` |
| Logic | `and` `or` `not` | `object.probability >= 50 and not object.closed` |
| Parentheses | `(` … `)` | `(object.price - object.discount) * object.qty` |
| Function call | `name(arg, …)`, names are case-insensitive | `round(object.amount, 2)` |

**Synonyms:** `=` is the same as `==`; `<>` is the same as `!=`; `&&`, `||`, `!` are the same as `and`, `or`, `not`.

**Operator precedence** (top to bottom — from weakest to strongest; operators of the same level are evaluated left to right):

| Level | Operators | Reads as |
|---|---|---|
| 1 | `or`, `\|\|` | `a or b and c` = `a or (b and c)` |
| 2 | `and`, `&&` | `a and b or c` = `(a and b) or c` |
| 3 | `not`, `!` | `not a == b` = `not (a == b)` |
| 4 | `==` `!=` `<` `<=` `>` `>=` (and synonyms) | cannot be chained: `1 < 2 < 3` is a save error |
| 5 | `+` `-` | `a + b > c` = `(a + b) > c` |
| 6 | `*` `/` `%` | `a + b * c` = `a + (b * c)` |
| 7 | unary `-` `+` | `-a * b` = `(-a) * b` |

The order is the same as in SQL and Python. When in doubt, add parentheses: `(a or b) and c`.

**Keywords** `and`, `or`, `not`, `true`, `false`, `null` are case-insensitive (`AND`, `True`, `NULL`). A word with a dot
is always a path: `object.or`, `object.and`, `v1.null` are fields, not operators. A word without a dot that is not
followed by `(` is an error — write `object.amount`, not `amount`.

### Values and types

- **A missing field gives an empty string** (it never stops the calculation).
- `+` **adds** when both operands are numbers in strict format (a number or text such as `"10"`, `"-0.5"`, `" 3 "`), and **concatenates** otherwise: `'10' + 5` → `15`, `object.name + 5` → `Deal5`. Text like `'1 234,56'` (space, comma) or `'1e3'` is not a number — without `to_number()` it is concatenated. `'' + 5` → `5` (concatenation).
- `-` `*` `/` `%` require numbers. An empty value counts as `0`. Text that is not a number fails the step: `Operator "*" requires numbers, got "abc"`.
- Division or modulo by zero fails the step: `Division by zero in expression`, `Modulo by zero in expression`. The remainder has the sign of the dividend: `-7 % 3` → `-1`.
- **Arithmetic is exact decimal** (bcmath), not floating point: `0.1 + 0.2` → `0.3`, `19.99 * 3` → `59.97`, `0.1 + 0.2 == 0.3` → `true`. Intermediate results keep 10 decimals; division is rounded to 10 decimals, half away from zero (`1 / 3` → `0.3333333333`, `2 / 3` → `0.6666666667`).
- Result type: an integer below 10¹⁵ is an integer (`9.0` → `9`, `object.amount * 0.9` with `500` → `450`); a fraction exact in 15 significant digits is a float (`10 / 4` → `2.5`); **otherwise it is text holding the number**, so large sums are not distorted: `'12345678901234567.89' + 0.01` → `'12345678901234567.9'`, `9007199254740993 + 0` → `'9007199254740993'`. Such a value is written to the CRM and compared by conditions as a number; in the audit its `new_value` is a string.
- A number with more than 100 integer digits is an error `Number is too large` (in a literal — when the process is saved).
- To join numbers as text, use `concat`: `concat(object.code, object.number)`.
- Comparisons and logical operators return `true` or `false`. Written to a field, such a value is stored as a boolean.

### Comparison rules

The rules are applied in order — the first matching one wins:

| # | Situation | How it is compared | Examples |
|---|---|---|---|
| 1 | Empty values | `null` and `''` are equal (a missing field is `''` too) | `object.missing == ''` → `true`, `null == ''` → `true` |
| 2 | `<` `<=` `>` `>=` with at least one empty operand | always `false` | `object.missing > 5` → `false`, `object.missing < 5` → `false` |
| 3 | One operand is `true` / `false` | the other is converted to a boolean (`1`, `yes`, `да` → `true`; `0`, `no`, `нет` → `false`) | `'yes' == true` → `true`, `'abc' == true` → `false` |
| 4 | Both operands are strictly formatted numbers | exact decimal comparison (no float noise) | `'100' == 100.00` → `true`, `'9' < '10'` → `true` |
| 5 | Both are lists | `==` / `!=` element by element; `<` and the others are an error | `[1, 2] == ['1', '2']` → `true` |
| 6 | Anything else | as strings, byte by byte; **case matters** | `'abc' == 'ABC'` → `false`, `'b' > 'a'` → `true` |

- **A strictly formatted number** is digits with an optional sign and a decimal point: `10`, `-3.5`, `.5`, `' 42 '`
  (surrounding spaces are fine). Text like `'1 000'`, `'1 234,56'`, `'10 USD'` is **not** a number and is compared as a
  string: `'1 234,56' > 5` is a string comparison. Convert such values to numbers first (type casts in conditions or a calculation).
- A string and a number, when the string is not a number: string comparison (`'abc' > 5` → `true`, because `'a'` sorts after `'5'`).
- **Dates are not detected automatically.** Compare them in the canonical `YYYY-MM-DD` (or `YYYY-MM-DD HH:MM:SS`) format —
  then the string order matches the chronological one: `object.close_date < '2026-12-31'`. Dates like `31.12.2026` cannot
  be compared this way.
- Case is ignored only in keywords and function names, never in values. For a case-insensitive comparison:
  `lower(object.status) == 'active'`.
- A boolean compared with a value that cannot be converted: `==` gives `false`, `!=` gives `true`, and `<` `>` … fail with `Cannot compare boolean with "abc"`.

### Truthiness of conditions

`and`, `or`, `not`, the condition of `if()` and the conditions of `ifs()` accept only values that clearly mean “yes” or “no”:

| Treated as true | Treated as false |
|---|---|
| `true`, `1`, `yes`, `on`, `да` | `false`, `0`, `no`, `off`, `нет`, `''`, `null` (and a missing field) |

Any other value fails the step with `Condition must be boolean, got "abc"` — a typo in a condition never silently turns
into “false”. Usually a condition is a comparison: not `if(object.status, …)` but `if(object.status == 'Active', …)`.

`and` and `or` evaluate the right side only when it is needed (`false and …` → `false` without evaluating the right side)
and always return `true` or `false`. `not object.missing` → `true`.

### Conditional functions

The arguments of these functions are evaluated **lazily**: a branch that is not chosen is not evaluated, so
`if(true, 1, 1 / 0)` → `1` without a division-by-zero error.

| Function | Returns | Example |
|---|---|---|
| `if(cond, then[, else])` | `then` when `cond` is true, otherwise `else` (an empty string without `else`) | `if(object.amount > 100000, 'VIP', 'Regular')` |
| `coalesce(a, b, …)` | the first non-empty value (not `null` and not `''`); an empty string when all are empty | `coalesce(object.phone_mobile, object.phone_work, v1.phone_office)` |
| `ifempty(a, default)` | `a` when it is not empty, otherwise `default` | `ifempty(object.title, 'Untitled')` |
| `ifs(cond1, value1, cond2, value2, …[, default])` | the value of the first pair with a true condition; an odd last argument is the default; an empty string when nothing matches and there is no default | `ifs(object.amount > 1000000, 'A', object.amount > 100000, 'B', 'C')` |
| `switch(x, key1, value1, key2, value2, …[, default])` | the value for the first key equal to `x` (by the `==` rules); an extra last argument is the default | `switch(object.rating, 'Hot', 'High', 'Warm', 'Medium', 'Low')` |

Examples:

```text
concat('Category ', ifs(object.amount > 1000000, 'A', object.amount > 100000, 'B', 'C'))
if(object.probability >= 50 and object.sales_stage != 'Closed Lost', 'In progress', 'Low priority')
concat('Call: ', coalesce(object.phone_mobile, object.phone_work, object.phone_other, 'no phone'))
switch(lower(object.lead_source), 'web site', 'Website', 'cold call', 'Cold call', 'Other')
if(object.amount > 0, round(object.discount / object.amount * 100, 1), 0)
```

`coalesce` and `ifempty` treat only `null` and `''` as empty: `0`, `false` and `' '` (a space) are values.

### Functions

| Function | Result | Example | Result of the example |
|---|---|---|---|
| `if(cond, then[, else])` | see [Conditional functions](#conditional-functions) | `if(object.amount > 100, 'VIP', 'Regular')` | `VIP` |
| `coalesce(a, b, …)` | the first non-empty value | `coalesce(object.missing, object.name)` | `Deal` |
| `ifempty(a, default)` | `a` or the default | `ifempty(object.missing, 'none')` | `none` |
| `ifs(cond1, value1, …[, default])` | the value of the first true pair | `ifs(object.amount > 1000, 'B', 'C')` | `C` |
| `switch(x, key1, value1, …[, default])` | the value for the matching key | `switch(object.name, 'Deal', 1, 0)` | `1` |
| `concat(a, b, …)` | all arguments joined as text | `concat('Call back: ', object.name)` | `Call back: Ivan Petrov` |
| `upper(s)` | upper case | `upper('hello')` | `HELLO` |
| `lower(s)` | lower case | `lower('ABC')` | `abc` |
| `trim(s)` | text without leading/trailing spaces | `trim('  x  ')` | `x` |
| `len(s)` | length in characters | `len('Привет')` | `6` |
| `substr(s, start[, length])` | part of the text; `start` counts from 0, negative counts from the end | `substr('hello world', 0, 5)` | `hello` |
| `round(x[, digits])` | rounded number, `digits` decimals (default 0) | `round(10.456, 2)` | `10.46` |
| `abs(x)` | absolute value | `abs(-5)` | `5` |
| `min(a, b, …)` | smallest number | `min(object.amount, 1000)` | e.g. `500` |
| `max(a, b, …)` | largest number | `max(object.amount, 0)` | e.g. `500` |
| `default(a, fallback)` | `a` if it is not empty, otherwise `fallback` (like `ifempty`; handy as a template filter) | `default(object.missing, '—')` | `—` |
| `number(x[, decimals[, dec_sep[, thousands_sep]]])` | alias of `number_format` | `number(1234.5, 2, ',', ' ')` | `1 234,50` |
| `capitalize(s)` | first letter upper-case | `capitalize('ivan')` | `Ivan` |
| `truncate(s, length[, suffix])` | text no longer than `length` characters including `suffix` (default `…`) | `truncate('Big deal', 5)` | `Big …` |
| `nl2br(s)` | line breaks → `<br>` (for HTML e-mails) | `nl2br(object.description)` | `line1<br>line2` |
| `json(v)` | the value as JSON | `json([1, 'a'])` | `[1,"a"]` |
| `urlencode(s)` | text encoded for a URL (RFC 3986) | `urlencode('a b&c')` | `a%20b%26c` |
| `join(list[, separator])` | list items joined with `separator` (default `, `); accepts an array, a JSON array and a multienum `^a^,^b^` | `join('^Banking^,^Retail^')` | `Banking, Retail` |

Text functions work with any language (Cyrillic included). The number of arguments is checked **when the process is
saved**: `substr(object.name)` is rejected with `Function "substr" expects 2..3 arguments, got 1`.
Numeric functions are described in [Numbers](#numbers).

The full list of functions with signatures and descriptions is returned by [`GET /api/dictionaries/functions`](../06-api-reference/endpoints.md#get-apidictionariesfunctions).
New functions can be added by a developer: [Expression functions](../07-developer-guide/extending/expression-functions.md).
Date functions (`today`, `add_days`, `date_diff`, `format_date` …) are described in [Dates and time](#dates-and-time).

### Numbers

All numeric functions compute exactly (decimal, see [Values and types](#values-and-types)) and return results by the
same type rules. An empty argument (`''`, a missing field) counts as `0`; a non-numeric one is a run-time error
`Function "floor" requires a number, got "abc"`.

| Function | Result | Example | Example result |
|---|---|---|---|
| `round(x[, digits])` | rounded to `digits` decimals, **half away from zero** (2.5 → 3, −2.5 → −3); `digits` may be negative — to tens, hundreds … | `round(1.005, 2)`, `round(1250, -2)` | `1.01`, `1300` |
| `floor(x[, digits])` | rounded down (toward −∞) | `floor(-1.5)`, `floor(10.459, 2)` | `-2`, `10.45` |
| `ceil(x[, digits])` | rounded up (toward +∞) | `ceil(1.01)` | `2` |
| `trunc(x[, digits])` | fractional part dropped (toward zero) | `trunc(-1.7)` | `-1` |
| `abs(x)` | absolute value | `abs(-0.5)` | `0.5` |
| `sign(x)` | sign: `-1`, `0` or `1` | `sign(-3)` | `-1` |
| `min(a, b, …)`, `max(a, b, …)` | smallest / largest; with one list argument — over its items (an empty list → `''`) | `min([5, 2, 9])` | `2` |
| `pow(x, n)` | `x` to the power `n`; an integer power up to 100 in absolute value is exact, otherwise floating point | `pow(1.1, 2)`, `pow(9, 0.5)` | `1.21`, `3` |
| `sqrt(x)` | square root, 10 decimals; of a negative number — error `sqrt() of a negative number` | `sqrt(2)` | `1.4142135624` |
| `percent(x, p)` | `p` percent of `x` (`x * p / 100`) | `percent(200, 15)` | `30` |
| `to_number(s)` | number from text: spaces (non-breaking too) are removed, `,` is the decimal separator when there is no `.`; empty stays empty (handy with `coalesce`); `'1,234.56'` (both separators) and `'abc'` are an error `Cannot convert "abc" to a number` | `to_number('1 234,56')` | `1234.56` |
| `to_int(s)` | integer from text; a fractional part is an error `Cannot convert "1.5" to an integer`; empty stays empty | `to_int(' 42 ')` | `42` |
| `number_format(x[, decimals[, dec_sep[, thousands_sep]]])` | text with separators; defaults `decimals = 0`, `dec_sep = '.'`, `thousands_sep = ' '` (a plain space); rounding half away from zero | `number_format(1234567.891, 2, ',', ' ')` | `1 234 567,89` |

Scenarios:

```
round(object.amount * 1.2, 2)                      amount with VAT: 19.99 → 23.99, no 23.987999…
floor(object.amount / object.qty * 100) / 100      unit price: 100 / 3 → 33.33
to_number(v1.price_text)                           '1 234,56' → 1234.56
number_format(object.amount, 2, ',', ' ')          1234567.891 → '1 234 567,89'
```

Changes compared with earlier versions: `round(1.005, 2)` is now `1.01` (float noise used to give `1`); numeric
function errors read `Function "round" requires a number, got "…"` (previously
`Operator "round()" requires numbers, got "…"`). A precision (`digits`, `decimals`) given as a literal must be an
integer, otherwise the process is not saved: `Function "round": precision must be an integer`,
`Function "number_format": decimals must be an integer`. A computed fractional precision is a run-time error.

### Dates and time

**Value format.** Date functions return strings in a canonical format — they can be written to the CRM as is and
compare correctly as strings (`add_days(today(), 3) > '2026-10-18'`):

| Kind | Format | Time zone |
|---|---|---|
| date | `Y-m-d` (`2026-10-16`) | calendar day, no zone |
| datetime | `Y-m-d H:i:s` (`2026-10-16 07:30:00`) | always UTC |

**Input values.** A date argument is accepted as `Y-m-d`, `Y-m-d H:i:s`, `Y-m-d H:i`, ISO 8601
(`2026-10-16T07:30:00Z`, `2026-10-16T10:30:00+03:00`, with fractions of a second and without a zone), `d.m.Y`,
`d.m.Y H:i`, `d.m.Y H:i:s`.

- A datetime **without an offset is UTC** — that is how the CRM sends it. A value with an offset is converted to UTC.
- A date without a time where a moment is needed (`add_hours`, `date_diff` in hours) is **midnight in `WPS_TIMEZONE`**.
- An empty value (`''`, `null`, a missing field, `0000-00-00`, `0000-00-00 00:00:00`) gives `''` (except `now()` and
  `today()`). Emptiness flows on, just like a missing field.

**Time zone.** `WPS_TIMEZONE` ([Configuration](../04-operations/configuration.md), default `UTC`) is the business
time zone, "local". `today()`, calendar days, period boundaries, date parts and `format_date()` are computed in it.
Functions with a `tz` argument accept `'local'` (= `WPS_TIMEZONE`), `'UTC'` or an IANA name (`'Europe/Moscow'`).

**Functions.** Examples assume `WPS_TIMEZONE=Europe/Moscow` and the current moment 2026-10-16 07:30 UTC.

| Function | Result | Example | Result of the example |
|---|---|---|---|
| `now()` | current moment, UTC | `now()` | `2026-10-16 07:30:00` |
| `today([tz])` | today's date in `tz` (local by default) | `today()` | `2026-10-16` |
| `date(value[, format])` | calendar date; from a datetime — the day in local; `format` is a PHP format for non-standard input | `date('2026-10-15 22:30:00')`, `date('16/10/2026', 'd/m/Y')` | `2026-10-16`, `2026-10-16` |
| `datetime(value[, format[, tz]])` | datetime in UTC; `tz` is the zone an input without an offset is written in (UTC by default); a date without a time is midnight in `tz`, or in local without `tz` | `datetime('2026-10-17 10:00', 'Y-m-d H:i', 'local')` | `2026-10-17 07:00:00` |
| `add_minutes(d, n)`, `add_hours(d, n)` | absolute time; a date becomes a datetime (from local midnight) | `add_hours('2026-10-16', 2)` | `2026-10-15 23:00:00` |
| `add_days(d, n)`, `add_weeks(d, n)` | calendar days; a datetime keeps its local time | `add_days(today(), 3)` | `2026-10-19` |
| `add_months(d, n)`, `add_years(d, n)` | months, clipped to the end of the month | `add_months('2026-01-31', 1)`, `add_years('2024-02-29', 1)` | `2026-02-28`, `2025-02-28` |
| `date_diff(from, to, unit)` | `to − from` in whole units, truncated toward zero; `unit`: `minutes`, `hours`, `days`, `weeks`, `months`, `years` | `date_diff('2026-10-16', '2026-10-15', 'days')` | `-1` |
| `year(d[, tz])`, `month`, `day`, `hour`, `minute` | part of the date in local or `tz` | `hour('2026-10-16 07:30:00')`, `hour('2026-10-16 07:30:00', 'UTC')` | `10`, `7` |
| `weekday(d[, tz])` | ISO day of the week: 1 — Monday … 7 — Sunday | `weekday('2026-10-16')` | `5` |
| `week(d[, tz])`, `quarter(d[, tz])` | ISO week number, quarter 1–4 | `week('2026-10-16')`, `quarter('2026-10-16')` | `42`, `4` |
| `start_of_day(d)`, `end_of_day(d)` | start (`00:00:00`) / end (`23:59:59`) of the day | `end_of_day('2026-10-16 07:30:00')` | `2026-10-16 20:59:59` |
| `start_of_week(d)`, `end_of_week(d)` | Monday / Sunday | `start_of_week('2026-10-16')` | `2026-10-12` |
| `start_of_month(d)`, `end_of_month(d)` | first / last day of the month | `end_of_month('2026-02-10')` | `2026-02-28` |
| `start_of_quarter(d)`, `end_of_quarter(d)` | first / last day of the quarter | `end_of_quarter(today())` | `2026-12-31` |
| `start_of_year(d)`, `end_of_year(d)` | January 1 / December 31 | `start_of_year('2026-10-16')` | `2026-01-01` |
| `is_weekend(d[, tz])` | Saturday or Sunday (`true`/`false`) | `is_weekend('2026-10-16 22:00:00')` | `true` (already Saturday in Moscow) |
| `format_date(d, format[, tz])` | text with PHP `DateTimeInterface::format` tokens; a datetime is shown in local or `tz` | `format_date('2026-10-16 07:30:00', 'd.m.Y H:i')` | `16.10.2026 10:30` |
| `format_date_locale(d, pattern, locale[, tz])` | text by an ICU pattern with month and day names in `locale` | `format_date_locale('2026-10-16', 'd MMMM y', 'ru')` | `16 октября 2026` |

Period boundaries keep the kind of value: a date gives a date, a datetime gives the boundary in local converted to
UTC. `format_date_locale` exists only when the PHP `intl` extension is installed in the image (the WPS image has it);
without it the function is missing from the dictionary and a process using it is not saved.

**Daylight saving time.** `add_days`, `add_weeks`, `add_months` work with the local time of a datetime: with
`WPS_TIMEZONE=Europe/Berlin` `add_days('2026-03-28 06:00:00', 1)` = `2026-03-29 05:00:00` (07:00 Berlin time before and
after the clock change). `add_hours`, `add_minutes` add absolute time: 2026-03-29 in Berlin has 23 hours
(`date_diff(datetime('2026-03-29'), datetime('2026-03-30'), 'hours')` = `23`).

**`date_diff`.** `days` is the difference of calendar days (a datetime is converted to local first):
`date_diff('2026-10-15 20:00:00', '2026-10-16 10:00:00', 'days')` = `1` (23:00 and 13:00 Moscow time are different
days), in hours it is `14`. `weeks` are whole weeks of the days. `months` and `years` count full months:
`date_diff('2026-01-31', '2026-02-28', 'months')` = `0`, `date_diff('2026-01-15', '2026-03-15', 'months')` = `2`.

**Typical scenarios:**

```text
add_days(today(), 3)                                                         task due in 3 days
datetime(concat(add_days(today(), 1), ' 10:00'), 'Y-m-d H:i', 'local')       tomorrow at 10:00 local time
date_diff(object.date_modified, now(), 'days')                               days since the deal last changed
format_date(object.date_closed, 'd.m.Y')                                     date for a letter: 16.10.2026
end_of_quarter(today())                                                      end of the quarter
```

**Working days are not supported**: there are no `add_workdays`, `workdays_between`, `is_workday` functions, and no
holiday calendar (`is_weekend` only checks Saturday and Sunday). If you need them, a developer adds a function with
`registerFunction` — see [Expression functions](../07-developer-guide/extending/expression-functions.md#34-example-working-days).

**Date errors on save:** `Function "date_diff": unit must be one of minutes, hours, days, weeks, months, years, got
"decades"` (a literal unit), `Unknown timezone "Mars/Base"` (a literal zone), `Function "format_date": format must not
be empty`, `Unknown function "add_workdays"`. **At run time:** `Cannot parse date "abc" in add_days()`,
`Function "add_days": count must be an integer, got "x"` (a fraction is an error too — use `add_hours` for fractional
days), `Unknown timezone "…" in today()` for a computed zone, `Cannot parse date "2026-02-30" in date()` for a date
that does not exist.

### Limits

| Limit | Value | Message |
|---|---|---|
| Expression length | 4000 characters | `Expression is too long (max 4000 characters)` |
| Expression size | 500 nodes (numbers, paths, operators, calls) | `Expression is too complex (max 500 nodes)` |
| Nesting | 32 levels of parentheses and calls | `Expression is nested too deeply (max 32 levels)` |
| Result length | 1 MB | `Expression result is too long` |

The first three are checked on save, the result length at run time.

### Edge cases

| Expression | Result | Why |
|---|---|---|
| `true + 1` | `true1` | `+` concatenates anything that is not numbers (kept for compatibility) |
| `object.missing == ''` | `true` | a missing field is an empty string, `null` = `''` |
| `object.missing > 5` | `false` | an ordering comparison with an empty value is always false |
| `not object.missing` | `true` | an empty value is false |
| `'abc' > 5` | `true` | `'abc'` is not a number — string comparison |
| `'1 234,56' > 5` | `false` | the space and the comma are not a number format, the strings `'1…'` and `'5'` are compared |
| `if(false, 1)` | `''` | without `else` an empty string is returned |
| `object.or == 1` | compares the field `or` | a word with a dot is a path, not an operator |
| `if(object.status, 1, 0)` with `status = 'Active'` | error `Condition must be boolean, got "Active"` | a condition must be boolean |

### Examples

All of these were checked on a running WPS with the event object `{ "id": "1", "name": "Deal", "amount": "500" }`:

| Expression | Result |
|---|---|
| `object.amount * 0.9` | `450` |
| `round(object.amount * 0.9, 2)` | `450` |
| `'10' + 5` | `15` |
| `object.name + 5` | `Deal5` |
| `object.missing * 2 + 1` | `1` |
| `10 / 4` | `2.5` |
| `0.1 + 0.2` | `0.3` |
| `round(object.amount * 1.2 + 0.005, 2)` | `600.01` |
| `7 % 3` | `1` |
| `substr(upper('hello world'), 0, 5)` | `HELLO` |
| `concat('Discount applied, new amount: ', object.amount)` | `Discount applied, new amount: 500` |
| `if(object.amount > 100, 'VIP', 'Regular')` | `VIP` |
| `object.amount == '500.00'` | `true` |
| `coalesce(object.missing, object.name)` | `Deal` |

### Errors

Checked when the process is saved (the step never runs with a broken expression):

| Message | Cause |
|---|---|
| `Action 1: specify a value` / `Expression is required` | Empty expression |
| `Unknown function "foo"` | Function does not exist |
| `Function "if" expects 2..3 arguments, got 1`, `Function "upper" expects 1 argument, got 0`, `Function "switch" expects at least 3 arguments, got 2` | Wrong number of function arguments |
| `Comparison operators cannot be chained …` | A comparison chain `1 < 2 < 3` — write `1 < 2 and 2 < 3` |
| `Unknown identifier "amount" (use object.<field> or <variable>.<field>) at position 0 in "amount * 2"` | A field without `object.` / variable prefix |
| `Unexpected ")" at position 8 in "round(1))"`, `Expected ")" at position …` | Unbalanced parentheses or a stray operator |
| `Unterminated string starting at position …` | A missing closing quote |
| `Unexpected character "#" at position …` | A character that is not part of the syntax |
| `Expression is too long …`, `Expression is too complex …`, `Expression is nested too deeply …` | A [limit](#limits) is exceeded |
| `Variable "v3" is not defined at this point` | The expression uses a variable that is not declared before this row |
| `Function "round": precision must be an integer`, `Function "number_format": decimals must be an integer` | A fractional literal precision |
| `Number is too large at position …` | A numeric literal longer than 100 digits |

Checked during the execution (the step fails, the execution ends as **Failed**): operations on non-numeric text,
division by zero, a non-boolean condition (`Condition must be boolean, got "…"`), an ordering comparison of lists
(`Lists cannot be compared with "<"`) or of a boolean with a value that cannot be converted
(`Cannot compare boolean with "…"`), a result that is too long (`Expression result is too long`), a result that is not a
finite number (`Expression result is not a finite number`), a number longer than 100 digits (`Number is too large`), a
non-numeric argument of a numeric function (`Function "floor" requires a number, got "abc"`), unparseable text in
`to_number` / `to_int` (`Cannot convert "abc" to a number`).

## Template

Builds text from fixed parts and substitutions — instead of chains of `concat()`. The result is always a string.

```text
Call: {{ object.first_name }} {{ object.last_name }}
Deal {{ object.name }} for {{ object.amount | number:2:',':' ' }}, due {{ object.date_closed | format_date:'d.m.Y' }}
Owner: {{ owner.last_name | default:'not assigned' }}
Discount: {{= if(object.amount > 100000, '10%', '0%') }}
```

Through the API the template goes into `value` (`template` is a synonym):

```json
{ "type": "modify", "target": "call.name", "change_type": "template",
  "value": "Call: {{ object.first_name }} {{ object.last_name | upper | truncate:25 }}", "missing": "empty" }
```

### Template syntax

| Construct | What it does |
|---|---|
| `{{ path }}` | a field value: `object.name`, `v1.address.city`; spaces inside the braces do not matter |
| `{{ path \| f }}`, `{{ path \| f:a:b }}` | filters, left to right; `x \| f:a:b` is computed as the function `f(x, a, b)` |
| `{{ 'text' }}`, `{{ 5 }}` | a quoted string or a number instead of a path |
| `{{= expression }}` | any [Calculate](#calculate) expression: `{{= coalesce(object.phone_mobile, object.phone_work, 'none') }}`; no filters inside `{{= }}` — use functions |
| `{{! comment }}` | outputs nothing |
| `\{{` | a literal `{{` (no substitution) |
| `\\{{` | a literal `\` followed by a substitution |

- A filter argument is a path (`truncate:object.qty`), a string in `'…'` / `"…"` or a number. A colon or `|` inside
  quotes does not split arguments: `format_date:'H:i'`.
- `}}` inside a string of an expression does not close the tag: `{{= concat('}}', object.name) }}`.
- A lone `}}` in plain text is just text (JSON-like text `{"a":{"b":1}}` does not break the template).
- A field value is never rendered as a template again: a field containing `{{ object.name }}` is inserted as is.
- Inside `{{ }}` there is only a path, a string or a number with filters. Calculations (`object.a * 2`) go into `{{= }}`.

### Filters

A filter is any [expression function](#functions) whose first argument becomes the value: `upper`, `lower`, `trim`,
`substr`, `round`, `number`, `format_date`, `default`, `truncate`, `capitalize`, `join`, `json`, `urlencode`, `nl2br`
and the rest. The number of filter arguments is checked on save (`{{ x | truncate }}` →
`Template filter "truncate" expects 1..2 arguments, got 0`). The full list with examples is returned by
[`GET /api/dictionaries/template-filters`](../06-api-reference/endpoints.md#get-apidictionariestemplate-filters).

| Template | Result (`object.amount = 1234567.891`, `object.date_closed = 2026-10-31`) |
|---|---|
| `{{ object.amount \| number:2:',':' ' }}` | `1 234 567,89` |
| `{{ object.date_closed \| format_date:'d.m.Y' }}` | `31.10.2026` |
| `{{ '  abc ' \| trim \| upper }}` | `ABC` |
| `{{ object.name \| truncate:5 }}` with `name = Big deal` | `Big …` |
| `{{ object.industries \| join }}` with `^Banking^,^Retail^` | `Banking, Retail` |

### Missing values

A value is **missing** when the variable does not exist, is empty (`null`) or has no such field. A field that exists
but contains `''` or `null` is **not** missing: an empty string is inserted.

The row parameter `missing` decides what a missing value gives:

| `missing` | Result |
|---|---|
| `empty` (default) | an empty string; the path is listed in `result_data.missing` of the step (visible in the audit) |
| `error` | the step fails with `Template references missing value "v1.email"` |
| `keep` | the tag stays in the text as is (`{{ object.none }}`) — handy for debugging |

The `default` filter covers both empty and missing values: `{{ object.none | default:'—' }}` → `—`, and with
`missing: error` a substitution with `default` does not fail.

### Conversion to a string

`null` → empty string, `true` / `false` → `true` / `false`, a fraction without trailing zeros (`90.0` → `90`), a list or
an object → JSON (`["a","b"]`). A multienum (`^a^,^b^`) is inserted as is — the `join` filter turns it into text.

### Template limits and errors

| Limit / error | When | Message |
|---|---|---|
| Template length 64 KB | on save | `Template is too long (max 65536 characters)` |
| Result over 1 MB | at run time | `Template result is too long` |
| Unclosed tag | on save | `Unclosed "{{" at position 7` (position in characters) |
| Empty tag `{{ }}` | on save | `Empty template tag at position 2` |
| Empty expression `{{= }}` | on save | `Empty expression in template tag at position …` |
| Unknown filter | on save | `Unknown template filter "foo"` |
| Wrong number of filter arguments | on save | `Template filter "truncate" expects 1..2 arguments, got 0` |
| An expression instead of a path in `{{ }}` | on save | `Invalid value "object.a * 2" in template tag …` |
| Error in `{{= }}` | on save | the expression parse message, e.g. `Unexpected end of expression …` |
| The `raw` filter | on save | `Template filter "raw" is not allowed here` (forbidden in `modify`) |
| Undeclared variable | on save | `Variable "v9" is not defined at this point` |
| A whole variable `{{ object }}` | on save | `"object" must point to a field, e.g. object.name` |
| Wrong `missing` | on save | `Must be one of: empty, error, keep` (field `missing`) |
| The template is not a string | on save | `Template must be a string` |
| A filter function fails | at run time | as in Calculate: `Function "number_format" requires a number, got "Big deal"` |

Run-time substitution errors are counted by the
[`wps_template_render_errors_total`](../04-operations/monitoring.md) metric.

## Date shift

The `date_shift` type ("Сдвиг даты") computes a date from a base with a shift — without formulas. It is the
structured form of what **Calculate** does with `add_days()`, `add_months()` and so on (see
[Dates and time](#dates-and-time)): both use the same code, so the results match. `date_shift` is handier for simple
deadlines and the future UI, `calculate` — when logic is needed (`if`, `date_diff`, formatting).

```json
{ "type": "modify", "target": "task.date_due", "change_type": "date_shift",
  "base": "now", "shift": [ { "amount": 2, "unit": "day" } ], "set_time": "18:00" }
```

| Field | Values | Default |
|---|---|---|
| `base` | `now` — the current moment; `today` — today's date in `WPS_TIMEZONE`; a field path (`object.date_closed`, `v1.x`) | `now` |
| `base_cast` | how to parse the base field: `"date"` / `"datetime"` or an object `{"type": "date"\|"datetime", "format": "d.m.Y H:i", "timezone": "Europe/Moscow"}` (`format` — a PHP format string or a list of them, `timezone` — the zone of a time written without an offset) | the format is detected automatically (as in date functions), a datetime without an offset is UTC |
| `shift` | a list of `{"amount", "unit"}` applied in order; one object `{"amount": 3, "unit": "day"}`; a short string `"+2 day"`, `"-30 days"`, `"+1 week -2 day +30 minute"` | no shift |
| `unit` | `minute`, `hour`, `day`, `week`, `month`, `year` (plural is fine: `days`) | — |
| `amount` | an integer (may be negative) or a path to a field with an integer (`"object.days"`); `\|amount\| ≤ 100000` | — |
| `set_time` | `HH:MM` — the time of day after the shift, in `WPS_TIMEZONE` | unchanged |
| `result` | `date` → `Y-m-d` (the day in `WPS_TIMEZONE`), `datetime` → `Y-m-d H:i:s` in UTC | the kind of the resulting value (below) |
| `on_missing` | when the base field is missing, empty or `0000-00-00`: `null` — the result is `null` (the field is cleared), `error` — the step fails, `now` — use the current moment | `null` |

The units are listed by [`GET /api/dictionaries/date-units`](../06-api-reference/endpoints.md#get-apidictionariesdate-units).

**How it is computed.**

- Base `now` is a datetime; `today` is a date; a field is a date or a datetime according to its value (or `base_cast`).
- `minute`, `hour` — absolute time: across a DST change `+24 hour` gives a different local time. A date shifted by
  hours becomes a datetime (from midnight in `WPS_TIMEZONE`).
- `day`, `week`, `month`, `year` — calendar units in local time (`WPS_TIMEZONE`): 09:00 stays 09:00 after a DST change.
  Months clip to the end of the month: 31.01 + 1 month = 28.02 (29.02 in a leap year), 29.02 + 1 year = 28.02.
- `set_time` sets the local time and makes the result a datetime. If that time does not exist (it falls into the DST
  gap), the next existing time is used: 02:30 on the night of the change in Berlin → 03:30.
- `result` by default is the kind of the resulting value: `today` or a date field without `set_time` and without an hour
  shift → a date; `now`, a field with a time, a minute/hour shift or `set_time` → a datetime in UTC. An explicit
  `result: date` of a datetime takes the day in `WPS_TIMEZONE`, `result: datetime` of a date — midnight in `WPS_TIMEZONE`.

**Audit.** Besides `old_value` / `new_value`, the step's `result_data` has `base` (the base in canonical form),
`shifts` (the applied shifts with amounts taken from fields: `[{"amount": 5, "unit": "day"}]`) and `missing_base`
(`true` when the base field had no value).

**No working days.** Shifts use calendar units only; business calendars, holidays and working hours are not taken
into account. To add, say, `workday`, add a `DateUnit` case and a `DateMath` method (see
[Custom expression functions](../07-developer-guide/extending/expression-functions.md)).

### Scenarios

| Task | Settings | Result (`WPS_TIMEZONE = Europe/Moscow`, now 16.10.2026 10:30 MSK) |
|---|---|---|
| Task due in 2 days at 18:00 | `{"base": "now", "shift": "+2 day", "set_time": "18:00"}` | `2026-10-18 15:00:00` (UTC) |
| Call tomorrow at 10:00 | `{"base": "today", "shift": "+1 day", "set_time": "10:00"}` | `2026-10-17 07:00:00` |
| Reminder 30 days before the contract ends | `{"base": "object.contract_end", "shift": "-30 day"}` with `2026-12-01` | `2026-11-01` |
| Next contact in a month | `{"base": "object.date_closed", "shift": "+1 month"}` with `2026-01-31` | `2026-02-28` |
| Case response due in 8 hours | `{"base": "now", "shift": "+8 hour"}` | `2026-10-16 15:30:00` |

The same with **Calculate**: `datetime(concat(add_days(today(), 2), ' 18:00'), 'Y-m-d H:i', 'local')`,
`add_days(object.contract_end, -30)`, `add_months(object.date_closed, 1)`, `add_hours(now(), 8)`.

### Date shift errors

| Situation | When | Message |
|---|---|---|
| `base` is not `now` / `today` / a path | on save | `Base must be "now", "today" or a field path` or a path error (`"yesterday" must point to a field …`) |
| The base is an undeclared variable | on save | `Variable "v9" is not defined at this point` |
| Wrong `base_cast` | on save | `Base cast must be "date" or "datetime"`; options as in `cast` (`Unknown timezone "Mars/Base"` in `base_cast.timezone`) |
| `shift` is neither a list nor a string like `"+2 day"` | on save | `Shift must be a list of {amount, unit} or a string like "+2 day"` |
| Unknown unit (including `workday`) | on save | `Unit must be one of: minute, hour, day, week, month, year` |
| `amount` is a fraction or text | on save | `Amount must be an integer or a field path` |
| `\|amount\| > 100000` | on save | `Amount is out of range` |
| `set_time` is not `HH:MM` (`9:00`, `24:00`) | on save | `Time must be HH:MM` |
| Wrong `result` / `on_missing` | on save | `Result must be "date" or "datetime"` / `Must be one of: null, error, now` |
| The base field cannot be parsed | at run time | `date_shift: cannot parse "abc" as datetime` (or `as date` with `base_cast: date`) |
| `amount` from a field is not an integer | at run time | `date_shift: amount must be an integer, got "1.5"` |
| No base with `on_missing: error` | at run time | `date_shift: base "object.contract_end" has no value` |

## Increment / decrement by

The `increment` ("Increment by") and `decrement` ("Decrement by") types add a step to the current value of the `target`
field or subtract it — touch counters, balances, scores without an `x + 1` expression.

```json
{ "type": "modify", "target": "object.touches_c", "change_type": "increment" }
{ "type": "modify", "target": "object.balance_c", "change_type": "decrement", "value": "150,75", "cast": "decimal", "scale": 2 }
{ "type": "modify", "target": "object.score_c", "change_type": "increment", "value_type": "from_object_field", "value": "v1.bonus" }
```

| Field | Values | Default |
|---|---|---|
| `value` | the step — a number (`1`, `"1,5"`) or a path with `value_type: from_object_field` | `1` |
| `cast` | `int` or `decimal` — the kind of the result | an integer if the current value and the step are integers, otherwise a decimal |
| `scale` | decimal places, `0..10` | no rounding |
| `on_invalid` | the current value is not a number: `error` — the step fails, `reset` — count from 0 | `error` |

- A missing or empty current value counts as **0**: the first `increment` gives `1`.
- Fractions use decimal arithmetic without losing cents (`80 + 0.25 = 80.25`).
- The value is taken **from the execution context** (the event or a variable), not re-read from the CRM, and the `PUT`
  is not atomic: if two processes increment the same field at once, one increment can be lost. For exact counters
  compute the value with an aggregate over records.
- Errors: `Step must be a number`, `Cast must be "int" or "decimal"`, `Scale must be an integer from 0 to 10`,
  `Must be "error" or "reset"`; at run time — `increment: current value "abc" of object.x is not a number`.

## Clear

The `clear` type ("Clear") makes the field empty:

```json
{ "type": "modify", "target": "object.sic_code", "change_type": "clear" }
{ "type": "modify", "target": "object.reason_c", "change_type": "clear", "empty": "null" }
```

`empty` — `""` (default, an empty string — the SuiteCRM plugin reliably writes it to a field of any type) or `"null"`
(for CRMs where the difference matters; the SuiteCRM plugin clears the field too). Another value — `Must be "" or "null"`.

## Append, prepend, remove from a list

The `append` ("Append to the end"), `prepend` ("Prepend") and `remove` ("Remove from list") types append text to a field
or add and remove elements of a list (a multienum).

**Text** (`mode: "text"`, default) — the [template](#template) `value` (or a field value with
`value_type: from_object_field`) is added to the current value:

```json
{ "type": "modify", "target": "object.description", "change_type": "append",
  "value": "{{ now() | format_date:'d.m.Y' }}: ticker {{ object.ticker_symbol }}", "separator": "; ", "max_length": 2000 }
```

- `separator` goes only between the old and the new value: an empty field gets no separator.
- An empty new value leaves the field as it was.
- `max_length` caps the result length: `append` keeps the **end** (the newest entries), `prepend` the start.

**List** (`mode: "list"`) — the field is parsed as a list, the elements of `value` are added or removed:

```json
{ "type": "modify", "target": "object.template_ddown_c", "change_type": "append", "mode": "list",
  "value": "vip", "list": { "separator": ",", "wrapper": "^" } }
{ "type": "modify", "target": "object.template_ddown_c", "change_type": "remove", "mode": "list",
  "value": "^cold^,^test^", "list": { "separator": ",", "wrapper": "^" } }
```

- `list` is the storage format: `separator` (`,` by default, 1–5 characters) and `wrapper` (e.g. `^` for a SuiteCRM
  multienum `^a^,^b^`). `value` is parsed with the same format, so several elements can be added at once.
- `unique` (default `true`) — do not add elements that are already there.
- The result is written in the same format (`^vip^,^gold^`); if the field was an array (e.g. from an HTTP answer), it
  stays an array. All elements removed — an empty string `''`.
- `remove` works **only** with `mode: "list"`: `"remove" works only with mode "list"`.
- Errors: `Value is required`, `Mode must be "text" or "list"`, `Must be an object {"separator": ..., "wrapper": ...}`,
  `Separator must be 1-5 characters`, `Wrapper must be up to 5 characters`, `Must be a positive integer` (`max_length`).

## Map

The `map` type ("By a lookup table") translates the value of the `source` field by a table: industry → rating, amount →
category, country code → region.

**Exact match** (`mode: "exact"`, default):

```json
{ "type": "modify", "target": "object.rating", "change_type": "map", "source": "object.industry",
  "map": { "Banking": "Hot", "Technology": "Warm", "": "Not set" }, "default": "Cold",
  "cast": "string", "cast_options": { "case_sensitive": false } }
```

- The `map` keys are cast with the same `cast` / `cast_options` as the `source` value (case — `case_sensitive`, as in
  conditions). The `""` key matches an empty or missing value. Keys equal after the cast (`"100"` and `"100.0"` with
  `decimal`) are a save error.

**Ranges** (`mode: "ranges"`, `cast` is required: `int`, `decimal`, `date`, `datetime`):

```json
{ "type": "modify", "target": "object.priority_c", "change_type": "map", "source": "object.amount", "mode": "ranges",
  "cast": "decimal", "ranges": [ { "lt": 10000, "value": "Low" }, { "lt": 100000, "value": "Medium" }, { "lte": 1000000, "value": "High" } ],
  "default": "Strategic" }
```

- Ranges are checked in order, the first matching one wins: `lt` — strictly less than the bound, `lte` — less than or
  equal. The bounds must be ascending.

**Common.**

- `value_template: true` — the table values and `default` are [templates](#template): `"Customer {{ object.name }}"`.
- No match — `on_no_match`: `default` (the `default` value; the default when `default` is set), `keep` (the field is
  unchanged; the default without `default`), `null`, `error` (the step fails: `map: no match for "Other" in object.industry`).
- Save errors: `Mode must be "exact" or "ranges"`, `Map must be a non-empty object`, `Too many entries (max 500)`,
  `Key "x" cannot be cast to decimal`, `Keys "100" and "100.0" are equal after cast`,
  `Mode "ranges" requires cast: int, decimal, date or datetime`, `Ranges must be a non-empty list`,
  `Range must have exactly one of "lt", "lte"`, `Value is required`, `Bound is not a valid decimal`,
  `Ranges must be in ascending order`, `Must be one of: default, keep, null, error`, `Default value is required`.

**Example: an account card.** When the account ticker changes — a counter, a history, a clear and a rating by industry:

```json
{ "type": "modify", "target": "object.employees", "change_type": "increment" }
{ "type": "modify", "target": "object.description", "change_type": "append", "value": "ticker {{ object.ticker_symbol }}", "separator": "; " }
{ "type": "modify", "target": "object.sic_code", "change_type": "clear" }
{ "type": "modify", "target": "object.rating", "change_type": "map", "source": "object.industry",
  "map": { "Banking": "Hot", "Technology": "Warm" }, "default": "Cold", "cast": "string", "cast_options": { "case_sensitive": false } }
```

Result on SuiteCRM 8 (case `.tehnical/suiteCRM8/processes/cases/5.1.3-change-types.json`, texts in Russian): an account
with 4 employees, the description "создан", `sic_code` 6021 and the Banking industry after the ticker changed to WPS1 —
5, "создан; тикер WPS1", an empty `sic_code`, rating Hot; after the industry changed to Other and the ticker to WPS2 — 6,
"создан; тикер WPS1; тикер WPS2", rating Cold.

## Aggregate over records

The `aggregate` type ("Aggregate over records") computes **a count, sum, average, minimum or maximum over CRM records**
and writes the result into a field: "the number of open tasks of the account", "the sum of deals", "the date of the last
call". It is the counterpart of roll-up summary fields — without a separate search and a loop.

```json
{ "type": "modify", "target": "object.annual_revenue", "change_type": "aggregate",
  "function": "sum", "field": "amount",
  "source": { "relationship": { "module": "Accounts", "id": "object.id", "link": "opportunities" } } }
```

| Field | Values | Default |
|---|---|---|
| `function` | `count`, `count_distinct`, `sum`, `avg`, `min`, `max` (dictionary [`GET /api/dictionaries/aggregate-functions`](../06-api-reference/endpoints.md#get-apidictionariesaggregate-functions)) | — |
| `field` | Field of the records the function works on; not needed for `count` (records are counted) | — |
| `cast` | How values are cast: for `sum` / `avg` — `int` or `decimal`; for `min` / `max` required — `int`, `decimal`, `date`, `datetime` or `string`; for `count_distinct` — the same or no cast. Options — `cast_options`, as in conditions | `decimal` for `sum` / `avg` |
| `scale` | Decimal places of `sum` / `avg`, `0..10` | `2` for `avg`, no rounding for `sum` |
| `source` | **Exactly one** of two: `{"module": "Tasks", "filter": [...]}` — records of a module by a filter (format and operators as in [Find records](actions.md#find-records), the filter is required); `{"relationship": {"module": "Accounts", "id": "object.id", "link": "opportunities"}}` — records linked to the record `id` (a path to the id) through a link field | — |
| `limit` | Most records read, `1..AGGREGATE_MAX_RECORDS` | `1000` |
| `on_limit` | More records than `limit`: `error` — the step fails; `partial` — the result over the first `limit` records (`truncated: true` in the audit) | `error` |
| `empty_result` | Result when there are no records (or all have an empty field) | `0` for `count`, `count_distinct`, `sum`; `null` for `avg`, `min`, `max` |

The type works wherever change types work: in `modify`, in [`set_variable`](actions.md#set-a-process-variable) (several
aggregates in one variable) and in the rules of [`update_records`](actions.md#update-list-records).

**How it is computed.**

1. **Source `module` + `filter`.** The filter is translated into a CRM contract 2.0 query. If a filter value from the
   record is empty (e.g. `object.account_id` is not filled), **there is no request**: the result is `empty_result`,
   with `skipped_reason` in the audit. Otherwise `account_id = ''` would count all records without an account.
2. **`count` without `field` by a filter** — one request: the number comes from the CRM `meta.total` (the `count`
   feature), records are not read.
3. Other cases: WPS reads the records in pages of 100 (only `id` and `field`) and folds them itself. More records than
   `limit` — by `on_limit`.
4. **Source `relationship`** — the related records are read in full (no filter); an empty `id` — `empty_result`
   without a request. The CRM feature `relationships` is needed.
5. Empty values (`null`, `''`) are skipped. A value that cannot be cast by `cast` is a step error with the record id:
   `aggregate: value "abc" of Opportunities.amount (id …) is not a decimal`.
6. Sums and averages use decimal arithmetic without losing cents (`1000 + 2500.50 = 3500.5`); numbers are returned as
   numbers. `min` / `max` of dates are in the CRM storage format: date `Y-m-d`, datetime `Y-m-d H:i:s` in UTC.
7. **The result is not transactional**: records in the CRM may change while WPS reads the pages. For summaries this
   usually does not matter; for money where accuracy at a moment matters, reconcile the total separately.

**Audit.** The step `result_data`, besides `old_value` / `new_value`: `function`, `module` (and `link` for a
relationship), `records_scanned` (how many records were read), `pages`, `truncated`, `total` (when the number came
from `meta.total`), `skipped_reason` (no request was made). For `set_variable` the details are per field.

**Load on the CRM.** Every aggregate is a separate request (or several pages) on every execution of the process. Narrow
filters, `count` without `field` and a reasonable `limit` keep it down; the overall cap is `AGGREGATE_MAX_RECORDS` (see
[CRM integration](../04-operations/crm-integration.md)).

### Example: an account summary

```json
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
    "empty_result": "none" } ] }
```

Result on SuiteCRM 8 (case `.tehnical/suiteCRM8/processes/cases/5.1.7-aggregate.json`): the account has 3 linked deals
for 1000, 2500.50 and 500 → `deals = 3`, `total = 4000.5`, `avg = 1333.5` (`records_scanned = 3`); 2 open tasks out of
3 → `open_tasks = 2` in one request (`total = 2`); calls on 03.10 and 05.11 → `last_call = 2026-11-05 14:30:00`. The
account description by a template is "Сделок: 3 на 4000.5 (средняя 1333.5); открытых задач: 2; последний звонок:
2026-11-05 14:30:00" (in Russian).

### Aggregate errors

| Situation | When | Message (field) |
|---|---|---|
| Unknown function | on save | `Function must be one of: count, count_distinct, sum, avg, min, max` (`…function`) |
| No `field` for a function other than `count`, invalid field name | on save | `Field is required for "sum"`, `Invalid field name` (`…field`) |
| Unsuitable `cast` | on save | `Function "sum" requires cast: int or decimal`, `Function "max" requires cast: int, decimal, date, datetime or string`, `Cast must be one of: …` (`…cast`) |
| Invalid `scale` | on save | `Scale must be an integer between 0 and 10` (`…scale`) |
| No source or both | on save | `Specify either "module" with "filter" or "relationship"` (`…source`) |
| Aggregate over a whole module | on save | `Filter is required (aggregating a whole module is not allowed)` (`…source.filter`) |
| Filter row errors | on save | the same as in Find records (`…source.filter[j]…`) |
| Invalid relationship | on save | `Invalid CRM module`, `Link name is required (e.g. contacts)`, an `id` path error (`…source.relationship.*`) |
| Invalid `limit` / `on_limit` | on save | `Limit must be between 1 and 5000`, `Must be "error" or "partial"` |
| More records than `limit` with `on_limit: error` | at run time | `aggregate: more than 1000 records of Opportunities; narrow the filter or set "on_limit": "partial"` |
| A value cannot be cast | at run time | `aggregate: value "abc" of Opportunities.amount (id …) is not a decimal` |

## See also

- [Actions](actions.md) — targets, **Save to CRM**, new variables.
- [Recipes](recipes.md) — complete processes with calculations.

<!-- footer-nav -->
---

← Previous: [Actions](actions.md) | [Contents](../README.md) | [Child processes](subprocesses.md) Next →
<!-- /footer-nav -->
