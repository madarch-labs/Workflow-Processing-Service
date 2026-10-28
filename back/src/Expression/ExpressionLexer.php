<?php

declare(strict_types=1);

namespace Wps\Expression;

use Wps\Exceptions\ConfigurationException;

/**
 * Tokens: num, str, path ("object.amount"), ident (a word without a dot: function names), kw (and, or, not, true,
 * false, null — case-insensitive), op. A word with a dot is always a path, so "object.and" stays a path.
 */
final class ExpressionLexer
{
    public const KEYWORDS = ['and', 'or', 'not', 'true', 'false', 'null'];
    private const TWO_CHAR_OPS = ['==', '!=', '<=', '>=', '<>', '&&', '||'];
    private const ONE_CHAR_OPS = '+-*/%(),=<>![]';

    /**
     * @return list<array{0: string, 1: mixed, 2: int}> tokens [type, value, position]
     */
    public static function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $i = 0;
        while ($i < $length) {
            $ch = $source[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $length && ctype_digit($source[$i + 1]))) {
                $start = $i;
                while ($i < $length && (ctype_digit($source[$i]) || $source[$i] === '.')) {
                    $i++;
                }
                $text = substr($source, $start, $i - $start);
                if (!is_numeric($text)) {
                    throw new ConfigurationException("Invalid number \"{$text}\" at position {$start}");
                }
                if (strlen($text) > Decimal::MAX_DIGITS) {
                    throw new ConfigurationException("Number is too large at position {$start}");
                }
                $tokens[] = ['num', Decimal::toNative(Decimal::of($text)), $start];
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $start = $i;
                $quote = $ch;
                $i++;
                $value = '';
                while ($i < $length && $source[$i] !== $quote) {
                    // A backslash escapes the quote and itself only: regex patterns keep '\d', '\p{Lu}' as written
                    // (md/tasks_list/done/5.2.4-expr-strings.md §4.1).
                    if ($source[$i] === '\\' && $i + 1 < $length && ($source[$i + 1] === $quote || $source[$i + 1] === '\\')) {
                        $i++;
                    }
                    $value .= $source[$i];
                    $i++;
                }
                if ($i >= $length) {
                    throw new ConfigurationException("Unterminated string starting at position {$start}");
                }
                $i++;
                $tokens[] = ['str', $value, $start];
                continue;
            }
            if (ctype_alpha($ch) || $ch === '_') {
                $start = $i;
                while ($i < $length && (ctype_alnum($source[$i]) || $source[$i] === '_' || $source[$i] === '.')) {
                    $i++;
                }
                $text = rtrim(substr($source, $start, $i - $start), '.');
                $i = $start + strlen($text);
                if (str_contains($text, '.')) {
                    $tokens[] = ['path', $text, $start];
                } elseif (in_array(strtolower($text), self::KEYWORDS, true)) {
                    $tokens[] = ['kw', strtolower($text), $start];
                } else {
                    $tokens[] = ['ident', $text, $start];
                }
                continue;
            }
            $two = substr($source, $i, 2);
            if (in_array($two, self::TWO_CHAR_OPS, true)) {
                $tokens[] = ['op', $two, $i];
                $i += 2;
                continue;
            }
            if (str_contains(self::ONE_CHAR_OPS, $ch)) {
                $tokens[] = ['op', $ch, $i];
                $i++;
                continue;
            }
            throw new ConfigurationException("Unexpected character \"{$ch}\" at position {$i}");
        }
        $tokens[] = ['eof', null, $length];
        return $tokens;
    }
}
