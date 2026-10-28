<?php

declare(strict_types=1);

namespace Wps\Expression\Functions;

use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\Decimal;
use Wps\Expression\ExpressionEngine;
use Wps\Expression\FunctionSpec;
use Wps\Expression\SafeRegex;
use Wps\Support\Transliterator;

/**
 * Text helpers that templates use as filters (md/tasks_list/done/5.1.1-template.md §4.1): {{ x | truncate:40 }} is
 * truncate(x, 40). They are ordinary expression functions as well.
 *
 * The search / pad / regex / transliteration functions come from md/tasks_list/done/5.2.4-expr-strings.md §4; they live
 * here (not in a separate StringFunctions) so that template filters and expressions share one registry.
 */
final class TextFunctions
{
    private const MAX_COUNT = 1000;

    public static function register(ExpressionEngine $engine): void
    {
        $str = static fn (mixed $v): string => ExpressionEngine::toString($v);
        $spec = static fn (string $name, int $min, ?int $max, string $signature, string $description, string $example, string $returns = 'string', ?\Closure $validator = null): FunctionSpec
            => new FunctionSpec($name, $min, $max, 'string', $signature, $description, $example, $returns, true, $validator);

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
        $engine->registerFunction('join', static function (mixed $list, mixed $separator = ', ', mixed $wrapper = null) use ($str): string {
            $wrap = $wrapper === null || $wrapper === '' ? null : $str($wrapper);
            $items = array_map($str, self::items($list));
            if ($wrap !== null) {
                $items = array_map(static fn (string $item): string => $wrap . $item . $wrap, $items);
            }
            return implode($str($separator), $items);
        }, $spec('join', 1, 3, "join(list[, separator=', '[, wrapper]])", 'Склеивает элементы списка (массив, JSON-массив или multienum ^a^,^b^); wrapper оборачивает каждый элемент', "join(object.industries, ', ')"));

        $engine->registerFunction('replace', static fn (mixed $s, mixed $search, mixed $replacement) => str_replace($str($search), $str($replacement), $str($s)),
            $spec('replace', 3, 3, 'replace(s, search, replacement)', 'Заменяет все вхождения (с учётом регистра); пустой поиск ничего не меняет', "replace(object.phone, '-', '')"));
        $engine->registerFunction('replace_ci', static fn (mixed $s, mixed $search, mixed $replacement) => self::replaceCaseInsensitive($str($s), $str($search), $str($replacement)),
            $spec('replace_ci', 3, 3, 'replace_ci(s, search, replacement)', 'Заменяет все вхождения без учёта регистра (Unicode)', "replace_ci(object.status, 'active', 'Active')"));
        $engine->registerFunction('contains', static fn (mixed $s, mixed $part) => str_contains($str($s), $str($part)),
            $spec('contains', 2, 2, 'contains(s, part)', 'Есть ли подстрока (с учётом регистра)', "contains(object.email, '@')", 'boolean'));
        $engine->registerFunction('starts_with', static fn (mixed $s, mixed $part) => str_starts_with($str($s), $str($part)),
            $spec('starts_with', 2, 2, 'starts_with(s, part)', 'Начинается ли строка с подстроки', "starts_with(object.code, 'A-')", 'boolean'));
        $engine->registerFunction('ends_with', static fn (mixed $s, mixed $part) => str_ends_with($str($s), $str($part)),
            $spec('ends_with', 2, 2, 'ends_with(s, part)', 'Заканчивается ли строка подстрокой', "ends_with(object.email, '@gmail.com')", 'boolean'));
        $engine->registerFunction('index_of', static function (mixed $s, mixed $part) use ($str): int {
            $position = mb_strpos($str($s), $str($part), 0, 'UTF-8');
            return $position === false ? -1 : $position;
        }, $spec('index_of', 2, 2, 'index_of(s, part)', 'Позиция первого вхождения с 0 или -1', "index_of(object.email, '@')", 'number'));
        $engine->registerFunction('before', static function (mixed $s, mixed $separator) use ($str): string {
            $text = $str($s);
            $position = mb_strpos($text, $str($separator), 0, 'UTF-8');
            return $position === false ? $text : mb_substr($text, 0, $position);
        }, $spec('before', 2, 2, 'before(s, separator)', 'Часть до первого вхождения; нет вхождения — вся строка', "before(object.email, '@')"));
        $engine->registerFunction('after', static function (mixed $s, mixed $separator) use ($str): string {
            $text = $str($s);
            $find = $str($separator);
            $position = mb_strpos($text, $find, 0, 'UTF-8');
            return $position === false ? '' : mb_substr($text, $position + mb_strlen($find));
        }, $spec('after', 2, 2, 'after(s, separator)', 'Часть после первого вхождения; нет вхождения — пустая строка', "after(object.email, '@')"));

        $padValidator = static fn (string $fn): \Closure => self::countValidator($fn, 1, 'length');
        foreach (['pad_left', 'pad_right'] as $name) {
            $engine->registerFunction($name, static function (mixed $s, mixed $length, mixed $char = ' ') use ($str, $name): string {
                $text = $str($s);
                $size = self::integer($length, $name, 'length');
                $padding = $str($char);
                if (mb_strlen($padding, 'UTF-8') !== 1) {
                    throw new NonRecoverableException("{$name}(): pad character must be exactly one character");
                }
                $missing = $size - mb_strlen($text, 'UTF-8');
                if ($missing <= 0) {
                    return $text;
                }
                return $name === 'pad_left' ? str_repeat($padding, $missing) . $text : $text . str_repeat($padding, $missing);
            }, $spec($name, 2, 3, "{$name}(s, length[, char=' '])", 'Дополняет строку до length символов (char — ровно один символ)', "{$name}('42', 6, '0')", 'string', $padValidator($name)));
        }

        $engine->registerFunction('title_case', static fn (mixed $s) => mb_convert_case($str($s), MB_CASE_TITLE, 'UTF-8'),
            $spec('title_case', 1, 1, 'title_case(s)', 'Каждое слово с заглавной буквы', 'title_case(object.name)'));
        $engine->registerFunction('repeat', static function (mixed $s, mixed $count) use ($str): string {
            $text = $str($s);
            $times = self::integer($count, 'repeat', 'count');
            if (strlen($text) * $times > ExpressionEngine::MAX_RESULT_BYTES) {
                throw new NonRecoverableException('Expression result is too long');
            }
            return str_repeat($text, $times);
        }, $spec('repeat', 2, 2, 'repeat(s, count)', 'Повторяет строку count раз (count ≤ 1000)', "repeat('-', 10)", 'string', self::countValidator('repeat', 1, 'count')));

        $engine->registerFunction('split', static function (mixed $s, mixed $separator, mixed $wrapper = null) use ($str, $engine): array {
            $sep = $str($separator);
            if ($sep === '') {
                throw new NonRecoverableException('split(): separator must not be empty');
            }
            $wrap = $wrapper === null || $wrapper === '' ? null : $str($wrapper);
            $items = $engine->caster()->toList($str($s), $sep, $wrap);
            if (count($items) > ListFunctions::MAX_ITEMS) {
                throw new NonRecoverableException('List is too large (max ' . ListFunctions::MAX_ITEMS . ' items)');
            }
            return array_values($items);
        }, $spec('split', 2, 3, "split(s, separator[, wrapper])", 'Делит строку на список; элементы обрезаются, пустые отбрасываются; wrapper снимает обрамление (multienum ^a^,^b^)', "split('^vip^,^partner^', ',', '^')", 'list'));

        $engine->registerFunction('left', static function (mixed $s, mixed $count) use ($str): string {
            return mb_substr($str($s), 0, self::nonNegative($count, 'left', 'count'), 'UTF-8');
        }, $spec('left', 2, 2, 'left(s, count)', 'Первые count символов', 'left(object.code, 2)'));
        $engine->registerFunction('right', static function (mixed $s, mixed $count) use ($str): string {
            $text = $str($s);
            $size = self::nonNegative($count, 'right', 'count');
            if ($size === 0) {
                return '';
            }
            $length = mb_strlen($text, 'UTF-8');
            return mb_substr($text, max(0, $length - $size), null, 'UTF-8');
        }, $spec('right', 2, 2, 'right(s, count)', 'Последние count символов', 'right(object.code, 2)'));
        $engine->registerFunction('strip_tags', static fn (mixed $s) => strip_tags($str($s)),
            $spec('strip_tags', 1, 1, 'strip_tags(s)', 'Удаляет HTML-теги', 'strip_tags(object.description)'));

        $engine->registerFunction('translit', static fn (mixed $s) => Transliterator::toLatin($str($s)),
            $spec('translit', 1, 1, 'translit(s)', 'Кириллица → латиница (ГОСТ 7.79-2000, система Б)', "translit('Щука')"));
        $engine->registerFunction('slug', static function (mixed $s) use ($str): string {
            $text = strtolower(Transliterator::toLatin($str($s)));
            $slug = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
            return trim($slug, '-');
        }, $spec('slug', 1, 1, 'slug(s)', 'Транслитерация, нижний регистр, небуквенные символы → «-»', "slug('Заказ №42')"));

        $engine->registerFunction('regex_match', static fn (mixed $s, mixed $pattern, mixed $flags = '') => SafeRegex::match($str($s), $str($pattern), $str($flags), 'regex_match'),
            $spec('regex_match', 2, 3, 'regex_match(s, pattern[, flags])', 'Проверяет совпадение регулярного выражения (шаблон без разделителей, флаги i/m/s)', "regex_match(object.inn, '^\\d{10}(\\d{2})?$')", 'boolean', self::regexValidator('regex_match', 1, 2)));
        $engine->registerFunction('regex_extract', static function (mixed $s, mixed $pattern, mixed $group = 0, mixed $flags = '') use ($str): string {
            return SafeRegex::extract($str($s), $str($pattern), $str($flags), self::nonNegative($group, 'regex_extract', 'group'), 'regex_extract');
        }, $spec('regex_extract', 2, 4, 'regex_extract(s, pattern[, group[, flags]])', "Первое совпадение или группа (с 0; нет — ''), флаги i/m/s", "regex_extract(object.subject, '#(\\d+)', 1)", 'string', self::regexValidator('regex_extract', 1, 3, 2)));
        $engine->registerFunction('regex_replace', static fn (mixed $s, mixed $pattern, mixed $replacement, mixed $flags = '') => SafeRegex::replace($str($s), $str($pattern), $str($replacement), $str($flags), 'regex_replace'),
            $spec('regex_replace', 3, 4, 'regex_replace(s, pattern, replacement[, flags])', 'Заменяет совпадения; в replacement допустимы ссылки $1, флаги i/m/s', "regex_replace(object.phone, '[^0-9+]', '')", 'string', self::regexValidator('regex_replace', 1, 3)));
    }

