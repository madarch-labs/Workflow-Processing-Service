<?php

declare(strict_types=1);

namespace Wps\Support;

use Flow\JSONPath\JSONPath as JsonPathDocument;
use Flow\JSONPath\JSONPathException;

/**
 * JSONPath shared by http_request response.extract and the json_get/json_get_all expression functions
 * (md/tasks_list/done/5.2.6-expr-types.md §4.3): softcreatr/jsonpath with Goessner syntax ($.data.id, $.items[0],
 * $..id, filters). One implementation for both, so the behaviour cannot drift.
 */
final class JsonPath
{
    /**
     * Validates a path when a process is saved; null means the path is valid.
     */
    public static function error(string $path): ?string
    {
        if (!str_starts_with($path, '$')) {
            return 'JSONPath must start with "$"';
        }
        try {
            (new JsonPathDocument([]))->find($path);
            return null;
        } catch (JSONPathException $e) {
            return 'Invalid JSONPath: ' . $e->getMessage();
        } catch (\Throwable) {
            return 'Invalid JSONPath';
        }
    }

    /**
     * The first match, or null when nothing is found.
     */
    public static function first(mixed $data, string $path): mixed
    {
        $found = self::all($data, $path);
        return $found[0] ?? null;
    }

    /**
     * All matches as a list; an empty list when nothing is found or the document is not an array.
     *
     * @return list<mixed>
     */
    public static function all(mixed $data, string $path): array
    {
        if (!is_array($data)) {
            return [];
        }
        try {
            $found = (new JsonPathDocument($data))->find($path)->getData();
        } catch (\Throwable) {
            return [];
        }
        $found = is_array($found) ? array_values($found) : [$found];
        return array_map(self::plain(...), $found);
    }

    private static function plain(mixed $value): mixed
    {
        return $value instanceof JsonPathDocument ? $value->getData() : $value;
    }
}
