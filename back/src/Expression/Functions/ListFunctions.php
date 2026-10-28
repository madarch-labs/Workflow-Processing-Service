<?php

declare(strict_types=1);

namespace Wps\Expression\Functions;

use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\Decimal;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\FunctionSpec;
use Wps\Types\CastOptions;
use Wps\Types\CastType;

/**
 * Functions over lists (md/tasks_list/done/5.2.5-expr-lists.md §4.2): list(), to_list, count, in, first/last/nth,
 * slice, unique, sort, reverse, concat_lists, without, sum/avg, pluck, where, index_of_item.
 *
 * A list is a PHP list array of scalars or CRM records. Values are converted with TypeCaster::toList(), so a missing
 * path, null and '' are an empty list, a JSON array in a string is decoded, and any other string is one element
 * (multienum needs explicit separator/wrapper — the language does not know about CRM formats).
 * Iteration is the job of the foreach block (4.11), not of the expression language.
 */
final class ListFunctions
{
    public const MAX_ITEMS = 10000;

    public static function register(ExpressionEngine $engine): void
    {
        $caster = $engine->caster();
        $comparator = $engine->comparator();
        $list = static function (mixed $value, string $fn) use ($caster): array {
            return self::limit($caster->toList($value), $fn);
        };
        $text = static fn (mixed $v): string => ExpressionEngine::toString($v);
        $spec = static fn (string $name, int $min, ?int $max, string $signature, string $description, string $example, string $returns = 'list', ?\Closure $validator = null): FunctionSpec
            => new FunctionSpec($name, $min, $max, 'list', $signature, $description, $example, $returns, true, $validator);

        $engine->registerFunction('list', static fn (mixed ...$items) => self::limit(array_values($items), 'list'),
            $spec('list', 0, null, 'list(a, …)', 'Список из аргументов (то же, что литерал [a, …])', "list('vip', 'partner')"));

        $engine->registerFunction('to_list', static function (mixed $value, mixed $separator = null, mixed $wrapper = null) use ($caster, $text): array {
            $sep = $separator === null || $separator === '' ? null : $text($separator);
            $wrap = $wrapper === null || $wrapper === '' ? null : $text($wrapper);
            if ($sep === null && $wrap !== null) {
                throw new NonRecoverableException('to_list(): separator is required when wrapper is given');
            }
            return self::limit($caster->toList($value, $sep, $wrap), 'to_list');
        }, $spec('to_list', 1, 3, "to_list(s[, separator[, wrapper]])",
            'Приводит значение к списку: массив — как есть, пустое — [], JSON-массив — декодированный, строка — один элемент; с separator — делит строку (wrapper снимает обрамление каждого элемента, мультисписок ^a^,^b^)',
            "to_list(object.tags_c, ',', '^')"));

        $engine->registerFunction('count', static fn (mixed $value) => count($list($value, 'count')),
            $spec('count', 1, 1, 'count(list)', 'Число элементов списка; отсутствующее и пустое — 0, скаляр — 1', 'count(tasks.items)', 'number'));

        $engine->registerFunction('in', static function (mixed $value, mixed $haystack) use ($caster, $comparator): bool {
            foreach (self::limit($caster->toList($haystack), 'in') as $item) {
                if ($comparator->compare('==', $value, $item)) {
                    return true;
                }
            }
            return false;
        }, $spec('in', 2, 2, 'in(x, list)', 'Есть ли x в списке (сравнение по правилам ==, "100" == 100); строка — один элемент, не подстрока',
            "in('vip', ['vip', 'partner'])", 'boolean'));

        $engine->registerFunction('first', static fn (mixed $value) => $list($value, 'first')[0] ?? '',
            $spec('first', 1, 1, 'first(list)', "Первый элемент списка или '' для пустого", 'first(tasks.items)', 'any'));
        $engine->registerFunction('last', static function (mixed $value) use ($list) {
            $items = $list($value, 'last');
            return $items === [] ? '' : $items[count($items) - 1];
        }, $spec('last', 1, 1, 'last(list)', "Последний элемент списка или '' для пустого", 'last(tasks.items)', 'any'));

        $engine->registerFunction('nth', static function (mixed $value, mixed $index) use ($list) {
            $items = $list($value, 'nth');
            $position = self::integer($index, 'nth', 'index');
            if ($position < 0) {
                $position += count($items);
            }
            return $items[$position] ?? '';
        }, $spec('nth', 2, 2, 'nth(list, index)', "Элемент по индексу с 0; отрицательный — с конца; вне диапазона — ''", 'nth(tasks.items, 0)', 'any'));

        $engine->registerFunction('slice', static function (mixed $value, mixed $start, mixed $length = null) use ($list): array {
            $items = $list($value, 'slice');
            $offset = self::integer($start, 'slice', 'start');
            $count = $length === null ? null : self::integer($length, 'slice', 'length');
            return self::limit(array_slice($items, $offset, $count), 'slice');
        }, $spec('slice', 2, 3, 'slice(list, start[, length])', 'Подсписок с позиции start (array_slice: отрицательные значения считаются с конца)',
            'slice(tasks.items, 0, 5)'));

        $engine->registerFunction('unique', static function (mixed $value) use ($list, $comparator): array {
            $result = [];
            foreach ($list($value, 'unique') as $item) {
                foreach ($result as $seen) {
                    if ($comparator->compare('==', $seen, $item)) {
                        continue 2;
                    }
                }
                $result[] = $item;
            }
            return self::limit($result, 'unique');
        }, $spec('unique', 1, 1, 'unique(list)', 'Без повторов (сравнение ==), порядок первого вхождения', 'unique(concat_lists(a.items, b.items))'));

        $engine->registerFunction('sort', static function (mixed $value, mixed $order = 'asc') use ($list, $text): array {
            $items = $list($value, 'sort');
            $direction = $text($order) === 'desc' ? -1 : 1;
            usort($items, static function (mixed $a, mixed $b) use ($direction): int {
                return $direction * self::compareItems($a, $b);
            });
            return self::limit($items, 'sort');
        }, $spec('sort', 1, 2, "sort(list[, 'asc'|'desc'])", 'Сортировка: числа десятично, строки побайтово, в смешанном списке числа перед строками',
            "sort(['b', 10, 'a', 2])", 'list', self::orderValidator('sort')));

        $engine->registerFunction('reverse', static fn (mixed $value) => array_reverse($list($value, 'reverse')),
            $spec('reverse', 1, 1, 'reverse(list)', 'Обратный порядок элементов', 'reverse(tasks.items)'));

        $engine->registerFunction('concat_lists', static function (mixed ...$values) use ($caster): array {
            $result = [];
            foreach ($values as $value) {
                $result = array_merge($result, $caster->toList($value));
                self::limit($result, 'concat_lists');
            }
            return $result;
        }, $spec('concat_lists', 1, null, 'concat_lists(a, b, …)', 'Склейка списков (не путать со строковым concat)', "concat_lists(object.tags_c_list, ['vip'])"));

        $engine->registerFunction('without', static function (mixed $value, mixed $excluded) use ($list, $comparator): array {
            $result = [];
            foreach ($list($value, 'without') as $item) {
                if (!$comparator->compare('==', $item, $excluded)) {
                    $result[] = $item;
                }
            }
            return $result;
        }, $spec('without', 2, 2, 'without(list, x)', 'Список без всех элементов, равных x', "without(object.tags_list, 'spam')"));

        foreach (['sum' => ['Сумма чисел списка (пустые элементы пропускаются)', 'sum(orders.items.amount)'], 'avg' => ['Среднее чисел списка', 'avg([1, 2, 3])']] as $name => [$description, $example]) {
            $engine->registerFunction($name, static function (mixed $value) use ($list, $name, $text) {
                $items = $list($value, $name);
                $total = '0';
                $count = 0;
                foreach ($items as $index => $item) {
                    if ($item === null || $item === '') {
                        continue;
                    }
                    if (!Decimal::isNumeric($item)) {
                        throw new NonRecoverableException(sprintf('%s(): element %d is not a number: "%s"', $name, $index + 1, $text($item)));
                    }
                    $total = Decimal::add($total, Decimal::of($item));
                    $count++;
                }
                if ($name === 'avg') {
                    return $count === 0 ? '' : Decimal::toNative(Decimal::div($total, (string)$count));
                }
                return Decimal::toNative($total);
            }, $spec($name, 1, 1, "{$name}(list)", $description, $example, 'number'));
        }

        $engine->registerFunction('pluck', static function (mixed $value, mixed $field) use ($list, $text): array {
            $result = [];
            $path = $text($field);
            foreach ($list($value, 'pluck') as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $result[] = self::field($item, $path) ?? '';
            }
            return $result;
        }, $spec('pluck', 2, 2, 'pluck(list, field)', "Значения поля из списка записей; отсутствующее поле — ''; поле может быть вложенным ('address.city')",
            "pluck(tasks.items, 'name')"));

        $engine->registerFunction('where', static function (mixed $value, mixed $field, mixed $expected) use ($list, $text, $comparator): array {
            $result = [];
            $path = $text($field);
            foreach ($list($value, 'where') as $item) {
                if ($comparator->compare('==', self::field($item, $path), $expected)) {
                    $result[] = $item;
                }
            }
            return $result;
        }, $spec('where', 3, 3, 'where(list, field, value)', 'Записи, у которых поле равно value (правила ==); простая фильтрация без лямбд',
            "where(tasks.items, 'status', 'Not Started')"));

        $engine->registerFunction('index_of_item', static function (mixed $value, mixed $needle) use ($list, $comparator) {
            foreach ($list($value, 'index_of_item') as $index => $item) {
                if ($comparator->compare('==', $item, $needle)) {
                    return $index;
                }
            }
            return -1;
        }, $spec('index_of_item', 2, 2, 'index_of_item(list, x)', 'Индекс первого элемента, равного x, или -1', "index_of_item(object.tags_list, 'vip')", 'number'));
    }

