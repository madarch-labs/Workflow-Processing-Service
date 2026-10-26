<?php

declare(strict_types=1);

namespace Wps\Secrets;

/**
 * "secret://name" or "secret://name.field" in a credential field of an action.
 */
final class SecretReference
{
    public const PREFIX = 'secret://';
    public const NAME_PATTERN = '[a-z][a-z0-9_]{1,63}';

    private function __construct(public readonly string $name, public readonly ?string $field)
    {
    }

    public static function isReference(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public static function parse(string $value): ?self
    {
        if (!preg_match('#^secret://(' . self::NAME_PATTERN . ')(?:\.([a-z_][a-z0-9_]*))?$#', $value, $m)) {
            return null;
        }
        return new self($m[1], isset($m[2]) && $m[2] !== '' ? $m[2] : null);
    }

    /**
     * Every string starting with "secret://" anywhere in a definition, with its path.
     *
     * @param array<mixed> $node
     * @return list<array{path: string, value: string}>
     */
    public static function findAll(array $node, string $path = 'definition'): array
    {
        $found = [];
        foreach ($node as $key => $value) {
            $childPath = is_int($key) ? "{$path}[{$key}]" : "{$path}.{$key}";
            if (is_array($value)) {
                array_push($found, ...self::findAll($value, $childPath));
            } elseif (is_string($value) && str_contains($value, self::PREFIX)) {
                $found[] = ['path' => $childPath, 'value' => $value];
            }
        }
        return $found;
    }
}
