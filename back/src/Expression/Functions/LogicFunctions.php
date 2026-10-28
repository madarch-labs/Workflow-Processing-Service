<?php

declare(strict_types=1);

namespace Wps\Expression\Functions;

use Wps\Expression\ExpressionEngine;
use Wps\Expression\FunctionSpec;

/**
 * Conditional special forms (md/tasks_list/done/5.2.1-expr-conditionals.md §4.5): arguments are evaluated lazily,
 * so if(true, 1, 1 / 0) is 1 and coalesce('x', 1 / 0) is 'x'.
 */
final class LogicFunctions
{
    public static function register(ExpressionEngine $engine): void
    {
        $empty = static fn (mixed $v): bool => $v === null || $v === '';

        $engine->registerSpecialForm('if', static function (array $args) use ($engine): mixed {
            if ($engine->truthy($args[0]())) {
                return $args[1]();
            }
            return isset($args[2]) ? $args[2]() : '';
        }, new FunctionSpec('if', 2, 3, 'logic', 'if(cond, then[, else])',
            'Значение then, если cond истинно, иначе else (или пустая строка)', "if(object.amount > 100000, 'VIP', 'Regular')"));

        $engine->registerSpecialForm('coalesce', static function (array $args) use ($empty): mixed {
            foreach ($args as $arg) {
                $value = $arg();
                if (!$empty($value)) {
                    return $value;
                }
            }
            return '';
        }, new FunctionSpec('coalesce', 1, null, 'logic', 'coalesce(a, b, …)',
            'Первое непустое значение (не null и не пустая строка)', 'coalesce(object.phone_mobile, object.phone_work, v1.phone_office)'));

        $engine->registerSpecialForm('ifempty', static function (array $args) use ($empty): mixed {
            $value = $args[0]();
            return $empty($value) ? $args[1]() : $value;
        }, new FunctionSpec('ifempty', 2, 2, 'logic', 'ifempty(a, default)',
            'a, если оно не пустое, иначе default', "ifempty(object.title, 'Без названия')"));

        $engine->registerSpecialForm('ifs', static function (array $args) use ($engine): mixed {
            $count = count($args);
            for ($i = 0; $i + 1 < $count; $i += 2) {
                if ($engine->truthy($args[$i]())) {
                    return $args[$i + 1]();
                }
            }
            return $count % 2 === 1 ? $args[$count - 1]() : '';
        }, new FunctionSpec('ifs', 2, null, 'logic', 'ifs(cond1, value1, cond2, value2, …[, default])',
            'Значение первой пары с истинным условием; нечётный последний аргумент — значение по умолчанию',
            "ifs(object.amount > 1000000, 'A', object.amount > 100000, 'B', 'C')"));

        $engine->registerSpecialForm('switch', static function (array $args) use ($engine): mixed {
            $value = $args[0]();
            $count = count($args);
            for ($i = 1; $i + 1 < $count; $i += 2) {
                if ($engine->comparator()->compare('==', $value, $args[$i]())) {
                    return $args[$i + 1]();
                }
            }
            return $count % 2 === 0 ? $args[$count - 1]() : '';
        }, new FunctionSpec('switch', 3, null, 'logic', 'switch(x, key1, value1, key2, value2, …[, default])',
            'Значение для первого ключа, равного x; лишний последний аргумент — значение по умолчанию',
            "switch(object.rating, 'Hot', 'High', 'Warm', 'Medium', 'Low')"));
    }
}
