<?php

declare(strict_types=1);

namespace Wps\Template;

use Wps\Exceptions\ConfigurationException;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\ExpressionEngine;
use Wps\Services\MetricsService;

/**
 * Logic-less templates with substitutions (md/tasks_list/done/5.1.1-template.md): "Позвонить: {{ object.name }}",
 * filters {{ x | truncate:40 }} = expression functions, {{= expression }}. Used by the "template" change type and by
 * actions that build text (e-mail, HTTP request, notifications); the consumer picks the EscapeMode.
 *
 * The resolver is fn (string $path): array{0: bool, 1: mixed} — [found, value], like ExecutionContext::resolve().
 */
final class TemplateEngine
{
    public const MAX_LENGTH = 65536;
    public const MAX_RESULT_BYTES = 1048576;
    private const CACHE_SIZE = 1000;

    /** Pseudo-filter: no HTML escaping for this substitution (only where the consumer allows it) */
    public const RAW = 'raw';

    /** @var array<string, Template> */
    private array $cache = [];

    private readonly TemplateParser $parser;

    public function __construct(
        private readonly ExpressionEngine $expressions,
        private readonly ?MetricsService $metrics = null,
    ) {
        $this->parser = new TemplateParser();
    }

    /**
     * Parses (with a cache) and checks filters and expressions; throws ConfigurationException.
     */
    public function parse(string $template): Template
    {
        if (isset($this->cache[$template])) {
            return $this->cache[$template];
        }
        if (strlen($template) > self::MAX_LENGTH) {
            throw new ConfigurationException('Template is too long (max ' . self::MAX_LENGTH . ' characters)');
        }
        $parsed = $this->parser->parse($template);
        foreach ($parsed->nodes as $node) {
            if ($node[0] === 'expr') {
                $this->expressions->parse($node[1]);
            } elseif ($node[0] === 'tag') {
                foreach ($node[2] as [$name, $args]) {
                    $this->assertFilter($name, count($args));
                }
            }
        }
        if (count($this->cache) >= self::CACHE_SIZE) {
            $this->cache = [];
        }
        return $this->cache[$template] = $parsed;
    }

    /**
     * @param callable(string): array{0: bool, 1: mixed} $resolver
     */
    public function render(string $template, callable $resolver, EscapeMode $mode = EscapeMode::Text, MissingPolicy $missing = MissingPolicy::Empty): RenderResult
    {
        $state = new RenderState();
        try {
            $output = '';
            foreach ($this->parse($template)->nodes as $node) {
                $output .= $node[0] === 'text' ? $node[1] : $this->substitute($node, $resolver, $mode, $missing, $state);
                if (strlen($output) > self::MAX_RESULT_BYTES) {
                    throw new NonRecoverableException('Template result is too long');
                }
            }
        } catch (\Throwable $e) {
            $this->metrics?->inc(MetricsService::TEMPLATE_RENDER_ERRORS);
            throw $e instanceof ConfigurationException ? new NonRecoverableException($e->getMessage(), previous: $e) : $e;
        }
        return new RenderResult($output, $state->missing, $state->warnings);
    }

    /**
     * JSON mode: string leaves of a document are templates; a leaf that is one substitution keeps the value type
     * (a number stays a number, a list stays a list). Keys are not templated.
     *
     * @param callable(string): array{0: bool, 1: mixed} $resolver
     */
    public function renderStructure(mixed $document, callable $resolver, MissingPolicy $missing = MissingPolicy::Empty): RenderResult
    {
        $state = new RenderState();
        try {
            $value = $this->structure($document, $resolver, $missing, $state);
        } catch (\Throwable $e) {
            $this->metrics?->inc(MetricsService::TEMPLATE_RENDER_ERRORS);
            throw $e instanceof ConfigurationException ? new NonRecoverableException($e->getMessage(), previous: $e) : $e;
        }
        if (strlen((string)json_encode($value)) > self::MAX_RESULT_BYTES) {
            throw new NonRecoverableException('Template result is too long');
        }
        return new RenderResult($value, $state->missing, $state->warnings);
    }

