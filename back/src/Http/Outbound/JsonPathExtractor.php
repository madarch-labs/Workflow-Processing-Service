<?php

declare(strict_types=1);

namespace Wps\Http\Outbound;

use Flow\JSONPath\JSONPath;
use Flow\JSONPath\JSONPathException;

/**
 * JSONPath for response.extract (softcreatr/jsonpath, Goessner syntax: $.data.id, $.items[0], $..id, filters):
 * nothing found → null, one match → the value, several → a list.
 */
final class JsonPathExtractor
{
    public static function error(string $path): ?string
    {
        if (!str_starts_with($path, '$')) {
            return 'JSONPath must start with "$"';
        }
        try {
            (new JSONPath([]))->find($path);
            return null;
        } catch (JSONPathException $e) {
            return 'Invalid JSONPath: ' . $e->getMessage();
        } catch (\Throwable $e) {
            return 'Invalid JSONPath';
        }
    }

    public static function extract(mixed $data, string $path): mixed
    {
        if (!is_array($data)) {
            return null;
        }
        try {
            $found = (new JSONPath($data))->find($path)->getData();
        } catch (\Throwable) {
            return null;
        }
        $found = is_array($found) ? array_values($found) : [$found];
        return match (count($found)) {
            0 => null,
            1 => self::plain($found[0]),
            default => array_map(self::plain(...), $found),
        };
    }

    private static function plain(mixed $value): mixed
    {
        return $value instanceof JSONPath ? $value->getData() : $value;
    }
}
