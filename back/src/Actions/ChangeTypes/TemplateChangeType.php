<?php

declare(strict_types=1);

namespace Wps\Actions\ChangeTypes;

use Wps\Context\ExecutionContext;
use Wps\Services\Validation\DefinitionScope;
use Wps\Template\EscapeMode;
use Wps\Template\MissingPolicy;
use Wps\Template\TemplateEngine;
use Wps\Template\TemplateValidator;

/**
 * "Шаблон": a string with substitutions, e.g. "Позвонить: {{ object.first_name }} {{ object.last_name | upper }}".
 * The template is taken from "value" (or "template"); "missing": empty (default) | error | keep.
 */
final class TemplateChangeType implements ChangeTypeInterface, ReportsDetails
{
    public function __construct(
        private readonly TemplateEngine $templates,
        private readonly TemplateValidator $validator,
    ) {
    }

    public function name(): string
    {
        return 'template';
    }

    public function label(): string
    {
        return 'Шаблон';
    }

    public function compute(ExecutionContext $context, array $config): mixed
    {
        return $this->computeWithDetails($context, $config)[0];
    }

    public function computeWithDetails(ExecutionContext $context, array $config): array
    {
        $result = $this->templates->render(
            self::template($config),
            static fn (string $path) => $context->resolve($path),
            EscapeMode::Text,
            MissingPolicy::tryFrom((string)($config['missing'] ?? '')) ?? MissingPolicy::Empty,
        );
        $details = [];
        if ($result->missing !== []) {
            $details['missing'] = $result->missing;
        }
        if ($result->warnings !== []) {
            $details['warnings'] = $result->warnings;
        }
        return [$result->value, $details];
    }

    public function validate(array $config, DefinitionScope $scope, string $path): void
    {
        $key = array_key_exists('value', $config) || !array_key_exists('template', $config) ? 'value' : 'template';
        $template = $config[$key] ?? '';
        if (!is_string($template)) {
            $scope->error("{$path}.{$key}", 'Template must be a string');
        } else {
            $this->validator->validate($template, $scope, "{$path}.{$key}");
        }
        if (isset($config['missing']) && MissingPolicy::tryFrom((string)$config['missing']) === null) {
            $scope->error("{$path}.missing", 'Must be one of: ' . implode(', ', MissingPolicy::values()));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function template(array $config): string
    {
        $template = $config['value'] ?? $config['template'] ?? '';
        return is_scalar($template) ? (string)$template : '';
    }
}
