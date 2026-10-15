# Функции выражений

Как добавить функцию в выражения `calculate` — на проверенном примере `coalesce(a, b, …)`.

<!-- lang-switch -->
[🇬🇧 English](../../../en/07-developer-guide/extending/expression-functions.md) | 🇷🇺 Русский
<!-- /lang-switch -->

## 1. Когда это нужно

Выражения `calculate` (`modify` с `"change_type": "calculate"`) поддерживают числа, строки в кавычках, пути
(`object.amount`, `v1.price`), `+ - * / %`, скобки и функции `concat`, `upper`, `lower`, `trim`, `len`,
`substr`, `round`, `abs`, `min`, `max`. Функцию стоит добавить, когда пользователям нужно небольшое чистое вычисление:
значение по умолчанию, форматирование даты, дополнение строки. Для логики с собственной конфигурацией пишите
[тип изменения](new-change-type.md).

## 2. Как это устроено

- `Wps\Expression\ExpressionEngine` = `ExpressionLexer` (токены) + `ExpressionParser` (AST) + вычислитель. `eval` не используется.
  Грамматика вызова: `IDENT '(' [expr (',' expr)*] ')'`; имена функций нечувствительны к регистру (хранятся в нижнем регистре).
- Точка расширения — `registerFunction(string $name, callable $fn)`. Функция получает уже вычисленные аргументы
  (отсутствующий путь даёт `''`) и возвращает значение.
- **При сохранении** `CalculateChangeType::validate()` разбирает выражение; `parse()` проверяет, что каждая вызываемая
  функция зарегистрирована (`Unknown function "foo"` → `422`). **Во время выполнения** неверное число аргументов
  превращается в `NonRecoverableException("Wrong number of arguments for function foo()")`.
- На каждый воркер в `Container::expressions()` создаётся один экземпляр движка; его используют `CalculateChangeType`
  и всё остальное, что вычисляет выражения, поэтому зарегистрированная там функция сразу доступна везде.
- Разобранное AST кэшируется по строке выражения (до 1000 записей).

## 3. Пошагово

### 3.1 Регистрация функции — `back/src/Kernel/Container.php`

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

Для большого набора функций создайте класс (например, `Wps\Expression\DateFunctions` со статическим
`register(ExpressionEngine $e)`) и вызовите его здесь — встроенные функции регистрируются так же,
в `ExpressionEngine::registerDefaultFunctions()`.

### 3.2 Использование

```json
{ "type": "modify", "target": "object.label", "change_type": "calculate", "value": "coalesce(object.nickname, object.name)" }
```

При `nickname = ""` и `name = "Bob"` результат — `"Bob"` (проверено).

## 4. Валидация и ошибки

- Обязательные аргументы объявляйте обычными параметрами: `ArgumentCountError` PHP превращается в понятную
  невосстановимую ошибку. Необязательным задайте значения по умолчанию, переменному числу — `mixed ...$args`.
- Преобразование значений: для строк используйте `ExpressionEngine::toString($v)`. Для чисел ориентируйтесь на встроенные
  функции (они бросают `NonRecoverableException` на нечисловой ввод через приватный `toNumber()` движка); в своей функции
  проверьте `is_numeric()` и бросьте `NonRecoverableException` сами.
- Функции должны быть **чистыми и быстрыми**: без ввода-вывода и без случайности, ломающей повторы. Результат пишется
  в контекст и может быть сохранён в CRM.

## 5. UI и переводы

Справка по выражениям в блоке изменения (`ModifyPanel.vue`, переключатель «Справка по выражениям «Расчёт»») перечисляет
функции как код, а не как переводимый текст. Добавьте новую в список:

```vue
<i18n-t keypath="modify.help.functions" tag="li" scope="global">
  <template #list>
    <code>concat(a, b, …)</code>, <code>upper(s)</code>, <code>lower(s)</code>, <code>trim(s)</code>,
    <code>len(s)</code>, <code>substr(s, start[, length])</code>, <code>round(x[, precision])</code>,
    <code>abs(x)</code>, <code>min(a, b, …)</code>, <code>max(a, b, …)</code>, <code>coalesce(a, b, …)</code>
  </template>
</i18n-t>
```

Ключи локализации не нужны. Боковая панель переменных извлекает пути из выражений регулярным выражением
(`extractPaths()` в `front/src/utils/variables.ts`); имена функций без точки за пути не принимаются.

Чтобы показывать список динамически, отдайте `ExpressionEngine::functionNames()` через эндпоинт — именно этот эндпоинт
разобран в примере [Новый эндпоинт API](new-api-endpoint.md).

## 6. Тест

Из проверенной группы `testExtensions()` ([полный текст](new-action-type.md#6-тест)):

```php
// action: ['type' => 'modify', 'target' => 'object.label', 'change_type' => 'calculate',
//          'value' => 'coalesce(object.nickname, object.name)']
// manual run with 'nickname' => '', 'name' => 'Bob':
$this->assertEquals('Bob', $execution['context']['variables']['object']['label']);
```

Неизвестная функция отклоняется при сохранении (существующая проверка в `testProcessValidation()` использует
синтаксическую ошибку; для `foo(1)` сообщается то же поле `definition.actions[0].value`).

## 7. Чек-лист готовности

- [ ] Функция зарегистрирована в `Container::expressions()` (имя в нижнем регистре, чистая функция)
- [ ] Ошибки аргументов дают `NonRecoverableException`
- [ ] Добавлена в список справки в `ModifyPanel.vue`
- [ ] Интеграционная проверка добавлена, тесты зелёные
- [ ] Указана в [Типах изменений и выражениях](../../03-user-guide/change-types-and-expressions.md) (en + ru) и в `back/API.md`

<!-- footer-nav -->
---

← Назад: [Новая стратегия загрузки](new-load-strategy.md) | [Оглавление](../../README.md) | [Новый эндпоинт API](new-api-endpoint.md) Далее →
<!-- /footer-nav -->
