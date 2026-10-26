<?php

declare(strict_types=1);

namespace Wps\Expression\Functions;

use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\FunctionSpec;

/**
 * Text helpers that templates use as filters (md/tasks_list/done/5.1.1-template.md §4.1): {{ x | truncate:40 }} is
 * truncate(x, 40). They are ordinary expression functions as well.
 */
final class TextFunctions
{
    public static function register(ExpressionEngine $engine): void
    {
        $str = static fn (mixed $v): string => ExpressionEngine::toString($v);
        $spec = static fn (string $name, int $min, ?int $max, string $signature, string $description, string $example, string $returns = 'string'): FunctionSpec
            => new FunctionSpec($name, $min, $max, 'string', $signature, $description, $example, $returns);

        $engine->registerFunction('default', static fn (mixed $value, mixed $fallback) => $value === null || $value === '' ? $fallback : $value,
            $spec('default', 2, 2, 'default(a, fallback)', 'a, если оно не пустое, иначе fallback (как ifempty; в шаблоне — {{ x | default:\'—\' }})', "default(owner.last_name, 'не назначен')", 'any'));
        $engine->registerFunction('number', static fn (mixed $x, mixed $decimals = 0, mixed $decimalSeparator = '.', mixed $thousandsSeparator = ' ') => $engine->call('number_format', $x, $decimals, $decimalSeparator, $thousandsSeparator),
            $spec('number', 1, 4, 'number(x[, decimals[, dec_sep[, thousands_sep]]])', 'Синоним number_format', "number(object.amount, 2, ',', ' ')"));
        $engine->registerFunction('capitalize', static fn (mixed $s) => mb_strtoupper(mb_substr($str($s), 0, 1)) . mb_substr($str($s), 1),
            $spec('capitalize', 1, 1, 'capitalize(s)', 'Первая буква — заглавная', 'capitalize(object.first_name)'));
        $engine->registerFunction('truncate', static function (mixed $s, mixed $length, mixed $suffix = '…') use ($str): string {
            $text = $str($s);
            if (!is_numeric($length) || (int)$length < 0 || (string)(int)$length !== trim((string)$length)) {
                throw new NonRecoverableException(sprintf('Function "truncate": length must be a non-negative integer, got "%s"', $str($length)));
            }
            $max = (int)$length;
            if (mb_strlen($text) <= $max) {
                return $text;
            }
            $tail = $str($suffix);
            return mb_substr($text, 0, max(0, $max - mb_strlen($tail))) . $tail;
        }, $spec('truncate', 2, 3, "truncate(s, length[, suffix='…'])", 'Обрезает текст до length символов вместе с suffix', 'truncate(topic.text, 40)'));
        $engine->registerFunction('nl2br', static fn (mixed $s) => nl2br($str($s), false),
            $spec('nl2br', 1, 1, 'nl2br(s)', 'Переводы строк → <br> (для HTML-писем; применяется после экранирования)', 'nl2br(object.description)'));
        $engine->registerFunction('json', static fn (mixed $v) => (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            $spec('json', 1, 1, 'json(v)', 'Значение в виде JSON', 'json(object.tags)'));
        $engine->registerFunction('urlencode', static fn (mixed $s) => rawurlencode($str($s)),
            $spec('urlencode', 1, 1, 'urlencode(s)', 'Кодирует текст для URL (RFC 3986)', 'urlencode(object.name)'));
        $engine->registerFunction('join', static function (mixed $list, mixed $separator = ', ') use ($str): string {
            return implode($str($separator), array_map($str, self::items($list)));
        }, $spec('join', 1, 2, "join(list[, separator=', '])", 'Склеивает элементы списка (массив, JSON-массив или multienum ^a^,^b^)', "join(object.industries, ', ')"));
    }

    /**
     * @return list<mixed>
     */
    private static function items(mixed $list): array
    {
        if (is_array($list)) {
            return array_values($list);
        }
        if ($list === null || $list === '') {
            return [];
        }
        $text = (string)(is_scalar($list) ? $list : '');
        if (str_starts_with($text, '[') && is_array($decoded = json_decode($text, true))) {
            return array_values($decoded);
        }
        if (str_starts_with($text, '^') && str_ends_with($text, '^')) {
            return array_map(static fn (string $item) => trim($item, '^'), explode('^,^', $text));
        }
        return [$text];
    }
}
