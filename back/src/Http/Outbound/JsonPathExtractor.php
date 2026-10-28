<?php

declare(strict_types=1);

namespace Wps\Http\Outbound;

use Wps\Support\JsonPath;

/**
 * JSONPath for response.extract (softcreatr/jsonpath, Goessner syntax: $.data.id, $.items[0], $..id, filters):
 * nothing found → null, one match → the value, several → a list. The parsing lives in Wps\Support\JsonPath, shared
 * with the json_get/json_get_all expression functions (md/tasks_list/done/5.2.6-expr-types.md §4.3).
 */
final class JsonPathExtractor
{
    public static function error(string $path): ?string
    {
        return JsonPath::error($path);
    }

    public static function extract(mixed $data, string $path): mixed
    {
        if (!is_array($data)) {
            return null;
        }
        $found = JsonPath::all($data, $path);
        return match (count($found)) {
            0 => null,
            1 => $found[0],
            default => $found,
        };
    }
}
