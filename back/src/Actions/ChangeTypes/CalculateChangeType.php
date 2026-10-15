<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Exceptions\ConfigurationException;
use Wps\Expression\ExpressionEngine;
use Wps\Services\Validation\DefinitionScope;

/**
 * "Расчет": arithmetic and string operations over several fields, e.g. "object.amount * 0.9",
 * "concat(v1.first_name, ' ', v1.last_name)". The expression is taken from "expression" or "value".
 */
final class CalculateChangeType implements ChangeTypeInterface
{
    public function __construct(private readonly ExpressionEngine $engine)
    {
    }

    public function name(): string
    {
        return 'calculate';
    }

    public function label(): string
    {
        return 'Расчет';
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        return $this->engine->evaluate(self::expression($config), static fn (string $path) => $context->get($path));
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $expression = self::expression($config);
        $field = isset($config['expression']) ? "{$path}.expression" : "{$path}.value";
        if (trim($expression) === '') {
            $scope->error($field, 'Expression is required');
            return;
        }
        try {
            foreach ($this->engine->paths($expression) as $ref) {
                $scope->checkReadPath($ref, $field);
            }
        } catch (ConfigurationException $e) {
            $scope->error($field, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function expression(array $config): string
    {
        $expression = $config['expression'] ?? $config['value'] ?? '';
        return is_scalar($expression) ? (string)$expression : '';
    }
}