    /**
     * Case-insensitive replacement that keeps the case of the untouched parts.
     */
    private static function replaceCaseInsensitive(string $text, string $search, string $replacement): string
    {
        if ($search === '') {
            return $text;
        }
        $result = '';
        $offset = 0;
        $findLength = mb_strlen($search, 'UTF-8');
        while (($position = mb_stripos($text, $search, $offset, 'UTF-8')) !== false) {
            $result .= mb_substr($text, $offset, $position - $offset, 'UTF-8') . $replacement;
            $offset = $position + $findLength;
        }
        return $result . mb_substr($text, $offset, null, 'UTF-8');
    }

    /**
     * count for pad_* / repeat: an integer from 0 to 1000.
     */
    private static function integer(mixed $value, string $fn, string $what): int
    {
        if (!Decimal::isNumeric($value) || str_contains(Decimal::of($value), '.')) {
            throw new NonRecoverableException(sprintf('Function "%s": %s must be an integer between 0 and %d, got "%s"', $fn, $what, self::MAX_COUNT, ExpressionEngine::toString($value)));
        }
        $number = (int)Decimal::of($value);
        if ($number < 0 || $number > self::MAX_COUNT) {
            throw new NonRecoverableException(sprintf('Function "%s": %s must be an integer between 0 and %d, got "%s"', $fn, $what, self::MAX_COUNT, ExpressionEngine::toString($value)));
        }
        return $number;
    }

