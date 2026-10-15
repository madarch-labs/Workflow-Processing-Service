<?php

declare(strict_types=1);

namespace Wps\Expression;

use Wps\Exceptions\ConfigurationException;

final class ExpressionLexer
{
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
                $tokens[] = ['num', str_contains($text, '.') ? (float)$text : (int)$text, $start];
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $start = $i;
                $quote = $ch;
                $i++;
                $value = '';
                while ($i < $length && $source[$i] !== $quote) {
                    if ($source[$i] === '\\' && $i + 1 < $length) {
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
                $tokens[] = [str_contains($text, '.') ? 'path' : 'ident', $text, $start];
                continue;
            }
            if (str_contains('+-*/%(),', $ch)) {
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
