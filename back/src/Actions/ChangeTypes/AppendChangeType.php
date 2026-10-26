<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Services\Validation\DefinitionScope;
use Wps\Template\EscapeMode;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;
use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * "Дописать в конец" / "Дописать в начало" / "Удалить из списка" (md/tasks_list/done/5.1.5-append.md):
 * text — the rendered template is added to the current value with a separator (none when the current value is empty);
 * list — elements are added to / removed from a list stored as a string ("^a^,^b^", "a,b") or an array, the result is
 * written back in the same format.
 */
final class AppendChangeType implements ChangeTypeInterface
{
    public const OPERATIONS = ['append' => 'Дописать в конец', 'prepend' => 'Дописать в начало', 'remove' => 'Удалить из списка'];

    private readonly TemplateValidator $validator;

    public function __construct(
        private readonly TemplateEngine $templates,
        private readonly TypeCaster $caster,
        private readonly string $operation,
    ) {
        $this->validator = new TemplateValidator($templates);
    }

    public function name(): string
    {
        return $this->operation;
    }

    public function label(): string
    {
        return self::OPERATIONS[$this->operation];
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        $current = $context->get((string)($config['target'] ?? ''));
        $new = ($config['value_type'] ?? 'static') === 'from_object_field'
            ? $context->get((string)($config['value'] ?? ''))
            : (string)$this->templates->render((string)($config['value'] ?? ''), static fn (string $p) => $context->resolve($p), EscapeMode::Text)->value;
        return ($config['mode'] ?? 'text') === 'list' ? $this->computeList($current, $new, $config) : $this->computeText($current, $new, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function computeText(mixed $current, mixed $new, array $config): mixed
    {
        $old = $current === null ? '' : TypeCaster::stringify($current);
        $add = $new === null ? '' : TypeCaster::stringify($new);
        if ($add === '') {
            return $current; // nothing to add: the value stays as it was
        }
        $separator = $old === '' ? '' : (string)($config['separator'] ?? '');
        $result = $this->operation === 'prepend' ? $add . $separator . $old : $old . $separator . $add;
        $max = isset($config['max_length']) ? (int)$config['max_length'] : 0;
        if ($max > 0 && mb_strlen($result) > $max) {
            // append keeps the newest (last) characters, prepend the first ones
            $result = $this->operation === 'prepend' ? mb_substr($result, 0, $max) : mb_substr($result, -$max);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function computeList(mixed $current, mixed $new, array $config): mixed
    {
        $format = (array)($config['list'] ?? []);
        $options = new CastOptions(type: CastType::List, separator: (string)($format['separator'] ?? ','),
            wrapper: isset($format['wrapper']) && $format['wrapper'] !== '' ? (string)$format['wrapper'] : null);
        $items = $current === null || $current === '' ? [] : (array)($this->caster->cast($current, $options)->value ?? []);
        $added = $new === null || $new === '' ? [] : (array)($this->caster->cast($new, $options)->value ?? []);
        if ($this->operation === 'remove') {
            $result = array_values(array_filter($items, static fn ($i) => !in_array($i, $added, true)));
        } else {
            if ($config['unique'] ?? true) {
                $added = array_values(array_filter(array_unique($added), static fn ($i) => !in_array($i, $items, true)));
            }
            $result = $this->operation === 'prepend' ? array_merge($added, $items) : array_merge($items, $added);
        }
        if (is_array($current)) {
            return $result; // a list that came as an array (an HTTP response) stays an array
        }
        if ($result === []) {
            return '';
        }
        $w = $options->wrapper ?? '';
        return $w . implode($w . $options->separator . $w, $result) . $w;
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $value = $config['value'] ?? null;
        if (($config['value_type'] ?? 'static') === 'from_object_field') {
            $scope->checkReadPath($value, "{$path}.value");
        } elseif (!is_string($value) && !is_int($value) || (string)$value === '') {
            $scope->error("{$path}.value", 'Value is required');
        } else {
            $this->validator->validate((string)$value, $scope, "{$path}.value");
        }
        $mode = $config['mode'] ?? 'text';
        if (!in_array($mode, ['text', 'list'], true)) {
            $scope->error("{$path}.mode", 'Mode must be "text" or "list"');
        } elseif ($this->operation === 'remove' && $mode !== 'list') {
            $scope->error("{$path}.mode", '"remove" works only with mode "list"');
        }
        $format = $config['list'] ?? [];
        if (!is_array($format)) {
            $scope->error("{$path}.list", 'Must be an object {"separator": ..., "wrapper": ...}');
        } else {
            if (isset($format['separator']) && (!is_string($format['separator']) || $format['separator'] === '' || mb_strlen($format['separator']) > 5)) {
                $scope->error("{$path}.list.separator", 'Separator must be 1-5 characters');
            }
            if (isset($format['wrapper']) && (!is_string($format['wrapper']) || mb_strlen($format['wrapper']) > 5)) {
                $scope->error("{$path}.list.wrapper", 'Wrapper must be up to 5 characters');
            }
        }
        if (isset($config['max_length']) && (!is_int($config['max_length']) || $config['max_length'] < 1)) {
            $scope->error("{$path}.max_length", 'Must be a positive integer');
        }
        if (isset($config['separator']) && !is_string($config['separator'])) {
            $scope->error("{$path}.separator", 'Must be a string');
        }
        if (isset($config['unique']) && !is_bool($config['unique'])) {
            $scope->error("{$path}.unique", 'Must be true or false');
        }
    }
}