    /**
     * Paths read by the template: tag values, filter arguments and paths inside {{= }} expressions.
     *
     * @return list<string>
     */
    public function paths(string $template): array
    {
        $paths = [];
        foreach ($this->parse($template)->nodes as $node) {
            if ($node[0] === 'expr') {
                array_push($paths, ...$this->expressions->paths($node[1]));
            } elseif ($node[0] === 'tag') {
                $operands = [$node[1]];
                foreach ($node[2] as [, $args]) {
                    array_push($operands, ...$args);
                }
                foreach ($operands as $operand) {
                    if ($operand[0] === 'path') {
                        $paths[] = $operand[1];
                    }
                }
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * Filter names used by the template.
     *
     * @return list<string>
     */
    public function filters(string $template): array
    {
        $names = [];
        foreach ($this->parse($template)->nodes as $node) {
            if ($node[0] === 'tag') {
                foreach ($node[2] as [$name]) {
                    $names[] = $name;
                }
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Filters for the dictionary: every expression function that takes a value as its first argument, and raw.
     *
     * @return list<array<string, mixed>>
     */
    public function filterDictionary(): array
    {
        $result = [];
        foreach ($this->expressions->functionSpecs() as $spec) {
            if ($spec->minArgs < 1 && $spec->maxArgs === 0) {
                continue;
            }
            $result[] = [
                'value' => $spec->name,
                'label' => $spec->description,
                'category' => $spec->category,
                'signature' => $spec->signature,
                'min_args' => max(0, $spec->minArgs - 1),
                'max_args' => $spec->maxArgs === null ? null : $spec->maxArgs - 1,
                'example' => self::example($spec->name, $spec->minArgs),
            ];
        }
        $result[] = [
            'value' => self::RAW, 'label' => 'Без HTML-экранирования (только там, где действие это разрешает)', 'category' => 'template',
            'signature' => 'raw', 'min_args' => 0, 'max_args' => 0, 'example' => '{{ v1.signature_html | raw }}',
        ];
        return $result;
    }

    private static function example(string $name, int $minArgs): string
    {
        return match ($name) {
            'number', 'number_format' => "{{ object.amount | {$name}:2:',':' ' }}",
            'format_date' => "{{ object.date_closed | format_date:'d.m.Y' }}",
            'default' => "{{ owner.last_name | default:'не назначен' }}",
            'truncate' => '{{ topic.text | truncate:40 }}',
            'substr' => '{{ object.name | substr:0:50 }}',
            default => $minArgs > 1 ? "{{ object.field | {$name}:… }}" : "{{ object.field | {$name} }}",
        };
    }

    private function assertFilter(string $name, int $argCount): void
    {
        if ($name === self::RAW) {
            if ($argCount > 0) {
                throw new ConfigurationException('Template filter "raw" takes no arguments');
            }
            return;
        }
        $spec = $this->expressions->spec($name) ?? throw new ConfigurationException("Unknown template filter \"{$name}\"");
        $count = $argCount + 1;
        if ($count < $spec->minArgs || ($spec->maxArgs !== null && $count > $spec->maxArgs)) {
            $min = max(0, $spec->minArgs - 1);
            $range = $spec->maxArgs === null ? "at least {$min}" : ($min === $spec->maxArgs - 1 ? (string)$min : $min . '..' . ($spec->maxArgs - 1));
            throw new ConfigurationException("Template filter \"{$name}\" expects {$range} argument" . ($range === '1' ? '' : 's') . ", got {$argCount}");
        }
    }

    /**
     * @param array<int, mixed> $node
     * @param callable(string): array{0: bool, 1: mixed} $resolver
     */
    private function substitute(array $node, callable $resolver, EscapeMode $mode, MissingPolicy $missing, RenderState $state): string
    {
        [$value, $raw, $kept] = $this->value($node, $resolver, $missing, $state, $mode);
        if ($kept) {
            return $node[$node[0] === 'tag' ? 3 : 2];
        }
        $text = ExpressionEngine::toString($value);
        return match ($mode) {
            EscapeMode::Html => $raw ? $text : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            EscapeMode::Header => $this->header($text, $state),
            EscapeMode::Url => rawurlencode($text),
            EscapeMode::Text, EscapeMode::Json => $text,
        };
    }

    /**
     * @param array<int, mixed> $node
     * @param callable(string): array{0: bool, 1: mixed} $resolver
     * @return array{0: mixed, 1: bool, 2: bool} [value, raw, keep the tag]
     */
    private function value(array $node, callable $resolver, MissingPolicy $missing, RenderState $state, EscapeMode $mode = EscapeMode::Text): array
    {
        if ($node[0] === 'expr') {
            return [$this->expressions->evaluate($node[1], static fn (string $path) => $resolver($path)[1]), false, false];
        }
        [, $operand, $filters] = $node;
        $raw = false;
        if ($operand[0] === 'path') {
            [$found, $value] = $resolver($operand[1]);
            if (!$found) {
                $hasDefault = in_array('default', array_column($filters, 0), true);
                if ($missing === MissingPolicy::Error && !$hasDefault) {
                    throw new NonRecoverableException("Template references missing value \"{$operand[1]}\"");
                }
                if ($missing === MissingPolicy::Keep && !$hasDefault) {
                    return [null, false, true];
                }
                $state->missing[] = $operand[1];
                $value = null;
            }
        } else {
            $value = $operand[1];
        }
        foreach ($filters as [$name, $args]) {
            if ($name === self::RAW) {
                $raw = true;
                continue;
            }
            if ($name === 'nl2br' && $mode === EscapeMode::Html && !$raw) {
                // escape first, so the inserted <br> stays markup and the value stays text
                $value = nl2br(htmlspecialchars(ExpressionEngine::toString($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
                $raw = true;
                continue;
            }
            $values = array_map(static fn (array $arg) => $arg[0] === 'path' ? $resolver($arg[1])[1] : $arg[1], $args);
            $value = $this->expressions->call($name, $value, ...$values);
        }
        return [$value, $raw, false];
    }

    private function header(string $text, RenderState $state): string
    {
        if (strpbrk($text, "\r\n") === false) {
            return $text;
        }
        $state->warnings[] = 'Line breaks removed from a header value';
        return str_replace(["\r\n", "\r", "\n"], ' ', $text);
    }

    /**
     * @param callable(string): array{0: bool, 1: mixed} $resolver
     */
    private function structure(mixed $document, callable $resolver, MissingPolicy $missing, RenderState $state): mixed
    {
        if (is_array($document)) {
            $result = [];
            foreach ($document as $key => $item) {
                $result[$key] = $this->structure($item, $resolver, $missing, $state);
            }
            return $result;
        }
        if (!is_string($document)) {
            return $document;
        }
        $template = $this->parse($document);
        $single = $template->single();
        if ($single === null) {
            $output = '';
            foreach ($template->nodes as $node) {
                $output .= $node[0] === 'text' ? $node[1] : $this->substitute($node, $resolver, EscapeMode::Json, $missing, $state);
            }
            return $output;
        }
        [$value, , $kept] = $this->value($single, $resolver, $missing, $state);
        return $kept ? $document : $value;
    }
}
