<?php

declare(strict_types=1);

namespace Wps\Expression\Functions;

use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\Decimal;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\FunctionSpec;
use Wps\Support\JsonPath;
use Wps\Time\DateMath;
use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * Type checks, conversions and JSON (md/tasks_list/done/5.2.6-expr-types.md §4).
 *
 * Checks never throw: they return false for an empty value and for a value that does not fit the type. Conversions
 * use the same TypeCaster as conditions (one casting implementation) and throw NonRecoverableException on a bad value.
 * Emptiness is TypeCaster::isEmpty(), exactly like the is_empty condition operator (3.2): null, a blank string and an
 * empty list are empty.
 *
 * json_get/json_get_all accept an already decoded array (resp.body of http_request) or a JSON string and use the same
 * Wps\Support\JsonPath adapter as response.extract.
 */
final class TypeFunctions
{
    public const MAX_JSON_BYTES = 1048576;
    public const MAX_JSON_DEPTH = 64;

    public static function register(ExpressionEngine $engine, TypeCaster $caster, DateMath $dates): void
    {
        $text = static fn (mixed $v): string => ExpressionEngine::toString($v);
        $spec = static fn (string $name, int $min, ?int $max, string $signature, string $description, string $example, string $returns = 'boolean', ?\Closure $validator = null): FunctionSpec
            => new FunctionSpec($name, $min, $max, 'type', $signature, $description, $example, $returns, true, $validator);

        $engine->registerFunction('is_empty', static fn (mixed $value): bool => TypeCaster::isEmpty($value),
            $spec('is_empty', 1, 1, 'is_empty(x)', "Пусто ли значение: null, пустая строка или пробелы, пустой список (как оператор is_empty)", 'is_empty(object.phone_mobile)'));
        $engine->registerFunction('is_not_empty', static fn (mixed $value): bool => !TypeCaster::isEmpty($value),
            $spec('is_not_empty', 1, 1, 'is_not_empty(x)', 'Обратное is_empty', 'is_not_empty(object.phone_mobile)'));
        $engine->registerFunction('is_number', static fn (mixed $value): bool => Decimal::isNumeric($value),
            $spec('is_number', 1, 1, 'is_number(x)', "Строгое число: int, float или текст вида '12.5', ' 12 '; '1 234,56' и '1e3' — нет", 'is_number(object.discount_c)'));
        $engine->registerFunction('is_numeric_text', static function (mixed $value) use ($caster): bool {
            if (TypeCaster::isEmpty($value)) {
                return false;
            }
            return $caster->cast($value, new CastOptions(type: CastType::Decimal, scale: Decimal::SCALE))->isOk();
        }, $spec('is_numeric_text', 1, 1, 'is_numeric_text(x)', "Приводится ли значение к числу как to_number: '1 234,56' — да", 'is_numeric_text(v1.price_text)'));
        $engine->registerFunction('is_int', static function (mixed $value) use ($caster): bool {
            if (TypeCaster::isEmpty($value)) {
                return false;
            }
            return $caster->cast($value, new CastOptions(type: CastType::Int))->isOk();
        }, $spec('is_int', 1, 1, 'is_int(x)', 'Целое ли значение (по правилам приведения int)', 'is_int(object.qty)'));
        $engine->registerFunction('is_date', static function (mixed $value) use ($dates): bool {
            if (TypeCaster::isEmpty($value)) {
                return false;
            }
            try {
                return $dates->parse($value, 'is_date') !== null;
            } catch (NonRecoverableException) {
                return false;
            }
        }, $spec('is_date', 1, 1, 'is_date(x)', 'Разбирается ли значение как дата или дата-время', 'is_date(object.date_closed)'));
        $engine->registerFunction('is_bool', static function (mixed $value) use ($caster): bool {
            if (TypeCaster::isEmpty($value)) {
                return false;
            }
            return $caster->cast($value, new CastOptions(type: CastType::Bool))->isOk();
        }, $spec('is_bool', 1, 1, 'is_bool(x)', 'Приводится ли значение к bool без ошибки (true/1/yes/да, false/0/no/нет)', 'is_bool(object.do_not_call)'));
        $engine->registerFunction('is_list', static fn (mixed $value): bool => is_array($value) && array_is_list($value),
            $spec('is_list', 1, 1, 'is_list(x)', 'Список ли это (массив с индексами 0..n)', 'is_list(object.tags)'));
        $engine->registerFunction('is_object', static fn (mixed $value): bool => is_array($value) && !array_is_list($value),
            $spec('is_object', 1, 1, 'is_object(x)', 'Запись ли это (ассоциативный массив)', 'is_object(resp.body)'));

        $engine->registerFunction('to_string', $text,
            $spec('to_string', 1, 1, 'to_string(x)', 'Значение как текст: bool → true/false, список или запись → JSON', 'to_string(object.amount)', 'string'));
        $engine->registerFunction('to_bool', static function (mixed $value) use ($caster, $text): bool {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return false;
            }
            $cast = $caster->cast($value, new CastOptions(type: CastType::Bool));
            if (!$cast->isOk()) {
                throw new NonRecoverableException(sprintf('Cannot convert "%s" to boolean', mb_substr($text($value), 0, 100)));
            }
            return (bool)$cast->value;
        }, $spec('to_bool', 1, 1, 'to_bool(x)', "bool: '1'/'yes'/'да' → true, '0'/'no'/'нет' → false, пустое → false", 'to_bool(object.do_not_call)'));
        $engine->registerFunction('to_date', static fn (mixed $value, mixed $format = null) => $engine->call('date', $value, $format),
            $spec('to_date', 1, 2, 'to_date(x[, format])', 'Синоним date(): календарная дата', "to_date('16.10.2026')", 'date'));
        $engine->registerFunction('to_datetime', static fn (mixed $value, mixed $format = null, mixed $tz = null) => $engine->call('datetime', $value, $format, $tz),
            $spec('to_datetime', 1, 3, 'to_datetime(x[, format[, tz]])', 'Синоним datetime(): дата-время в UTC', "to_datetime('16.10.2026 10:00', 'd.m.Y H:i', 'local')", 'datetime'));