    /**
     * Unbounded count for left() / right() / regex group.
     */
    private static function nonNegative(mixed $value, string $fn, string $what): int
    {
        if (!Decimal::isNumeric($value) || str_contains(Decimal::of($value), '.')) {
            throw new NonRecoverableException(sprintf('Function "%s": %s must be a non-negative integer, got "%s"', $fn, $what, ExpressionEngine::toString($value)));
        }
        $number = (int)Decimal::of($value);
        if ($number < 0) {
            throw new NonRecoverableException(sprintf('Function "%s": %s must be a non-negative integer, got "%s"', $fn, $what, ExpressionEngine::toString($value)));
        }
        return $number;
    }

    private static function countValidator(string $fn, int $position, string $what): \Closure
    {
        return static function (array $args) use ($fn, $position, $what): ?string {
            $literal = FunctionSpec::literal($args[$position] ?? null);
            if ($literal === null) {
                return null;
            }
            $valid = Decimal::isNumeric($literal) && !str_contains(Decimal::of($literal), '.')
                && (int)Decimal::of($literal) >= 0 && (int)Decimal::of($literal) <= self::MAX_COUNT;
            return $valid ? null : sprintf('Function "%s": %s must be an integer between 0 and %d, got "%s"', $fn, $what, self::MAX_COUNT, $literal);
        };
    }

    private static function regexValidator(string $fn, int $patternPosition, int $flagsPosition, ?int $groupPosition = null): \Closure
    {
        return static function (array $args) use ($fn, $patternPosition, $flagsPosition, $groupPosition): ?string {
            if ($groupPosition !== null && ($literal = FunctionSpec::literal($args[$groupPosition] ?? null)) !== null) {
                $valid = Decimal::isNumeric($literal) && !str_contains(Decimal::of($literal), '.') && (int)Decimal::of($literal) >= 0;
                if (!$valid) {
                    return sprintf('Function "%s": group must be a non-negative integer, got "%s"', $fn, $literal);
                }
            }
            $flags = FunctionSpec::literal($args[$flagsPosition] ?? null);
            if ($flags !== null && ($error = SafeRegex::flagsError($flags, $fn)) !== null) {
                return $error;
            }
            $pattern = FunctionSpec::literal($args[$patternPosition] ?? null);
            return $pattern === null ? null : SafeRegex::validate($pattern, $flags ?? '', $fn);
        };
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
