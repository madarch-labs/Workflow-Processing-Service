<?php

declare(strict_types=1);

namespace Wps\Types;

use Wps\Exceptions\ConfigurationException;

/**
 * How a value is cast: the type and its options ("cast" + "cast_options" of a condition row).
 * The same object is used by conditions, expressions and templates.
 */
final class CastOptions
{
    public const DEFAULT_SCALE = 10;
    public const MAX_SCALE = 20;

    /**
     * @param list<string> $formats date / datetime formats (DateTime::createFromFormat); empty = defaults
     */
    public function __construct(
        public readonly CastType $type = CastType::String,
        public readonly bool $caseSensitive = true,
        public readonly bool $trim = false,
        public readonly array $formats = [],
        public readonly ?string $timezone = null,
        public readonly int $scale = self::DEFAULT_SCALE,
        public readonly string $separator = ',',
        public readonly ?string $wrapper = null,
    ) {
    }

    /**
     * Builds the options from a definition; throws ConfigurationException with the first problem.
     *
     * @param array<string, mixed>|null $options
     */
    public static function fromDefinition(mixed $type, mixed $options = null): self
    {
        $errors = self::errors($type, $options);
        if ($errors !== []) {
            $first = $errors[0];
            throw new ConfigurationException(($first['field'] !== '' ? $first['field'] . ': ' : '') . $first['message']);
        }
        $castType = $type === null || $type === '' ? CastType::String : CastType::from((string)$type);
        $o = is_array($options) ? $options : [];
        $formats = $o['format'] ?? [];
        return new self(
            type: $castType,
            caseSensitive: (bool)($o['case_sensitive'] ?? true),
            trim: (bool)($o['trim'] ?? false),
            formats: is_string($formats) ? [$formats] : array_values($formats),
            timezone: isset($o['timezone']) ? (string)$o['timezone'] : null,
            scale: (int)($o['scale'] ?? self::DEFAULT_SCALE),
            separator: (string)($o['separator'] ?? ','),
            wrapper: isset($o['wrapper']) ? (string)$o['wrapper'] : null,
        );
    }

    /**
     * Validation for the process validator: problems with the field they belong to
     * ("" = the "cast" itself, "cast_options" or "cast_options.<name>").
     *
     * @return list<array{field: string, message: string}>
     */
    public static function errors(mixed $type, mixed $options): array
    {
        if ($type !== null && $type !== '' && (!is_string($type) || CastType::tryFrom($type) === null)) {
            $shown = is_scalar($type) ? (string)$type : gettype($type);
            return [['field' => '', 'message' => "Unknown cast \"{$shown}\" (allowed: " . implode(', ', CastType::values()) . ')']];
        }
        if ($options === null) {
            return [];
        }
        if (!is_array($options) || (array_is_list($options) && $options !== [])) {
            return [['field' => 'cast_options', 'message' => 'Must be an object']];
        }
        $castType = $type === null || $type === '' ? CastType::String : CastType::from($type);
        $allowed = array_column($castType->options(), 'name');
        $errors = [];
        foreach ($options as $name => $value) {
            $field = 'cast_options.' . $name;
            if (!in_array($name, $allowed, true)) {
                $errors[] = ['field' => $field, 'message' => "Option \"{$name}\" is not supported by cast \"{$castType->value}\""];
                continue;
            }
            $message = match ($name) {
                'case_sensitive', 'trim' => is_bool($value) ? null : 'Must be true or false',
                'scale' => is_int($value) && $value >= 0 && $value <= self::MAX_SCALE ? null : 'Must be an integer from 0 to ' . self::MAX_SCALE,
                'format' => self::validFormat($value) ? null : 'Must be a non-empty string or a list of strings',
                'timezone' => self::timezoneError($value),
                'separator', 'wrapper' => is_string($value) && $value !== '' && mb_strlen($value) <= 5 ? null : 'Must be a non-empty string up to 5 characters',
                default => null,
            };
            if ($message !== null) {
                $errors[] = ['field' => $field, 'message' => $message];
            }
        }
        return $errors;
    }

    /**
     * Options for an element of a list: a trimmed string with the same case sensitivity.
     */
    public function element(): self
    {
        return new self(type: CastType::String, caseSensitive: $this->caseSensitive, trim: true);
    }

    public function withType(CastType $type): self
    {
        return new self($type, $this->caseSensitive, $this->trim, $this->formats, $this->timezone, $this->scale, $this->separator, $this->wrapper);
    }

    /**
     * Non-default options (trace, audit).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        if (!$this->caseSensitive) {
            $result['case_sensitive'] = false;
        }
        if ($this->trim) {
            $result['trim'] = true;
        }
        if ($this->formats !== []) {
            $result['format'] = count($this->formats) === 1 ? $this->formats[0] : $this->formats;
        }
        if ($this->timezone !== null) {
            $result['timezone'] = $this->timezone;
        }
        if ($this->type === CastType::Decimal && $this->scale !== self::DEFAULT_SCALE) {
            $result['scale'] = $this->scale;
        }
        if ($this->type === CastType::List && $this->separator !== ',') {
            $result['separator'] = $this->separator;
        }
        if ($this->wrapper !== null) {
            $result['wrapper'] = $this->wrapper;
        }
        return $result;
    }

    private static function validFormat(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $format) {
            if (!is_string($format) || trim($format) === '') {
                return false;
            }
        }
        return true;
    }

    private static function timezoneError(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return 'Must be a time zone name, e.g. Europe/Moscow';
        }
        try {
            new \DateTimeZone($value);
            return null;
        } catch (\Exception) {
            return "Unknown timezone \"{$value}\"";
        }
    }
}
