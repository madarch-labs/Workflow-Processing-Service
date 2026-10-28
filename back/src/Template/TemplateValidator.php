<?php

declare(strict_types=1);

namespace Wps\Template;

use Wps\Exceptions\ConfigurationException;
use Wps\Services\Validation\DefinitionScope;

/**
 * Save-time checks of a template field for the consumers (template change type, e-mail, HTTP request,
 * notifications): syntax, filters, declared variables, raw, substitutions in a URL host.
 */
final class TemplateValidator
{
    public function __construct(private readonly TemplateEngine $templates)
    {
    }

    /**
     * @param string|array<mixed> $template a string, or a JSON document whose string leaves are templates (Json mode)
     */
    public function validate(mixed $template, DefinitionScope $scope, string $field, EscapeMode $mode = EscapeMode::Text, bool $allowRaw = false): void
    {
        if ($mode === EscapeMode::Json && is_array($template)) {
            array_walk_recursive($template, function (mixed $leaf) use ($scope, $field, $allowRaw): void {
                if (is_string($leaf)) {
                    $this->validateString($leaf, $scope, $field, EscapeMode::Json, $allowRaw);
                }
            });
            return;
        }
        if (!is_string($template)) {
            $scope->error($field, 'Template must be a string');
            return;
        }
        $this->validateString($template, $scope, $field, $mode, $allowRaw);
    }

    private function validateString(string $template, DefinitionScope $scope, string $field, EscapeMode $mode, bool $allowRaw): void
    {
        try {
            $parsed = $this->templates->parse($template);
            if (in_array(TemplateEngine::RAW, $this->templates->filters($template), true) && !($allowRaw && $mode === EscapeMode::Html)) {
                $scope->error($field, 'Template filter "raw" is not allowed here');
            }
            if ($mode === EscapeMode::Url && ($error = self::urlError($template)) !== null) {
                $scope->error($field, $error);
            }
            $single = $mode === EscapeMode::Json ? $parsed->single() : null;
            foreach ($this->templates->paths($template) as $path) {
                // a JSON leaf that is exactly {{ v1 }} may insert a whole variable (an object)
                $whole = $single !== null && $single[0] === 'tag' && $single[1] === ['path', $path];
                $scope->checkReadPath($path, $field, $whole);
            }
        } catch (ConfigurationException $e) {
            $scope->error($field, $e->getMessage());
        }
    }

    /**
     * Substitutions are allowed only in the path and the query: the scheme, host and port are fixed (SSRF).
     */
    private static function urlError(string $url): ?string
    {
        $tag = strpos($url, '{{');
        if ($tag === false) {
            return null;
        }
        $scheme = strpos($url, '://');
        if ($scheme === false || $tag < $scheme) {
            return 'URL must start with a fixed scheme and host (https://host/…); substitutions are allowed only in the path and query';
        }
        $pathStart = strpos($url, '/', $scheme + 3);
        foreach (['?', '#'] as $delimiter) {
            $position = strpos($url, $delimiter, $scheme + 3);
            if ($position !== false && ($pathStart === false || $position < $pathStart)) {
                $pathStart = $position;
            }
        }
        if ($pathStart === false || $tag < $pathStart) {
            return 'Substitutions are not allowed in the scheme, host or port of a URL';
        }
        return null;
    }
}