    /**
     * @return list<mixed>
     */
    private static function limit(array $items, string $fn): array
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new NonRecoverableException("List is too large (max " . self::MAX_ITEMS . ' items)');
        }
        return array_values($items);
    }

    private static function integer(mixed $value, string $fn, string $what): int
    {
        if (!Decimal::isNumeric($value) || str_contains(Decimal::of($value), '.')) {
            throw new NonRecoverableException(sprintf('%s(): %s must be an integer, got "%s"', $fn, $what, ExpressionEngine::toString($value)));
        }
        return (int)Decimal::of($value);
    }

    private static function compareItems(mixed $a, mixed $b): int
    {
        $numericA = Decimal::isNumeric($a);
        $numericB = Decimal::isNumeric($b);
        if ($numericA && $numericB) {
            return Decimal::cmp(Decimal::of($a), Decimal::of($b));
        }
        if ($numericA !== $numericB) {
            return $numericA ? -1 : 1;
        }
        return strcmp(ExpressionEngine::toString($a), ExpressionEngine::toString($b));
    }

    /**
     * Value of a field of a record; nested paths use dots, a missing key or a non-record is null.
     */
    private static function field(mixed $item, string $path): mixed
    {
        $current = $item;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    private static function orderValidator(string $fn): \Closure
    {
        return static function (array $args) use ($fn): ?string {
            $literal = FunctionSpec::literal($args[1] ?? null);
            return $literal !== null && !in_array($literal, ['asc', 'desc'], true) ? "Function \"{$fn}\": order must be \"asc\" or \"desc\"" : null;
        };
    }
}
