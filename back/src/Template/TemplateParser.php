<?php

declare(strict_types=1);

namespace Wps\Template;

use Wps\Exceptions\ConfigurationException;

/**
 * Template syntax (md/tasks_list/done/5.1.1-template.md §4.1):
 *
 *   template := ( text | "\{{" | "\\" tag | tag )*
 *   tag      := "{{" ( "!" comment | "=" expression | pipeline ) "}}"
 *   pipeline := operand { "|" filter }
 *   filter   := IDENT { ":" operand }
 *   operand  := PATH | 'string' | "string" | NUMBER
 *
 * "}}" inside quotes does not close a tag; an unclosed "{{" is an error; a stray "}}" in text is literal text
 * (JSON-like bodies contain it).
 */
final class TemplateParser
{
    public function parse(string $source): Template
    {
        $nodes = [];
        $text = '';
        $length = strlen($source);
        $i = 0;
        while ($i < $length) {
            if (substr($source, $i, 4) === '\\\\{{') {
                // "\\{{" — a literal backslash, then a substitution
                $text .= '\\';
                $i += 2;
                continue;
            }
            if ($source[$i] === '\\' && substr($source, $i + 1, 2) === '{{') {
                $text .= '{{';
                $i += 3;
                continue;
            }
            if (substr($source, $i, 2) !== '{{') {
                $text .= $source[$i];
                $i++;
                continue;
            }
            $start = $i;
            // positions in messages are characters, not bytes (templates are mostly Cyrillic text)
            $position = mb_strlen(substr($source, 0, $start));
            $end = self::findClose($source, $i + 2);
            if ($end === null) {
                throw new ConfigurationException("Unclosed \"{{\" at position {$position}");
            }
            if ($text !== '') {
                $nodes[] = ['text', $text];
                $text = '';
            }
            $tagSource = substr($source, $start, $end + 2 - $start);
            $node = $this->tag(trim(substr($source, $start + 2, $end - $start - 2)), $tagSource, $position);
            if ($node !== null) {
                $nodes[] = $node;
            }
            $i = $end + 2;
        }
        if ($text !== '') {
            $nodes[] = ['text', $text];
        }
        return new Template($nodes);
    }

    private static function findClose(string $source, int $from): ?int
    {
        $length = strlen($source);
        $quote = null;
        for ($i = $from; $i < $length; $i++) {
            $ch = $source[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '}' && ($source[$i + 1] ?? '') === '}') {
                return $i;
            }
        }
        return null;
    }

    /**
     * @return array<int, mixed>|null null for a comment
     */
    private function tag(string $inner, string $source, int $position): ?array
    {
        if ($inner === '') {
            throw new ConfigurationException("Empty template tag at position {$position}");
        }
        if ($inner[0] === '!') {
            return null;
        }
        if ($inner[0] === '=') {
            $expression = trim(substr($inner, 1));
            if ($expression === '') {
                throw new ConfigurationException("Empty expression in template tag at position {$position}");
            }
            return ['expr', $expression, $source];
        }
        $parts = self::split($inner, '|');
        $operand = $this->operand(trim(array_shift($parts)), $position);
        $filters = [];
        foreach ($parts as $part) {
            $pieces = self::split(trim($part), ':');
            $name = strtolower(trim(array_shift($pieces)));
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
                throw new ConfigurationException("Invalid template filter \"{$name}\" at position {$position}");
            }
            $filters[] = [$name, array_map(fn (string $arg) => $this->operand(trim($arg), $position), $pieces)];
        }
        return ['tag', $operand, $filters, $source];
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private function operand(string $text, int $position): array
    {
        if ($text === '') {
            throw new ConfigurationException("Missing value in template tag at position {$position}");
        }
        if ($text[0] === '"' || $text[0] === "'") {
            $quote = $text[0];
            if (strlen($text) < 2 || substr($text, -1) !== $quote) {
                throw new ConfigurationException("Unterminated string in template tag at position {$position}");
            }
            $value = preg_replace('/\\\\(.)/s', '$1', substr($text, 1, -1));
            return ['lit', $value];
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return ['lit', str_contains($text, '.') ? $text : (int)$text];
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)*$/', $text)) {
            return ['path', $text];
        }
        throw new ConfigurationException("Invalid value \"{$text}\" in template tag at position {$position} (use a path, a 'string' or a number; expressions go into {{= … }})");
    }

    /**
     * Splits by a separator outside quotes.
     *
     * @return list<string>
     */
    private static function split(string $text, string $separator): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];
            if ($quote !== null) {
                $current .= $ch;
                if ($ch === '\\' && $i + 1 < $length) {
                    $current .= $text[++$i];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === $separator) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;
        return $parts;
    }
}
