<?php

declare(strict_types=1);

namespace Wps\Kernel;

/**
 * Environment access.
 *
 * Resolution order (requirement: ".env first, then real environment overrides it"):
 * a value present in the process environment (getenv) wins, otherwise the value loaded from .env.
 */
final class Env
{
    /**
     * Loads KEY=VALUE pairs from a .env file without overriding variables that already exist
     * in the process environment.
     */
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = self::unquote(trim($value));
            if ($key === '' || getenv($key) !== false) {
                continue;
            }
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        if (array_key_exists($key, $_ENV)) {
            return (string)$_ENV[$key];
        }
        return $default;
    }

    /**
     * First non-empty value among several keys (used for alias names like DB_POSTGRES_HOST / DB_HOST).
     *
     * @param string[] $keys
     */
    public static function first(array $keys, ?string $default = null): ?string
    {
        foreach ($keys as $key) {
            $value = self::get($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return ($value === null || $value === '' || !is_numeric($value)) ? $default : (int)$value;
    }

    public static function float(string $key, float $default): float
    {
        $value = self::get($key);
        return ($value === null || $value === '' || !is_numeric($value)) ? $default : (float)$value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function unquote(string $value): string
    {
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }
        return $value;
    }
}
