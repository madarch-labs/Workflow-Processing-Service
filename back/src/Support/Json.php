<?php

declare(strict_types=1);

namespace Wps\Support;

final class Json
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS | JSON_THROW_ON_ERROR);
    }

    /**
     * Decodes JSON into arrays; returns $default for null/empty/invalid input.
     */
    public static function decode(?string $json, mixed $default = null): mixed
    {
        if ($json === null || $json === '') {
            return $default;
        }
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $default;
        }
    }

    /**
     * Converts a PostgreSQL timestamp (UTC, without time zone) into ISO-8601.
     */
    public static function time(?string $pgTimestamp): ?string
    {
        if ($pgTimestamp === null || $pgTimestamp === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($pgTimestamp, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
        } catch (\Exception) {
            return $pgTimestamp;
        }
    }
}