        $engine->registerFunction('json_encode', static function (mixed $value) use ($text): string {
            try {
                return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new NonRecoverableException('json_encode(): ' . $e->getMessage());
            }
        }, $spec('json_encode', 1, 1, 'json_encode(x)', 'Значение в виде JSON; кириллица и слэши не экранируются', 'json_encode(list(object.id, object.name))', 'string'));
        $engine->registerFunction('json_decode', static function (mixed $value) use ($text): mixed {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return '';
            }
            $json = $text($value);
            if (strlen($json) > self::MAX_JSON_BYTES) {
                throw new NonRecoverableException('json_decode(): JSON is too long (max 1048576 bytes)');
            }
            try {
                return json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new NonRecoverableException('json_decode(): ' . $e->getMessage());
            }
        }, $spec('json_decode', 1, 1, 'json_decode(s)', 'Строка JSON → значение; запись — ассоциативный массив, пустая строка → пусто; глубина ≤ 64', "json_decode(resp.body_text)", 'any'));
        $engine->registerFunction('json_get', static function (mixed $json, mixed $path, mixed $default = '') use ($text): mixed {
            $document = self::document($json, 'json_get', $text);
            if (!is_array($document)) {
                return $default;
            }
            $found = JsonPath::first($document, $text($path));
            return $found ?? $default;
        }, $spec('json_get', 2, 3, 'json_get(json, path[, default])', 'Первое совпадение JSONPath в массиве или JSON-строке; нет совпадения — default (пустая строка)', "json_get(resp.body, '$.data.customer.id')", 'any', self::pathValidator('json_get')));
        $engine->registerFunction('json_get_all', static function (mixed $json, mixed $path) use ($text): array {
            $document = self::document($json, 'json_get_all', $text);
            return is_array($document) ? JsonPath::all($document, $text($path)) : [];
        }, $spec('json_get_all', 2, 2, 'json_get_all(json, path)', 'Все совпадения JSONPath (например, $.items[*].id)', "json_get_all(resp.body, '$.items[*].id')", 'list', self::pathValidator('json_get_all')));
    }

    /**
     * An array is used as is, a string is decoded; an empty string is "no document", invalid JSON is an error.
     */
    private static function document(mixed $value, string $fn, \Closure $text): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null) {
            return null;
        }
        $json = $text($value);
        if (trim($json) === '') {
            return null;
        }
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new NonRecoverableException($fn . '(): JSON is too long (max 1048576 bytes)');
        }
        try {
            return json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new NonRecoverableException($fn . '(): invalid JSON');
        }
    }

    /**
     * @return \Closure(list<array<int, mixed>>): ?string
     */
    private static function pathValidator(string $fn): \Closure
    {
        return static function (array $args) use ($fn): ?string {
            $path = FunctionSpec::literal($args[1] ?? null);
            if ($path === null) {
                return null;
            }
            $error = JsonPath::error($path);
            return $error === null ? null : $fn . '(): ' . $error;
        };
    }
}
