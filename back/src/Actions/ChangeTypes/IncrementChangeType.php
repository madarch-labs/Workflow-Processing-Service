<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\NonRecoverableException;
use Wps\Expression\Decimal;
use Wps\Services\Validation\DefinitionScope;
use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * "Увеличить на" / "Уменьшить на" (md/tasks_list/done/5.1.3-increment.md): the current value of the target ± a step.
 * The value is taken from the context (the event or a preload), not incremented atomically in the CRM. A missing or
 * empty current value counts as 0. Integers stay integers, otherwise decimal arithmetic.
 */
final class IncrementChangeType implements ChangeTypeInterface
{
    public function __construct(
        private readonly TypeCaster $caster,
        private readonly int $sign,
    ) {
    }

    public function name(): string
    {
        return $this->sign > 0 ? 'increment' : 'decrement';
    }

    public function label(): string
    {
        return $this->sign > 0 ? 'Увеличить на' : 'Уменьшить на';
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        $target = (string)($config['target'] ?? '');
        $current = $target !== '' ? $context->get($target) : null;
        $stepRaw = ($config['value_type'] ?? 'static') === 'from_object_field' ? $context->get((string)($config['value'] ?? '')) : ($config['value'] ?? 1);
        $decimal = CastOptions::fromDefinition('decimal', isset($config['scale']) ? ['scale' => (int)$config['scale']] : null);
        $step = $this->caster->cast($stepRaw, $decimal);
        if (!$step->isOk()) {
            throw new NonRecoverableException("{$this->name()}: step is not a number");
        }
        $now = $this->caster->cast($current, $decimal);
        if ($now->isError()) {
            if (($config['on_invalid'] ?? 'error') !== 'reset') {
                throw new NonRecoverableException(sprintf('%s: current value "%s" of %s is not a number', $this->name(), TypeCaster::stringify($current), $target));
            }
            $now = null;
        }
        $base = $now !== null && $now->isOk() ? (string)$now->value : '0';
        $delta = (string)$step->value;
        $result = $this->sign > 0 ? Decimal::add($base, $delta) : Decimal::sub($base, $delta);
        $cast = $config['cast'] ?? null;
        $integer = $cast === 'int' || ($cast === null && !str_contains($base, '.') && !str_contains($delta, '.'));
        if ($integer) {
            return (int)Decimal::round($result, 0);
        }
        return isset($config['scale']) ? Decimal::normalize(Decimal::round($result, (int)$config['scale'])) : Decimal::normalize($result);
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $valueType = $config['value_type'] ?? 'static';
        if ($valueType === 'from_object_field') {
            $scope->checkReadPath($config['value'] ?? null, "{$path}.value");
        } elseif ($valueType !== 'static') {
            $scope->error("{$path}.value_type", 'Must be "static" or "from_object_field"');
        } elseif (array_key_exists('value', $config) && !$this->caster->cast($config['value'], CastOptions::fromDefinition('decimal', null))->isOk()) {
            $scope->error("{$path}.value", 'Step must be a number');
        }
        if (isset($config['cast']) && !in_array($config['cast'], [CastType::Int->value, CastType::Decimal->value], true)) {
            $scope->error("{$path}.cast", 'Cast must be "int" or "decimal"');
        }
        if (isset($config['scale']) && (!is_int($config['scale']) || $config['scale'] < 0 || $config['scale'] > 10)) {
            $scope->error("{$path}.scale", 'Scale must be an integer from 0 to 10');
        }
        if (isset($config['on_invalid']) && !in_array($config['on_invalid'], ['error', 'reset'], true)) {
            $scope->error("{$path}.on_invalid", 'Must be "error" or "reset"');
        }
    }
}
