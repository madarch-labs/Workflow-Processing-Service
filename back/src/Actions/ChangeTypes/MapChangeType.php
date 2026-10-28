<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Services\Validation\DefinitionScope;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;
use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * "По таблице соответствия" (md/tasks_list/done/5.1.6-map.md): the value of "source" is looked up in a table
 * ("map", keys cast like the source) or in ordered ranges ("ranges": [{"lt" | "lte": …, "value": …}], the first
 * matching one). No match → on_no_match: default, keep (the target stays), null, error.
 */
final class MapChangeType implements ChangeTypeInterface
{
    public const MAX_ENTRIES = 500;

    private readonly TemplateValidator $validator;

    public function __construct(
        private readonly TypeCaster $caster,
        private readonly TemplateEngine $templates,
    ) {
        $this->validator = new TemplateValidator($templates);
    }

    public function name(): string
    {
        return 'map';
    }

    public function label(): string
    {
        return 'По таблице соответствия';
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        $source = (string)$config['source'];
        [$found, $raw] = $context->resolve($source);
        $options = CastOptions::fromDefinition($config['cast'] ?? null, $config['cast_options'] ?? null);
        [$matched, $value] = ($config['mode'] ?? 'exact') === 'ranges'
            ? $this->matchRange($found ? $raw : null, (array)($config['ranges'] ?? []), $options)
            : $this->matchExact($found ? $raw : null, (array)($config['map'] ?? []), $options);
        if (!$matched) {
            $policy = (string)($config['on_no_match'] ?? (array_key_exists('default', $config) ? 'default' : 'keep'));
            return match ($policy) {
                'default' => $this->value($config['default'] ?? null, $config, $context),
                'null' => null,
                'error' => throw new NonRecoverableException(sprintf('map: no match for "%s" in %s', TypeCaster::stringify($raw), $source)),
                default => $context->get((string)($config['target'] ?? '')),
            };
        }
        return $this->value($value, $config, $context);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function value(mixed $value, array $config, ExecutionContext $context): mixed
    {
        if (!empty($config['value_template']) && is_string($value)) {
            return (string)$this->templates->render($value, static fn (string $p) => $context->resolve($p))->value;
        }
        return $value;
    }

    /**
     * @param array<mixed> $map
     * @return array{0: bool, 1: mixed}
     */
    private function matchExact(mixed $raw, array $map, CastOptions $options): array
    {
        if ($raw === null || $raw === '') {
            return array_key_exists('', $map) ? [true, $map['']] : [false, null];
        }
        $key = $this->caster->cast($raw, $options);
        if (!$key->isOk()) {
            return [false, null];
        }
        foreach ($map as $k => $v) {
            $cast = $this->caster->cast((string)$k, $options);
            if ($cast->isOk() && $this->caster->equals($key->value, $cast->value, $options)) {
                return [true, $v];
            }
        }
        return [false, null];
    }

    /**
     * @param array<mixed> $ranges
     * @return array{0: bool, 1: mixed}
     */
    private function matchRange(mixed $raw, array $ranges, CastOptions $options): array
    {
        $key = $this->caster->cast($raw, $options);
        if (!$key->isOk()) {
            return [false, null];
        }
        foreach ($ranges as $range) {
            $range = (array)$range;
            $strict = array_key_exists('lt', $range);
            $bound = $this->caster->cast($range[$strict ? 'lt' : 'lte'] ?? null, $options);
            if (!$bound->isOk()) {
                continue;
            }
            $cmp = $this->caster->compare($key->value, $bound->value, $options);
            if ($strict ? $cmp < 0 : $cmp <= 0) {
                return [true, $range['value'] ?? null];
            }
        }
        return [false, null];
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $scope->checkReadPath($config['source'] ?? null, "{$path}.source");
        $mode = $config['mode'] ?? 'exact';
        if (!in_array($mode, ['exact', 'ranges'], true)) {
            $scope->error("{$path}.mode", 'Mode must be "exact" or "ranges"');
            return;
        }
        $castErrors = CastOptions::errors($config['cast'] ?? null, $config['cast_options'] ?? null);
        foreach ($castErrors as $error) {
            $scope->error($path . ($error['field'] === '' ? '.cast' : ".{$error['field']}"), $error['message']);
        }
        $options = $castErrors === [] ? CastOptions::fromDefinition($config['cast'] ?? null, $config['cast_options'] ?? null) : null;
        if ($mode === 'exact') {
            $map = $config['map'] ?? null;
            if (!is_array($map) || $map === [] || array_is_list($map)) {
                $scope->error("{$path}.map", 'Map must be a non-empty object');
            } elseif (count($map) > self::MAX_ENTRIES) {
                $scope->error("{$path}.map", 'Too many entries (max ' . self::MAX_ENTRIES . ')');
            } elseif ($options !== null) {
                $seen = [];
                foreach (array_keys($map) as $k) {
                    if ((string)$k === '') {
                        continue;
                    }
                    $cast = $this->caster->cast((string)$k, $options);
                    if (!$cast->isOk()) {
                        $scope->error("{$path}.map", "Key \"{$k}\" cannot be cast to {$options->type->value}");
                        continue;
                    }
                    $norm = TypeCaster::stringify(TypeCaster::export($cast->value, $options->type));
                    if (isset($seen[$norm])) {
                        $scope->error("{$path}.map", "Keys \"{$seen[$norm]}\" and \"{$k}\" are equal after cast");
                    }
                    $seen[$norm] = (string)$k;
                }
            }
            if (!empty($config['value_template'])) {
                foreach ((array)$map as $k => $v) {
                    if (is_string($v)) {
                        $this->validator->validate($v, $scope, "{$path}.map.{$k}");
                    }
                }
            }
        } else {
            if (!in_array($options?->type, [CastType::Int, CastType::Decimal, CastType::Date, CastType::DateTime], true) || !isset($config['cast'])) {
                $scope->error("{$path}.cast", 'Mode "ranges" requires cast: int, decimal, date or datetime');
            }
            $ranges = $config['ranges'] ?? null;
            if (!is_array($ranges) || $ranges === [] || !array_is_list($ranges)) {
                $scope->error("{$path}.ranges", 'Ranges must be a non-empty list');
            } else {
                $previous = null;
                foreach ($ranges as $k => $range) {
                    if (!is_array($range) || array_key_exists('lt', $range) === array_key_exists('lte', $range)) {
                        $scope->error("{$path}.ranges[{$k}]", 'Range must have exactly one of "lt", "lte"');
                        continue;
                    }
                    if (!array_key_exists('value', $range)) {
                        $scope->error("{$path}.ranges[{$k}].value", 'Value is required');
                    }
                    if ($options === null || !in_array($options->type, CastType::orderable(), true)) {
                        continue;
                    }
                    $bound = $this->caster->cast($range['lt'] ?? $range['lte'], $options);
                    if (!$bound->isOk()) {
                        $scope->error("{$path}.ranges[{$k}]", 'Bound is not a valid ' . $options->type->value);
                        continue;
                    }
                    if ($previous !== null && $this->caster->compare($bound->value, $previous, $options) < 0) {
                        $scope->error("{$path}.ranges[{$k}]", 'Ranges must be in ascending order');
                    }
                    $previous = $bound->value;
                }
            }
        }
        $policy = $config['on_no_match'] ?? null;
        if ($policy !== null && !in_array($policy, ['default', 'keep', 'null', 'error'], true)) {
            $scope->error("{$path}.on_no_match", 'Must be one of: default, keep, null, error');
        } elseif ($policy === 'default' && !array_key_exists('default', $config)) {
            $scope->error("{$path}.default", 'Default value is required');
        }
    }
}
